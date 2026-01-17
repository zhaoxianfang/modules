<?php

namespace zxf\Modules\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Contracts\RepositoryInterface;

/**
 * 模块加载器类
 *
 * 负责自动发现和加载所有模块组件
 * 包括配置、服务提供者、路由、视图、命令、迁移、翻译等
 */
class ModuleLoader
{
    /**
     * 模块仓库
     *
     * @var RepositoryInterface
     */
    protected RepositoryInterface $repository;

    /**
     * 创建新实例
     *
     * @param RepositoryInterface $repository
     */
    public function __construct(RepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * 加载所有模块
     *
     * @return void
     */
    public function loadAll(): void
    {
        $this->repository->scan();

        $modules = $this->repository->all();

        foreach ($modules as $module) {
            $this->loadModule($module);
        }
    }

    /**
     * 加载单个模块
     *
     * @param ModuleInterface $module
     * @return void
     */
    public function loadModule(ModuleInterface $module): void
    {
        // 检查模块是否启用
        if (! $module->isEnabled()) {
            return;
        }

        // 按顺序加载模块组件
        $this->loadConfig($module);
        $this->loadServiceProvider($module);
        $this->loadRoutes($module);
        $this->loadViews($module);
        $this->loadCommands($module);
        $this->loadMigrations($module);
        $this->loadTranslations($module);
        $this->loadEvents($module);
    }

    /**
     * 加载模块配置
     *
     * @param ModuleInterface $module
     * @return void
     */
    protected function loadConfig(ModuleInterface $module): void
    {
        if (! config('modules.discovery.config', true)) {
            return;
        }

        $configPath = $module->getConfigPath();

        if (! is_dir($configPath)) {
            return;
        }

        $configFiles = ConfigLoader::getConfigFiles($module->getName());

        foreach ($configFiles as $configFile) {
            $configKey = ConfigLoader::getConfigKey($module->getName(), $configFile);
            $configValue = ConfigLoader::load($module->getName(), $configFile);

            Config::set($configKey, $configValue);
        }
    }

    /**
     * 加载模块服务提供者
     *
     * @param ModuleInterface $module
     * @return void
     */
    protected function loadServiceProvider(ModuleInterface $module): void
    {
        if (! config('modules.discovery.providers', true)) {
            return;
        }

        $providerClass = $module->getServiceProviderClass();

        if (! $providerClass) {
            return;
        }

        if (! class_exists($providerClass)) {
            return;
        }

        app()->register($providerClass);
    }

    /**
     * 加载模块路由
     *
     * @param ModuleInterface $module
     * @return void
     */
    protected function loadRoutes(ModuleInterface $module): void
    {
        if (! config('modules.discovery.routes', true)) {
            return;
        }

        RouteLoader::load($module);
    }

    /**
     * 加载模块视图
     *
     * @param ModuleInterface $module
     * @return void
     */
    protected function loadViews(ModuleInterface $module): void
    {
        if (! config('modules.discovery.views', true)) {
            return;
        }

        ViewLoader::load($module);
    }

    /**
     * 加载模块命令
     *
     * @param ModuleInterface $module
     * @return void
     */
    protected function loadCommands(ModuleInterface $module): void
    {
        if (! config('modules.discovery.commands', true)) {
            return;
        }

        $commandsPath = $module->getCommandsPath();

        if (! is_dir($commandsPath)) {
            return;
        }

        $files = glob($commandsPath . DIRECTORY_SEPARATOR . '*.php');

        foreach ($files as $file) {
            $className = basename($file, '.php');
            $commandClass = $module->getClassNamespace() . '\\Console\\Commands\\' . $className;

            if (class_exists($commandClass) && is_subclass_of($commandClass, \Illuminate\Console\Command::class)) {
                $signature = property_exists($commandClass, 'signature') ? (new \ReflectionProperty($commandClass, 'signature'))->getValue(app($commandClass)) : '';
                $description = property_exists($commandClass, 'description') ? (new \ReflectionProperty($commandClass, 'description'))->getValue(app($commandClass)) : '';

                if ($signature) {
                    Artisan::command($signature, function () use ($commandClass) {
                        $instance = app($commandClass);
                        call_user_func([$instance, 'handle']);
                    })->describe($description);
                }
            }
        }
    }

    /**
     * 加载模块迁移
     *
     * @param ModuleInterface $module
     * @return void
     */
    protected function loadMigrations(ModuleInterface $module): void
    {
        if (! config('modules.discovery.migrations', true)) {
            return;
        }

        $migrationsPath = $module->getMigrationsPath();

        if (! is_dir($migrationsPath)) {
            return;
        }

        app('migrator')->path($migrationsPath);
    }

    /**
     * 加载模块翻译文件
     *
     * @param ModuleInterface $module
     * @return void
     */
    protected function loadTranslations(ModuleInterface $module): void
    {
        if (! config('modules.discovery.translations', true)) {
            return;
        }

        $langPath = $module->getPath('Resources/lang');

        if (! is_dir($langPath)) {
            return;
        }

        app('translator')->addNamespace($module->getLowerName(), $langPath);
    }

    /**
     * 加载模块事件
     *
     * @param ModuleInterface $module
     * @return void
     */
    protected function loadEvents(ModuleInterface $module): void
    {
        if (! config('modules.discovery.events', true)) {
            return;
        }

        $eventsPath = $module->getPath('Events');

        if (! is_dir($eventsPath)) {
            return;
        }

        $files = glob($eventsPath . DIRECTORY_SEPARATOR . '*.php');

        foreach ($files as $file) {
            $className = basename($file, '.php');

            // Laravel 会自动加载 Events 目录中的类
            // 这里不需要额外注册，但可以进行验证
        }
    }

    /**
     * 重新加载所有模块
     *
     * @return void
     */
    public function reload(): void
    {
        $this->loadAll();
    }

    /**
     * 重新加载指定模块
     *
     * @param ModuleInterface $module
     * @return void
     */
    public function reloadModule(ModuleInterface $module): void
    {
        $this->loadModule($module);
    }

    /**
     * 获取已加载的模块列表
     *
     * @return array
     */
    public function getLoadedModules(): array
    {
        return $this->repository->all();
    }
}
