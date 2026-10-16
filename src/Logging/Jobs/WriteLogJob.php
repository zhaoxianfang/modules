<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;
use zxf\Modules\Logging\Events\LogRecorded;
use zxf\Modules\Logging\LogContext;
use zxf\Modules\Logging\Writer\LogRecordFactory;
use zxf\Modules\Support\ModuleContext;

/**
 * 真正执行日志落库的队列任务。
 * 由 QueuedDatabaseLogWriter 投递；在 worker 进程中创建 Log 并 save，
 * 随后派发 LogRecorded 事件（与同步路径保持一致的事件语义）。
 *
 * 关键实现细节：
 * - 与同步路径共用 LogRecordFactory，保证两条落库链路的行为完全一致（列过滤 / forceFill）；
 * - handle() 开头重置 LogContext：队列 worker 是长驻进程，若不重置，
 *   同一 worker 内前一个 job 残留的请求上下文会被注入到后续日志（数据串味）；
 * - 同时重置 ModuleContext 的请求级静态缓存：模块名与模块配置都是请求作用域的，
 *   而 worker 不走 Application::terminate()，$app->terminating() 的清理永不触发，
 *   不重置会导致 A 模块的日志被打上 B 模块的标签；
 * - tryOnQueue / $this->job 均为可选依赖，使用可空类型避免在不同 Laravel 版本下签名不兼容。
 */
class WriteLogJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * 落库失败重试次数与退避间隔（秒）。
     * 短退避可覆盖「数据库连接瞬断」「迁移锁」等常见短暂故障；
     * 仍失败则交由 failed() 兜底，不会无限占用 worker。
     */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [1, 5, 15];

    /** 单次执行超时（秒），避免 worker 被一条慢日志挂住 */
    public int $timeout = 15;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public array $attributes,
        public ?string $model = null,
        public ?string $table = null,
    ) {}

    public function handle(): void
    {
        // 长驻 worker 进程必须清理请求级上下文，避免跨 job 串味
        LogContext::reset();
        LogContext::setStart(microtime(true));
        ModuleContext::flushRequestScopedCache();

        $log = LogRecordFactory::make($this->attributes, $this->model, $this->table);
        if ($log === null) {
            return;
        }

        $log->save();

        try {
            event(new LogRecorded($this->attributes, $log));
        } catch (Throwable) {
            // 监听器异常不得影响已成功的落库
        }
    }

    /**
     * 队列任务失败时的兜底：把日志内容回退到默认通道，避免异步落库失败导致日志彻底丢失。
     */
    public function failed(?Throwable $exception = null): void
    {
        try {
            logger()->channel('stack')->error('[database-log] 队列落库失败', [
                'message' => (string) ($this->attributes['message'] ?? ''),
                'level' => (string) ($this->attributes['level'] ?? ''),
                'exception' => $exception?->getMessage(),
            ]);
        } catch (Throwable) {
            // 彻底放弃，绝不影响 worker
        }
    }
}
