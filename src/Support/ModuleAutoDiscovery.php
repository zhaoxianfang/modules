<?php

namespace zxf\Modules\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use zxf\Modules\Contracts\ModuleInterface;

/**
 * 模块自动发现器
 *
 * 提供智能的模块组件自动发现和加载能力
 *
 * 性能优化：
 * 1. 使用类缓存避免重复检查 class_exists
 * 2. 延迟文件系统操作（按需扫描）
 * 3. 批量注册减少函数调用次数
 * 4. 支持发现结果缓存
 * 5. 优化反射类使用
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
     * 类存在性缓存
     *
     * @var array<string, bool>
     */
    protected static array $classExistenceCache = [];

    /**
     * 模块实例
     */
    protected ModuleInterface $module;

    /**
     * 应用实例
     */
    protected \Illuminate\Contracts\Foundation\Application $app;

    /**
     * 缓存管理器
     */
    protected ModuleCacheManager $cacheManager;

    /**
     * 发现结果缓存（当前实例）
     *
     * @var array<string, mixed>
     */
    protected array $discoveryCache = [];

    /**
     * 是否启用缓存
     */
    protected bool $cacheEnabled = true;

    /**
     * 是否使用静态缓存
     */
    protected bool $useStaticCache = true;

    /**
     * 静态发现缓存（跨实例共享）
     *
     * @var array<string, array>
     */
    protected static array $staticCache = [];

    /**
     * 发现日志
     *
     * @var array<string, string>
     */
    protected array $logs = [];

    /**
     * 批量收集的命令（延迟注册）
     *
     * @var array<string>
     */
    protected array $collectedCommands = [];

    /**
     * 创建新实例
     */
    public function __construct(ModuleInterface $module)
    {
        $this->module = $module;
        $this->app = app();
        $this->cacheManager = app(ModuleCacheManager::class);
        $this->cacheEnabled = config('modules.cache.discovery', true);
        $this->useStaticCache = config('modules.cache.static', true);
    }

    /**
     * 静态方法：从模块信息数组自动发现模块
     */
    public static function discoverModule(array $moduleInfo): void
    {
        $module = self::createModuleFromArray($moduleInfo);
        $discovery = new self($module);
        $discovery->discoverAll();
    }

    /**
     * 从数组创建模块对象
     */
    protected static function createModuleFromArray(array $moduleInfo): ModuleInterface
    {
        $name = $moduleInfo['name'];
        $namespace = $moduleInfo['namespace'];
        $path = $moduleInfo['path'];

        return new class($name, $namespace, $path) implements ModuleInterface {
            protected string $name;
            protected string $namespace;
            protected string $path;
            protected ?array $configCache = null;

            public function __construct(string $name, string $namespace, string $path)
            {
                $this->name = $name;
                $this->namespace = $namespace;
                $this->path = $path;
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getCamelName(): string
            {
                return Str::camel($this->name);
            }

            public function getLowerCamelName(): string
            {
                return lcfirst(Str::camel($this->name));
            }

            public function getLowerName(): string
            {
                return strtolower($this->name);
            }

            public function getPath(?string $path = null): string
            {
                return $this->path . ($path ? '/' . $path : '');
            }

            public function getNamespace(): string
            {
                return $this->namespace;
            }

            public function isEnabled(): bool
            {
                $config = $this->getModuleConfig();
                return $config['enabled'] ?? true;
            }

            public function getConfigPath(): string
            {
                return $this->getPath('Config');
            }

            public function getRoutesPath(): string
            {
                return $this->getPath('Routes');
            }

            public function getProvidersPath(): string
            {
                return $this->getPath('Providers');
            }

            public function getCommandsPath(): string
            {
                return $this->getPath('Console/Commands');
            }

            public function getViewsPath(): string
            {
                return $this->getPath('Resources/views');
            }

            public function getMigrationsPath(): string
            {
                return $this->getPath('Database/Migrations');
            }

            public function getControllersPath(): string
            {
                return $this->getPath('Http/Controllers');
            }

            public function config(string $key, $default = null)
            {
                return config(strtolower($this->name) . '.' . $key, $default);
            }

            public function hasRoute(string $route): bool
            {
                return file_exists($this->getRoutesPath() . '/' . $route . '.php');
            }

            public function getServiceProviderClass(): ?string
            {
                return $this->namespace . '\\' . $this->name . '\\Providers\\' . $this->name . 'ServiceProvider';
            }

            public function getRouteFiles(): array
            {
                $routesPath = $this->getRoutesPath();

                if (! is_dir($routesPath)) {
                    return [];
                }

                return glob($routesPath . '/*.php');
            }

            public function getModuleConfig(): array
            {
                if ($this->configCache !== null) {
                    return $this->configCache;
                }

                $configPath = $this->getConfigPath() . '/' . $this->getLowerName() . '.php';

                if (! file_exists($configPath)) {
                    $this->configCache = [];
                    return $this->configCache;
                }

                $this->configCache = require $configPath;
                return $this->configCache;
            }

            public function getClassNamespace(): string
            {
                return $this->namespace . '\\' . $this->name;
            }
        };
    }

    /**
     * 检查类是否存在（带缓存）
     */
    protected function classExists(string $className): bool
    {
        // 检查内存缓存
        if (isset(self::$classExistenceCache[$className])) {
            return self::$classExistenceCache[$className];
        }

        // 检查持久化缓存
        $cached = $this->cacheManager->getClassCheck($className);
        if ($cached !== null) {
            self::$classExistenceCache[$className] = $cached;
            return $cached;
        }

        // 实际检查
        $exists = class_exists($className);
        self::$classExistenceCache[$className] = $exists;
        $this->cacheManager->setClassCheck($className, $exists);

        return $exists;
    }

    /**
     * 执行所有自动发现任务
     */
    public function discoverAll(): void
    {
        if (! $this->module->isEnabled()) {
            return;
        }

        // 尝试从缓存恢复
        if ($this->tryRestoreFromCache()) {
            return;
        }

        // 按顺序执行发现任务（优化：延迟执行非关键任务）
        $this->discoverProviders();
        $this->discoverConfigs();
        $this->discoverMiddlewares();
        $this->discoverRoutes();
        $this->discoverViews();
        $this->discoverMigrations();
        $this->discoverTranslations();

        // 仅在控制台模式下发现命令
        if ($this->app->runningInConsole()) {
            $this->discoverCommands();
        }

        // 可选组件（延迟加载）
        $this->discoverEvents();
        $this->discoverObservers();
        $this->discoverPolicies();
        $this->discoverRepositories();

        // 保存发现结果到缓存
        $this->saveToCache();
    }

    /**
     * 尝试从缓存恢复发现结果
     */
    protected function tryRestoreFromCache(): bool
    {
        if (! $this->cacheEnabled) {
            return false;
        }

        $cacheKey = $this->getCacheKey();

        // 先检查静态缓存
        if ($this->useStaticCache && isset(self::$staticCache[$cacheKey])) {
            $this->restoreFromData(self::$staticCache[$cacheKey]);
            return true;
        }

        // 检查持久化缓存
        $cached = $this->cacheManager->getModuleDiscovery($this->module->getName());
        if ($cached !== null) {
            $this->restoreFromData($cached);
            // 回填静态缓存
            if ($this->useStaticCache) {
                self::$staticCache[$cacheKey] = $cached;
            }
            return true;
        }

        return false;
    }

    /**
     * 获取缓存键
     */
    protected function getCacheKey(): string
    {
        return 'discovery:' . $this->module->getName();
    }

    /**
     * 从缓存数据恢复
     */
    protected function restoreFromData(array $data): void
    {
        // 恢复配置
        if (! empty($data['configs'])) {
            foreach ($data['configs'] as $key => $value) {
                config([$key => $value]);
            }
        }

        // 恢复视图
        if (! empty($data['views'])) {
            app('view')->addNamespace($data['views']['namespace'], $data['views']['path']);
        }

        // 恢复迁移路径
        if (! empty($data['migrations'])) {
            app('migrator')->path($data['migrations']);
        }

        // 恢复命令
        if (! empty($data['commands'])) {
            foreach ($data['commands'] as $commandClass) {
                if (! in_array($commandClass, self::$globalCommands, true)) {
                    self::$globalCommands[] = $commandClass;
                }
            }
        }

        $this->discoveryCache = $data['discoveryCache'] ?? [];
    }

    /**
     * 保存发现结果到缓存
     */
    protected function saveToCache(): void
    {
        if (! $this->cacheEnabled) {
            return;
        }

        $cacheKey = $this->getCacheKey();
        $data = $this->prepareCacheData();

        // 保存到持久化缓存
        $this->cacheManager->setModuleDiscovery($this->module->getName(), $data);

        // 保存到静态缓存
        if ($this->useStaticCache) {
            self::$staticCache[$cacheKey] = $data;
        }
    }

    /**
     * 准备缓存数据
     */
    protected function prepareCacheData(): array
    {
        return [
            'module' => $this->module->getName(),
            'discoveryCache' => $this->discoveryCache,
            'timestamp' => time(),
        ];
    }

    /**
     * 发现并注册服务提供者
     */
    protected function discoverProviders(): void
    {
        if (! $this->shouldDiscover('providers')) {
            return;
        }

        $providersPath = $this->module->getProvidersPath();

        if (! is_dir($providersPath)) {
            return;
        }

        try {
            $providerFiles = File::files($providersPath);
            $providersToRegister = [];

            foreach ($providerFiles as $providerFile) {
                if ($providerFile->getExtension() !== 'php') {
                    continue;
                }

                $className = $providerFile->getBasename('.php');
                $providerClass = $this->module->getClassNamespace() . '\\Providers\\' . $className;

                if (! $this->classExists($providerClass)) {
                    continue;
                }

                // 批量收集，最后统一注册
                $providersToRegister[] = $providerClass;
                $this->discoveryCache["provider.{$className}"] = $providerClass;
            }

            // 批量注册
            foreach ($providersToRegister as $providerClass) {
                try {
                    $this->app->register($providerClass);
                } catch (\Throwable) {
                    // 静默处理
                }
            }
        } catch (\Throwable) {
            // 静默处理
        }
    }

    /**
     * 发现并合并配置文件
     */
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
            $configFiles = File::files($configPath);
            $configsToMerge = [];

            foreach ($configFiles as $configFile) {
                if ($configFile->getExtension() !== 'php') {
                    continue;
                }

                $filename = $configFile->getBasename('.php');
                $moduleLowerName = $this->module->getLowerName();

                $configKey = strtolower($filename) === $moduleLowerName
                    ? $moduleLowerName
                    : $moduleLowerName . '.' . strtolower($filename);

                try {
                    $configValue = require $configFile->getPathname();

                    if (! is_array($configValue)) {
                        continue;
                    }

                    $configsToMerge[$configKey] = $configValue;
                    $this->discoveryCache["config.{$configKey}"] = true;
                } catch (\Throwable) {
                    // 静默处理
                }
            }

            // 批量合并配置
            if (! empty($configsToMerge)) {
                config($configsToMerge);
            }
        } catch (\Throwable) {
            // 静默处理
        }
    }

    /**
     * 发现并加载路由文件
     */
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
            'admin' => ['web', 'admin'],
        ]);

        try {
            $routeFiles = File::files($routesPath);
            $router = app('router');

            foreach ($routeFiles as $routeFile) {
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

                $routeGroup = $router;

                if (! empty($middleware)) {
                    $routeGroup = $routeGroup->middleware($middleware);
                }

                if (! empty($controllerNamespace)) {
                    $routeGroup = $routeGroup->namespace($fullNamespace);
                }

                try {
                    $routeGroup->group(function () use ($routeFile) {
                        require $routeFile->getPathname();
                    });

                    $this->discoveryCache["route.{$filename}"] = true;
                } catch (\Throwable) {
                    // 静默处理
                }
            }
        } catch (\Throwable) {
            // 静默处理
        }
    }

    /**
     * 自动检测控制器命名空间
     */
    protected function autoDetectControllerNamespace(string $routeFilename): string
    {
        $standardNames = ['web', 'api', 'admin'];
        $subNamespace = ucfirst($routeFilename);

        if (in_array(strtolower($routeFilename), $standardNames, true)) {
            $controllerPath = $this->module->getPath('Http/Controllers/' . $subNamespace);

            return is_dir($controllerPath) ? '\\' . $subNamespace : '';
        }

        $controllerPath = $this->module->getPath('Http/Controllers/' . $subNamespace);

        return is_dir($controllerPath) ? '\\' . $subNamespace : '';
    }

    /**
     * 发现并注册视图命名空间
     */
    protected function discoverViews(): void
    {
        if (! $this->shouldDiscover('views')) {
            return;
        }

        $possiblePaths = [
            $this->module->getPath('Resources/views'),
            $this->module->getPath('resources/views'),
            $this->module->getPath('views'),
        ];

        $viewsPath = $this->findFirstExistingPath($possiblePaths);

        if (! $viewsPath) {
            return;
        }

        $namespaceFormat = config('modules.views.namespace_format', 'lower');

        $viewNamespace = match($namespaceFormat) {
            'lower' => strtolower($this->module->getName()),
            'studly' => $this->module->getName(),
            'camel' => lcfirst($this->module->getName()),
            default => strtolower($this->module->getName()),
        };

        try {
            app('view')->addNamespace($viewNamespace, $viewsPath);
            $this->discoveryCache['view'] = true;
        } catch (\Throwable) {
            // 静默处理
        }
    }

    /**
     * 发现并注册迁移路径
     */
    protected function discoverMigrations(): void
    {
        if (! $this->shouldDiscover('migrations')) {
            return;
        }

        $possiblePaths = [
            $this->module->getPath('Database/Migrations'),
            $this->module->getPath('database/migrations'),
        ];

        $migrationsPath = $this->findFirstExistingPath($possiblePaths);

        if (! $migrationsPath) {
            return;
        }

        try {
            $migrator = app('migrator');
            $existingPaths = $migrator->paths();

            if (in_array($migrationsPath, $existingPaths, true)) {
                return;
            }

            $migrator->path($migrationsPath);
            $this->discoveryCache['migration'] = true;
        } catch (\Throwable) {
            // 静默处理
        }
    }

    /**
     * 发现并注册翻译命名空间
     */
    protected function discoverTranslations(): void
    {
        if (! $this->shouldDiscover('translations')) {
            return;
        }

        $possiblePaths = [
            $this->module->getPath('Resources/lang'),
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

            $namespace = $this->module->getLowerName();
            $translator->addNamespace($namespace, $langPath);

            $this->discoveryCache['translation'] = true;
        } catch (\Throwable) {
            // 静默处理
        }
    }

    /**
     * 发现并注册 Artisan 命令
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
            ['path' => $this->module->getPath('Console/Commands'), 'namespace' => '\\Console\\Commands'],
            ['path' => $this->module->getPath('Commands'), 'namespace' => '\\Commands'],
        ];

        $foundCommands = [];

        foreach ($possiblePaths as $pathInfo) {
            $commandsPath = $pathInfo['path'];
            $namespace = $pathInfo['namespace'];

            if (! is_dir($commandsPath)) {
                continue;
            }

            try {
                $commandFiles = File::files($commandsPath);

                foreach ($commandFiles as $commandFile) {
                    if ($commandFile->getExtension() !== 'php') {
                        continue;
                    }

                    $className = $commandFile->getBasename('.php');
                    $commandClass = $this->module->getClassNamespace() . $namespace . '\\' . $className;

                    if (! $this->classExists($commandClass)) {
                        continue;
                    }

                    try {
                        $reflection = new \ReflectionClass($commandClass);

                        if ($reflection->isSubclassOf(\Illuminate\Console\Command::class) && ! $reflection->isAbstract()) {
                            $foundCommands[] = $commandClass;
                        }
                    } catch (\ReflectionException) {
                        // 静默处理
                    }
                }
            } catch (\Throwable) {
                // 静默处理
            }
        }

        // 添加到全局缓存
        foreach ($foundCommands as $commandClass) {
            if (! in_array($commandClass, self::$globalCommands, true)) {
                self::$globalCommands[] = $commandClass;
            }
        }

        $this->discoveryCache['commands'] = $foundCommands;
    }

    /**
     * 发现并注册事件和监听器
     */
    protected function discoverEvents(): void
    {
        if (! $this->shouldDiscover('events')) {
            return;
        }

        // Laravel 11+ 自动发现 Events 和 Listeners
        // 这里仅记录日志，实际注册由 Laravel 处理
        $eventsPath = $this->module->getPath('Events');

        if (! is_dir($eventsPath)) {
            return;
        }

        try {
            $eventFiles = File::files($eventsPath);
            $eventClasses = [];

            foreach ($eventFiles as $eventFile) {
                if ($eventFile->getExtension() !== 'php') {
                    continue;
                }

                $className = $eventFile->getBasename('.php');
                $eventClass = $this->module->getClassNamespace() . '\\Events\\' . $className;

                if ($this->classExists($eventClass)) {
                    $eventClasses[] = $eventClass;
                }
            }

            $this->discoveryCache['events'] = $eventClasses;
        } catch (\Throwable) {
            // 静默处理
        }
    }

    /**
     * 发现并注册中间件
     */
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
                $middlewareFiles = File::files($middlewarePath);

                foreach ($middlewareFiles as $middlewareFile) {
                    if ($middlewareFile->getExtension() !== 'php') {
                        continue;
                    }

                    $className = $middlewareFile->getBasename('.php');
                    $middlewareClass = $this->module->getClassNamespace() . '\\Http\\Middleware\\' . $className;

                    if (! $this->classExists($middlewareClass)) {
                        $middlewareClass = $this->module->getClassNamespace() . '\\Http\\Filters\\' . $className;
                    }

                    if ($this->classExists($middlewareClass)) {
                        $this->discoveryCache["middleware.{$className}"] = $middlewareClass;
                    }
                }
            } catch (\Throwable) {
                // 静默处理
            }
        }
    }

    /**
     * 发现并注册模型观察者
     */
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
            $observerFiles = File::files($observersPath);
            $observers = [];

            foreach ($observerFiles as $observerFile) {
                if ($observerFile->getExtension() !== 'php') {
                    continue;
                }

                $className = $observerFile->getBasename('.php');
                $observerClass = $this->module->getClassNamespace() . '\\Observers\\' . $className;

                if ($this->classExists($observerClass)) {
                    $observers[$className] = $observerClass;
                }
            }

            // 注册观察者到模型
            foreach ($observers as $observerName => $observerClass) {
                $modelName = str_replace('Observer', '', $observerName);
                $modelClass = $this->module->getClassNamespace() . '\\Models\\' . $modelName;

                if ($this->classExists($modelClass)) {
                    try {
                        $modelClass::observe($observerClass);
                        $this->discoveryCache["observer.{$modelName}"] = $observerClass;
                    } catch (\Throwable) {
                        // 静默处理
                    }
                }
            }
        } catch (\Throwable) {
            // 静默处理
        }
    }

    /**
     * 发现并注册策略类
     */
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
            $policyFiles = File::files($policiesPath);
            $policies = [];

            foreach ($policyFiles as $policyFile) {
                if ($policyFile->getExtension() !== 'php') {
                    continue;
                }

                $className = $policyFile->getBasename('.php');
                $policyClass = $this->module->getClassNamespace() . '\\Policies\\' . $className;

                if ($this->classExists($policyClass)) {
                    $policies[$className] = $policyClass;
                }
            }

            // 注册策略到 Gate
            foreach ($policies as $policyName => $policyClass) {
                $modelName = str_replace('Policy', '', $policyName);
                $modelClass = $this->module->getClassNamespace() . '\\Models\\' . $modelName;

                if ($this->classExists($modelClass)) {
                    try {
                        Gate::policy($modelClass, $policyClass);
                        $this->discoveryCache["policy.{$modelName}"] = $policyClass;
                    } catch (\Throwable) {
                        // 静默处理
                    }
                }
            }
        } catch (\Throwable) {
            // 静默处理
        }
    }

    /**
     * 发现并注册仓库类
     */
    protected function discoverRepositories(): void
    {
        if (! $this->shouldDiscover('repositories')) {
            return;
        }

        $repositoriesPath = $this->module->getPath('Repositories');

        if (! is_dir($repositoriesPath)) {
            return;
        }

        try {
            $repositoryFiles = File::files($repositoriesPath);
            $repositories = [];

            foreach ($repositoryFiles as $repositoryFile) {
                if ($repositoryFile->getExtension() !== 'php') {
                    continue;
                }

                $className = $repositoryFile->getBasename('.php');
                $repositoryClass = $this->module->getClassNamespace() . '\\Repositories\\' . $className;

                if ($this->classExists($repositoryClass)) {
                    $repositories[$className] = $repositoryClass;
                }
            }

            $this->discoveryCache['repositories'] = $repositories;
        } catch (\Throwable) {
            // 静默处理
        }
    }

    /**
     * 记录日志
     */
    protected function log(string $message): void
    {
        if (! config('app.debug', false)) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $this->logs[$timestamp] = $message;
    }

    /**
     * 判断是否应该发现指定类型的组件
     */
    protected function shouldDiscover(string $type): bool
    {
        return config("modules.discovery.{$type}", true);
    }

    /**
     * 获取发现缓存
     */
    public function getCache(): array
    {
        return $this->discoveryCache;
    }

    /**
     * 清空发现缓存
     */
    public function clearCache(): void
    {
        $this->discoveryCache = [];
    }

    /**
     * 获取发现日志
     */
    public function getLogs(): array
    {
        return $this->logs;
    }

    /**
     * 获取全局命令缓存
     *
     * @return array<string>
     */
    public static function getGlobalCommands(): array
    {
        return self::$globalCommands;
    }

    /**
     * 清空全局命令缓存
     */
    public static function clearGlobalCommands(): void
    {
        self::$globalCommands = [];
        self::$classExistenceCache = [];
        self::$staticCache = [];
    }

    /**
     * 获取模块的发现摘要
     */
    public function getDiscoverySummary(): array
    {
        return [
            'module' => $this->module->getName(),
            'enabled' => $this->module->isEnabled(),
            'providers_count' => count(array_filter($this->discoveryCache, fn ($k) => str_starts_with($k, 'provider.'), ARRAY_FILTER_USE_KEY)),
            'configs' => array_keys(array_filter($this->discoveryCache, fn ($k) => str_starts_with($k, 'config.'), ARRAY_FILTER_USE_KEY)),
            'routes' => array_keys(array_filter($this->discoveryCache, fn ($k) => str_starts_with($k, 'route.'), ARRAY_FILTER_USE_KEY)),
            'middlewares' => array_keys(array_filter($this->discoveryCache, fn ($k) => str_starts_with($k, 'middleware.'), ARRAY_FILTER_USE_KEY)),
            'views' => isset($this->discoveryCache['view']) ? 'registered' : 'not found',
            'migrations' => isset($this->discoveryCache['migration']) ? 'registered' : 'not found',
            'translations' => isset($this->discoveryCache['translation']) ? 'registered' : 'not found',
            'commands_count' => is_array($this->discoveryCache['commands'] ?? null) ? count($this->discoveryCache['commands']) : 0,
            'events_count' => is_array($this->discoveryCache['events'] ?? null) ? count($this->discoveryCache['events']) : 0,
            'observers_count' => count(array_filter($this->discoveryCache, fn ($k) => str_starts_with($k, 'observer.'), ARRAY_FILTER_USE_KEY)),
            'policies_count' => count(array_filter($this->discoveryCache, fn ($k) => str_starts_with($k, 'policy.'), ARRAY_FILTER_USE_KEY)),
            'repositories_count' => is_array($this->discoveryCache['repositories'] ?? null) ? count($this->discoveryCache['repositories']) : 0,
            'logs_count' => count($this->logs),
        ];
    }

    /**
     * 查找第一个存在的路径
     */
    protected function findFirstExistingPath(array $possiblePaths): ?string
    {
        foreach ($possiblePaths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * 清空类存在性缓存
     */
    public static function clearClassCache(): void
    {
        self::$classExistenceCache = [];
    }

    /**
     * 获取类存在性缓存统计
     */
    public static function getClassCacheStats(): array
    {
        return [
            'size' => count(self::$classExistenceCache),
            'keys' => array_keys(self::$classExistenceCache),
        ];
    }
}
