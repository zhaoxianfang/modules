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
 * 性能说明（v5.x）：
 * - 当 modules.cache.enabled=true 时，扫描得到的「发现清单」会被持久化到
 *   storage/framework/cache/modules/discovery.php。
 * - 后续每个请求/命令直接读取清单并「注册」，跳过 File::files() 目录扫描、
 *   class_exists() 自动加载探测与 ReflectionClass 反射，显著降低 IO 与 CPU 开销。
 * - 清单与模块元数据缓存同生命周期：module:cache 重建、module:clear 清除。
 * - 任何清单读取/解析失败都会安全回退到实时扫描，绝不阻断启动。
 *
 * @package zxf\Modules
 * @version 5.0.0
 */
class ModuleAutoDiscovery
{
    /**
     * 全局命令缓存
     *
     * @var array<int, string>
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
     * 发现缓存（调试/摘要用，键 => 值）
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
     * 结构化发现结果（同时用于清单缓存与统计摘要）
     *
     * @var array<string, mixed>
     */
    protected array $discovered = [
        'providers'       => [],
        'aliases'         => [],
        'commands'        => [],
        'events'          => [],
        'repositories'    => [],
        'observers'       => [],
        'policies'        => [],
        'configs'         => [],
        'routes'          => [],
        'view_path'       => null,
        'migration_path'  => null,
        'translation_path' => null,
    ];

    /**
     * 发现清单持久化缓存（模块名 => discovered 数组）
     *
     * @var array<string, array<string, mixed>>
     */
    protected static array $manifestCache = [];

    /**
     * 清单是否已从文件加载
     */
    protected static bool $manifestLoaded = false;

    /**
     * 清单是否有改动待写入
     */
    protected static bool $manifestDirty = false;

    /**
     * 创建新实例
     */
    public function __construct(ModuleInterface $module)
    {
        $this->module = $module;
        $this->app = app();
        $this->cacheEnabled = (bool) ModuleContext::getConfig('modules.cache.enabled', false);
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

        // 命中持久化清单：直接注册，跳过全部目录扫描与自动加载探测
        if ($this->cacheEnabled) {
            $this->loadManifestCache();
            $name = $this->module->getName();

            if (isset(self::$manifestCache[$name]) && is_array(self::$manifestCache[$name])) {
                $this->discovered = self::$manifestCache[$name];
                $this->registerAll();

                $this->triggerHook('after_discover', [
                    'module' => $name,
                    'cache' => $this->cache,
                    'from_cache' => true,
                ]);

                return;
            }
        }

        // 实时扫描并注册
        $this->scanAll();
        $this->registerAll();

        if ($this->cacheEnabled) {
            $this->persistManifest();
        }

        $this->triggerHook('after_discover', [
            'module' => $this->module->getName(),
            'cache' => $this->cache,
        ]);
    }

    /**
     * 扫描所有组件（仅探测，不注册）
     */
    protected function scanAll(): void
    {
        $this->scanProviders();
        $this->scanConfigs();
        $this->scanMiddlewares();
        $this->scanRoutes();
        $this->scanViews();
        $this->scanMigrations();
        $this->scanTranslations();

        if ($this->app->runningInConsole()) {
            $this->scanCommands();
        }

        $this->scanEvents();
        $this->scanObservers();
        $this->scanPolicies();
        $this->scanRepositories();
    }

    /**
     * 注册所有已发现的组件
     *
     * 注册是「运行时状态」，不随缓存持久化，因此无论是否命中清单都必须执行。
     */
    protected function registerAll(): void
    {
        $this->registerProviders();
        $this->registerConfigs();
        $this->registerRoutes();
        $this->registerViews();
        $this->registerMigrations();
        $this->registerTranslations();

        if ($this->app->runningInConsole()) {
            $this->registerCommands();
        }

        $this->registerObservers();
        $this->registerPolicies();
    }

    // ========================================================================
    //  服务提供者
    // ========================================================================

    protected function scanProviders(): void
    {
        if (! $this->shouldDiscover('providers')) {
            return;
        }

        foreach ($this->module->getLaravelProviders() as $providerClass) {
            if (is_string($providerClass) && class_exists($providerClass)) {
                $this->discovered['providers'][] = $providerClass;
                $this->cache["provider.extra.{$providerClass}"] = $providerClass;
            }
        }

        foreach ($this->module->getLaravelAliases() as $alias => $class) {
            if (is_string($alias) && is_string($class) && class_exists($class)) {
                $this->discovered['aliases'][$alias] = $class;
                $this->cache["alias.{$alias}"] = $class;
            }
        }

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
                    $this->discovered['providers'][] = $providerClass;
                    $this->cache["provider.{$className}"] = $providerClass;
                }
            }
        } catch (\Throwable $e) {
            $this->log("Provider discovery error: {$e->getMessage()}");
        }
    }

    protected function registerProviders(): void
    {
        foreach (array_unique($this->discovered['providers']) as $providerClass) {
            try {
                $this->app->register($providerClass);
                $this->log("Registered provider: {$providerClass}");
            } catch (\Throwable $e) {
                $this->log("Provider registration error: {$e->getMessage()}");
            }
        }

        foreach ($this->discovered['aliases'] as $alias => $class) {
            try {
                if (method_exists($this->app, 'alias')) {
                    $this->app->alias($class, $alias);
                } else {
                    $this->app->singleton($alias, fn () => $this->app->make($class));
                }
                $this->log("Registered alias: {$alias} => {$class}");
            } catch (\Throwable) {
                try {
                    $this->app->singleton($alias, fn () => $this->app->make($class));
                } catch (\Throwable) {
                    // 静默失败
                }
            }
        }
    }

    // ========================================================================
    //  配置文件
    // ========================================================================

    protected function scanConfigs(): void
    {
        if (! $this->shouldDiscover('config')) {
            return;
        }

        $configPath = $this->module->getConfigPath();

        if (! is_dir($configPath)) {
            return;
        }

        $moduleLower = $this->module->getLowerName();

        try {
            $files = File::files($configPath);

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $filename = $file->getBasename('.php');
                $isMain = (strtolower($filename) === $moduleLower || $filename === 'config');

                // 主配置文件已由 Module::initialize() 加载，直接复用，避免重复 require
                $configValue = $isMain
                    ? $this->module->getModuleConfig()
                    : require $file->getPathname();

                if (! is_array($configValue)) {
                    continue;
                }

                $configKey = $isMain ? $moduleLower : $moduleLower . '.' . strtolower($filename);

                $this->discovered['configs'][$configKey] = $configValue;
                $this->cache["config.{$configKey}"] = true;
                $this->log("Loaded config: {$configKey}");
            }
        } catch (\Throwable $e) {
            $this->log("Config discovery error: {$e->getMessage()}");
        }
    }

    protected function registerConfigs(): void
    {
        foreach ($this->discovered['configs'] as $configKey => $configValue) {
            if (! is_array($configValue)) {
                continue;
            }

            // 合并配置：后加载的覆盖先加载的
            config([$configKey => array_merge(config($configKey, []), $configValue)]);
        }
    }

    // ========================================================================
    //  中间件
    // ========================================================================

    protected function scanMiddlewares(): void
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

    protected function scanRoutes(): void
    {
        if (! $this->shouldDiscover('routes')) {
            return;
        }

        $routesPath = $this->module->getRoutesPath();

        if (! is_dir($routesPath)) {
            return;
        }

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

                $this->discovered['routes'][] = $filename;
                $this->cache["route.{$filename}"] = true;
            }
        } catch (\Throwable $e) {
            $this->log("Route discovery error: {$e->getMessage()}");
        }
    }

    protected function registerRoutes(): void
    {
        if (empty($this->discovered['routes'])) {
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
            foreach ($this->discovered['routes'] as $filename) {
                $file = $routesPath . DIRECTORY_SEPARATOR . $filename . '.php';

                if (! file_exists($file)) {
                    continue;
                }

                $middleware = $middlewareGroups[$filename] ?? [];
                $router = app('router');
                $routeGroup = $router;

                if (! empty($middleware)) {
                    $routeGroup = $routeGroup->middleware($middleware);
                }

                $routeGroup->group(function () use ($file) {
                    require $file;
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

    protected function scanViews(): void
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

        $this->discovered['view_path'] = $viewsPath;
        $this->cache['view'] = true;
    }

    protected function registerViews(): void
    {
        $viewsPath = $this->discovered['view_path'] ?? null;

        if (! is_string($viewsPath) || ! is_dir($viewsPath)) {
            return;
        }

        $namespaceFormat = config('modules.views.namespace_format', 'lower');

        $viewNamespace = match ($namespaceFormat) {
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

    protected function scanMigrations(): void
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

        $this->discovered['migration_path'] = $migrationsPath;
        $this->cache['migration'] = true;
    }

    protected function registerMigrations(): void
    {
        $migrationsPath = $this->discovered['migration_path'] ?? null;

        if (! is_string($migrationsPath) || ! is_dir($migrationsPath)) {
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

    protected function scanTranslations(): void
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

        $this->discovered['translation_path'] = $langPath;
        $this->cache['translation'] = true;
    }

    protected function registerTranslations(): void
    {
        $langPath = $this->discovered['translation_path'] ?? null;

        if (! is_string($langPath) || ! is_dir($langPath)) {
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
     * 扫描并注册 Artisan 命令
     *
     * 公开方法，供 module:debug-commands 等直接调用。
     */
    public function discoverCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        if (! $this->shouldDiscover('commands')) {
            return;
        }

        $this->scanCommands();
        $this->registerCommands();
    }

    protected function scanCommands(): void
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
                        $this->discovered['commands'][] = $commandClass;
                    }
                }
            } catch (\Throwable $e) {
                $this->log("Command scan error: {$e->getMessage()}");
            }
        }
    }

    protected function registerCommands(): void
    {
        if (empty($this->discovered['commands'])) {
            return;
        }

        $foundCommands = array_values(array_unique($this->discovered['commands']));

        // 去重并添加到全局缓存
        foreach ($foundCommands as $commandClass) {
            if (! in_array($commandClass, self::$globalCommands, true)) {
                self::$globalCommands[] = $commandClass;
            }
        }

        $this->cache['commands'] = $foundCommands;

        // 注册命令到 Artisan（通过 Kernel，而非 Application）
        try {
            $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
            foreach ($foundCommands as $commandClass) {
                try {
                    $kernel->registerCommand($this->app->make($commandClass));
                } catch (\Throwable $e) {
                    $this->log("Command registration failed: {$e->getMessage()}");
                }
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

    // ========================================================================
    //  事件
    // ========================================================================

    protected function scanEvents(): void
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

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $className = $file->getBasename('.php');
                $eventClass = $this->module->getClassNamespace() . '\\Events\\' . $className;

                if (class_exists($eventClass)) {
                    $this->discovered['events'][] = $eventClass;
                    $this->cache["event.{$className}"] = $eventClass;
                }
            }
        } catch (\Throwable $e) {
            $this->log("Event discovery error: {$e->getMessage()}");
        }
    }

    // ========================================================================
    //  模型观察者
    // ========================================================================

    protected function scanObservers(): void
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

                // 使用正则只替换后缀，防止类名中间的 "Observer" 被误替换（如 ObserverObserver）
                $modelName = preg_replace('/Observer$/', '', $className);
                $modelClass = $this->module->getClassNamespace() . '\\Models\\' . $modelName;

                if (class_exists($modelClass)) {
                    $this->discovered['observers'][] = [
                        'model' => $modelClass,
                        'observer' => $observerClass,
                    ];
                    $this->cache["observer.{$modelName}"] = $observerClass;
                }
            }
        } catch (\Throwable $e) {
            $this->log("Observer discovery error: {$e->getMessage()}");
        }
    }

    protected function registerObservers(): void
    {
        foreach ($this->discovered['observers'] as $item) {
            try {
                ($item['model'])::observe($item['observer']);
                $this->log("Registered observer: {$item['observer']}");
            } catch (\Throwable $e) {
                $this->log("Observer registration error: {$e->getMessage()}");
            }
        }
    }

    // ========================================================================
    //  策略类
    // ========================================================================

    protected function scanPolicies(): void
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

                // 使用正则只替换后缀，防止类名中间的 "Policy" 被误替换
                $modelName = preg_replace('/Policy$/', '', $className);
                $modelClass = $this->module->getClassNamespace() . '\\Models\\' . $modelName;

                if (class_exists($modelClass)) {
                    $this->discovered['policies'][] = [
                        'model' => $modelClass,
                        'policy' => $policyClass,
                    ];
                    $this->cache["policy.{$modelName}"] = $policyClass;
                }
            }
        } catch (\Throwable $e) {
            $this->log("Policy discovery error: {$e->getMessage()}");
        }
    }

    protected function registerPolicies(): void
    {
        foreach ($this->discovered['policies'] as $item) {
            try {
                Gate::policy($item['model'], $item['policy']);
                $this->log("Registered policy: {$item['policy']}");
            } catch (\Throwable $e) {
                $this->log("Policy registration error: {$e->getMessage()}");
            }
        }
    }

    // ========================================================================
    //  仓库类
    // ========================================================================

    protected function scanRepositories(): void
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

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $className = $file->getBasename('.php');
                $repoClass = $this->module->getClassNamespace() . '\\Repositories\\' . $className;

                if (class_exists($repoClass)) {
                    $this->discovered['repositories'][$className] = $repoClass;
                    $this->cache["repository.{$className}"] = $repoClass;
                }
            }
        } catch (\Throwable $e) {
            $this->log("Repository discovery error: {$e->getMessage()}");
        }
    }

    // ========================================================================
    //  清单缓存（性能核心）
    // ========================================================================

    /**
     * 获取清单缓存文件路径
     */
    protected static function getManifestPath(): string
    {
        $dir = ModuleContext::getConfig('modules.cache.path', storage_path('framework/cache/modules'));

        return rtrim((string) $dir, '/\\') . '/discovery.php';
    }

    /**
     * 从文件加载清单缓存（进程内仅加载一次）
     */
    protected static function loadManifestCache(): void
    {
        if (self::$manifestLoaded) {
            return;
        }

        self::$manifestLoaded = true;

        $file = self::getManifestPath();

        if (! file_exists($file)) {
            return;
        }

        try {
            $data = require $file;

            if (is_array($data)) {
                self::$manifestCache = $data;
            }
        } catch (\Throwable) {
            self::$manifestCache = [];
        }
    }

    /**
     * 将当前模块的发现结果写入清单缓存
     */
    protected function persistManifest(): void
    {
        $name = $this->module->getName();
        self::$manifestCache[$name] = $this->discovered;
        self::$manifestDirty = true;

        $this->saveManifestCache();
    }

    /**
     * 将内存中的清单缓存落盘
     */
    protected static function saveManifestCache(): void
    {
        if (! self::$manifestDirty) {
            return;
        }

        $file = self::getManifestPath();
        self::$manifestDirty = false;

        try {
            $dir = dirname($file);
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $content = '<?php return ' . var_export(self::$manifestCache, true) . ';';
            file_put_contents($file, $content, LOCK_EX);
        } catch (\Throwable) {
            // 缓存写入失败不中断
        }
    }

    /**
     * 预热单个模块的发现清单（用于 module:cache）
     *
     * 仅扫描并持久化，不注册，避免在 CLI 中产生副作用。
     */
    public static function warm(ModuleInterface $module): void
    {
        if (! (bool) ModuleContext::getConfig('modules.cache.enabled', false)) {
            return;
        }

        try {
            $instance = new self($module);
            $instance->scanAll();
            $instance->persistManifest();
        } catch (\Throwable) {
            // 预热失败不影响主流程
        }
    }

    /**
     * 清除发现清单缓存
     */
    public static function clearDiscoveryCache(): void
    {
        self::$manifestCache = [];
        self::$manifestLoaded = false;
        self::$manifestDirty = false;

        $file = self::getManifestPath();

        if (file_exists($file)) {
            @unlink($file);
        }
    }

    // ========================================================================
    //  辅助方法
    // ========================================================================

    protected function shouldDiscover(string $type): bool
    {
        return (bool) ModuleContext::getConfig("modules.discovery.{$type}", true);
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
        $this->discovered = [
            'providers' => [], 'aliases' => [], 'commands' => [], 'events' => [],
            'repositories' => [], 'observers' => [], 'policies' => [], 'configs' => [],
            'routes' => [], 'view_path' => null, 'migration_path' => null, 'translation_path' => null,
        ];
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
            'providers' => count($this->discovered['providers']),
            'configs' => array_keys($this->discovered['configs']),
            'routes' => $this->discovered['routes'],
            'views' => $this->discovered['view_path'] !== null,
            'migrations' => $this->discovered['migration_path'] !== null,
            'translations' => $this->discovered['translation_path'] !== null,
            'commands' => count($this->discovered['commands']),
            'events' => count($this->discovered['events']),
            'observers' => count($this->discovered['observers']),
            'policies' => count($this->discovered['policies']),
            'repositories' => count($this->discovered['repositories']),
        ];
    }
}
