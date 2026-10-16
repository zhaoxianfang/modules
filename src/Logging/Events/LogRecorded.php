<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * 一条日志记录成功落库后派发的事件。
 * 用于解耦下游能力（告警 / 指标上报 / 外部同步 / 审计触发等），默认无任何监听器。
 * 业务系统可监听此事件实现「日志入库即触发通知」等高级流程，而无需改动落库核心逻辑。
 * 同步写入（DatabaseLogWriter）与异步写入（WriteLogJob）都会派发本事件，保证事件语义一致。
 *
 * 说明：$model 声明为 Model 而非内置 Log，以便业务方通过 logging.channels.database
 * 的 handler_with.model 指定自定义日志模型（或按月分表的自定义模型）时仍能正常派发。
 */
final class LogRecorded
{
    /**
     * @param  array<string, mixed>  $attributes  已落库、与 logs 表字段一一对应的属性数组
     * @param  Model|null  $model  已落库的模型实例（同步与异步写入均可用）
     */
    public function __construct(
        public readonly array $attributes,
        public readonly ?Model $model = null,
    ) {}
}
