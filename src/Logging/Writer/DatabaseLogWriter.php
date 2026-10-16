<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Writer;

use zxf\Modules\Logging\Contracts\LogWriter;
use zxf\Modules\Logging\Events\LogRecorded;

/**
 * 基于 Eloquent 的默认日志写入器（同步落库）。
 *
 * 负责把 DatabaseLogHandler 归一化后的属性数组真正落库到 logs 表，
 * 落库成功后派发 LogRecorded 事件。模型与表名可由 handler 配置（model / table）覆盖，
 * 便于分表或自定义模型。
 *
 * 关键实现细节：
 * - 通过 LogRecordFactory 构造模型：按真实表列过滤属性（新增列未升级时自动跳过其余字段，
 *   不会整条日志丢失），并用 forceFill 规避自定义模型 $fillable 限制；
 * - 事件派发包裹在 try/catch 中：监听器抛错不应把「已落库」变成「落库失败」。
 */
class DatabaseLogWriter implements LogWriter
{
    public function __construct(
        // 这两个值构造后不再被修改（write() 只读取），
        // 标记为 readonly 让「不可变」成为编译器可校验的约束，
        // 避免后续维护者不小心在 write() 里改写而引入隐蔽的状态问题。
        protected readonly ?string $model = null,
        protected readonly ?string $table = null,
    ) {}

    public function write(array $attributes): void
    {
        $log = LogRecordFactory::make($attributes, $this->model, $this->table);
        if ($log === null) {
            return;
        }

        $log->save();

        // 派发「已落库」事件，供下游（告警 / 同步 / 审计）解耦扩展。
        try {
            event(new LogRecorded($attributes, $log));
        } catch (\Throwable) {
            // 监听器异常不得影响已成功的落库
        }
    }
}
