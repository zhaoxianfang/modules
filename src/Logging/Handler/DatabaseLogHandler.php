<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Handler;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log as LogFacade;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;
use zxf\Modules\Logging\Config\LoggingConfig;
use zxf\Modules\Logging\Contracts\LogWriter;
use zxf\Modules\Logging\Contracts\RedactorContract;
use zxf\Modules\Logging\LogContext;
use zxf\Modules\Logging\Middleware\LogContextMiddleware;
use zxf\Modules\Logging\Models\Log as LogModel;
use zxf\Modules\Logging\Support\BotSignature;
use zxf\Modules\Logging\Support\Redactor;
use zxf\Modules\Logging\Writer\DatabaseLogWriter;

/**
 * 数据库日志处理器（Monolog 3 兼容，Laravel 11 / 12 / 13 通用）。
 *
 * 落库语义（本模块的核心约定）：
 * - **每个 HTTP 请求记录一条「请求日志」**：由 {@see LogContextMiddleware}
 *   在响应采集完成后向 database 通道写入一条带 `context.__request__ = true` 的记录，
 *   该行承载完整的请求 + 响应上下文（方法 / URL / 路由 / IP / 入参 / 请求头 /
 *   状态码 / 耗时 / 峰值内存 / 响应体 …）。幂等由 {@see LogContext::claimRequestLog()} 保证，
 *   配合 RequestHandled 兜底，绝不会重复写两条。
 * - **业务日志逐条落库**：请求期间业务代码里的 Log::info() / Log::error() / report($e)
 *   各自写入一行，携带轻量请求上下文以便溯源；它们**不重复**存储大体积的
 *   请求入参 / 请求头 / 响应体（那些只存在于请求日志那一行）。
 * - 命中 skip_paths 的请求（健康检查 / 调试面板 / 轮询等）一条日志都不写。
 * - 无请求上下文（CLI / 队列 worker）时逐条落库，由 logging.db.log_cli 控制开关。
 *
 * 职责拆分：
 * - 归一化：把 LogRecord 翻译为与 logs 表字段对应的属性数组（buildAttributes）；
 * - 脱敏：所有敏感字段脱敏交由 RedactorContract（全模块唯一事实来源）；
 * - 落库：委托给 LogWriter 抽象（默认 DatabaseLogWriter，可配置为 QueuedDatabaseLogWriter）；
 * - 事件：每次成功落库派发 LogRecorded 事件（默认无监听，可用于告警 / 同步 / 审计）；
 * - 回退：落库失败时回退到 stack 文件通道，确保关键日志不丢失。
 *
 * 性能要点：
 * - extra 中由中间件预先脱敏过的键（见 {@see LogContext::PRE_REDACTED_KEYS}）
 *   在 sanitizeContext 时被摘出、不做二次深度遍历，每条日志省掉一次
 *   对 request_headers / request_input 数组的递归脱敏。
 * - 日志表存在性判定带 TTL 缓存，避免每个请求都查一次 information_schema。
 *
 * 关键实现约束：
 * - LogRecord 的 level 是 Monolog\Level 枚举；取等级名请用数组访问 `$record['level_name']`
 *   （返回大写名如 'ERROR'）。本环境的 Monolog 在 LogRecord 上未暴露 level_name 公共属性，
 *   只能通过 offsetGet 访问；切勿写 `$record->level_name`（会触发 Undefined property）。
 *   切勿对 level 重新赋值（会抛 LogicException）。
 * - 仅 extra / formatted 可写；context / message 等为 readonly，不可重新赋值。
 * - 任何写入异常都被捕获或回退，绝不影响主业务流程。
 */
class DatabaseLogHandler extends AbstractProcessingHandler
{
    private ?LoggingConfig $config = null;

    /** $config 的进程内缓存时间戳，用于常驻进程（Octane / queue worker）下按 TTL 刷新配置 */
    private float $configLoadedAt = 0.0;

    /** config() 读取到的 TTL 本身也只解析一次（避免每条日志都走容器） */
    private ?float $configTtl = null;

    /** 进程内兜底限流状态（仅在 logging.db.burst_throttle 开启时使用） */
    private static array $burstSeen = [];

    /** 回退写文件时的重入守护：防止 stack 通道又含 database 时无限递归（直至栈溢出） */
    private static bool $fallbackLock = false;

    public function __construct(
        protected ?string $model = null,
        protected ?string $table = null,
        protected bool $captureRequestBody = true,
        protected bool $captureResponseBody = true,
        protected ?RedactorContract $redactor = null,
        protected ?LogWriter $writer = null,
        Level $level = Level::Debug,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);
    }

    /**
     * 检查日志表是否存在（带 TTL 的进程级缓存）。
     *
     * 用于「未发布 / 未执行迁移、或库表未初始化时自动跳过本日志模块」的能力：
     * 此时绝不抛错，也不会回退到文件通道刷屏，仅仅是静默跳过。
     * 任何数据库不可达 / 查询异常都被视为「不存在」并安全跳过。
     *
     * @param  LoggingConfig  $config  强类型配置
     */
    private function logsTableExists(LoggingConfig $config): bool
    {
        $ttl = max(0, $config->schemaCacheTtl);
        $cached = LogContext::logsTableExists($ttl);

        if ($cached !== null) {
            return $cached;
        }

        try {
            $table = $this->table ?? (new LogModel)->getTable();
            $exists = Schema::hasTable($table);
        } catch (Throwable) {
            // 探测失败（数据库瞬时不可达）时**不写入缓存**：
            // 一旦把 false 缓存住，数据库恢复后日志仍会长期静默。
            return false;
        }

        LogContext::setLogsTableExists($exists, $ttl);

        return $exists;
    }

    protected function write(LogRecord $record): void
    {
        // 防御：若响应内容已经发送（headers_sent = true），说明请求已进入异常终态
        // （如响应体已输出、或发生「headers already sent」类问题）。此时再向数据库落库既无意义，
        // 更可能卷入「输出已开始后再触碰会话 / 头信息」的连锁错误，故直接跳过本模块功能，
        // 避免日志子系统本身放大或重复记录同类问题。
        //
        // 必须排除 CLI / phpdbg：这两个 SAPI 不使用 HTTP 头，但 headers_sent()
        // 在**任何输出之后**都会返回 true。artisan 命令、队列 worker、测试脚本
        // 一旦打印过内容，若不加区分地判断，后续所有日志都会被这个守卫静默丢弃。
        if (! LogContext::isCliSapi() && headers_sent()) {
            return;
        }

        // 命中 skip_paths 的请求（健康检查 / 调试面板 / 轮询等高频路径）一条日志都不写。
        if (LogContext::isSkipped()) {
            return;
        }

        $config = $this->config();

        // CLI 模式默认不写库（无请求上下文）。支持两种显式开启方式：
        //   1) 单条日志携带 context['console_to_log'] = true
        //   2) 全局配置 logging.db.log_cli = true
        if (PHP_SAPI === 'cli'
            && empty($record->context['console_to_log'])
            && ! $config->logCli
        ) {
            return;
        }

        // 日志表不存在（迁移未发布 / 未执行 migrate / 库表尚未初始化）时，直接跳过本模块功能，
        // 避免任何数据库异常污染主业务流程（如 composer update、首次部署前）。
        if (! $this->logsTableExists($config)) {
            return;
        }

        // 区分「请求日志」与「业务日志」：前者每请求恰好一条，后者逐条落库。
        if ((bool) ($record->context[LogContext::REQUEST_LOG_FLAG] ?? false)) {
            if (! LogContext::claimRequestLog()) {
                return;
            }
        } else {
            // 单次请求内同签名业务日志只记一次（避免异常在同一请求里被反复记录刷屏）
            $key = $this->dedupeKey($record);
            if (LogContext::hasSeen($key)) {
                return;
            }
            LogContext::markSeen($key);

            // 跨请求限流（仅在显式开启时生效，默认关闭）：
            // 开启后会吞掉本应各自留痕的偶发错误，故默认关闭。
            if ($config->burstThrottle && $this->shouldThrottleBurst($record, $config->burstThrottleSeconds)) {
                return;
            }
        }

        // 落库（失败时回退到 stack 文件通道，确保关键日志不丢失）
        try {
            $this->writer()->write($this->buildAttributes($record));
        } catch (Throwable $e) {
            $this->fallbackToStack($record, $e);
        }
    }

    /**
     * 将 LogRecord 归一化为与 logs 表字段对应的属性数组。
     *
     * @return array<string, mixed>
     */
    protected function buildAttributes(LogRecord $record): array
    {
        $extra = $record->extra;
        $redactor = $this->redactor();

        // 剔除一次性控制标记，避免写入 context 列造成噪音
        $context = $record->context;
        unset($context[LogContext::REQUEST_LOG_FLAG], $context['console_to_log']);

        $trace = $this->trace($record);

        // 中间件已对下列键完成脱敏（request_headers / request_input / response_body 等），
        // 再次深度遍历纯属浪费 CPU（业务日志量通常是请求日志的数十倍）。
        // 把它们从待脱敏集合中摘出，只 sanitizeContext 业务自己产生的 extra。
        $preRedacted = [];
        $rest = [];
        foreach ($extra as $key => $value) {
            if (in_array($key, LogContext::PRE_REDACTED_KEYS, true)) {
                $preRedacted[$key] = $value;
            } else {
                $rest[$key] = $value;
            }
        }

        $attributes = [
            'module_name' => $extra['module_name'] ?? null,
            'level' => $this->normalizeLevel($record),
            'channel' => $record->channel,
            'message' => mb_substr($redactor->maskText($record->message), 0, 65535),
            'context' => $redactor->sanitizeContext($context),
            'extra' => $redactor->sanitizeContext($rest),
            'is_crawler' => (bool) ($extra['is_crawler'] ?? false),

            'request_method' => $extra['request_method'] ?? null,
            // fullUrl() 含 query string，?token= / ?password= / ?api_key= 这类凭证
            // 会明文入库并被后台检索、导出，因此与 request_headers 同口径脱敏。
            'request_url' => $redactor->maskText((string) ($extra['request_url'] ?? '')) ?: null,
            'request_path' => $extra['request_path'] ?? null,
            'request_route' => $extra['request_route'] ?? null,
            'request_controller' => $extra['request_controller'] ?? null,
            'request_ip' => $extra['request_ip'] ?? null,
            'request_user_agent' => $extra['request_user_agent'] ?? null,
            'request_referer' => $redactor->maskText((string) ($extra['request_referer'] ?? '')) ?: null,
            // session id 本身就是 Cookie laravel_session 的值，
            // 不脱敏等于抵消了 request_headers.cookie 的脱敏效果。
            'request_session_id' => $redactor->maskText((string) ($extra['request_session_id'] ?? '')) ?: null,
            'request_input' => ($this->captureRequestBody ? ($extra['request_input'] ?? null) : null),
            // 中间件虽已 maskHeaders()，但 RequestContextProcessor 等其它生产者
            // 并不会脱敏，一旦有任一路径绕过中间件，Cookie / Authorization 就会
            // 明文入库。因此在**最终落库前**统一再脱敏一次，保证「无论数据从哪来」。
            'request_headers' => ($this->captureRequestBody
                ? $redactor->maskHeaders((array) ($extra['request_headers'] ?? []))
                : null),

            'user_id' => $extra['user_id'] ?? null,
            'user_type' => $extra['user_type'] ?? null,

            'response_code' => $extra['response_code'] ?? null,
            'response_status' => $extra['response_status'] ?? null,
            // response_content 单独成列（视图名 / JSON 数据）。与 response_body 保持一致，
            // 经过脱敏处理：视图名不含敏感信息仅做格式保留；JSON 数据中的敏感键（password / token …）
            // 会被 Redactor 统一脱敏，避免泄露到 logs 表。
            'response_content' => isset($extra['response_content'])
                ? $redactor->maskText((string) $extra['response_content'])
                : null,
            'response_duration_ms' => $extra['response_duration_ms'] ?? null,
            'response_memory_mb' => $extra['response_memory_mb'] ?? null,
            'response_headers' => ($this->captureResponseBody
                ? $redactor->maskHeaders((array) ($extra['response_headers'] ?? []))
                : null),
            'response_body' => ($this->captureResponseBody
                ? $redactor->maskText((string) ($extra['response_body'] ?? ''))
                : null),

            'exception_class' => $trace['class'] ?? null,
            'exception_message' => $trace['message'] ?? null,
            'file' => $trace['file'] ?? null,
            'trace' => $trace['trace'] ?? null,
        ];

        // 补回被摘出的预脱敏键。
        // 只在「该列尚未被显式赋值」时补（module_name 已在上方直接读取），
        // 否则会用 extra 里的原始值覆盖掉上面已完成的脱敏结果 —— 例如
        // response_body 明明已经过 maskText，再覆盖一次就等于没脱敏。
        foreach ($preRedacted as $key => $value) {
            if (! array_key_exists($key, $attributes)) {
                $attributes[$key] = $value;
            }
        }

        return $this->applyBotAttributes($attributes);
    }

    /**
     * 追加机器人识别相关列（bot_* 为可空列，旧表结构下由写入器自动过滤）。
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function applyBotAttributes(array $attributes): array
    {
        $bot = LogContext::bot();
        if ($bot === null) {
            return $attributes;
        }

        $attributes['bot_type'] = $bot->type;
        $attributes['bot_score'] = $bot->score;
        $attributes['bot_client'] = $bot->client;
        $attributes['bot_signals'] = $bot->signals === [] ? null : implode(' | ', $bot->signals);

        // 同时写入 context['bot']，确保在尚未升级表结构（无 bot_* 列）的旧安装上
        // 识别结果依然完整可查，不会因列缺失而丢失。
        $context = is_array($attributes['context'] ?? null) ? $attributes['context'] : [];
        $context['bot'] = $bot->toArray();
        $attributes['context'] = $context;

        return $attributes;
    }

    /**
     * 落库失败时回退：把原始信息写入 stack 文件通道，确保关键日志不丢失。
     */
    protected function fallbackToStack(LogRecord $record, ?Throwable $e = null): void
    {
        // 重入守护：若 stack 通道又含 database（如 LOG_STACK=single,database），回退写文件会再次
        // 触发 DatabaseLogHandler::write → 再次回退，形成无限递归直至栈溢出。用静态锁阻断嵌套回退。
        if (self::$fallbackLock) {
            return;
        }
        self::$fallbackLock = true;
        try {
            LogFacade::channel('stack')->error('[database-log] 写入日志表失败，已回退至文件通道', [
                'original_level' => $record['level_name'],
                'message' => $record->message,
                'exception' => $e?->getMessage(),
            ]);
        } catch (Throwable) {
            // 彻底放弃，不影响主流程
        } finally {
            self::$fallbackLock = false;
        }
    }

    /**
     * 单次请求内的去重签名：level|message|异常类。
     */
    protected function dedupeKey(LogRecord $record): string
    {
        $key = $record['level_name'].'|'.$record->message;
        if ($this->hasException($record)) {
            $key .= '|'.$record->context['exception']::class;
        }

        return $key;
    }

    /**
     * 该记录是否承载异常。
     */
    protected function hasException(LogRecord $record): bool
    {
        return isset($record->context['exception']) && $record->context['exception'] instanceof Throwable;
    }

    /**
     * 从 context.exception 提取异常信息（单独成列，便于检索）。
     *
     * @return array<string, string>
     */
    protected function trace(LogRecord $record): array
    {
        if (! $this->hasException($record)) {
            return [];
        }

        /** @var Throwable $e */
        $e = $record->context['exception'];

        return [
            'class' => $e::class,
            'message' => mb_substr($e->getMessage(), 0, 65535),
            'file' => $e->getFile().':'.$e->getLine(),
            'trace' => mb_substr($e->getTraceAsString(), 0, 60000),
        ];
    }

    /**
     * 把 Monolog 等级归一化为 PSR 小写键（如 error / info / warning）。
     * 优先使用 Monolog\Level::toPsrLogLevel()（语义最准确）；该方法不可用时回退为
     * 对 level_name 做 strtolower。logs 表的 level 列只存这种小写键，
     * 便于 where('level','error') 之类的检索与跨驱动一致。
     */
    protected function normalizeLevel(LogRecord $record): string
    {
        $level = $record->level;
        if ($level instanceof Level && method_exists($level, 'toPsrLogLevel')) {
            return $level->toPsrLogLevel();
        }

        return strtolower((string) ($record['level_name'] ?? 'info'));
    }

    /**
     * 跨请求限流判定（仅在 logging.db.burst_throttle = true 时启用）。
     * 用于消除「每个请求都报同一个错」导致的重复日志。
     * 优先级：Laravel Cache（跨进程、跨请求持久，键 TTL 秒）> 进程内内存限流（兜底）。
     * 任何异常都被视为「不限流」（放行），绝不因限流逻辑自身故障而吞掉日志。
     */
    protected function shouldThrottleBurst(LogRecord $record, int $seconds = 60): bool
    {
        $name = strtolower((string) ($record['level_name'] ?? ''));
        // 仅对错误 / 警告级别限流；info / debug 等常规日志保持全量落库
        if (! in_array($name, ['error', 'critical', 'alert', 'emergency', 'warning'], true)) {
            return false;
        }

        $signature = $this->dedupeKey($record);

        try {
            if (function_exists('cache') && class_exists(Cache::class)) {
                /** @var Repository $store */
                $store = Cache::store();
                $cacheKey = 'logging:burst:'.md5($signature);
                if ($store->has($cacheKey)) {
                    return true;
                }
                $store->put($cacheKey, 1, $seconds);

                return false;
            }
        } catch (Throwable) {
            // Cache 不可用时回退进程内限流
        }

        return self::throttleInMemory($signature, $seconds);
    }

    /**
     * 进程内兜底限流（仅当前 PHP 进程有效，FPM 下按 worker 隔离）。
     *
     * @param  string  $signature  限流签名
     * @param  int  $seconds  限流窗口
     */
    private static function throttleInMemory(string $signature, int $seconds = 60): bool
    {
        $now = microtime(true);
        if (isset(self::$burstSeen[$signature])
            && ($now - self::$burstSeen[$signature]) < $seconds) {
            return true;
        }
        self::$burstSeen[$signature] = $now;

        return false;
    }

    /**
     * 脱敏器：懒加载，优先使用容器绑定的 RedactorContract 实现（由 LoggingServiceProvider 绑定），
     * 非容器环境下回退为按配置新建。
     */
    protected function redactor(): RedactorContract
    {
        if ($this->redactor === null) {
            $this->redactor = (function_exists('app') && app()->bound(RedactorContract::class))
                ? app(RedactorContract::class)
                : new Redactor(LoggingConfig::fromConfig()->sensitiveKeys);
        }

        return $this->redactor;
    }

    /**
     * 落库写入器：懒加载，优先使用容器绑定的实现（可由自定义 LogWriter 替换，
     * 或按配置切换为 QueuedDatabaseLogWriter），非容器环境下回退为默认 Eloquent 写入器。
     */
    protected function writer(): LogWriter
    {
        if ($this->writer === null) {
            $this->writer = (function_exists('app') && app()->bound(LogWriter::class))
                ? app(LogWriter::class, ['model' => $this->model, 'table' => $this->table])
                : new DatabaseLogWriter($this->model, $this->table);
        }

        return $this->writer;
    }

    /**
     * 强类型配置（logging.db.* + handler_with.*），懒加载并按 TTL 缓存。
     *
     * 必须带 TTL：handler 由 config/logging.php 挂在 monolog 通道上，
     * 在 Octane / queue worker 这类长驻进程里实例长期存活，
     * 若永久缓存，logging.db.* 的配置改动在进程重启前完全不生效。
     * TTL 设为 0 表示进程内只读一次。
     */
    private function config(): LoggingConfig
    {
        $ttl = $this->configTtl();

        if ($this->config !== null && ($ttl <= 0.0 || (microtime(true) - $this->configLoadedAt) < $ttl)) {
            return $this->config;
        }

        $this->config = LoggingConfig::fromConfig();
        $this->configLoadedAt = microtime(true);

        return $this->config;
    }

    /** 配置缓存存活时长（秒）；无配置门面时退回 300 秒 */
    private function configTtl(): float
    {
        if ($this->configTtl !== null) {
            return $this->configTtl;
        }

        $ttl = 300.0;
        try {
            if (function_exists('config')) {
                $ttl = (float) config('logging.db.config_cache_ttl', 300);
            }
        } catch (Throwable) {
            // 容器不可用：用默认值
        }

        // TTL <= 0 表示「进程内只读一次」，此时无需再判断过期
        return $this->configTtl = ($ttl > 0 ? $ttl : 0.0);
    }

    /** 强制下次 config() 重新读取（供测试与 Octane 重置钩子调用） */
    public function flushConfigCache(): void
    {
        $this->config = null;
        $this->configLoadedAt = 0.0;
        $this->configTtl = null;
    }

    /**
     * 当前请求的机器人识别结果（供外部统计 / 告警复用）。
     */
    public static function currentBotSignature(): ?BotSignature
    {
        return LogContext::bot();
    }
}
