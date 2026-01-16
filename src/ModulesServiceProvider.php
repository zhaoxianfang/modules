<?php

namespace zxf\Modules;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use zxf\Modules\Contracts\RepositoryInterface;
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
        $this->loadModules();
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
            return new ModuleLoader($app->make(RepositoryInterface::class));
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