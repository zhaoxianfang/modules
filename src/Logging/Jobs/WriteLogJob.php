<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use zxf\Modules\Logging\Events\LogRecorded;
use zxf\Modules\Logging\Models\Log;

/**
 * 真正执行日志落库的队列任务。
 *
 * 由 QueuedDatabaseLogWriter 投递；在 worker 进程中创建 Log 并 save，
 * 随后派发 LogRecorded 事件（与同步路径保持一致的事件语义）。
 *
 * @package zxf\Modules\Logging
 */
class WriteLogJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public array $attributes,
        public ?string $model = null,
        public ?string $table = null,
    ) {
    }

    public function handle(): void
    {
        $modelClass = $this->model ?: Log::class;

        /** @var Log $log */
        $log = new $modelClass();
        if ($this->table !== null) {
            $log->setTable($this->table);
        }

        $log->fill($this->attributes)->save();

        if (function_exists('event')) {
            event(new LogRecorded($this->attributes, $log));
        }
    }
}
