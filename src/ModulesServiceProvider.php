<?php

namespace zxf\Modules;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Managers\ModuleManager;

class ModulesServiceProvider extends ServiceProvider
{
    /**
     * 注册任何应用程序服务。
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/modules.php', 'modules'
        );

        $this->app->singleton(ModuleManager::class, function ($app) {
            return new ModuleManager($app);
        });

        $this->app->bind(ModuleInterface::class, function ($app) {
            return $app->make(ModuleManager::class);
        });

        // 注册所有启用的模块
        $this->app->make(ModuleManager::class)->registerModules();
    }

    /**
     * 引导任何应用程序服务。
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/modules.php' => $this->app->configPath('modules.php'),
        ], 'modules-config');

        $this->publishes([
            __DIR__ . '/../stubs' => $this->app->basePath('stubs/modules'),
        ], 'modules-stubs');

        if ($this->app->runningInConsole()) {
            $this->commands([
                \zxf\Modules\Console\Commands\MakeModuleCommand::class,
                \zxf\Modules\Console\Commands\MakeModuleControllerCommand::class,
                \zxf\Modules\Console\Commands\MakeModuleModelCommand::class,
                \zxf\Modules\Console\Commands\MakeModuleMigrationCommand::class,
                \zxf\Modules\Console\Commands\MakeModuleEventCommand::class,
                \zxf\Modules\Console\Commands\MakeModuleListenerCommand::class,
                \zxf\Modules\Console\Commands\MakeModulePolicyCommand::class,
                \zxf\Modules\Console\Commands\MakeModuleRequestCommand::class,
                \zxf\Modules\Console\Commands\MakeModuleResourceCommand::class,
                \zxf\Modules\Console\Commands\MakeModuleServiceCommand::class,
                \zxf\Modules\Console\Commands\MakeModuleMiddlewareCommand::class,
                \zxf\Modules\Console\Commands\MakeModuleSeederCommand::class,
                \zxf\Modules\Console\Commands\MakeModuleFactoryCommand::class,
                \zxf\Modules\Console\Commands\MakeModuleRuleCommand::class,
                \zxf\Modules\Console\Commands\MakeModuleJobCommand::class,
                \zxf\Modules\Console\Commands\MakeModuleMailCommand::class,
                \zxf\Modules\Console\Commands\MakeModuleNotificationCommand::class,
                \zxf\Modules\Console\Commands\MakeModuleExceptionCommand::class,
                \zxf\Modules\Console\Commands\MakeModuleObserverCommand::class,
                \zxf\Modules\Console\Commands\MakeModuleContractCommand::class,
                \zxf\Modules\Console\Commands\MakeModuleRepositoryCommand::class,
                \zxf\Modules\Console\Commands\MakeModuleChannelCommand::class,
                \zxf\Modules\Console\Commands\MakeModuleComponentCommand::class,
                \zxf\Modules\Console\Commands\MakeModuleCastCommand::class,
                \zxf\Modules\Console\Commands\ModuleListCommand::class,
                \zxf\Modules\Console\Commands\ModuleEnableCommand::class,
                \zxf\Modules\Console\Commands\ModuleDisableCommand::class,
                \zxf\Modules\Console\Commands\ModuleMigrateCommand::class,
                \zxf\Modules\Console\Commands\ModulePublishCommand::class,
                \zxf\Modules\Console\Commands\ModuleCacheCommand::class,
                \zxf\Modules\Console\Commands\ModuleClearCacheCommand::class,
                \zxf\Modules\Console\Commands\ModuleCheckCommand::class,
                \zxf\Modules\Console\Commands\ModuleInstallCommand::class,
                \zxf\Modules\Console\Commands\ModuleUninstallCommand::class,
            ]);
        }

        // 引导所有启用的模块
        $manager = $this->app->make(ModuleManager::class);
        $manager->bootModules();

        // 加载模块资源
        $this->loadModuleResources($manager);
    }

    /**
     * 为所有启用的模块加载资源（路由、迁移、视图、配置、翻译、中间件）。
     */
    protected function loadModuleResources(ModuleManager $manager): void
    {
        // 检查自动发现总开关
        if (!$this->app['config']->get('modules.auto_discovery', true)) {
            return;
        }
        
        $enabledModules = $manager->enabled();
        
        if (empty($enabledModules)) {
            return;
        }

        foreach ($enabledModules as $module) {
            try {
                // 根据配置加载各种资源
                $config = $this->app['config'];
                
                // 加载路由
                if ($config->get('modules.auto_discover_routes', true)) {
                    $this->loadModuleRoutes($module);
                }
                
                // 加载迁移
                if ($config->get('modules.auto_discover_migrations', true)) {
                    $this->loadModuleMigrations($module);
                }
                
                // 加载视图
                $this->loadModuleViews($module);
                
                // 加载翻译
                $this->loadModuleTranslations($module);
                
                // 注册中间件
                $this->registerModuleMiddleware($module);
                
                // 合并配置
                $this->mergeModuleConfig($module);
                
                // 自动发现类（命令、事件、监听器、观察者等）
                $this->discoverModuleClasses($module);
                
            } catch (\Exception $e) {
                // 记录错误但继续加载其他模块
                if ($this->app->bound('log')) {
                    $this->app['log']->error(
                        sprintf('加载模块 [%s] 资源失败：%s', $module->getName(), $e->getMessage()),
                        ['exception' => $e]
                    );
                }
                
                // 在开发环境中可选择重新抛出
                if ($this->app->environment('local', 'testing')) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Load module routes.
     */
    protected function loadModuleRoutes(ModuleInterface $module): void
    {
        foreach ($module->getRoutes() as $routeType => $routePath) {
            if (file_exists($routePath)) {
                match ($routeType) {
                    'web' => $this->loadWebRoutes($routePath),
                    'api' => $this->loadApiRoutes($routePath),
                    'console' => $this->loadConsoleRoutes($routePath),
                    default => null,
                };
            }
        }
    }

    /**
     * Load module migrations.
     */
    protected function loadModuleMigrations(ModuleInterface $module): void
    {
        foreach ($module->getMigrations() as $migrationPath) {
            if (is_dir($migrationPath)) {
                $this->loadMigrationsFrom($migrationPath);
            }
        }
    }

    /**
     * Load module views.
     */
    protected function loadModuleViews(ModuleInterface $module): void
    {
        foreach ($module->getViews() as $viewPath) {
            if (is_dir($viewPath)) {
                $this->loadViewsFrom($viewPath, $module->getName());
            }
        }
    }

    /**
     * Load module translations.
     */
    protected function loadModuleTranslations(ModuleInterface $module): void
    {
        foreach ($module->getTranslations() as $translationPath) {
            if (is_dir($translationPath)) {
                $this->loadTranslationsFrom($translationPath, $module->getName());
            }
        }
    }

    /**
     * Register module middleware.
     */
    protected function registerModuleMiddleware(ModuleInterface $module): void
    {
        $router = $this->app['router'];
        foreach ($module->getMiddleware() as $middlewareType => $middlewares) {
            foreach ($middlewares as $middleware) {
                if (class_exists($middleware)) {
                    if (method_exists($router, 'pushMiddlewareToGroup')) {
                        $router->pushMiddlewareToGroup($middlewareType, $middleware);
                    }
                }
            }
        }
    }

    /**
     * Merge module config.
     */
    protected function mergeModuleConfig(ModuleInterface $module): void
    {
        foreach ($module->getConfig() as $key => $configPath) {
            if (file_exists($configPath)) {
                $this->mergeConfigFrom($configPath, $key);
            }
        }
    }

    /**
     * 加载 Web 路由。
     */
    protected function loadWebRoutes(string $path): void
    {
        $middlewareGroups = $this->app['config']->get('modules.middleware_groups', ['web' => ['web']]);
        $webMiddleware = $middlewareGroups['web'] ?? ['web'];
        Route::middleware($webMiddleware)->group(function () use ($path) {
            require $path;
        });
    }

    /**
     * 加载 API 路由。
     */
    protected function loadApiRoutes(string $path): void
    {
        $middlewareGroups = $this->app['config']->get('modules.middleware_groups', ['api' => ['api']]);
        $apiMiddleware = $middlewareGroups['api'] ?? ['api'];
        Route::middleware($apiMiddleware)->prefix('api')->group(function () use ($path) {
            require $path;
        });
    }

    /**
     * 加载控制台路由。
     */
    protected function loadConsoleRoutes(string $path): void
    {
        if ($this->app->runningInConsole()) {
            require $path;
        }
    }

    /**
     * 自动发现模块中的类（命令、事件、监听器、观察者等）。
     */
    protected function discoverModuleClasses(ModuleInterface $module): void
    {
        // 检查自动发现配置
        $config = $this->app['config'];
        $autoDiscoverCommands = $config->get('modules.auto_discover_commands', true);
        $autoDiscoverEvents = $config->get('modules.auto_discover_events', true);
        $autoDiscoverListeners = $config->get('modules.auto_discover_listeners', true);
        $autoDiscoverObservers = $config->get('modules.auto_discover_observers', true);
        $autoDiscoverPolicies = $config->get('modules.auto_discover_policies', true);
        $autoDiscoverRequests = $config->get('modules.auto_discover_requests', true);
        $autoDiscoverResources = $config->get('modules.auto_discover_resources', true);
        
        $modulePath = $module->getPath();
        $namespace = $module->getNamespace();
        
        // 自动发现命令
        if ($autoDiscoverCommands) {
            $this->discoverCommands($module, $modulePath, $namespace);
        }
        
        // 自动发现事件和监听器
        if ($autoDiscoverEvents || $autoDiscoverListeners) {
            $this->discoverEventsAndListeners($module, $modulePath, $namespace);
        }
        
        // 自动发现观察者
        if ($autoDiscoverObservers) {
            $this->discoverObservers($module, $modulePath, $namespace);
        }
        
        // 自动发现策略
        if ($autoDiscoverPolicies) {
            $this->discoverPolicies($module, $modulePath, $namespace);
        }
        
        // 自动发现表单请求
        if ($autoDiscoverRequests) {
            $this->discoverRequests($module, $modulePath, $namespace);
        }
        
        // 自动发现资源
        if ($autoDiscoverResources) {
            $this->discoverResources($module, $modulePath, $namespace);
        }
    }
    
    /**
     * 自动发现模块中的 Artisan 命令。
     */
    protected function discoverCommands(ModuleInterface $module, string $modulePath, string $namespace): void
    {
        $commandsPath = $modulePath . '/Console/Commands';
        if (is_dir($commandsPath)) {
            $files = glob($commandsPath . '/*.php');
            foreach ($files as $file) {
                $className = $namespace . '\\Console\\Commands\\' . basename($file, '.php');
                if (class_exists($className) && is_subclass_of($className, \Illuminate\Console\Command::class)) {
                    $this->app->extend('commands', function ($commands) use ($className) {
                        $commands[] = $className;
                        return $commands;
                    });
                    
                    // 记录日志
                    if ($this->app->bound('log')) {
                        $this->app['log']->debug('自动发现模块命令', [
                            'module' => $module->getName(),
                            'command' => $className,
                        ]);
                    }
                }
            }
        }
    }
    
    /**
     * 自动发现模块中的事件和监听器。
     */
    protected function discoverEventsAndListeners(ModuleInterface $module, string $modulePath, string $namespace): void
    {
        $config = $this->app['config'];
        $autoDiscoverEvents = $config->get('modules.auto_discover_events', true);
        $autoDiscoverListeners = $config->get('modules.auto_discover_listeners', true);
        
        // 发现事件
        if ($autoDiscoverEvents) {
            $eventsPath = $modulePath . '/Events';
            if (is_dir($eventsPath)) {
                $files = glob($eventsPath . '/*.php');
                foreach ($files as $file) {
                    $className = $namespace . '\\Events\\' . basename($file, '.php');
                    if (class_exists($className)) {
                        // 记录日志
                        if ($this->app->bound('log')) {
                            $this->app['log']->debug('自动发现模块事件', [
                                'module' => $module->getName(),
                                'event' => $className,
                            ]);
                        }
                    }
                }
            }
        }
        
        // 发现并注册监听器
        if ($autoDiscoverListeners) {
            $listenersPath = $modulePath . '/Listeners';
            if (is_dir($listenersPath)) {
                $files = glob($listenersPath . '/*.php');
                foreach ($files as $file) {
                    $className = $namespace . '\\Listeners\\' . basename($file, '.php');
                    if (class_exists($className)) {
                        // 解析监听器监听的事件类
                        $eventClass = $this->getEventFromListener($className);
                        if ($eventClass) {
                            // 注册事件监听器
                            $this->app['events']->listen($eventClass, $className);
                            
                            // 记录日志
                            if ($this->app->bound('log')) {
                                $this->app['log']->debug('自动注册模块事件监听器', [
                                    'module' => $module->getName(),
                                    'event' => $eventClass,
                                    'listener' => $className,
                                ]);
                            }
                        } else {
                            // 记录警告：无法解析事件类
                            if ($this->app->bound('log')) {
                                $this->app['log']->warning('无法解析模块监听器的事件类', [
                                    'module' => $module->getName(),
                                    'listener' => $className,
                                ]);
                            }
                        }
                    }
                }
            }
        }
    }
    
    /**
     * 从监听器类解析事件类。
     */
    protected function getEventFromListener(string $listenerClass): ?string
    {
        try {
            $reflection = new \ReflectionClass($listenerClass);
            if (!$reflection->hasMethod('handle')) {
                return null;
            }
            
            $method = $reflection->getMethod('handle');
            $parameters = $method->getParameters();
            if (count($parameters) === 0) {
                return null;
            }
            
            $firstParam = $parameters[0];
            $type = $firstParam->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                return $type->getName();
            }
        } catch (\ReflectionException $e) {
            // 忽略反射异常
            unset($e);
        }
        
        return null;
    }
    
    /**
     * 自动发现模块中的观察者。
     */
    protected function discoverObservers(ModuleInterface $module, string $modulePath, string $namespace): void
    {
        $observersPath = $modulePath . '/Observers';
        if (is_dir($observersPath)) {
            $files = glob($observersPath . '/*.php');
            foreach ($files as $file) {
                $className = $namespace . '\\Observers\\' . basename($file, '.php');
                if (class_exists($className) && method_exists($className, 'observe')) {
                    // 确定观察者观察的模型
                    // 约定：观察者类应该有一个静态属性 $model 或方法 getModel()
                    $modelClass = null;
                    
                    if (property_exists($className, 'model') && ($modelClass = $className::$model)) {
                        // 从静态属性获取模型类
                    } elseif (method_exists($className, 'getModel')) {
                        $modelClass = $className::getModel();
                    } else {
                        // 尝试从类名推断模型类
                        // 例如：UserObserver -> User
                        $observerName = basename($file, '.php');
                        if (str_ends_with($observerName, 'Observer')) {
                            $modelName = substr($observerName, 0, -8); // 移除 'Observer'
                            $modelClass = $namespace . '\\Models\\' . $modelName;
                        }
                    }
                    
                    if ($modelClass && class_exists($modelClass)) {
                        // 注册观察者
                        $modelClass::observe($className);
                        
                        // 记录日志
                        if ($this->app->bound('log')) {
                            $this->app['log']->debug('自动发现模块观察者', [
                                'module' => $module->getName(),
                                'observer' => $className,
                                'model' => $modelClass,
                            ]);
                        }
                    }
                }
            }
        }
    }

    /**
     * 自动发现模块中的策略。
     */
    protected function discoverPolicies(ModuleInterface $module, string $modulePath, string $namespace): void
    {
        $policiesPath = $modulePath . '/Policies';
        if (is_dir($policiesPath)) {
            $files = glob($policiesPath . '/*.php');
            foreach ($files as $file) {
                $className = $namespace . '\\Policies\\' . basename($file, '.php');
                if (class_exists($className)) {
                    // 尝试推断对应的模型类
                    $policyName = basename($file, '.php');
                    if (str_ends_with($policyName, 'Policy')) {
                        $modelName = substr($policyName, 0, -6); // 移除 'Policy'
                        $modelClass = $namespace . '\\Models\\' . $modelName;
                        if (class_exists($modelClass)) {
                            // 注册策略
                            \Illuminate\Support\Facades\Gate::policy($modelClass, $className);
                            
                            // 记录日志
                            if ($this->app->bound('log')) {
                                $this->app['log']->debug('自动发现模块策略', [
                                    'module' => $module->getName(),
                                    'policy' => $className,
                                    'model' => $modelClass,
                                ]);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * 自动发现模块中的表单请求。
     */
    protected function discoverRequests(ModuleInterface $module, string $modulePath, string $namespace): void
    {
        $requestsPath = $modulePath . '/Http/Requests';
        if (is_dir($requestsPath)) {
            $files = glob($requestsPath . '/*.php');
            foreach ($files as $file) {
                $className = $namespace . '\\Http\\Requests\\' . basename($file, '.php');
                if (class_exists($className)) {
                    // 记录日志
                    if ($this->app->bound('log')) {
                        $this->app['log']->debug('自动发现模块表单请求', [
                            'module' => $module->getName(),
                            'request' => $className,
                        ]);
                    }
                }
            }
        }
    }

    /**
     * 自动发现模块中的资源。
     */
    protected function discoverResources(ModuleInterface $module, string $modulePath, string $namespace): void
    {
        $resourcesPath = $modulePath . '/Http/Resources';
        if (is_dir($resourcesPath)) {
            $files = glob($resourcesPath . '/*.php');
            foreach ($files as $file) {
                $className = $namespace . '\\Http\\Resources\\' . basename($file, '.php');
                if (class_exists($className)) {
                    // 记录日志
                    if ($this->app->bound('log')) {
                        $this->app['log']->debug('自动发现模块资源', [
                            'module' => $module->getName(),
                            'resource' => $className,
                        ]);
                    }
                }
            }
        }
    }
}