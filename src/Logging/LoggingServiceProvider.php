<?php

declare(strict_types=1);

namespace zxf\Modules\Logging;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Throwable;
use zxf\Modules\Logging\Contracts\LogWriter;
use zxf\Modules\Logging\Contracts\RedactorContract;
use zxf\Modules\Logging\Middleware\LogContextMiddleware;
use zxf\Modules\Logging\Support\Redactor;
use zxf\Modules\Logging\Writer\DatabaseLogWriter;
use zxf\Modules\Logging\Writer\QueuedDatabaseLogWriter;

/**
 * 数据库日志子系统服务提供者。
 *
 * 职责（本模块只负责「采集 + 落库」，刻意不提供任何后台管理界面）：
 * 1. 通过 mergeConfigFrom 把 database 日志通道合并进应用的 config/logging.php，
 *    使得在 .env 设置 LOG_CHANNEL=database 即可启用数据库日志。
 * 2. 发布迁移文件（php artisan vendor:publish --tag=modules-logging），创建 logs 表。
 * 3. 注册请求 / 响应上下文中间件（web / api 组，仅 HTTP 模式），用于自动采集上下文。
 * 4. 监听日志事件，使 Laravel 的 report($e) 等携带异常的日志也能落入 database 通道
 *    （被 datatable 检索），且避免重复写入。
 * 5. 绑定核心抽象（RedactorContract / LogWriter），LogWriter 可按配置切换为队列异步写入。
 *
 * 说明：日志的查看 / 检索 / 删除等管理功能不在本模块职责范围内（已由控制器 / 视图中移除），
 * 需要检索时直接使用 Log 模型或接入外部日志平台即可。
 *
 * @package zxf\Modules\Logging
 */
class LoggingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 将 database 日志通道与扩展配置合并进 config('logging')
        $this->mergeConfigFrom(__DIR__.'/../config/logging.php', 'logging');

        // 当配置被缓存（configuration:cache）时 mergeConfigFrom 不会生效，
        // 这里保证 database 通道一定存在，使 LOG_CHANNEL=database 随时可用。
        $channelConfig = require __DIR__.'/../config/logging.php';
        $database = $channelConfig['channels']['database'] ?? null;
        if ($database !== null && ! $this->app['config']->has('logging.channels.database')) {
            $this->app['config']->set('logging.channels.database', $database);
        }

        // 绑定脱敏器（契约 + 具体实现：配置只读取一次，handler 与 middleware 共享同一实例）
        $this->app->singleton(RedactorContract::class, function () {
            $keys = $this->app['config']->get('logging.db.sensitive_keys', []);
            $maxFieldLength = (int) $this->app['config']->get('logging.db.max_field_length', 20000);
            return new Redactor(is_array($keys) ? $keys : [], $maxFieldLength);
        });

        // 绑定落库写入器：默认同步 Eloquent 实现；开启 logging.db.queue 时切换为队列异步写入，
        // handler 自动受益，无需任何改动。
        $writer = $this->app['config']->get('logging.db.queue', false)
            ? QueuedDatabaseLogWriter::class
            : DatabaseLogWriter::class;
        $this->app->bind(LogWriter::class, $writer);
    }

    public function boot(): void
    {
        // 幂等保护：本服务提供者可能通过 composer 自动发现 + 应用 providers 双重注册，
        // 用容器标记确保 boot 逻辑只执行一次（避免中间件重复入组、事件监听重复注册导致双写）。
        if ($this->app->bound('logging.booted')) {
            return;
        }
        $this->app->instance('logging.booted', true);

        // 发布迁移文件（仅 CLI）
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/Database/Migrations/2026_09_18_000001_create_logs_table.php'
                    => database_path('migrations/2026_09_18_000001_create_logs_table.php'),
            ], 'modules-logging');
        }

        // 注册请求 / 响应上下文中间件（仅 HTTP 模式）：自动采集上下文并落库。
        // 注意：本模块不注册任何日志查看 / 管理路由。
        if (PHP_SAPI !== 'cli' && $this->app->bound('router')) {
            try {
                /** @var Router $router */
                $router = $this->app['router'];
                $router->pushMiddlewareToGroup('web', LogContextMiddleware::class);
                $router->pushMiddlewareToGroup('api', LogContextMiddleware::class);
            } catch (\Throwable) {
                // 忽略（如路由组不存在）
            }
        }

        // 让 Laravel 原生的 report($e)（以及任何携带异常的日志）也能落入 database 通道，
        // 从而被后台 datatable 检索到。若 database 通道未配置则静默跳过。
        $this->app['events']->listen(MessageLogged::class, function (MessageLogged $event): void {
            // 仅当日志携带异常、且来源通道不是 database 时，额外写入 database 通道，避免重复。
            if (! isset($event->context['exception']) || ($event->channel ?? null) === 'database') {
                return;
            }

            // 默认通道已是 database（LOG_CHANNEL=database），或默认 stack 已包含 database 时，
            // report($e) 已直接落库，无需重复写入。
            $default = $this->app['config']->get('logging.default');
            if ($default === 'database'
                || ($default === 'stack'
                    && in_array('database', $this->app['config']->get('logging.channels.stack.channels', []), true))) {
                return;
            }

            // 以原始等级转发到 database 通道（保留 error / critical 等等级信息）
            try {
                $level = $event->level;
                $this->app['log']->channel('database')->{$level}($event->message, $event->context);
            } catch (Throwable) {
                // 忽略（不影响主流程）
            }
        });
    }
}
