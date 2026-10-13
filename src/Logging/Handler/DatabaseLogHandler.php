<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Handler;

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
use zxf\Modules\Logging\Models\Log as LogModel;
use zxf\Modules\Logging\Support\Redactor;
use zxf\Modules\Logging\Writer\DatabaseLogWriter;

/**
 * 数据库日志处理器（Monolog 3 兼容，Laravel 11 / 12 / 13 通用）。
 *
 * 职责拆分（清晰的关注点分离）：
 * - 去重：单次请求内相同日志只落库一次（去重集合存于 LogContext，随每次请求 reset）；
 * - 归一化：把 LogRecord 翻译为与 logs 表字段对应的属性数组（buildAttributes）；
 * - 脱敏：所有敏感字段脱敏交由 RedactorContract（全模块唯一事实来源）；
 * - 落库：委托给 LogWriter 抽象（默认 DatabaseLogWriter，可配置为 QueuedDatabaseLogWriter）；
 * - 事件：每次成功落库派发 LogRecorded 事件（默认无监听，可用于告警 / 同步 / 审计）；
 * - 回退：落库失败时回退到 stack 文件通道，确保关键日志不丢失。
 *
 * 设计要点：
 * - LogRecord 的 level 是 Monolog\Level 枚举；取等级名请用数组访问 `$record['level_name']`
 *   （返回大写名如 'ERROR'）。本环境的 Monolog 在 LogRecord 上未暴露 level_name 公共属性，
 *   只能通过 offsetGet 访问；切勿写 `$record->level_name`（会触发 Undefined property）。
 *   切勿对 level 重新赋值（会抛 LogicException）。
 * - logs 表的 level 列统一存储「PSR 小写等级键」（如 error / info / warning），由
 *   normalizeLevel() 归一化（优先 Monolog\Level::toPsrLogLevel()，回退 strtolower）。
 *   原冗余的 level_name（大写名）列已移除，避免与 level 重复。
 * - 仅 extra / formatted 可写；context / message 等为 readonly，不可重新赋值。
 * - 任何写入异常都被捕获或回退，绝不影响主业务流程。
 * - 异常处理策略：异常日志的「文件落盘」由 Laravel 默认通道（report($e) / stack）负责，
 *   本 handler 不再二次写 stack，避免与默认通道重复；仅当数据库写入失败时回退 stack 文件。
 *
 * @package zxf\Modules\Logging
 */
class DatabaseLogHandler extends AbstractProcessingHandler
{
    private ?LoggingConfig $config = null;

    /** 进程内兜底限流状态（仅错误 / 警告级别使用，跨请求去重的兜底手段） */
    private static array $burstSeen = [];

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
     * 检查日志表是否存在（结果按「请求」缓存于 LogContext）。
     *
     * 用于实现「未发布 / 未执行迁移、或库表未初始化时自动跳过本日志模块」的能力：
     * 此时绝不抛错，也不会回退到文件通道刷屏，仅仅是静默跳过。
     * 任何数据库不可达 / 查询异常都被视为「不存在」并安全跳过。
     *
     * 缓存放在 LogContext（随每次请求 reset 清空），而非处理器实例：
     * 既避免每条日志都查 information_schema，又能在 Octane / Swoole 常驻进程下
     * 保证表存在性判定随请求刷新（实例会跨请求复用，挂在实例上会跨请求残留）。
     */
    private function logsTableExists(): bool
    {
        $cached = LogContext::logsTableExists();
        if ($cached !== null) {
            return $cached;
        }

        try {
            $table = $this->table ?? (new LogModel())->getTable();
            $exists = Schema::hasTable($table);
        } catch (Throwable) {
            $exists = false;
        }

        LogContext::setLogsTableExists($exists);

        return $exists;
    }

    protected function write(LogRecord $record): void
    {
        // CLI 模式默认不写库（无请求上下文）。支持两种显式开启方式：
        //   1) 单条日志携带 context['console_to_log'] = true
        //   2) 全局配置 logging.db.log_cli = true
        if (PHP_SAPI === 'cli'
            && empty($record->context['console_to_log'])
            && ! $this->config()->logCli) {
            return;
        }

        // 日志表不存在（迁移未发布 / 未执行 migrate / 库表尚未初始化）时，直接跳过本模块功能，
        // 避免任何数据库异常污染主业务流程（如 composer update、首次部署前）。结果按请求缓存。
        if (! $this->logsTableExists()) {
            return;
        }

        // 单次请求内精确去重（避免异常在同一请求里被记录多次刷屏）
        $key = $record['level_name'].'|'.$record->message;
        if ($this->hasException($record)) {
            $key .= '|'.get_class($record->context['exception']);
        }
        if (LogContext::hasSeen($key)) {
            return;
        }
        LogContext::markSeen($key);

        // 跨请求限流（仅对错误 / 警告等级）：同一个错误签名在一段时间内只落库一次，
        // 避免「每请求都报同一个错误」时日志被海量重复内容刷屏（如测试期反复出现的
        // "Cannot modify header information - headers already sent" 类提示）。
        // 通过 Cache 实现跨进程去重；Cache 不可用时回退为进程内限流。
        if ($this->shouldThrottleBurst($record)) {
            return;
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
        unset($context['__summary__'], $context['console_to_log']);

        $trace = $this->trace($record);

        return [
            'module_name' => $extra['module_name'] ?? null,
            'level' => $this->normalizeLevel($record),
            'channel' => $record->channel,
            'message' => mb_substr($redactor->maskText($record->message), 0, 65535),
            'context' => $redactor->sanitizeContext($context),
            'extra' => $redactor->sanitizeContext($extra),
            'is_crawler' => $extra['is_crawler'] ?? false,

            'request_method' => $extra['request_method'] ?? null,
            'request_url' => $extra['request_url'] ?? null,
            'request_path' => $extra['request_path'] ?? null,
            'request_route' => $extra['request_route'] ?? null,
            'request_controller' => $extra['request_controller'] ?? null,
            'request_ip' => $extra['request_ip'] ?? null,
            'request_user_agent' => $extra['request_user_agent'] ?? null,
            'request_referer' => $extra['request_referer'] ?? null,
            'request_session_id' => $extra['request_session_id'] ?? null,
            'request_input' => ($this->captureRequestBody ? ($extra['request_input'] ?? null) : null),
            'request_headers' => ($this->captureRequestBody ? ($extra['request_headers'] ?? null) : null),

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
            'response_headers' => ($this->captureResponseBody ? ($extra['response_headers'] ?? null) : null),
            'response_body' => ($this->captureResponseBody
                ? $redactor->maskText($extra['response_body'] ?? '')
                : null),

            'exception_class' => $trace['class'] ?? null,
            'exception_message' => $trace['message'] ?? null,
            'file' => $trace['file'] ?? null,
            'trace' => $trace['trace'] ?? null,
        ];
    }

    /**
     * 落库失败时回退：把原始信息写入 stack 文件通道，确保关键日志不丢失。
     */
    protected function fallbackToStack(LogRecord $record, ?Throwable $e = null): void
    {
        try {
            LogFacade::channel('stack')->error('[database-log] 写入日志表失败，已回退至文件通道', [
                'original_level' => $record['level_name'],
                'message' => $record->message,
                'exception' => $e?->getMessage(),
            ]);
        } catch (Throwable) {
            // 彻底放弃，不影响主流程
        }
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
     */
    protected function trace(LogRecord $record): array
    {
        if (! $this->hasException($record)) {
            return [];
        }

        $e = $record->context['exception'];

        return [
            'class' => get_class($e),
            'message' => mb_substr($e->getMessage(), 0, 65535),
            'file' => $e->getFile().':'.$e->getLine(),
            'trace' => mb_substr($e->getTraceAsString(), 0, 60000),
        ];
    }

    /**
     * 把 Monolog 等级归一化为 PSR 小写键（如 error / info / warning）。
     *
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
     * 跨请求限流判定：同一错误 / 警告签名在短时间内只应落库一次。
     *
     * 用于消除「每个请求都报同一个错」导致的海量重复日志（例如反复出现的
     * "Cannot modify header information - headers already sent" 提示）。
     *
     * 优先级：Laravel Cache（跨进程、跨请求持久，键 60s 过期）> 进程内内存限流（兜底）。
     * 任何异常都被视为「不限流」（放行），绝不因限流逻辑自身故障而吞掉日志。
     */
    protected function shouldThrottleBurst(LogRecord $record): bool
    {
        $name = strtolower((string) ($record['level_name'] ?? ''));
        // 仅对错误 / 警告级别限流；info / debug 等常规日志保持全量落库
        if (! in_array($name, ['error', 'critical', 'alert', 'emergency', 'warning'], true)) {
            return false;
        }

        $signature = $name.'|'.$record->message;
        if ($this->hasException($record)) {
            $signature .= '|'.get_class($record->context['exception']);
        }

        try {
            if (function_exists('cache')
                && class_exists(\Illuminate\Support\Facades\Cache::class)) {
                /** @var \Illuminate\Contracts\Cache\Repository $store */
                $store = \Illuminate\Support\Facades\Cache::store();
                $cacheKey = 'logging:burst:'.md5($signature);
                if ($store->has($cacheKey)) {
                    return true;
                }
                $store->put($cacheKey, 1, 60);

                return false;
            }
        } catch (\Throwable) {
            // Cache 不可用时回退进程内限流
        }

        return self::throttleInMemory($signature);
    }

    /** 进程内兜底限流（仅当前 PHP 进程有效，FPM 下按 worker 隔离） */
    private static function throttleInMemory(string $signature): bool
    {
        $now = microtime(true);
        if (isset(self::$burstSeen[$signature])
            && ($now - self::$burstSeen[$signature]) < 60) {
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
     * 强类型配置（logging.db.* + handler_with.*），懒加载并缓存。
     */
    private function config(): LoggingConfig
    {
        return $this->config ??= LoggingConfig::fromConfig();
    }
}
