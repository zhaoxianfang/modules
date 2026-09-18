<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Writer;

use zxf\Modules\Logging\Contracts\LogWriter;
use zxf\Modules\Logging\Jobs\WriteLogJob;

/**
 * 基于队列的日志写入器（可选）。
 *
 * 当 logging.db.queue = true 时启用：把落库动作投递到队列，
 * 由 WriteLogJob 在后台 worker 中真正写入数据库，从而将日志写入的
 * 数据库 I/O 从请求主链路剥离，显著提升高并发接口吞吐。
 *
 * 若未配置真实队列驱动（Laravel 默认 sync 驱动），行为退化为「立即写入」，
 * 与 DatabaseLogWriter 等价，绝不破坏既有行为。
 *
 * @package zxf\Modules\Logging
 */
class QueuedDatabaseLogWriter implements LogWriter
{
    public function __construct(
        protected ?string $model = null,
        protected ?string $table = null,
    ) {
    }

    public function write(array $attributes): void
    {
        WriteLogJob::dispatch($attributes, $this->model, $this->table);
    }
}
