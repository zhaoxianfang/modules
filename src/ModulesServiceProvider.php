<?php

namespace zxf\Modules;

use Composer\InstalledVersions;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use zxf\Modules\Contracts\RepositoryInterface;
use zxf\Modules\Repository;
use zxf\Modules\Support\ModuleLoader;

class ModulesServiceProvider extends ServiceProvider
{
    /**
     * 延迟加载
     *
     * @var bool
     */
    protected bool $defer = false;

    /**
     * 注册任何应用程序服务。
     */
    public function register(): void
    {
        $this->registerRepository();
        $this->registerModuleLoader();
        $this->mergeConfig();
    }

    /**
     * 引导任何应用程序服务。
     */
    public function boot(): void
    {
        $this->publishConfig();
        $this->registerCommands();
        
        // 先加载模块以发现所有命令
        $this->loadModules();
        
        // 在加载模块后，注册模块中的命令
        $this->registerModuleCommands();

        // 把 zxf/modules 添加到 about 命令中
        AboutCommand::add('Extend', [
            'zxf/modules' => fn () => InstalledVersions::getPrettyVersion('zxf/modules'),
        ]);
    }

    /**
     * 注册模块仓库
     *
     * @return void
     */
    protected function registerRepository(): void
    {
        $this->app->singleton(RepositoryInterface::class, function ($app) {
            return new Repository($app['files']);
        });

        $this->app->alias(RepositoryInterface::class, 'modules');
    }

    /**
     * 注册模块加载器
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
     */
    protected function publishConfig(): void
    {
        $this->publishes([
            __DIR__ . '/../config/modules.php' => config_path('modules.php'),
        ], 'modules-config');
    }

    /**
     * 注册命令
     *
     * @return void
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
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
    }

    /**
     * 加载所有模块
     *
     * @return void
     */
    protected function loadModules(): void
    {
        $loader = $this->app->make(ModuleLoader::class);
        $loader->loadAll();
    }

    /**
     * 注册模块中的命令
     *
     * 在模块加载后，收集所有模块的命令并注册到 Artisan
     * 使用 Laravel 的命令注册机制确保命令可以正确执行
     *
     * @return void
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
     *
     * @return array
     */
    public function provides(): array
    {
        return [
            RepositoryInterface::class,
            ModuleLoader::class,
        ];
    }
}