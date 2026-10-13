<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Writer;

use zxf\Modules\Logging\Contracts\LogWriter;
use zxf\Modules\Logging\Events\LogRecorded;
use zxf\Modules\Logging\Models\Log;

/**
 * 基于 Eloquent 的默认日志写入器（同步落库）。
 * 负责把 DatabaseLogHandler 归一化后的属性数组真正落库到 logs 表，
 * 落库成功后派发 LogRecorded 事件。模型与表名可由 handler 配置（model / table）覆盖，
 * 便于分表或自定义模型。
 */
class DatabaseLogWriter implements LogWriter
{
    public function __construct(
        protected ?string $model = null,
        protected ?string $table = null,
    ) {}

    public function write(array $attributes): void
    {
        $modelClass = $this->model ?: Log::class;

        /** @var Log $log */
        $log = new $modelClass;
        if ($this->table !== null) {
            $log->setTable($this->table);
        }

        $log->fill($attributes)->save();

        // 派发「已落库」事件，供下游（告警 / 同步 / 审计）解耦扩展。
        if (function_exists('event')) {
            event(new LogRecorded($attributes, $log));
        }
    }
}
