<?php

declare(strict_types=1);

namespace zxf\Modules;

use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use zxf\Modules\BuilderQuery\MacrosBuilder;
use zxf\Modules\Contracts\RepositoryInterface;
use zxf\Modules\Support\ModuleLoader;

/**
 * 模块系统服务提供者
 *
 * 为 Laravel 11+ / 12+ / 13+ 优化的模块化系统服务提供者。
 *
 * 设计原则：
 * - 最小化 Laravel 框架依赖，使用 illuminate/contracts 而非完整框架
 * - 延迟加载非必要服务，不影响 HTTP 请求性能
 * - 自动发现和注册模块组件（路由、视图、中间件、事件、命令等）
 * - 支持模块缓存以提升生产环境加载速度
 * - **环境隔离**：CLI 命令相关功能/文件仅在命令行环境加载，浏览器环境完全跳过
 *
 * Laravel 13 兼容特性：
 * - 兼容 Eloquent Builder 的新查询宏（如向量相似度 whereVectorSimilarTo）
 * - 兼容 withRouting() 回调与属性路由（#[Get]/#[Post]/#[Middleware]）
 * - CSRF 中间件已使用新增的 PreventRequestForgery 命名约定
 * - 兼容 withScheduling() 的延迟注册时机与队列路由等 13.x 新特性
 *
 * @package zxf\Modules
 * @version 5.1.0
 */
class ModulesServiceProvider extends ServiceProvider
{
    /**
     * 注册服务
     */
    public function register(): void
    {
        $this->registerRepository();
        $this->registerModuleLoader();
        $this->mergeModuleConfig();

        // 注册 Eloquent Builder 查询宏（whereHasIn 等）
        if ($this->app->bound('db')) {
            MacrosBuilder::register($this);
        }

        // ------------------------------------------------------------------
        // CLI 环境专用注册（命令行 Artisan 命令、about 信息）
        //
        // 包自带的 module:* 命令与 about 信息不依赖任何模块加载结果，
        // 因此放在 register() 阶段、且仅在命令行（runningInConsole）下注册：
        //   - 比 boot() 更早完成命令注册，避免依赖 boot 生命周期；
        //   - 浏览器（HTTP）请求阶段完全跳过，命令类文件不会被加载；
        //   - 即使 boot() 中模块加载出现非致命异常，module:* 命令依然可用，
        //     从而规避「There are no commands defined in the 'module' namespace」。
        // ------------------------------------------------------------------
        if ($this->app->runningInConsole()) {
            $this->registerPackageCommands();
            $this->registerAboutInfo();
        }
    }

    /**
     * 引导服务
     *
     * 加载策略严格区分「浏览器（HTTP）环境」与「命令行（CLI）环境」：
     *
     *  - HTTP 环境：仅加载处理请求所必需的模块组件
     *    （路由、视图、翻译、配置、迁移、服务提供者等），
     *    完全不触碰 Artisan 命令相关逻辑与文件。
     *
     *  - CLI 环境：在 HTTP 加载的基础上，额外注册扩展包自身的
     *    Artisan 命令、模块内自动发现的命令，以及 about 信息。
     *
     * 这样可确保 src/Commands 下的命令类在 HTTP 请求期间不会被
     * 自动加载/实例化，减少内存占用与自动加载开销。
     *
     * 注意：由于模块的加载必须在服务提供者注册后立刻执行
     * （路由、视图、翻译等都需要在请求处理前完成），
     * 因此本提供者不支持延迟加载，boot() 会立即执行。
     */
    public function boot(): void
    {
        // 发布配置（HTTP / CLI 均需要）
        $this->publishConfig();

        // 加载所有模块（HTTP / CLI 均需要，且必须在 boot 阶段完成，不可延迟）
        $this->loadModules();

        // 注册模块视图命名空间（HTTP 必需，CLI 下无害）
        $this->registerViewNamespace();

        // 仅命令行（CLI）环境才需要注册「模块内自动发现的 Artisan 命令」。
        // 注意：模块命令必须在 loadModules()（模块自动发现）之后注册，
        // 因此放在 boot() 而非 register()；包自带命令已在 register() 注册。
        // 浏览器（HTTP）环境下整体跳过，避免加载 src/Commands 下的命令类。
        if ($this->app->runningInConsole()) {
            $this->registerModuleCommands();
        }
    }

    /**
     * 注册模块仓库
     */
    protected function registerRepository(): void
    {
        $this->app->singleton(RepositoryInterface::class, function (Application $app) {
            // 收集所有扫描路径
            $paths = [config('modules.path', base_path('Modules'))];

            $extraPaths = config('modules.scan_paths', []);
            if (is_array($extraPaths)) {
                $paths = array_merge($paths, $extraPaths);
            }

            // 过滤无效路径
            $paths = array_filter($paths, fn($p) => is_string($p) && $p !== '');

            return new Repository(
                $app['files'],
                $paths,
                config('modules.namespace', 'Modules')
            );
        });

        $this->app->alias(RepositoryInterface::class, 'modules');
    }

    /**
     * 注册模块加载器
     */
    protected function registerModuleLoader(): void
    {
        $this->app->singleton(ModuleLoader::class, function (Application $app) {
            return new ModuleLoader(
                $app->make(RepositoryInterface::class),
                $app
            );
        });
    }

    /**
     * 合并模块配置
     */
    protected function mergeModuleConfig(): void
    {
        $configPath = __DIR__ . '/../config/modules.php';

        if (file_exists($configPath)) {
            $this->mergeConfigFrom($configPath, 'modules');
        }
    }

    /**
     * 发布配置文件
     */
    protected function publishConfig(): void
    {
        $this->publishes([
            __DIR__ . '/../config/modules.php' => config_path('modules.php'),
        ], 'modules-config');

        // 发布 stub 文件
        $this->publishes([
            __DIR__ . '/Commands/stubs' => resource_path('stubs/modules'),
        ], 'modules-stubs');
    }

    /**
     * 注册扩展包自身命令
     */
    protected function registerPackageCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $commands = [
            Commands\ModuleMakeCommand::class,
            Commands\ModuleListCommand::class,
            Commands\ModuleDeleteCommand::class,
            Commands\ModuleCacheCommand::class,
            Commands\ModuleClearCommand::class,
            Commands\ModuleInfoCommand::class,
            Commands\ModuleValidateCommand::class,
            Commands\ModuleDebugCommandsCommand::class,
            Commands\ModuleCheckLangCommand::class,
            Commands\ModulePublishCommand::class,
            Commands\ControllerMakeCommand::class,
            Commands\ModelMakeCommand::class,
            Commands\MigrationMakeCommand::class,
            Commands\RequestMakeCommand::class,
            Commands\SeederMakeCommand::class,
            Commands\ProviderMakeCommand::class,
            Commands\CommandMakeCommand::class,
            Commands\EventMakeCommand::class,
            Commands\ListenerMakeCommand::class,
            Commands\JobMakeCommand::class,
            Commands\MiddlewareMakeCommand::class,
            Commands\RouteMakeCommand::class,
            Commands\ConfigMakeCommand::class,
            Commands\ObserverMakeCommand::class,
            Commands\PolicyMakeCommand::class,
            Commands\RepositoryMakeCommand::class,
            Commands\ResourceMakeCommand::class,
            Commands\TestMakeCommand::class,
            Commands\ViewMakeCommand::class,
            Commands\SeedCommand::class,
            Commands\MigrateCommand::class,
            Commands\MigrateResetCommand::class,
            Commands\MigrateRefreshCommand::class,
            Commands\MigrateFreshCommand::class,
            Commands\MigrateRollbackCommand::class,
            Commands\MigrateStatusCommand::class,
        ];

        $this->commands($commands);
    }

    /**
     * 加载所有模块
     */
    protected function loadModules(): void
    {
        /** @var ModuleLoader $loader */
        $loader = $this->app->make(ModuleLoader::class);
        $loader->loadAll();
    }

    /**
     * 注册模块中的 Artisan 命令
     */
    protected function registerModuleCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $moduleCommands = \zxf\Modules\Support\ModuleAutoDiscovery::getGlobalCommands();

        if (! empty($moduleCommands)) {
            $this->commands($moduleCommands);
        }
    }

    /**
     * 注册视图命名空间
     */
    protected function registerViewNamespace(): void
    {
        $viewPath = __DIR__ . '/../resources/views';

        if (! is_dir($viewPath)) {
            return;
        }

        if (! $this->app->bound('view')) {
            return;
        }

        $this->loadViewsFrom($viewPath, 'modules');

        // 允许用户自定义覆盖视图
        $customViewPath = resource_path('views/vendor/modules');
        if (is_dir($customViewPath)) {
            $this->app['view']->prependNamespace('modules', $customViewPath);
        }
    }

    /**
     * 注册 about 命令信息
     */
    protected function registerAboutInfo(): void
    {
        if (! class_exists('Illuminate\Foundation\Console\AboutCommand')) {
            return;
        }

        try {
            \Illuminate\Foundation\Console\AboutCommand::add('Extend', [
                'zxf/modules' => fn () => InstalledVersions::getPrettyVersion('zxf/modules') ?? 'unknown',
            ]);
        } catch (\Throwable) {
            // 静默失败
        }
    }

    /**
     * 获取服务提供者提供的服务
     *
     * 注意：本提供者因 boot() 阶段需要加载模块路由/视图/翻译等，
     * 不可使用 Laravel 延迟加载机制。provides() 仅作文档用途。
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            RepositoryInterface::class,
            ModuleLoader::class,
            'modules',
        ];
    }
}
