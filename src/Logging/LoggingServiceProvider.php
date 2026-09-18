<?php

declare(strict_types=1);

namespace zxf\Modules\Logging;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use zxf\Modules\Logging\Console\Commands\CleanLogsCommand;
use zxf\Modules\Logging\Http\Controllers\LogController;
use zxf\Modules\Logging\Middleware\LogContextMiddleware;

/**
 * 数据库日志子系统服务提供者。
 *
 * 职责：
 * 1. 通过 mergeConfigFrom 把 database 日志通道合并进应用的 config/logging.php，
 *    使得在 .env 设置 LOG_CHANNEL=database 即可启用数据库日志。
 * 2. 发布迁移文件（php artisan vendor:publish --tag=modules-logging）。
 * 3. 注册 logs:clean 命令。
 * 4. 注册请求 / 响应上下文中间件（web / api 组，仅 HTTP 模式）。
 * 5. 按需注册日志查看器路由与视图（默认开启，可在 config 关闭）。
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
    }

    public function boot(): void
    {
        // 发布迁移文件
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/Database/Migrations/2026_09_18_000001_create_system_logs_table.php'
                    => database_path('migrations/2026_09_18_000001_create_system_logs_table.php'),
            ], 'modules-logging');

            $this->commands([CleanLogsCommand::class]);
        }

        // 注册请求 / 响应上下文中间件（仅 HTTP 模式）
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

        // 可选的日志查看器
        if (config('logging.db.viewer_enabled', true)) {
            $this->registerViewer();
        }
    }

    /**
     * 注册日志查看器（列表 / 详情 / 删除 / 清理）。
     */
    protected function registerViewer(): void
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'modules-logging');

        $prefix = config('logging.db.viewer_prefix', 'logs');
        $middleware = config('logging.db.viewer_middleware', ['web']);

        $this->app['router']->group([
            'prefix' => $prefix,
            'as' => 'logs.',
            'middleware' => $middleware,
        ], function () {
            $router = $this->app['router'];
            $router->get('/', [LogController::class, 'index'])->name('index');
            $router->get('/{id}', [LogController::class, 'show'])->name('show')->where('id', '[0-9]+');
            $router->delete('/{id}', [LogController::class, 'destroy'])->name('destroy')->where('id', '[0-9]+');
            $router->post('/clean', [LogController::class, 'clean'])->name('clean');
        });
    }
}
