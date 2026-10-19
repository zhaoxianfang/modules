<?php

declare(strict_types=1);

namespace zxf\Modules\Support;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use zxf\Modules\Contracts\ModuleInterface;

/**
 * 模块自动发现器
 * 提供智能的模块组件自动发现和加载能力。
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
 * 性能说明（v5.x）：
 * - 当 modules.cache.enabled=true 时，扫描得到的「发现清单」会被持久化到
 *   storage/framework/cache/modules/discovery.json（JSON 格式，原子写入）。
 * - 后续每个请求/命令直接读取清单并「注册」，跳过 SafeFilesystem::files() 目录扫描、
 * - 清单与模块元数据缓存同生命周期：module:cache 重建、module:clear 清除。
 * - 任何清单读取/解析失败都会安全回退到实时扫描，绝不阻断启动。
 *
 * @version 1.13.9
 */
class ModuleAutoDiscovery
{
    /**
     * 框架在运行期注入 config('app') 的键，模块配置合并时必须原样保留
     *
     * 典型是 **providers**，它的生命周期完全由 Laravel 掌控：
     *
     *   1. `Illuminate\Foundation\Bootstrap\RegisterProviders::mergeAdditionalProviders()`
     *      在 bootstrap 阶段把「默认 provider + 合并项 + bootstrap/providers.php」
     *      写进 `config('app.providers')`；
     *   2. 但 `RegisterProviders::bootstrap()` 只在**配置未被缓存**时才执行这一步：
     *          if (! $app->bound('config_loaded_from_cache')
     *              || $app->make('config_loaded_from_cache') === false) { ... }
     *      即 `php artisan config:cache` 写出的配置文件**必须自带 app.providers**，
     *      否则从下一次启动起，所有 Illuminate 核心 provider 都不会被注册；
     *   3. 核心 provider 不注册 → files / validator / view / db / cache / session
     *      等服务全部没有绑定 → 谁先解析谁抛：
     *          Target class [files] does not exist.
     *          Class "files" does not exist
     *      表现为 `php artisan optimize`、`php artisan` 等任意命令直接失败退出。
     *
     * 所以模块配置文件里即便声明了同名键（很容易与模块自身语义撞名），
     * 也**不得**覆盖框架运行时写入的值。
     *
     * @var array<int, string>
     */
    protected const FRAMEWORK_RUNTIME_APP_KEYS = [
        'providers',
    ];

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
    /**
     * 容器实例
     *
     * 类型由 Foundation\Application 放宽为 Container 基类。
     * 该契约只有 Illuminate\Foundation\Application 实现，裸容器赋值会直接抛
     *     TypeError: Cannot assign Illuminate\Container\Container to property
     *     ModuleAutoDiscovery::$app of type Illuminate\Contracts\Foundation\Application
     * 本类只用到 alias() / make() / register() / singleton()，全部在基类上。
     */
    protected Container $app;

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
        'providers' => [],
        'aliases' => [],
        'commands' => [],
        'events' => [],
        'repositories' => [],
        'observers' => [],
        'policies' => [],
        'configs' => [],
        'routes' => [],
        'view_path' => null,
        'migration_path' => null,
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
     * @param  string  $event  事件名: before_discover, after_discover, before_load, after_load
     * @param  callable  $callback  回调函数
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
        // 一次性建立模块目录快照：本次发现周期内所有子目录的「存在性 + 文件列表」
        // 都从这里取，避免 scanXxx() 各自重复 is_dir() + FilesystemIterator。
        // 12 个扫描器 → 最多只需遍历实际存在的那些目录一次。
        $this->buildDirectorySnapshot();

        $this->scanProviders();
        $this->scanConfigs();
        $this->scanMiddlewares();
        $this->scanRoutes();
        $this->scanViews();
        $this->scanMigrations();
        $this->scanTranslations();

        if (ModuleContext::runningInConsole()) {
            $this->scanCommands();
        }

        $this->scanEvents();
        $this->scanObservers();
        $this->scanPolicies();
        $this->scanRepositories();
    }

    /**
     * 建立模块子目录快照
     *
     * 以「归一化后的绝对路径」为 key，因此无论扫描器用 getProvidersPath() 还是
     * getPath('Providers') 拼路径，都能命中同一份结果。
     *
     * @var array<string, \Symfony\Component\Finder\SplFileInfo[]|null> 目录 => 文件列表（null = 目录不存在）
     */
    private array $directorySnapshot = [];

    /**
     * 预扫描模块子目录，把「是否存在」与「文件列表」缓存到本次发现的快照里
     *
     * 收益：原实现里 scanProviders/scanConfigs/scanEvents/... 各自
     * is_dir() + SafeFilesystem::files()，同一模块最多 12 次 stat + 12 次目录遍历；
     * 现在每个目录至多遍历一次，模块数量越多、目录越大，节省越明显。
     */
    protected function buildDirectorySnapshot(): void
    {
        if ($this->directorySnapshot !== []) {
            return;
        }

        foreach ($this->snapshotTargets() as $dir) {
            if (! is_string($dir) || $dir === '') {
                continue;
            }

            $key = $this->normalizeForCompare($dir);

            if (array_key_exists($key, $this->directorySnapshot)) {
                continue;
            }

            if (! is_dir($dir)) {
                $this->directorySnapshot[$key] = null;

                continue;
            }

            try {
                $this->directorySnapshot[$key] = SafeFilesystem::files($dir);
            } catch (\Throwable) {
                $this->directorySnapshot[$key] = null;
            }
        }
    }

    /**
     * 本次发现周期需要扫描的全部目录（绝对路径）
     *
     * @return array<int, string>
     */
    protected function snapshotTargets(): array
    {
        $dirs = [
            $this->module->getProvidersPath(),
            $this->module->getConfigPath(),
            $this->module->getRoutesPath(),
            $this->module->getCommandsPath(),
            $this->module->getViewsPath(),
            $this->module->getMigrationsPath(),
            $this->module->getLangPath(),
        ];

        // 其余组件目录没有专用 getter，按约定目录名补齐
        foreach (['Http/Middleware', 'Http/Filters', 'Events', 'Listeners', 'Observers', 'Policies', 'Repositories'] as $relative) {
            $dirs[] = $this->module->getPath($relative);
        }

        return $dirs;
    }

    /**
     * 从快照读取目录文件列表
     *
     * 快照尚未建立时（例如某个扫描器被单独调用、或 discoverAll 走了缓存命中分支）
     * **必须回退到真实读取**，绝不能返回空数组 —— 那会让组件被静默丢弃。
     *
     * @return array<int, \Symfony\Component\Finder\SplFileInfo>
     */
    protected function snapshotFiles(string $dir): array
    {
        if (! is_string($dir) || $dir === '') {
            return [];
        }

        $key = $this->normalizeForCompare($dir);

        if (array_key_exists($key, $this->directorySnapshot)) {
            return $this->directorySnapshot[$key] ?? [];
        }

        // 未建立快照：实时读取并补写快照（下次即可零 IO 命中）
        if (! is_dir($dir)) {
            return [];
        }

        try {
            $files = $this->snapshotFiles($dir);
        } catch (\Throwable) {
            return [];
        }

        $this->directorySnapshot[$key] = $files;

        return $files;
    }

    /**
     * 归一化路径用于快照 key（与 PathGuard 同口径：统一分隔符、去尾斜杠）
     */
    private function normalizeForCompare(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    /**
     * 注册所有已发现的组件
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

        if (ModuleContext::runningInConsole()) {
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
            $files = $this->snapshotFiles($providersPath);

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $className = $file->getBasename('.php');
                $providerClass = $this->module->getClassNamespace().'\\Providers\\'.$className;

                if (! class_exists($providerClass)) {
                    continue;
                }

                $reflection = new \ReflectionClass($providerClass);

                if ($reflection->isSubclassOf(ServiceProvider::class) && ! $reflection->isAbstract()) {
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
            $files = $this->snapshotFiles($configPath);

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

                $configKey = $isMain ? $moduleLower : $moduleLower.'.'.strtolower($filename);

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

            // 本方法是整个类里**唯一没有 try/catch** 的注册方法。
            // 而 config() 解析链路上任何一个键拿到非数组（例如某模块把
            // `config/foo.php` 写成了返回字符串），
            // array_merge() 就会抛 TypeError 并**直接冒泡出 boot()**，
            // 导致整个站点 500 —— 一个模块的配置文件写错，
            // 代价是全站不可用。
            // 现在隔离到单个配置键：失败只记录，不影响其它模块与本次启动。
            try {
                $existing = config($configKey, []);

                // 已有值不是数组时不能 merge（array_merge 会 TypeError），
                // 直接以模块配置覆盖。
                $merged = is_array($existing)
                    ? array_merge($existing, $configValue)
                    : $configValue;

                // 合并到 config('app') 时，必须保护框架运行时注入的键。
                // 详见 FRAMEWORK_RUNTIME_APP_KEYS 的说明：
                // 这里的值一旦丢失，config:cache 会写出残缺配置，
                // 下次启动所有 Illuminate 核心 provider 都不再注册，
                // 最终表现为 `php artisan optimize` 报
                //     Target class [files] does not exist.
                if ($configKey === 'app' && is_array($existing)) {
                    foreach (self::FRAMEWORK_RUNTIME_APP_KEYS as $protected) {
                        if (array_key_exists($protected, $existing)) {
                            $merged[$protected] = $existing[$protected];
                        }
                    }
                }

                config([$configKey => $merged]);
            } catch (\Throwable $e) {
                $this->log("Config [{$configKey}] merge failed: ".$e->getMessage());
            }
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
                $files = $this->snapshotFiles($middlewarePath);

                foreach ($files as $file) {
                    if ($file->getExtension() !== 'php') {
                        continue;
                    }

                    $className = $file->getBasename('.php');
                    $middlewareClass = $this->module->getClassNamespace().'\\Http\\Middleware\\'.$className;

                    if (! class_exists($middlewareClass)) {
                        $middlewareClass = $this->module->getClassNamespace().'\\Http\\Filters\\'.$className;
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

        $this->scanPhpFiles($routesPath, function (\SplFileInfo $routeFile) {
            $filename = $routeFile->getBasename('.php');

            if (str_starts_with($filename, '.')) {
                return;
            }

            $this->discovered['routes'][] = $filename;
            $this->cache["route.{$filename}"] = true;
        }, 'Route discovery');
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

        // 于是任意一个路由文件出错（语法错误 / 控制器不存在 / 路由名冲突），
        // 循环立刻中断 —— **该模块后续所有路由文件都不会被加载**，
        // 表现为一整片路由 404，而日志里只有一行笼统的
        // 「Route discovery error」，完全看不出是哪个文件出的问题。
        // 现在把 try/catch 下沉到循环内部：单个文件失败只跳过该文件。
        foreach ($this->discovered['routes'] as $filename) {
            // 路由文件名来自自动发现清单。
            // 实时扫描路径下做了 basename + 过滤，但清单缓存可能是
            // 旧版本/被篡改的 JSON，里面的 "../" 会直接拼成越界路径。
            $filename = basename((string) $filename);

            if ($filename === '' || $filename === '.' || $filename === '..') {
                continue;
            }

            $file = $routesPath.DIRECTORY_SEPARATOR.$filename.'.php';

            if (! is_file($file)) {
                continue;
            }

            try {
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
            } catch (\Throwable $e) {
                // 单个路由文件失败不影响其它文件（隔离到文件级）
                $this->log("Route file [{$filename}] load failed: ".$e->getMessage());
            }
        }

        //
        //
        // 三个事实让这段代码**在任何环境下都不可能成功**：
        //  1. Illuminate\Routing\Router 上**没有** attribute() 方法，
        //     该方法只存在于 RouteRegistrar（且签名是 attribute($key, $value)，
        //     → BadMethodCallException。
        //  2. 本宿主 Laravel 13 的 Illuminate\Routing\Attributes\ 目录下
        //     **只有 Controllers/**（Middleware、WithoutMiddleware），
        //     并不存在 Get / Post 这类「路由定义属性」。
        //  3. 上述异常又被这段代码自己的 try/catch 吞掉，
        //     只留一行 debug 日志 → 属性路由「静默失效」，
        //     模块开发者写了 #[Get] 却发现路由 404，且没有任何报错线索。
        //
        // 不调用任何不存在的 API，直接用标准 Route::group()
        // 带上模块前缀来 require 这些文件。
        // 这样文件里若写了普通的 Route::get(...)，会**正确生效**并自动带上前缀；
        // 同时把「属性路由」这一事实记录清楚，不再伪装成已支持。
        $attributesPath = $routesPath.DIRECTORY_SEPARATOR.'Attributes';
        if (is_dir($attributesPath)) {
            // app('router') 必须在 try 内解析：
            // Laravel 13 反转别名方向后，'router' 与 'files' 一样
            // 依赖 RoutingServiceProvider 真正注册 singleton，否则
            // make('router') 会抛 `Target class [router] does not exist.`。
            // 放在 try 外会让整个 boot() 中断（php artisan optimize 直接失败）。
            try {
                /** @var Router $router */
                $router = app('router');
            } catch (\Throwable $e) {
                $this->log("Router unavailable, skip attribute routes: {$e->getMessage()}");

                return;
            }

            $prefix = Str::snake($this->module->getName());

            try {
                $router->group(['prefix' => $prefix], function () use ($attributesPath) {
                    foreach (glob($attributesPath.DIRECTORY_SEPARATOR.'*.php') ?: [] as $attrFile) {
                        require $attrFile;
                    }
                });

                $this->log("Loaded routes from Attributes/ for module: {$this->module->getName()}");

                if (class_exists(\Illuminate\Routing\Attributes\Get::class)) {
                    $this->log('Attribute routing is available; #[Get]/#[Post] routes are honored.');
                } else {
                    $this->log(
                        '提示：当前 Laravel 版本未内置 #[Get]/#[Post] 路由定义属性，'
                        .'Attributes/ 下请使用标准 Route::get(...) 声明路由（已按模块前缀 "'.$prefix.'" 注册）。'
                    );
                }
            } catch (\Throwable $e) {
                $this->log("Attribute route load failed: {$e->getMessage()}");
            }
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
     * 公开方法，供 module:debug-commands 等直接调用。
     */
    public function discoverCommands(): void
    {
        if (! ModuleContext::runningInConsole()) {
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
        if (! ModuleContext::runningInConsole()) {
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
            $ns = $pathInfo['ns'];

            if (! is_dir($commandsPath)) {
                continue;
            }

            $this->scanPhpFiles($commandsPath, function (\SplFileInfo $file) use ($ns) {
                $className = $file->getBasename('.php');
                $commandClass = $this->module->getClassNamespace().$ns.'\\'.$className;

                if (! class_exists($commandClass)) {
                    return;
                }

                $reflection = new \ReflectionClass($commandClass);

                if ($reflection->isSubclassOf(Command::class) && ! $reflection->isAbstract()) {
                    $this->discovered['commands'][] = $commandClass;
                }
            }, 'Command scan');
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
            $kernel = $this->app->make(Kernel::class);
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
                Artisan::addCommands($foundCommands);
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

        $this->scanPhpFiles($eventsPath, function (\SplFileInfo $file) {
            $className = $file->getBasename('.php');
            $eventClass = $this->module->getClassNamespace().'\\Events\\'.$className;

            if (class_exists($eventClass)) {
                $this->discovered['events'][] = $eventClass;
                $this->cache["event.{$className}"] = $eventClass;
            }
        }, 'Event discovery');
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
            $files = $this->snapshotFiles($observersPath);

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $className = $file->getBasename('.php');
                $observerClass = $this->module->getClassNamespace().'\\Observers\\'.$className;

                if (! class_exists($observerClass)) {
                    continue;
                }

                // 使用正则只替换后缀，防止类名中间的 "Observer" 被误替换（如 ObserverObserver）
                $modelName = preg_replace('/Observer$/', '', $className);
                $modelClass = $this->module->getClassNamespace().'\\Models\\'.$modelName;

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
            $files = $this->snapshotFiles($policiesPath);

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $className = $file->getBasename('.php');
                $policyClass = $this->module->getClassNamespace().'\\Policies\\'.$className;

                if (! class_exists($policyClass)) {
                    continue;
                }

                // 使用正则只替换后缀，防止类名中间的 "Policy" 被误替换
                $modelName = preg_replace('/Policy$/', '', $className);
                $modelClass = $this->module->getClassNamespace().'\\Models\\'.$modelName;

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
            $files = $this->snapshotFiles($reposPath);

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $className = $file->getBasename('.php');
                $repoClass = $this->module->getClassNamespace().'\\Repositories\\'.$className;

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
        // 不能写成 getConfig('modules.cache.path', storage_path(...))：
        // 默认参数在调用**前**求值，容器未就绪时 storage_path() 会先抛异常；
        // 用 function_exists('storage_path') 兜底同样无效（助手已加载，
        // 但内部调 app()->storagePath()，裸容器没有该方法）。
        // 统一走「能力感知」的 storagePath()。
        $dir = ModuleContext::getConfig('modules.cache.path');

        if (! is_string($dir) || $dir === '') {
            $dir = ModuleContext::storagePath('framework/cache/modules');
        }

        return rtrim((string) $dir, '/\\').'/discovery.json';
    }

    /**
     * 从文件加载清单缓存（进程内仅加载一次）
     * 过期校验：清单写入时记录了各模块 Config 目录的 mtime 快照（__meta__ 键）。
     * 若任一个 Config 目录的 mtime 发生变化（例如修改了模块 Config 下的
     * providers/routes/aliases 等），说明清单已不能反映磁盘真实状态，直接丢弃
     * 并回退到实时扫描，避免“改了模块配置却不生效 / 已删组件仍被注册”。
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
            // JSON 读取：避免 require 带来的 OPcache 缓存与任意代码执行风险
            $data = ModuleCacheStore::read($file);

            if ($data === null) {
                return;
            }

            $meta = $data['__meta__'] ?? [];
            unset($data['__meta__']);

            if (isset($meta['config_mtimes']) && ! self::configMtimesUnchanged($meta['config_mtimes'])) {
                @unlink($file);

                return;
            }

            self::$manifestCache = $data;
        } catch (\Throwable) {
            self::$manifestCache = [];
        }
    }

    /**
     * 判断清单中记录的组件目录 mtime 是否与磁盘当前一致
     *
     * @param  array<string, int|null>  $stored
     */
    protected static function configMtimesUnchanged(array $stored): bool
    {
        foreach ($stored as $dir => $storedMtime) {
            $currentMtime = is_dir($dir) ? @filemtime($dir) : null;

            if ($storedMtime !== $currentMtime) {
                return false;
            }
        }

        return true;
    }

    /**
     * 记录模块关键组件目录的 mtime 快照，供 loadManifestCache 做过期校验
     * 仅记录 Config 目录不够：组件文件（Providers/Events/Observers 等）的增删
     * 只改变所在目录的 mtime，不会改变 Config 目录 mtime。因此将各组件目录
     * 一并纳入快照，保证「新增/删除组件文件」后清单必然失效并回退实时扫描。
     *
     * @return array<string, int|null> 目录绝对路径 => mtime（目录不存在时为 null）
     */
    protected function snapshotComponentMtimes(): array
    {
        $module = $this->module;
        $directories = [
            $module->getConfigPath(),
            $module->getProvidersPath(),
            $module->getRoutesPath(),
            $module->getCommandsPath(),
            $module->getPath('Events'),
            $module->getPath('Observers'),
            $module->getPath('Policies'),
            $module->getPath('Repositories'),
            $module->getPath('Http/Middleware'),
            $module->getPath('Http/Filters'),
        ];

        $snapshot = [];
        foreach ($directories as $dir) {
            $dir = rtrim((string) $dir, '/\\');
            $snapshot[$dir] = is_dir($dir) ? @filemtime($dir) : null;
        }

        return $snapshot;
    }

    /**
     * 将当前模块的发现结果写入清单缓存
     */
    protected function persistManifest(): void
    {
        $name = $this->module->getName();
        self::$manifestCache[$name] = $this->discovered;

        // 记录各组件目录的 mtime 快照，供 loadManifestCache 做过期校验
        foreach ($this->snapshotComponentMtimes() as $dir => $mtime) {
            self::$manifestCache['__meta__']['config_mtimes'][$dir] = $mtime;
        }

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
            ModuleCacheStore::write($file, self::$manifestCache);
        } catch (\Throwable) {
            // 缓存写入失败不中断
        }
    }

    /**
     * 预热单个模块的发现清单（用于 module:cache）
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

    /**
     * 获取本次发现的缓存结果
     */
    public function getCache(): array
    {
        return $this->cache;
    }

    /**
     * 清空发现结果与已发现项
     */
    public function clearCache(): void
    {
        $this->cache = [];
        $this->discovered = [
            'providers' => [], 'aliases' => [], 'commands' => [], 'events' => [],
            'repositories' => [], 'observers' => [], 'policies' => [], 'configs' => [],
            'routes' => [], 'view_path' => null, 'migration_path' => null, 'translation_path' => null,
        ];
    }

    /**
     * 获取发现过程中的日志
     */
    public function getLogs(): array
    {
        return $this->logs;
    }

    /**
     * 获取所有模块累积注册到全局的命令列表
     */
    public static function getGlobalCommands(): array
    {
        return self::$globalCommands;
    }

    /**
     * 清空全局命令列表
     */
    public static function clearGlobalCommands(): void
    {
        self::$globalCommands = [];
    }

    /**
     * 获取当前模块的发现结果摘要
     * 用于调试或展示各模块的 providers / routes / views / migrations 等
     * 自动发现数量统计。
     */
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

    /**
     * 通用 PHP 文件扫描器
     * 统一处理「遍历模块子目录中的 .php 文件」这一高度重复的模式：
     * 目录存在性检查、SafeFilesystem::files 遍历、.php 扩展名过滤、异常捕获与日志
     * 均在此完成，调用方只需通过 $callback 关注单个文件的实际业务逻辑。
     *
     * @param  string  $path  待扫描的目录绝对路径
     * @param  callable  $callback  处理单个文件的回调，签名为 (SplFileInfo $file): void
     * @param  string  $label  日志标签（用于错误定位）
     */
    protected function scanPhpFiles(string $path, callable $callback, string $label = 'scan'): void
    {
        // 目录存在性直接由快照判定，省掉一次 stat；
        // 快照未建立时 snapshotFiles() 会回退真实读取，这里额外保证不因目录不存在而误扫。
        if ($this->directorySnapshot === [] && ! is_dir($path)) {
            return;
        }

        try {
            foreach ($this->snapshotFiles($path) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $callback($file);
            }
        } catch (\Throwable $e) {
            $this->log("{$label} error: {$e->getMessage()}");
        }
    }
}
