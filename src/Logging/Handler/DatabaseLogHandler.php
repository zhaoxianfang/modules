<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Handler;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;
use zxf\Modules\Logging\Models\SystemLog;

/**
 * 数据库日志处理器（Monolog 3 兼容，Laravel 11 / 12 / 13 通用）。
 *
 * 设计要点（继承自 wsf Modules\Logs 的审计经验，并针对 Laravel 13 增强）：
 * - LogRecord 的 level 是 Monolog\Level 枚举；取等级名用 $record->level->name 或
 *   $record->level_name（大写字符串）。切勿对 level 重新赋值（会抛 LogicException）。
 * - 仅 extra / formatted 可写；context / message 等为 readonly，不可重新赋值。
 * - 单次请求内精确去重：相同 {level, message, exception} 只落库一次，避免异常刷屏。
 * - 对敏感字段（password / token / secret / authorization 等）强制脱敏。
 * - context 中可能含有 Throwable 实例（不可 JSON 序列化），写入前必须剔除 / 转换。
 * - 任何写入异常都被吞掉，绝不影响主业务流程。
 *
 * @package zxf\Modules\Logging
 */
class DatabaseLogHandler extends AbstractProcessingHandler
{
    /** 单次请求内去重集合（静态，避免同一次请求里异常重复刷屏） */
    protected static ?array $seen = null;

    public function __construct(
        protected ?string $model = null,
        protected ?string $table = null,
        protected bool $captureRequestBody = true,
        protected bool $captureResponseBody = true,
        protected int $maxBodyLength = 20000,
        protected ?int $retentionDays = 30,
        Level $level = Level::Debug,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        // CLI 模式不写库：无请求上下文，价值低（如需 CLI 日志落库，可移除该判断）
        if (PHP_SAPI === 'cli') {
            return;
        }

        // 单次请求内精确去重
        $key = $record->level->name.'|'.$record->message;
        if (isset($record->context['exception']) && $record->context['exception'] instanceof Throwable) {
            $key .= '|'.get_class($record->context['exception']);
        }
        if (self::$seen === null) {
            self::$seen = [];
        }
        if (isset(self::$seen[$key])) {
            return;
        }
        self::$seen[$key] = true;

        $modelClass = $this->model ?: SystemLog::class;

        try {
            /** @var SystemLog $log */
            $log = new $modelClass();
            if ($this->table !== null) {
                $log->setTable($this->table);
            }

            $extra = $record->extra;
            $trace = $this->trace($record);

            $log->fill([
                'level' => $record->level->name,
                'level_name' => $record->level_name,
                'channel' => $record->channel,
                'message' => mb_substr($record->message, 0, 65535),
                'context' => $this->sanitizeContext($record->context),
                'extra' => $this->sanitizeContext($extra),

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
                'response_duration_ms' => $extra['response_duration_ms'] ?? null,
                'response_memory_mb' => $extra['response_memory_mb'] ?? null,
                'response_headers' => ($this->captureResponseBody ? ($extra['response_headers'] ?? null) : null),
                'response_body' => ($this->captureResponseBody ? ($extra['response_body'] ?? null) : null),

                'exception_class' => $trace['class'] ?? null,
                'exception_message' => $trace['message'] ?? null,
                'file' => $trace['file'] ?? null,
                'trace' => $trace['trace'] ?? null,
            ]);
            $log->save();
        } catch (Throwable) {
            // 日志写入失败绝不能反向污染主流程
            return;
        }
    }

    /**
     * 脱敏 context / extra 中的敏感字段，并剔除不可 JSON 序列化的值（如 Throwable）。
     */
    protected function sanitizeContext(array $context): array
    {
        unset($context['__summary__']);

        $sensitive = [
            'password', 'password_confirmation', 'new_password', 'token', 'secret',
            'authorization', 'api_key', 'apikey', 'cookie', 'passwd', 'csrf_token', '_token',
        ];

        $walk = function (&$value, $key) use ($sensitive, &$walk) {
            if (is_array($value)) {
                foreach ($value as $k => &$v) {
                    $walk($v, $k);
                }

                return;
            }
            if ($value instanceof Throwable) {
                // Throwable 不可序列化，转为可读字符串
                $value = sprintf('[%s] %s', get_class($value), $value->getMessage());

                return;
            }
            if (is_object($value)) {
                $value = sprintf('[object] %s', get_class($value));

                return;
            }
            if (is_string($key) && in_array(strtolower($key), $sensitive, true)) {
                $value = '***REDACTED***';
            }
        };
        $walk($context, '');

        return $context;
    }

    /**
     * 从 context.exception 提取异常信息（单独成列，便于检索）。
     */
    protected function trace(LogRecord $record): array
    {
        if (! isset($record->context['exception']) || ! $record->context['exception'] instanceof Throwable) {
            return [];
        }

        $e = $record->context['exception'];

        return [
            'class' => get_class($e),
            'message' => mb_substr($e->getMessage(), 0, 65535),
            'file' => $e->getFile().':'.$e->getLine(),
            'trace' => $e->getTraceAsString(),
        ];
    }
}
