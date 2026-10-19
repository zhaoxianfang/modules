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
use zxf\Modules\Logging\Writer\LogRecordFactory;

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

    /**
     * $burstSeen 的容量上限。
     *
     * 原实现只写不清、只按时间判过期但不删除：常驻进程（Octane / horizon / queue worker）下，
     * 若 burst_throttle 开启且错误签名持续变化（含动态 ID 的消息），该数组单调增长直至 OOM。
     * 超过上限时整体丢弃（限流只影响"减少重复日志"，宁可少限流一轮也不能吃光内存）。
     */
    private const BURST_SEEN_MAX = 1000;

    /** 表探测失败（数据库不可达）的独立短 TTL 负缓存时长（秒） */
    private const PROBE_FAILURE_TTL = 5.0;

    /** 是否处于探测失败负缓存窗口内 */
    private static bool $probeFailed = false;

    /** 进入负缓存窗口的时间戳 */
    private static float $probeFailedAt = 0.0;

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

        // 数据库不可达时的【独立短 TTL 负缓存】。
        //
        // 原实现在探测失败时「不写缓存」以避免长期静默，但代价是：一次请求若产生 30 条日志，
        // 就会发起 30 次真实连接尝试 —— connect_timeout=5s 时最坏累计 150s 静默挂死。
        // 这既不是"不抛异常"，也让日志子系统成为可用性风险。
        // 用独立的短 TTL（5s）负缓存兼顾两头：避免高频重试，又不会像长 TTL 那样长期静默。
        if (self::$probeFailed && (microtime(true) - self::$probeFailedAt) < self::PROBE_FAILURE_TTL) {
            return false;
        }

        try {
            $table = $this->table ?? (new LogModel)->getTable();
            // 复用 LogRecordFactory 的表存在性缓存（与列清单同一条链路、同一 TTL），
            // 消除原先"handler 与 factory 各发一次 Schema::hasTable"的重复探测。
            $exists = LogRecordFactory::tableExists($table);
        } catch (Throwable) {
            // 探测失败（数据库瞬时不可达）：记入短 TTL 负缓存后安全跳过
            self::$probeFailed = true;
            self::$probeFailedAt = microtime(true);

            return false;
        }

        // 探测成功：立即解除负缓存，下一条日志即可正常落库
        self::$probeFailed = false;
        self::$probeFailedAt = 0.0;

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
        //
        // 必须用 LogContext::isCliSapi()（含 phpdbg）而不是裸 PHP_SAPI：
        // 上面 headers_sent() 守卫用的就是 isCliSapi()，两处判定口径必须一致，
        // 否则 phpdbg 下会"写库但跳过 headers_sent 守卫"，行为与 cli 自相矛盾。
        if (LogContext::isCliSapi()
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
     * 按数据库列宽安全截断，超宽值一律裁剪而非抛错。
     *
     * 这是**整条日志能否落库**的关键：MySQL 严格模式（STRICT_TRANS_TABLES）下，
     * 任何超长值都会抛 "Data too long"，进而触发 fallbackToStack —— 也就是说
     * 一个超长的路由名 / 控制器名 / 异常文件路径，会让**整行日志永久进不了库**。
     *
     * mb_strcut 而非 mb_substr：前者保证不会把一个多字节字符从中间劈开（劈开会产生
     * 非法 UTF-8，写入 MySQL 同样报错），且按字节裁剪，与 VARCHAR(N) 的字节语义一致。
     */
    private static function clip(mixed $value, int $width): ?string
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        $str = mb_strcut((string) $value, 0, $width, 'UTF-8');

        return $str === '' ? null : $str;
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
        $preRedactedLookup = LogContext::preRedactedKeyLookup();
        foreach ($extra as $key => $value) {
            // isset($lookup[$key]) 取代 in_array(..., PRE_REDACTED_KEYS, true)：
            // 后者对每条日志的每个 extra 键做一次 O(27) 线性字符串扫描。
            if (isset($preRedactedLookup[$key])) {
                $preRedacted[$key] = $value;
            } else {
                $rest[$key] = $value;
            }
        }

        // JSON 响应下 response_content 与 response_body 指向【同一个】≤20000 字符的字符串，
        // 原实现对两者各跑一遍 maskText（5 条正则）→ 白白翻倍 CPU。
        // 这里只算一次，两个列复用同一结果。
        $rawBody = $extra['response_body'] ?? '';
        $maskedBody = $rawBody === '' ? '' : $redactor->maskText((string) $rawBody);

        $attributes = [
            'module_name' => self::clip($extra['module_name'] ?? null, 50),
            'level' => $this->normalizeLevel($record),
            'channel' => self::clip($record->channel, 50),
            // 先截断再脱敏：落库的只有前 65535 字节，对这一段脱敏与"先脱敏再截断"等价，
            // 但避免对 10MB 的 message 跑完正则再丢掉 99%。
            'message' => $redactor->maskText(mb_strcut((string) $record->message, 0, 65535, 'UTF-8')),
            'context' => $redactor->sanitizeContext($context),
            'extra' => $redactor->sanitizeContext($rest),
            'is_crawler' => (bool) ($extra['is_crawler'] ?? false),

            'request_method' => self::clip($extra['request_method'] ?? null, 10),
            // fullUrl() 含 query string，?token= / ?password= / ?api_key= 这类凭证
            // 会明文入库并被后台检索、导出，因此与 request_headers 同口径脱敏。
            'request_url' => $redactor->maskText((string) ($extra['request_url'] ?? '')) ?: null,
            // 以下四列均为定宽 VARCHAR，原实现直接写入未截断值。
            'request_path' => self::clip($extra['request_path'] ?? null, 512),
            'request_route' => self::clip($extra['request_route'] ?? null, 255),
            'request_controller' => self::clip($extra['request_controller'] ?? null, 255),
            'request_ip' => self::clip($extra['request_ip'] ?? null, 45),
            'request_user_agent' => self::clip($extra['request_user_agent'] ?? null, 2000),
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
            'user_type' => self::clip($extra['user_type'] ?? null, 50),

            'response_code' => $extra['response_code'] ?? null,
            'response_status' => self::clip($extra['response_status'] ?? null, 50),
            // response_content 单独成列（视图名 / JSON 数据）。与 response_body 保持一致，
            // 经过脱敏处理：视图名不含敏感信息仅做格式保留；JSON 数据中的敏感键（password / token …）
            // 会被 Redactor 统一脱敏，避免泄露到 logs 表。
            'response_content' => isset($extra['response_content'])
                ? ($redactor->maskText((string) $extra['response_content']) ?: null)
                : null,
            'response_duration_ms' => $extra['response_duration_ms'] ?? null,
            'response_memory_mb' => $extra['response_memory_mb'] ?? null,
            'response_headers' => ($this->captureResponseBody
                ? $redactor->maskHeaders((array) ($extra['response_headers'] ?? []))
                : null),
            // 补 ?: null：空响应体落库为 '' 而非 null，会让
            // `WHERE response_body IS NOT NULL` 之类的查询失真（与 request_url 口径对齐）。
            'response_body' => ($this->captureResponseBody
                ? ($maskedBody ?: null)
                : null),

            'exception_class' => self::clip($trace['class'] ?? null, 255),
            'exception_message' => $trace['message'] ?? null,
            'file' => self::clip($trace['file'] ?? null, 512),
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

        // 仅在【旧表结构】（没有 bot_type 列，写入器会把这些列过滤掉）时才把识别结果
        // 冗余进 context['bot']，以免旧安装丢失数据。
        //
        // 绝不能无条件注入：Log::scopeBusinessLogs() 是 `context IS NOT NULL AND context != '[]'`，
        // 无条件注入会让每一条日志行的 context 至少是 {"bot":{...}}，
        // 该 scope 恒等于全表 —— 一个对外公开的查询 API 静默失效。
        // 列清单由 LogRecordFactory 缓存，此处只是一次 isset，无额外查询开销。
        $columns = $this->table === null ? null : LogRecordFactory::columns($this->table);
        $table = $this->table ?? (new LogModel)->getTable();
        $columns ??= LogRecordFactory::columns($table);

        if ($columns === null || ! in_array('bot_type', $columns, true)) {
            $context = is_array($attributes['context'] ?? null) ? $attributes['context'] : [];
            $context['bot'] = $bot->toArray();
            $attributes['context'] = $context;
        }

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
        // 同上：等级维度必须来自 normalizeLevel()。若 level_name 退化，
        // 签名会塌成 "|message"，等级维度消失 → 同请求内 info/error 同名消息互相吞掉。
        $key = $this->normalizeLevel($record).'|'.$record->message;
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
            // class(255) / file(512) 是定宽列，同样必须截断，
            // 否则一个超长路径会让整行日志触发 "Data too long" 而永久进不了库。
            'class' => self::clip($e::class, 255),
            'message' => mb_strcut($e->getMessage(), 0, 65535, 'UTF-8'),
            'file' => self::clip($e->getFile().':'.$e->getLine(), 512),
            'trace' => mb_strcut($e->getTraceAsString(), 0, 60000, 'UTF-8'),
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
        // 必须用 normalizeLevel()（走 Monolog\Level::toPsrLogLevel()）而不是 $record['level_name']。
        // level_name 的形态在不同 Monolog 3.x 小版本间有差异，一旦退化取不到，
        // strtolower('') 恒不匹配 → 【限流对所有错误级别静默失效】，而用户看到配置已开启。
        $name = $this->normalizeLevel($record);
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

        // 常驻进程（Octane / horizon / queue worker）下的无界增长防护：
        // 签名含动态 ID 时该数组单调增长，一旦超过上限整体丢弃（宁可少限流一轮）。
        if (count(self::$burstSeen) >= self::BURST_SEEN_MAX) {
            self::$burstSeen = [];
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

        $previous = $this->config;
        $this->config = LoggingConfig::fromConfig();
        $this->configLoadedAt = microtime(true);

        // 常驻进程（Octane / queue worker）下的配置热更新补偿。
        //
        // $this->config 带 TTL 会刷新，但 Redactor 是【单例】—— 它的敏感键表与
        // maxFieldLength 在注册时就固化了。Redactor 提供了 recompile() 却没有任何调用方，
        // 结果是：改了 logging.db.sensitive_keys / max_field_length 之后，
        // 配置对象读到了新值、脱敏器却仍是旧词表 → 静默不生效。
        // 这里在 TTL 到期时比对一次，发现词表变化就重建实例（recompile 仍不足以改变键表）。
        if ($this->redactor !== null
            && ($previous === null
                || $previous->sensitiveKeys !== $this->config->sensitiveKeys
                || $previous->maxFieldLength !== $this->config->maxFieldLength)
        ) {
            $this->redactor = new Redactor(
                $this->config->sensitiveKeys,
                $this->config->maxFieldLength
            );
        }

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
