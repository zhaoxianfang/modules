<?php

declare(strict_types=1);

namespace zxf\Modules\Logging;

use zxf\Modules\Logging\Support\BotSignature;

/**
 * 请求 / 响应上下文存储（请求级单例）。
 *
 * 设计：中间件在请求开始时采集请求信息、在响应结束时采集响应信息；
 * Monolog 处理器（RequestContextProcessor）与落库处理器（DatabaseLogHandler）
 * 都从这里读取数据，最终映射到 logs 表字段。
 * 数据同时同步进 Laravel Context 门面，供其它日志通道 / 处理器共享。
 *
 * 承担的请求级职责（全部随 reset() 在每次请求开始时清空）：
 *  1. 请求 / 响应上下文（$request / $response）；
 *  2. 预计算的 extra 片段（$baseExtra）：把「已脱敏的请求上下文」预先压平为
 *     可直接注入 Monolog extra 的标量数组，避免每条日志重复做同样的脱敏与数组构造；
 *  3. 单请求内同签名业务日志去重集合（$seen）；
 *  4. 请求日志幂等标记（$requestLogWritten）：保证「每个 HTTP 请求恰好一条请求日志」；
 *  5. 跳过标记（$skipped）：命中 skip_paths 时完全不落库（请求日志与业务日志一并跳过）；
 *  6. 机器人识别结果缓存（$bot）。
 *
 * 另有一项**进程级**缓存（日志表存在性判定，带 TTL），
 * 它不属于请求作用域，因此在 reset() 时不清空 —— 详见 {@see self::logsTableExists()}。
 */
final class LogContext
{
    /**
     * 「请求日志」标记：中间件写入该标记，落库处理器据此识别「这一行是本次请求的访问记录」，
     * 从而对它应用「每请求恰好一条」的幂等守卫，而不是与业务日志混同。
     *
     * 该键会被落库处理器从 context 列中剔除，不会污染落库数据。
     */
    public const REQUEST_LOG_FLAG = '__request__';

    /**
     * extra 中「由中间件预先完成脱敏」的键名。
     * 落库处理器据此跳过二次脱敏，既省 CPU，也保证这批字段的处理口径唯一。
     *
     * @var list<string>
     */
    public const PRE_REDACTED_KEYS = [
        'module_name',
        'request_method',
        'request_url',
        'request_path',
        'request_route',
        'request_controller',
        'request_ip',
        'request_user_agent',
        'request_referer',
        'request_session_id',
        'request_input',
        'request_headers',
        'user_id',
        'user_type',
        'is_crawler',
        'bot_type',
        'bot_score',
        'bot_client',
        'response_code',
        'response_status',
        'response_content',
        'response_duration_ms',
        'response_memory_mb',
        'response_headers',
        'response_body',
    ];

    /**
     * {@see self::PRE_REDACTED_KEYS} 的「键 → true」查表形式（惰性构建）。
     *
     * 处理器每条日志都要对 extra 的每个键做一次归属判断。用 in_array(..., PRE_REDACTED_KEYS, true)
     * 是一次 O(27) 线性字符串扫描，业务日志的 extra 通常有 13~15 个键 → 每条日志几百次
     * 字符串比较，纯属白烧 CPU。改成 isset() 查表后是 O(1)。
     *
     * @var array<string, bool>|null
     */
    private static ?array $preRedactedLookup = null;

    /**
     * 取得「预脱敏键」查表（首次调用时构建，之后复用）。
     *
     * @return array<string, bool>
     */
    public static function preRedactedKeyLookup(): array
    {
        if (self::$preRedactedLookup === null) {
            self::$preRedactedLookup = array_fill_keys(self::PRE_REDACTED_KEYS, true);
        }

        return self::$preRedactedLookup;
    }

    /** 预计算的 extra 片段（轻量标量：每条日志都注入） */
    private static array $baseExtra = [];

    /** 预计算的 extra 片段（大体积载荷：仅注入请求日志那一行） */
    private static array $payloadExtra = [];

    private static array $request = [];

    private static array $response = [];

    private static bool $hasResponse = false;

    private static float $startTime = 0.0;

    /** 单次请求内业务日志的去重集合（随 reset() 清空） */
    private static array $seen = [];

    /** 本次请求的请求日志是否已写入（幂等守卫） */
    private static bool $requestLogWritten = false;

    /** 本次请求是否命中 skip_paths（命中则一条日志都不落库） */
    private static bool $skipped = false;

    /** 机器人 / 自动化客户端识别结果（按请求缓存） */
    private static ?BotSignature $bot = null;

    /**
     * 日志表存在性判定（进程级缓存，带 TTL）。
     *
     * 挂在进程级而非请求级的原因：
     *  - 请求级缓存会让**每个请求**都做一次 information_schema / sqlite_master 查询，
     *    在高 QPS 下是可观的固定开销；
     *  - 进程级 + TTL 既把查询摊薄到「每 TTL 一次」，又能在建表 / 删表后自动生效。
     */
    private static ?bool $logsTableExists = null;

    /** 表存在性缓存的过期时间戳（Unix 秒） */
    private static int $logsTableExpiresAt = 0;

    /** 请求上下文是否已就绪（供 RequestContextProcessor 判断能否注入 extra） */
    private static bool $active = false;

    public static function setStart(float $t): void
    {
        self::$startTime = $t;
    }

    public static function getStart(): float
    {
        return self::$startTime;
    }

    /**
     * 写入请求上下文，并同步缓存可注入 extra 的片段。
     *
     * @param  array<string, mixed>  $data  原始请求上下文
     * @param  array<string, mixed>  $baseExtra  轻量标量片段（每条日志都注入）
     * @param  array<string, mixed>  $payloadExtra  大体积载荷片段（仅请求日志注入）
     */
    public static function setRequest(
        array $data,
        array $baseExtra = [],
        array $payloadExtra = []
    ): void {
        self::$request = $data;
        self::$baseExtra = $baseExtra;
        self::$payloadExtra = $payloadExtra;
        self::$active = true;
    }

    public static function getRequest(): array
    {
        return self::$request;
    }

    /**
     * 预计算的轻量 extra 片段（供 RequestContextProcessor 零成本注入到每条日志）。
     *
     * @return array<string, mixed>
     */
    public static function baseExtra(): array
    {
        return self::$baseExtra;
    }

    /**
     * 预计算的大体积载荷片段（请求入参 / 请求头；仅注入请求日志那一行）。
     *
     * @return array<string, mixed>
     */
    public static function payloadExtra(): array
    {
        return self::$payloadExtra;
    }

    public static function setResponse(array $data): void
    {
        self::$response = $data;
        self::$hasResponse = true;
    }

    public static function getResponse(): array
    {
        return self::$response;
    }

    public static function hasResponse(): bool
    {
        return self::$hasResponse;
    }

    /**
     * 当前是否处于「有效 HTTP 请求上下文」。
     * 在 CLI / 队列 worker 中恒为 false，避免把上一个请求（或上次 CLI 调用）的残留数据
     * 注入到本次日志里（长驻进程下这是真实存在的串味问题）。
     */
    public static function isActive(): bool
    {
        return self::$active && ! self::isCliSapi();
    }

    /**
     * 请求内去重：是否已记录过该 key（level|message|异常类）。
     */
    public static function hasSeen(string $key): bool
    {
        return isset(self::$seen[$key]);
    }

    public static function markSeen(string $key): void
    {
        self::$seen[$key] = true;
    }

    /**
     * 读取缓存的日志表存在性判定。
     *
     * @param  int  $maxAge  缓存最长存活秒数；<=0 表示不做过期判断，直接返回缓存值
     * @return bool|null null 表示尚未探测或已过期
     */
    public static function logsTableExists(int $maxAge = 0): ?bool
    {
        if (self::$logsTableExists === null) {
            return null;
        }

        if ($maxAge > 0 && self::$logsTableExpiresAt <= time()) {
            return null;
        }

        return self::$logsTableExists;
    }

    /**
     * 缓存日志表存在性判定。
     *
     * @param  int  $ttl  缓存存活秒数；<=0 表示永不过期
     */
    public static function setLogsTableExists(bool $exists, int $ttl = 0): void
    {
        self::$logsTableExists = $exists;
        self::$logsTableExpiresAt = $ttl > 0 ? time() + $ttl : PHP_INT_MAX;
    }

    /**
     * 强制清空日志表存在性缓存（执行迁移 / 改表结构后调用，使新表立即生效）。
     */
    public static function flushLogsTableCache(): void
    {
        self::$logsTableExists = null;
        self::$logsTableExpiresAt = 0;
    }

    /**
     * 读取按请求缓存的机器人识别结果（未检测过返回 null）。
     */
    public static function bot(): ?BotSignature
    {
        return self::$bot;
    }

    /**
     * 缓存机器人识别结果（随 reset() 在每次请求开始时清空）。
     */
    public static function setBot(?BotSignature $bot): void
    {
        self::$bot = $bot;
    }

    /**
     * 本次请求的请求日志是否已写入。
     */
    public static function requestLogWritten(): bool
    {
        return self::$requestLogWritten;
    }

    /**
     * 尝试占位「请求日志写入权」。
     *
     * @return bool true 表示本次调用抢到了写入权；false 表示已写过，应跳过
     */
    public static function claimRequestLog(): bool
    {
        if (self::$requestLogWritten) {
            return false;
        }

        return self::$requestLogWritten = true;
    }

    /**
     * 本次请求是否命中「跳过记录」名单（命中则任何日志都不落库）。
     */
    public static function isSkipped(): bool
    {
        return self::$skipped;
    }

    public static function setSkipped(bool $skipped): void
    {
        self::$skipped = $skipped;
    }

    /**
     * 是否运行在命令行 / 队列 worker 环境。
     *
     * 该判定还被 {@see DatabaseLogHandler} 与 {@see LogContextMiddleware} 用于决定
     * 是否启用 headers_sent() 守卫：PHP 的 CLI SAPI 在**任何输出之后**都会让
     * headers_sent() 返回 true（CLI 不使用 HTTP 头，但该函数仍会如实反映「已输出」）。
     * 若不加区分地判断，artisan 命令、队列 worker、测试脚本一旦打印过任何内容，
     * 日志子系统的 headers_sent() 守卫就会把所有后续日志全部丢弃 ——
     * 表现为「命令跑得越久，日志越少」，且没有任何报错，极难排查。
     */
    public static function isCliSapi(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }

    /**
     * 重置全部请求级状态。
     * 中间件在每个请求开始时调用，确保上下文与去重集合不会跨请求串味
     * （尤其是 Octane / Swoole 常驻进程）。
     *
     * 注意：日志表存在性缓存是进程级的，此处**不**清空，
     * 否则每请求都会重新探测一次表结构。
     */
    public static function reset(): void
    {
        self::$request = [];
        self::$response = [];
        self::$baseExtra = [];
        self::$payloadExtra = [];
        self::$hasResponse = false;
        self::$startTime = 0.0;
        self::$seen = [];
        self::$bot = null;
        self::$requestLogWritten = false;
        self::$skipped = false;
        self::$active = false;
    }
}
