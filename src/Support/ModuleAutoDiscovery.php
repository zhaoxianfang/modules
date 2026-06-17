<?php

declare(strict_types=1);

namespace zxf\Modules\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use zxf\Modules\Contracts\ModuleInterface;

/**
 * 模块自动发现器
 *
 * 提供智能的模块组件自动发现和加载能力。
 *
 * 自动发现顺序：
 * 1. 配置文件（最先加载）
 * 2. 服务提供者
 * 3. 中间件
 * 4. 路由文件
 * 5. 视图文件
 * 6. 迁移文件
 * 7. 翻译文件
 * 8. Artisan 命令
 * 9. 事件和监听器
 * 10. 模型观察者
 * 11. 策略类
 * 12. 仓库类
 *
 * @package zxf\Modules
 * @version 4.0.0
 */
class ModuleAutoDiscovery
{
    /**
     * 全局命令缓存
     *
     * @var array<string>
     */
    protected static array $globalCommands = [];

    /**
     * 模块实例
     */
    protected ModuleInterface $module;

    /**
     * 应用实例
     */
    protected \Illuminate\Contracts\Foundation\Application $app;

    /**
     * 发现缓存
     *
     * @var array<string, mixed>
     */
    protected array $cache = [];

    /**
     * 是否启用缓存
     */
    protected bool $cacheEnabled = true;

    /**
     * 发现日志
     *
     * @var array<string, string>
     */
    protected array $logs = [];

    /**
     * 事件钩子
     *
     * @var array<string, array<callable>>
     */
    protected static array $hooks = [];

    /**
     * 创建新实例
     */
    public function __construct(ModuleInterface $module)
    {
        $this->module = $module;
        $this->app = app();
        $this->cacheEnabled = config('modules.cache.enabled', false);
    }

    /**
     * 注册事件钩子
     *
     * @param string   $event    事件名: before_discover, after_discover, before_load, after_load
     * @param callable $callback 回调函数
     */
    public static function hook(string $event, callable $callback): void
    {
        self::$hooks[$event][] = $callback;
    }

    /**
     * 触发事件钩子
     */
    protected function triggerHook(string $event, array $context = []): void
    {
        if (! isset(self::$hooks[$event])) {
            return;
        }

        foreach (self::$hooks[$event] as $callback) {
            try {
                $callback($this->module, $context);
            } catch (\Throwable) {
                // 钩子执行失败不影响主流程
            }
        }
    }

    // ========================================================================
    //  主执行流程
    // ========================================================================

    /**
     * 执行所有自动发现任务
     */
    public function discoverAll(): void
    {
        if (! $this->module->isEnabled()) {
            return;
        }

        $this->triggerHook('before_discover', ['module' => $this->module->getName()]);

        // 按顺序执行发现
        $this->discoverProviders();
        $this->discoverConfigs();
        $this->discoverMiddlewares();
        $this->discoverRoutes();
        $this->discoverViews();
        $this->discoverMigrations();
        $this->discoverTranslations();
        $this->discoverCommands();
        $this->discoverEvents();
        $this->discoverObservers();
        $this->discoverPolicies();
        $this->discoverRepositories();

        $this->triggerHook('after_discover', [
            'module' => $this->module->getName(),
            'cache' => $this->cache,
        ]);

        if (! $this->cacheEnabled) {
            $this->cache = [];
        }
    }

    // ========================================================================
    //  服务提供者
    // ========================================================================

    protected function discoverProviders(): void
    {
        if (! $this->shouldDiscover('providers')) {
            return;
        }

        // 1. 注册配置文件中声明的额外 providers
        $extraProviders = $this->module->getLaravelProviders();
        foreach ($extraProviders as $providerClass) {
            if (is_string($providerClass) && class_exists($providerClass)) {
                try {
                    $this->app->register($providerClass);
                    $this->cache["provider.extra.{$providerClass}"] = true;
                    $this->log("Registered extra provider: {$providerClass}");
                } catch (\Throwable $e) {
                    $this->log("Extra provider registration error: {$e->getMessage()}");
                }
            }
        }

        // 2. 注册配置文件中声明的额外别名
        $extraAliases = $this->module->getLaravelAliases();
        if (! empty($extraAliases) && method_exists($this->app, 'alias')) {
            foreach ($extraAliases as $alias => $class) {
                if (is_string($alias) && is_string($class) && class_exists($class)) {
                    try {
                        $this->app->alias($class, $alias);
                        $this->cache["alias.{$alias}"] = $class;
                        $this->log("Registered alias: {$alias} => {$class}");
                    } catch (\Throwable) {
                        // 手动绑定到容器
                        try {
                            $this->app->singleton($alias, fn () => $this->app->make($class));
                            $this->cache["alias.{$alias}"] = $class;
                        } catch (\Throwable) {
                            // 静默失败
                        }
                    }
                }
            }
        }

        // 3. 自动扫描 Providers/ 目录下的 ServiceProvider 类
        $providersPath = $this->module->getProvidersPath();

        if (! is_dir($providersPath)) {
            return;
        }

        try {
            $files = File::files($providersPath);

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $className = $file->getBasename('.php');
                $providerClass = $this->module->getClassNamespace() . '\\Providers\\' . $className;

                if (! class_exists($providerClass)) {
                    continue;
                }

                $reflection = new \ReflectionClass($providerClass);

                if ($reflection->isSubclassOf(\Illuminate\Support\ServiceProvider::class) && ! $reflection->isAbstract()) {
                    $this->app->register($providerClass);
                    $this->cache["provider.{$className}"] = $providerClass;
                    $this->log("Registered provider: {$providerClass}");
                }
            }
        } catch (\Throwable $e) {
            $this->log("Provider discovery error: {$e->getMessage()}");
        }
    }

    // ========================================================================
    //  配置文件
    // ========================================================================

    protected function discoverConfigs(): void
    {
        if (! $this->shouldDiscover('config')) {
            return;
        }

        $configPath = $this->module->getConfigPath();

        if (! is_dir($configPath)) {
            return;
        }

        try {
            $files = File::files($configPath);
            $moduleLower = $this->module->getLowerName();

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $filename = $file->getBasename('.php');

                if (in_array(strtolower($filename), [$moduleLower, 'config'], true)) {
                    $configKey = $moduleLower;
                } else {
                    $configKey = $moduleLower . '.' . strtolower($filename);
                }

                $configValue = require $file->getPathname();

                if (! is_array($configValue)) {
                    continue;
                }

                // 合并配置：后加载的覆盖先加载的
                config([$configKey => array_merge(config($configKey, []), $configValue)]);
                $this->cache["config.{$configKey}"] = true;
                $this->log("Loaded config: {$configKey}");
            }
        } catch (\Throwable $e) {
            $this->log("Config discovery error: {$e->getMessage()}");
        }
    }

    // ========================================================================
    //  中间件
    // ========================================================================

    protected function discoverMiddlewares(): void
    {
        if (! $this->shouldDiscover('middlewares')) {
            return;
        }

        $possiblePaths = [
            $this->module->getPath('Http/Middleware'),
            $this->module->getPath('Http/Filters'),
        ];

        foreach ($possiblePaths as $middlewarePath) {
            if (! is_dir($middlewarePath)) {
                continue;
            }

            try {
                $files = File::files($middlewarePath);

                foreach ($files as $file) {
                    if ($file->getExtension() !== 'php') {
                        continue;
                    }

                    $className = $file->getBasename('.php');
                    $middlewareClass = $this->module->getClassNamespace() . '\\Http\\Middleware\\' . $className;

                    if (! class_exists($middlewareClass)) {
                        $middlewareClass = $this->module->getClassNamespace() . '\\Http\\Filters\\' . $className;
                    }

                    if (class_exists($middlewareClass)) {
                        $this->cache["middleware.{$className}"] = $middlewareClass;
                    }
                }
            } catch (\Throwable $e) {
                $this->log("Middleware discovery error: {$e->getMessage()}");
            }
        }
    }

    // ========================================================================
    //  路由文件
    // ========================================================================

    protected function discoverRoutes(): void
    {
        if (! $this->shouldDiscover('routes')) {
            return;
        }

        $routesPath = $this->module->getRoutesPath();

        if (! is_dir($routesPath)) {
            return;
        }

        $middlewareGroups = config('modules.middleware_groups', [
            'web' => ['web'],
            'api' => ['api'],
        ]);

        try {
            $files = File::files($routesPath);

            foreach ($files as $routeFile) {
                if ($routeFile->getExtension() !== 'php') {
                    continue;
                }

                $filename = $routeFile->getBasename('.php');

                if (str_starts_with($filename, '.')) {
                    continue;
                }

                $middleware = $middlewareGroups[$filename] ?? [];
                $controllerNamespace = $this->autoDetectControllerNamespace($filename);
                $fullNamespace = $this->module->getClassNamespace() . '\\Http\\Controllers' . $controllerNamespace;

                $router = app('router');
                $routeGroup = $router;

                if (! empty($middleware)) {
                    $routeGroup = $routeGroup->middleware($middleware);
                }

                if (! empty($controllerNamespace)) {
                    $routeGroup = $routeGroup->namespace($fullNamespace);
                }

                $routeGroup->group(function () use ($routeFile) {
                    require $routeFile->getPathname();
                });

                $this->log("Loaded route: {$filename}");
                $this->cache["route.{$filename}"] = true;
            }
        } catch (\Throwable $e) {
            $this->log("Route discovery error: {$e->getMessage()}");
        }
    }

    // ========================================================================
    //  视图
    // ========================================================================

    protected function discoverViews(): void
    {
        if (! $this->shouldDiscover('views')) {
            return;
        }

        $possiblePaths = [
            $this->module->getViewsPath(),
            $this->module->getPath('resources/views'),
            $this->module->getPath('views'),
        ];

        $viewsPath = $this->findFirstExistingPath($possiblePaths);

        if (! $viewsPath) {
            return;
        }

        $namespaceFormat = config('modules.views.namespace_format', 'lower');

        $viewNamespace = match ($namespaceFormat) {
            'lower' => $this->module->getLowerName(),
            'studly' => $this->module->getName(),
            'camel' => $this->module->getCamelName(),
            default => $this->module->getLowerName(),
        };

        try {
            app('view')->addNamespace($viewNamespace, $viewsPath);
            $this->cache['view'] = true;
            $this->log("Registered view namespace: {$viewNamespace}");
        } catch (\Throwable $e) {
            $this->log("View discovery error: {$e->getMessage()}");
        }
    }

    // ========================================================================
    //  迁移
    // ========================================================================

    protected function discoverMigrations(): void
    {
        if (! $this->shouldDiscover('migrations')) {
            return;
        }

        $possiblePaths = [
            $this->module->getMigrationsPath(),
            $this->module->getPath('database/migrations'),
        ];

        $migrationsPath = $this->findFirstExistingPath($possiblePaths);

        if (! $migrationsPath) {
            return;
        }

        try {
            $migrator = app('migrator');
            $existingPaths = $migrator->paths();

            if (! in_array($migrationsPath, $existingPaths, true)) {
                $migrator->path($migrationsPath);
            }

            $this->cache['migration'] = true;
        } catch (\Throwable) {
            // 静默失败
        }
    }

    // ========================================================================
    //  翻译
    // ========================================================================

    protected function discoverTranslations(): void
    {
        if (! $this->shouldDiscover('translations')) {
            return;
        }

        $possiblePaths = [
            $this->module->getLangPath(),
            $this->module->getPath('Lang'),
            $this->module->getPath('resources/lang'),
        ];

        $langPath = $this->findFirstExistingPath($possiblePaths);

        if (! $langPath) {
            return;
        }

        try {
            $translator = app('translator');

            if (! method_exists($translator, 'addNamespace')) {
                return;
            }

            $loader = $translator->getLoader();
            if (! method_exists($loader, 'addNamespace')) {
                return;
            }

            $translator->addNamespace($this->module->getLowerName(), $langPath);
            $this->cache['translation'] = true;
        } catch (\Throwable) {
            // 静默失败
        }
    }

    // ========================================================================
    //  Artisan 命令
    // ========================================================================

    /**
     * 扫描 Artisan 命令
     */
    public function discoverCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        if (! $this->shouldDiscover('commands')) {
            return;
        }

        $possiblePaths = [
            ['path' => $this->module->getCommandsPath(), 'ns' => '\\Console\\Commands'],
            ['path' => $this->module->getPath('Commands'), 'ns' => '\\Commands'],
        ];

        $foundCommands = [];

        foreach ($possiblePaths as $pathInfo) {
            $commandsPath = $pathInfo['path'];

            if (! is_dir($commandsPath)) {
                continue;
            }

            try {
                $files = File::files($commandsPath);

                foreach ($files as $file) {
                    if ($file->getExtension() !== 'php') {
                        continue;
                    }

                    $className = $file->getBasename('.php');
                    $commandClass = $this->module->getClassNamespace() . $pathInfo['ns'] . '\\' . $className;

                    if (! class_exists($commandClass)) {
                        continue;
                    }

                    $reflection = new \ReflectionClass($commandClass);

                    if ($reflection->isSubclassOf(\Illuminate\Console\Command::class) && ! $reflection->isAbstract()) {
                        $foundCommands[] = $commandClass;
                    }
                }
            } catch (\Throwable $e) {
                $this->log("Command scan error: {$e->getMessage()}");
            }
        }

        // 去重并添加到全局缓存
        foreach ($foundCommands as $commandClass) {
            if (! in_array($commandClass, self::$globalCommands, true)) {
                self::$globalCommands[] = $commandClass;
            }
        }

        // 注册命令到 Artisan（通过 Kernel，而非 Application）
        if (! empty($foundCommands)) {
            try {
                $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
                foreach ($foundCommands as $commandClass) {
                    $kernel->registerCommand($this->app->make($commandClass));
                }
            } catch (\Throwable) {
                // 降级方案：通过 Artisan facade
                try {
                    \Illuminate\Support\Facades\Artisan::addCommands($foundCommands);
                } catch (\Throwable $e) {
                    $this->log("Command registration failed: {$e->getMessage()}");
                }
            }
        }

        $this->cache['commands'] = $foundCommands;
    }

    // ========================================================================
    //  事件
    // ========================================================================

    protected function discoverEvents(): void
    {
        if (! $this->shouldDiscover('events')) {
            return;
        }

        $eventsPath = $this->module->getPath('Events');

        if (! is_dir($eventsPath)) {
            return;
        }

        try {
            $files = File::files($eventsPath);
            $events = [];

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $className = $file->getBasename('.php');
                $eventClass = $this->module->getClassNamespace() . '\\Events\\' . $className;

                if (class_exists($eventClass)) {
                    $events[] = $eventClass;
                }
            }

            $this->cache['events'] = $events;
        } catch (\Throwable $e) {
            $this->log("Event discovery error: {$e->getMessage()}");
        }
    }

    // ========================================================================
    //  模型观察者
    // ========================================================================

    protected function discoverObservers(): void
    {
        if (! $this->shouldDiscover('observers')) {
            return;
        }

        $observersPath = $this->module->getPath('Observers');

        if (! is_dir($observersPath)) {
            return;
        }

        try {
            $files = File::files($observersPath);

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $className = $file->getBasename('.php');
                $observerClass = $this->module->getClassNamespace() . '\\Observers\\' . $className;

                if (! class_exists($observerClass)) {
                    continue;
                }

                $modelName = str_replace('Observer', '', $className);
                $modelClass = $this->module->getClassNamespace() . '\\Models\\' . $modelName;

                if (class_exists($modelClass)) {
                    $modelClass::observe($observerClass);
                    $this->cache["observer.{$modelName}"] = $observerClass;
                }
            }
        } catch (\Throwable $e) {
            $this->log("Observer discovery error: {$e->getMessage()}");
        }
    }

    // ========================================================================
    //  策略类
    // ========================================================================

    protected function discoverPolicies(): void
    {
        if (! $this->shouldDiscover('policies')) {
            return;
        }

        $policiesPath = $this->module->getPath('Policies');

        if (! is_dir($policiesPath)) {
            return;
        }

        try {
            $files = File::files($policiesPath);

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $className = $file->getBasename('.php');
                $policyClass = $this->module->getClassNamespace() . '\\Policies\\' . $className;

                if (! class_exists($policyClass)) {
                    continue;
                }

                $modelName = str_replace('Policy', '', $className);
                $modelClass = $this->module->getClassNamespace() . '\\Models\\' . $modelName;

                if (class_exists($modelClass)) {
                    Gate::policy($modelClass, $policyClass);
                    $this->cache["policy.{$modelName}"] = $policyClass;
                }
            }
        } catch (\Throwable $e) {
            $this->log("Policy discovery error: {$e->getMessage()}");
        }
    }

    // ========================================================================
    //  仓库类
    // ========================================================================

    protected function discoverRepositories(): void
    {
        if (! $this->shouldDiscover('repositories')) {
            return;
        }

        $reposPath = $this->module->getPath('Repositories');

        if (! is_dir($reposPath)) {
            return;
        }

        try {
            $files = File::files($reposPath);
            $repos = [];

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $className = $file->getBasename('.php');
                $repoClass = $this->module->getClassNamespace() . '\\Repositories\\' . $className;

                if (class_exists($repoClass)) {
                    $repos[$className] = $repoClass;
                }
            }

            $this->cache['repositories'] = $repos;
        } catch (\Throwable $e) {
            $this->log("Repository discovery error: {$e->getMessage()}");
        }
    }

    // ========================================================================
    //  辅助方法
    // ========================================================================

    protected function shouldDiscover(string $type): bool
    {
        return config("modules.discovery.{$type}", true);
    }

    protected function autoDetectControllerNamespace(string $routeFilename): string
    {
        $subNamespace = ucfirst($routeFilename);
        $controllerPath = $this->module->getPath('Http/Controllers/' . $subNamespace);

        return is_dir($controllerPath) ? '\\' . $subNamespace : '';
    }

    protected function findFirstExistingPath(array $possiblePaths): ?string
    {
        foreach ($possiblePaths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }

        return null;
    }

    protected function log(string $message): void
    {
        $this->logs[date('Y-m-d H:i:s')] = $message;
    }

    // ========================================================================
    //  公开方法
    // ========================================================================

    public function getCache(): array
    {
        return $this->cache;
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }

    public function getLogs(): array
    {
        return $this->logs;
    }

    public static function getGlobalCommands(): array
    {
        return self::$globalCommands;
    }

    public static function clearGlobalCommands(): void
    {
        self::$globalCommands = [];
    }

    public function getDiscoverySummary(): array
    {
        return [
            'module' => $this->module->getName(),
            'enabled' => $this->module->isEnabled(),
            'providers' => count(array_filter($this->cache, fn($k) => str_starts_with($k, 'provider.'), ARRAY_FILTER_USE_KEY)),
            'configs' => array_keys(array_filter($this->cache, fn($k) => str_starts_with($k, 'config.'), ARRAY_FILTER_USE_KEY)),
            'routes' => array_keys(array_filter($this->cache, fn($k) => str_starts_with($k, 'route.'), ARRAY_FILTER_USE_KEY)),
            'views' => isset($this->cache['view']),
            'migrations' => isset($this->cache['migration']),
            'translations' => isset($this->cache['translation']),
            'commands' => is_array($this->cache['commands'] ?? null) ? count($this->cache['commands']) : 0,
            'events' => is_array($this->cache['events'] ?? null) ? count($this->cache['events']) : 0,
            'observers' => count(array_filter($this->cache, fn($k) => str_starts_with($k, 'observer.'), ARRAY_FILTER_USE_KEY)),
            'policies' => count(array_filter($this->cache, fn($k) => str_starts_with($k, 'policy.'), ARRAY_FILTER_USE_KEY)),
            'repositories' => is_array($this->cache['repositories'] ?? null) ? count($this->cache['repositories']) : 0,
        ];
    }
}
