<?php

namespace zxf\Modules;

use Composer\InstalledVersions;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use zxf\Modules\BuilderQuery\MacrosBuilder;
use zxf\Modules\Contracts\RepositoryInterface;
use zxf\Modules\Repository;
use zxf\Modules\Support\CompiledModuleLoader;
use zxf\Modules\Support\ModuleCacheManager;
use zxf\Modules\Support\ModuleLoader;

/**
 * 模块服务提供者
 *
 * 优化点：
 * 1. 移除 Laravel 11+ 中废弃的 $defer 属性
 * 2. 使用按需加载策略减少启动开销
 * 3. 命令注册延迟到控制台模式下执行
 * 4. 添加编译缓存支持
 * 5. 集成 ModuleCacheManager 高性能缓存
 */
class ModulesServiceProvider extends ServiceProvider
{
    /**
     * 注册任何应用程序服务。
     */
    public function register(): void
    {
        $this->registerCacheManager();
        $this->registerCompiledLoader();
        $this->registerRepository();
        $this->registerModuleLoader();
        $this->mergeConfig();

        // 注册 whereHasIn 查询宏（仅在需要时加载）
        MacrosBuilder::register($this);
    }

    /**
     * 引导任何应用程序服务。
     */
    public function boot(): void
    {
        $this->publishConfig();

        // 加载所有模块（核心功能）
        $this->loadModules();

        // 控制台模式下注册命令
        $this->registerCommandsIfConsole();

        // 注册 about 命令信息（仅在控制台模式下）
        $this->registerAboutCommand();
    }

    /**
     * 注册缓存管理器
     */
    protected function registerCacheManager(): void
    {
        $this->app->singleton(ModuleCacheManager::class, function ($app) {
            return new ModuleCacheManager($app);
        });
    }

    /**
     * 注册编译加载器
     */
    protected function registerCompiledLoader(): void
    {
        $this->app->singleton(CompiledModuleLoader::class, function ($app) {
            return new CompiledModuleLoader($app);
        });
    }

    /**
     * 仅在控制台模式下注册命令
     * 避免 HTTP 请求时加载不必要的命令类
     */
    protected function registerCommandsIfConsole(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->registerCommands();
        $this->registerModuleCommands();
    }

    /**
     * 注册 about 命令信息
     */
    protected function registerAboutCommand(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        try {
            AboutCommand::add('Extend', [
                'zxf/modules' => fn () => InstalledVersions::getPrettyVersion('zxf/modules') ?? 'unknown',
            ]);
        } catch (\Throwable) {
            // 静默处理，不影响核心功能
        }
    }

    /**
     * 注册模块仓库
     */
    protected function registerRepository(): void
    {
        $this->app->singleton(RepositoryInterface::class, function ($app) {
            $repository = new Repository($app['files']);
            $repository->setCacheManager($app->make(ModuleCacheManager::class));
            return $repository;
        });

        $this->app->alias(RepositoryInterface::class, 'modules');
    }

    /**
     * 注册模块加载器
     */
    protected function registerModuleLoader(): void
    {
        $this->app->singleton(ModuleLoader::class, function ($app) {
            return new ModuleLoader(
                $app->make(RepositoryInterface::class),
                $app
            );
        });
    }

    /**
     * 合并配置
     */
    protected function mergeConfig(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/modules.php',
            'modules'
        );
    }

    /**
     * 发布配置文件
     */
    protected function publishConfig(): void
    {
        $this->publishes([
            __DIR__ . '/../config/modules.php' => config_path('modules.php'),
        ], 'modules-config');
    }

    /**
     * 注册命令
     */
    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            Commands\ModuleMakeCommand::class,
            Commands\ModuleListCommand::class,
            Commands\ModuleDeleteCommand::class,
            Commands\ModuleInfoCommand::class,
            Commands\ModuleValidateCommand::class,
            Commands\ModuleDebugCommandsCommand::class,
            Commands\ControllerMakeCommand::class,
            Commands\ModelMakeCommand::class,
            Commands\MigrationMakeCommand::class,
            Commands\RequestMakeCommand::class,
            Commands\SeederMakeCommand::class,
            Commands\ProviderMakeCommand::class,
            Commands\CommandMakeCommand::class,
            Commands\EventMakeCommand::class,
            Commands\ListenerMakeCommand::class,
            Commands\MiddlewareMakeCommand::class,
            Commands\RouteMakeCommand::class,
            Commands\ConfigMakeCommand::class,
            Commands\MigrateCommand::class,
            Commands\MigrateResetCommand::class,
            Commands\MigrateRefreshCommand::class,
            Commands\MigrateStatusCommand::class,
            Commands\ModuleCheckLangCommand::class,
        ]);
    }

    /**
     * 加载所有模块
     */
    protected function loadModules(): void
    {
        $loader = $this->app->make(ModuleLoader::class);
        $loader->loadAll();
    }

    /**
     * 注册模块中的命令
     */
    protected function registerModuleCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        // 从全局缓存获取所有已发现的命令
        $moduleCommands = \zxf\Modules\Support\ModuleAutoDiscovery::getGlobalCommands();

        // 使用 Laravel 的 commands() 方法注册所有模块命令
        if (! empty($moduleCommands)) {
            $this->commands($moduleCommands);
        }
    }

    /**
     * 获取服务提供者
     */
    public function provides(): array
    {
        return [
            RepositoryInterface::class,
            ModuleLoader::class,
            ModuleCacheManager::class,
            CompiledModuleLoader::class,
        ];
    }
}
