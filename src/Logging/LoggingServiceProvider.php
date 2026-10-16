<?php

declare(strict_types=1);

namespace zxf\Modules\Logging;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Throwable;
use zxf\Modules\Logging\Config\LoggingConfig;
use zxf\Modules\Logging\Contracts\LogWriter;
use zxf\Modules\Logging\Contracts\RedactorContract;
use zxf\Modules\Logging\Listener\FlushRequestLog;
use zxf\Modules\Logging\Middleware\LogContextMiddleware;
use zxf\Modules\Logging\Support\Redactor;
use zxf\Modules\Logging\Writer\DatabaseLogWriter;
use zxf\Modules\Logging\Writer\QueuedDatabaseLogWriter;

/**
 * 数据库日志子系统服务提供者。
 *
 * 职责（本模块只负责「采集 + 落库」，刻意不提供任何后台管理界面）：
 * 1. 通过 mergeConfigFrom 把 database 日志通道合并进应用的 config/logging.php，
 *    使得在 .env 设置 LOG_CHANNEL=database 即可启用数据库日志（无需先 vendor:publish 配置）。
 * 2. 通过 loadMigrationsFrom 注册迁移，使得直接 `php artisan migrate` 即可创建 logs 表，
 *    无需先执行 `php artisan vendor:publish --tag=modules-logging`（同时保留 publishes 以兼容旧用法）。
 * 3. 运行时若 logs 表不存在（迁移未发布 / 未执行 migrate），数据库日志处理器会自动跳过，
 *    不影响主业务流程（详见 DatabaseLogHandler::write 的表存在性守卫）。
 * 4. 注册请求上下文中间件（默认挂载到 web / api 组，另注册别名 logging.context），
 *    负责采集上下文并为每个 HTTP 请求写入唯一一条请求日志。
 * 5. 注册 RequestHandled 兜底监听：当中间件因异常未能写出请求日志时补写一次
 *    （幂等守卫保证绝不重复写两条）。
 * 6. 监听日志事件，使Laravel 的 report($e) 等携带异常的日志也能落入 database 通道
 *    （被 datatable 检索），且避免重复写入。
 * 7. 绑定核心抽象（RedactorContract / LogWriter），LogWriter 可按配置切换为队列异步写入。
 *
 * 说明：日志的查看 / 检索 / 删除等管理功能不在本模块职责范围内，
 * 需要检索时直接使用 Log 模型或接入外部日志平台即可。
 */
class LoggingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 将 database 日志通道与扩展配置合并进 config('logging')
        // 注意：本服务提供者位于 src/Logging/，配置实际在包根 config/，需上溯两级。
        $channelConfig = require __DIR__.'/../../config/logging.php';

        $this->mergeConfigFrom(__DIR__.'/../../config/logging.php', 'logging');

        // Laravel 的 mergeConfigFrom 实现是：
        //             && $this->app->configurationIsCached())) { ... }
        // 即**配置被缓存时整段跳过**（源码见 Support\ServiceProvider:163）。
        //
        // 后果链条（可证实）：
        //   应用执行过 php artisan config:cache 且未 publish logging 配置
        //     → mergeConfigFrom 什么也没做
        //     → 下面 config('logging.db.*') 全部拿到硬编码兜底值
        //     → sensitive_keys 为 []（脱敏静默失效，敏感信息明文入库）
        //       queue 为 false（本该异步的写入退化为同步，拖慢请求）
        //     → 且**没有任何报错或日志**，排查成本极高。
        //
        // 修复：mergeConfigFrom 之后主动做一次「缺失键补齐」，
        // 用 Config::set 显式写入，使包默认值不依赖配置是否被缓存。
        // 应用侧已显式声明的键一律保留（不覆盖用户配置）。
        $this->ensureDbConfigPresent($channelConfig);

        $this->mergeDatabaseChannel($channelConfig);

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

    /**
     * 补齐缺失的 logging.db 配置键（对 config:cache 免疫）
     *
     * mergeConfigFrom 只做顶层 array_merge，且配置缓存时完全不执行；
     * 这里改用「递归缺失补齐」：只有**当前配置里没有**的键才写入包默认值，
     * 应用侧显式声明过的值一律不动。
     *
     * @param  array<string, mixed>  $channelConfig  包内 logging 配置
     */
    protected function ensureDbConfigPresent(array $channelConfig): void
    {
        $package = $channelConfig['db'] ?? null;

        if (! is_array($package)) {
            return;
        }

        /** @var Repository $config */
        $config = $this->app->make('config');

        $config->set('logging.db', $this->fillMissingKeys($config->get('logging.db', []), $package));
    }

    /**
     * 递归补齐缺失键（不覆盖已存在的键）
     *
     * @param  array<string, mixed>  $current  当前配置
     * @param  array<string, mixed>  $defaults  包内默认值
     * @return array<string, mixed>
     */
    protected function fillMissingKeys(array $current, array $defaults): array
    {
        foreach ($defaults as $key => $value) {
            if (! array_key_exists($key, $current)) {
                $current[$key] = $value;

                continue;
            }

            // 两边都是数组时继续下钻，保证嵌套层级（如 logging.db.queue.*）同样被补齐
            if (is_array($value) && is_array($current[$key])) {
                $current[$key] = $this->fillMissingKeys($current[$key], $value);
            }
        }

        return $current;
    }

    public function boot(): void
    {
        // 幂等保护：本服务提供者可能通过 composer 自动发现 + 应用 providers 双重注册，
        // 用容器标记确保 boot 逻辑只执行一次（避免中间件重复入组、事件监听重复注册导致双写）。
        if ($this->app->bound('logging.booted')) {
            return;
        }
        $this->app->instance('logging.booted', true);

        // 注册迁移路径：无需先 vendor:publish 即可直接 `php artisan migrate` 创建 logs 表。
        // publishes() 仍保留，以兼容希望将迁移发布到应用 migrations 目录的开发者；
        // 由于迁移文件名相同，Laravel 在 migrate 时会按名称去重，不会产生重复执行。
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        // 发布迁移文件（仅 CLI）
        //
        // 只能登记**真实存在**的迁移文件：publishes() 登记的源路径若不存在，
        // `vendor:publish --tag=modules-logging` 会直接失败，
        // 因此这里只登记本目录里实际存在的那些。
        if ($this->app->runningInConsole()) {
            $publishable = [];
            foreach ((array) glob(__DIR__.'/Database/Migrations/*.php') as $file) {
                $publishable[(string) $file] = database_path('migrations/'.basename((string) $file));
            }

            if ($publishable !== []) {
                $this->publishes($publishable, 'modules-logging');
            }
        }

        if (PHP_SAPI === 'cli' || ! $this->app->bound('router')) {
            return;
        }

        $this->registerMiddleware();
        $this->registerFallbackFlush();
        $this->forwardExceptionLogs();
    }

    /**
     * 深合并 database 通道配置。
     *
     * Laravel 的 mergeConfigFrom 只做【顶层】 array_merge，
     * 因此应用自身的 config/logging.php 只要存在 channels 键，就会把包内 channels 整体覆盖，
     * 导致 database 通道消失（在配置被缓存时 mergeConfigFrom 甚至完全不执行）。
     * 这里对 channels.database 做一次「递归合并」：
     * 应用侧显式声明的值优先，未声明的键（handler / tap / handler_with / level …）沿用包内默认值，
     * 从而既保证通道一定存在，又允许应用逐项覆盖。
     *
     * @param  array<string, mixed>  $channelConfig  包内 logging 配置
     */
    protected function mergeDatabaseChannel(array $channelConfig): void
    {
        $package = $channelConfig['channels']['database'] ?? null;
        if (! is_array($package)) {
            return;
        }

        /** @var Repository $config */
        $config = $this->app['config'];
        $current = $config->get('logging.channels.database');

        if ($current === null) {
            $config->set('logging.channels.database', $package);

            return;
        }

        if (! is_array($current)) {
            return;
        }

        $config->set('logging.channels.database', self::deepMerge($package, $current));
    }

    /**
     * 递归合并两个配置数组：$override 中的值优先，缺失的键沿用 $defaults。
     *
     * @param  array<string, mixed>  $defaults  默认值（包内提供）
     * @param  array<string, mixed>  $override  覆盖值（应用提供）
     * @return array<string, mixed>
     */
    protected static function deepMerge(array $defaults, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value)
                && isset($defaults[$key])
                && is_array($defaults[$key])
                && ! array_is_list($value)
            ) {
                $defaults[$key] = self::deepMerge($defaults[$key], $value);

                continue;
            }
            $defaults[$key] = $value;
        }

        return $defaults;
    }

    /**
     * 注册请求上下文中间件。
     *
     * - 注册别名 logging.context：便于在自定义路由组或单条路由上手动挂载；
     * - 按 logging.db.middleware_groups（默认 web / api）逐组追加，逐组 try/catch，
     *   避免应用未定义某个组时抛 InvalidArgumentException。
     */
    protected function registerMiddleware(): void
    {
        try {
            /** @var Router $router */
            $router = $this->app['router'];
            $router->aliasMiddleware('logging.context', LogContextMiddleware::class);
        } catch (Throwable) {
            // 路由组件不可用时跳过（如精简安装）
        }

        $groups = LoggingConfig::fromConfig()->middlewareGroups;
        if ($groups === []) {
            return;
        }

        try {
            /** @var Router $router */
            $router = $this->app['router'];
            foreach ($groups as $group) {
                try {
                    $router->pushMiddlewareToGroup($group, LogContextMiddleware::class);
                } catch (Throwable) {
                    // 该中间件组未定义：忽略，不影响其它组
                }
            }
        } catch (Throwable) {
            // 路由组件不可用时跳过
        }
    }

    /**
     * 注册 RequestHandled 兜底监听。
     *
     * 正常路径下由 LogContextMiddleware 在请求结束时写出请求日志；
     * 但若中间件在采集响应时发生异常、或宿主应用未把它挂到当前路由组，
     * 就会出现「有请求上下文却没落库」的情况。此监听在 HTTP 内核完成请求后补写一次；
     * LogContext::claimRequestLog() 保证两条路径最多只写一条，绝不会重复。
     */
    protected function registerFallbackFlush(): void
    {
        try {
            $this->app['events']->listen(RequestHandled::class, FlushRequestLog::class);
        } catch (Throwable) {
            // 事件系统不可用时跳过
        }
    }

    /**
     * 让 Laravel 原生的 report($e)（以及任何携带异常的日志）也能落入 database 通道，
     * 从而被后台 datatable 检索到。若 database 通道未配置则静默跳过。
     */
    protected function forwardExceptionLogs(): void
    {
        $this->app['events']->listen(MessageLogged::class, function (MessageLogged $event): void {
            // 仅当日志携带异常时，才需要额外转发到 database 通道（让 report($e) 等异常日志可被检索）。
            // 注意：本版本 Illuminate\Log\Events\MessageLogged 仅有 level / message / context 三属性，
            // 没有 channel 字段，切勿访问 $event->channel（会触发 Undefined property 警告）。
            if (! isset($event->context['exception']) || ! ($event->context['exception'] instanceof Throwable)) {
                return;
            }

            // 重入守护：转发到 database 通道会再次派发 MessageLogged 事件。本版本事件无 channel 字段，
            // 无法据来源判断，故用静态标志位阻断递归；否则在默认通道既非 database、也非含 database 的
            // stack 时，会无限递归直至栈溢出。
            static $forwarding = false;
            if ($forwarding) {
                return;
            }

            // 默认通道已是 database（LOG_CHANNEL=database），或默认 stack 已包含 database 时，
            // report($e) 已直接落库，无需重复写入。
            $default = $this->app['config']->get('logging.default');
            $stackChannels = $this->app['config']->get('logging.channels.stack.channels', []);
            $stackChannels = is_array($stackChannels) ? $stackChannels : [];
            if ($default === 'database'
                || ($default === 'stack' && in_array('database', $stackChannels, true))) {
                return;
            }

            // 以原始等级转发到 database 通道（保留 error / critical 等等级信息）。
            // level 统一转字符串，兼容各 Laravel 版本（字符串或枚举）的表示；对象且无 __toString 时
            // 兜底为 'error'，避免 (string) 强转抛 TypeError 影响主流程。
            $level = $event->level;
            if (is_object($level)) {
                $level = method_exists($level, '__toString') ? (string) $level : 'error';
            }
            $forwarding = true;
            try {
                $this->app['log']->channel('database')->{$level}($event->message, $event->context);
            } catch (Throwable) {
                // 忽略（不影响主流程）
            } finally {
                $forwarding = false;
            }
        });
    }
}
