<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Writer;

use zxf\Modules\Logging\Contracts\LogWriter;
use zxf\Modules\Logging\Jobs\WriteLogJob;

/**
 * 基于队列的日志写入器（可选）。
 * 当 logging.db.queue = true 时启用：把落库动作投递到队列，
 * 由 WriteLogJob 在后台 worker 中真正写入数据库，从而将日志写入的
 * 数据库 I/O 从请求主链路剥离，显著提升高并发接口吞吐。
 * 若未配置真实队列驱动（Laravel 默认 sync 驱动），行为退化为「立即写入」，
 * 与 DatabaseLogWriter 等价，绝不破坏既有行为。
 */
class QueuedDatabaseLogWriter implements LogWriter
{
    /**
     * 队列 payload 体积上限（字节）。
     * 数据库驱动（尤其 MySQL）单行有 max_allowed_packet 限制，
     * 超大 payload 会让 driver 直接抛「packet too large」，
     * 堆在队列里反复重试也只会一直失败。
     * 超限时保留键名结构、裁剪超长标量值，保证日志仍可入队可排查。
     */
    public const MAX_PAYLOAD_BYTES = 200000;

    /** 单个字符串/数组元素裁剪后的最大字节数 */
    public const MAX_SCALAR_BYTES = 8000;

    public function __construct(
        // 这两个值构造后不再被修改（write() 只读取），
        // 标记为 readonly 让「不可变」成为编译器可校验的约束，
        // 避免后续维护者不小心在 write() 里改写而引入隐蔽的状态问题。
        protected readonly ?string $model = null,
        protected readonly ?string $table = null,
    ) {}

    public function write(array $attributes): void
    {
        WriteLogJob::dispatch($this->fitPayload($attributes), $this->model, $this->table);
    }

    /**
     * 裁剪 payload，使其不超过 {@see self::MAX_PAYLOAD_BYTES}。
     *
     * 裁剪顺序：先逐个标量截断到 MAX_SCALAR_BYTES（保留可读前缀），
     * 若仍超限再整条丢弃超大字段（优先丢 trace / context / extra 这类
     * 非关键列，关键列 message / level / module_name 永远保留）。
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function fitPayload(array $attributes): array
    {
        $fitted = [];

        foreach ($attributes as $key => $value) {
            $fitted[$key] = $this->clipScalar($value);
        }

        if ($this->payloadBytes($fitted) <= self::MAX_PAYLOAD_BYTES) {
            return $fitted;
        }

        // 仍超限：按「体积从大到小」丢弃非关键字段，逐轮收敛
        $droppable = ['trace', 'context', 'extra', 'request_headers', 'request_input', 'response_content'];

        foreach ($droppable as $key) {
            if (! array_key_exists($key, $fitted)) {
                continue;
            }

            unset($fitted[$key]);

            if ($this->payloadBytes($fitted) <= self::MAX_PAYLOAD_BYTES) {
                break;
            }
        }

        if ($this->payloadBytes($fitted) > self::MAX_PAYLOAD_BYTES) {
            $fitted['message'] = $this->clipToBytes(
                (string) ($fitted['message'] ?? ''),
                self::MAX_PAYLOAD_BYTES
            );
        }

        return $fitted;
    }

    /** 标量截断（数组递归，其余原样） */
    protected function clipScalar(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->clipScalar($item), $value);
        }

        if (is_string($value)) {
            return $this->clipToBytes($value, self::MAX_SCALAR_BYTES);
        }

        return $value;
    }

    protected function clipToBytes(string $value, int $limit): string
    {
        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit).'…[truncated]';
    }

    /** @param  array<string, mixed>  $attributes */
    protected function payloadBytes(array $attributes): int
    {
        return strlen((string) json_encode($attributes, JSON_UNESCAPED_UNICODE));
    }
}
