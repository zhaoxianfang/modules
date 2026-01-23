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
 * 功能特性：
 * - 自动发现配置文件并合并到全局配置
 * - 自动发现并加载路由文件（自动应用中间件组和控制器命名空间）
 * - 自动发现并注册视图命名空间（支持多种视图路径格式）
 * - 自动发现并注册迁移路径
 * - 自动发现并注册 Artisan 命令
 * - 自动发现并注册翻译命名空间（支持 JSON 和 PHP 格式）
 * - 自动发现并注册事件和监听器
 * - 自动发现并注册模型观察者、策略、仓库等
 * - 支持多种路径格式的自动识别
 * - 支持自定义发现规则和配置
 * - 提供详细的发现摘要和调试信息
 *
 * 自动发现顺序（重要）：
 * 1. 配置文件（最先加载，其他组件可能依赖配置）
 * 2. 服务提供者（加载自定义服务绑定）
 * 3. 中间件（过滤器）
 * 4. 路由文件（Web、API、Admin 等）
 * 5. 视图文件
 * 6. 迁移文件
 * 7. 翻译文件
 * 8. Artisan 命令
 * 9. 事件和监听器
 * 10. 模型观察者
 * 11. 策略类
 * 12. 仓库类
 */
class ModuleAutoDiscovery
{
    /**
     * 全局命令缓存
     *
     * 存储所有模块已发现的命令类名
     *
     * @var array<string>
     */
    protected static array $globalCommands = [];

    /**
     * 模块实例
     *
     * @var ModuleInterface
     */
    protected ModuleInterface $module;

    /**
     * 应用实例
     *
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected \Illuminate\Contracts\Foundation\Application $app;

    /**
     * 发现缓存
     *
     * 存储已发现的组件信息，避免重复扫描
     * 键格式：组件类型.具体标识（如 config.blog, route.web 等）
     *
     * @var array<string, mixed>
     */
    protected array $cache = [];

    /**
     * 是否启用缓存
     *
     * 默认启用缓存以提高性能
     * 在开发环境可设置为 false 以实时检测变化
     *
     * @var bool
     */
    protected bool $cacheEnabled = true;

    /**
     * 发现日志
     *
     * 记录发现过程中的详细信息，用于调试
     * 格式：['时间' => '消息']
     *
     * @var array<string, string>
     */
    protected array $logs = [];

    /**
     * 创建新实例
     *
     * @param ModuleInterface $module 模块实例
     */
    public function __construct(ModuleInterface $module)
    {
        $this->module = $module;
        $this->app = app();
        $this->cacheEnabled = config('modules.cache.enabled', false);
    }

    /**
     * 静态方法：从模块信息数组自动发现模块
     *
     * 这是一个便捷方法，用于从模块信息数组自动发现模块
     * 主要用于 ServiceProvider 中调用，简化模块对象创建流程
     *
     * @param array<string, mixed> $moduleInfo 模块信息数组
     * @return void
     */
    public static function discoverModule(array $moduleInfo): void
    {
        // 从数组创建模块对象
        $module = self::createModuleFromArray($moduleInfo);
        
        // 创建自动发现器实例
        $discovery = new self($module);
        
        // 执行所有自动发现任务
        $discovery->discoverAll();
    }

    /**
     * 从数组创建模块对象
     *
     * 根据模块信息数组创建一个实现 ModuleInterface 的匿名类对象
     * 
     * @param array<string, mixed> $moduleInfo 模块信息数组
     * @return ModuleInterface 模块实例
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

            /**
             * 获取类命名空间（完整）
             * 这是一个辅助方法，不属于接口但被 ModuleAutoDiscovery 使用
             *
             * @return string
             */
            public function getClassNamespace(): string
            {
                return $this->namespace . '\\' . $this->name;
            }
        };
    }

    /**
     * 执行所有自动发现任务
     *
     * 按照正确的加载顺序依次发现各个组件，确保依赖关系正确：
     * 1. 服务提供者（最先加载，注册自定义服务和绑定）
     * 2. 配置文件（其他组件可能依赖配置）
     * 3. 中间件（过滤器，用于路由）
     * 4. 路由文件（Web、API、Admin 等）
     * 5. 视图文件（资源视图）
     * 6. 迁移文件（数据库迁移）
     * 7. 翻译文件（语言包）
     * 8. Artisan 命令（终端命令）
     * 9. 事件和监听器（事件系统）
     * 10. 模型观察者（模型钩子）
     * 11. 策略类（权限控制）
     * 12. 仓库类（数据访问层）
     *
     * @return void
     */
    public function discoverAll(): void
    {
        // 检查模块是否启用
        if (! $this->module->isEnabled()) {
            return;
        }

        // 按顺序执行所有发现任务
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

        // 清空缓存（如果需要）
        if (! $this->cacheEnabled) {
            $this->cache = [];
        }
    }

    /**
     * 发现并注册服务提供者
     *
     * 扫描 Providers/ 目录，发现所有服务提供者类
     * 自动注册到 Laravel 的服务容器中
     *
     * 服务提供者要求：
     * - 继承 Illuminate\Support\ServiceProvider
     * - 实现 register() 和 boot() 方法（可选）
     *
     * @return void
     */
    protected function discoverProviders(): void
    {
        if (! $this->shouldDiscover('providers')) {
            return;
        }

        try {
            $providersPath = $this->module->getProvidersPath();

            if (! is_dir($providersPath)) {
                return;
            }

            $providerFiles = File::files($providersPath);

            foreach ($providerFiles as $providerFile) {
                if ($providerFile->getExtension() !== 'php') {
                    continue;
                }

                $className = $providerFile->getBasename('.php');
                $providerClass = $this->module->getClassNamespace() . '\\Providers\\' . $className;

                if (! class_exists($providerClass)) {
                    $this->log("服务提供者类不存在: {$providerClass}");
                    continue;
                }

                try {
                    // 验证是否继承自 ServiceProvider
                    $reflection = new \ReflectionClass($providerClass);

                    if ($reflection->isSubclassOf(\Illuminate\Support\ServiceProvider::class)) {
                        // 注册服务提供者到 Laravel
                        $this->app->register($providerClass);
                        $this->cache["provider.{$className}"] = $providerClass;
                        $this->log("成功注册服务提供者: {$providerClass}");
                    } else {
                        $this->log("服务提供者未继承 ServiceProvider: {$providerClass}");
                    }
                } catch (\ReflectionException $e) {
                    $this->log("反射错误: {$providerClass}, 错误: {$e->getMessage()}");
                } catch (\Throwable $e) {
                    $this->log("注册服务提供者失败: {$providerClass}, 错误: {$e->getMessage()}");
                }
            }
        } catch (\Throwable $e) {
            $this->log("扫描服务提供者目录失败, 错误: {$e->getMessage()}");
        }
    }

    /**
     * 发现并合并配置文件
     *
     * 扫描 Config/ 目录，发现所有 .php 配置文件
     * 自动合并到全局配置，键名格式为：模块名.配置文件名（全小写）
     *
     * 支持的配置文件模式：
     * - config.php -> config('blog.key')
     * - settings.php -> config('blog.settings.key')
     * - custom.php -> config('blog.custom.key')
     *
     * @return void
     */
    protected function discoverConfigs(): void
    {
        if (! $this->shouldDiscover('config')) {
            return;
        }

        try {
            $configPath = $this->module->getConfigPath();

            if (! is_dir($configPath)) {
                return;
            }

            // 扫描所有配置文件
            $configFiles = File::files($configPath);

            foreach ($configFiles as $configFile) {
                if ($configFile->getExtension() !== 'php') {
                    continue;
                }

                $filename = $configFile->getBasename('.php');

                // 配置键名：模块名.配置文件名（全小写）
                // 如果配置文件名与模块名相同，直接用模块名
                $moduleLowerName = $this->module->getLowerName();

                if (strtolower($filename) === $moduleLowerName) {
                    $configKey = $moduleLowerName;
                } else {
                    $configKey = $moduleLowerName . '.' . strtolower($filename);
                }

                // 加载并合并配置
                try {
                    $configValue = require $configFile->getPathname();

                    if (! is_array($configValue)) {
                        $this->log("配置文件格式错误: {$filename}, 必须返回数组");
                        continue;
                    }

                    // 合并到全局配置
                    config([$configKey => $configValue]);

                    // 记录缓存
                    $this->cache["config.{$configKey}"] = true;
                    $this->log("成功加载配置: {$configKey}");
                } catch (\Throwable $e) {
                    $this->log("加载配置文件失败: {$filename}, 错误: {$e->getMessage()}");
                }
            }
        } catch (\Throwable $e) {
            $this->log("扫描配置目录失败, 错误: {$e->getMessage()}");
        }
    }

    /**
     * 发现并加载路由文件
     *
     * 扫描 Routes/ 目录，发现所有 .php 路由文件
     * 自动根据文件名应用对应的中间件组和控制器命名空间
     *
     * 默认路由文件：
     * - web.php: 应用 web 中间件组，使用 Web 控制器命名空间
     * - api.php: 应用 api 中间件组，使用 Api 控制器命名空间
     * - admin.php: 应用 admin 中间件组，使用 Admin 控制器命名空间
     * - custom.php: 不自动应用中间件（需要在文件中手动添加）
     *
     * 中间件组映射：
     * 可通过 config('modules.middleware_groups') 自定义
     *
     * 控制器命名空间自动识别规则：
     * - web.php -> Web 命名空间（检查 Http/Controllers/Web 目录是否存在）
     * - api.php -> Api 命名空间（检查 Http/Controllers/Api 目录是否存在）
     * - admin.php -> Admin 命名空间（检查 Http/Controllers/Admin 目录是否存在）
     * - other.php -> Other 命名空间（首字母大写的文件名）
     *
     * 如果对应的控制器子目录不存在，则不应用特定命名空间
     *
     * @return void
     */
    protected function discoverRoutes(): void
    {
        if (! $this->shouldDiscover('routes')) {
            return;
        }

        $routesPath = $this->module->getRoutesPath();

        if (! is_dir($routesPath)) {
            $this->log("路由目录不存在: {$routesPath}");
            return;
        }

        // 从全局配置获取中间件组映射
        $middlewareGroups = config('modules.middleware_groups', [
            'web' => ['web'],
            'api' => ['api'],
            'admin' => ['web', 'admin'],
        ]);

        // 扫描所有路由文件
        $routeFiles = File::files($routesPath);

        foreach ($routeFiles as $routeFile) {
            if ($routeFile->getExtension() !== 'php') {
                continue;
            }

            $filename = $routeFile->getBasename('.php');

            // 跳过 .gitignore 等隐藏文件
            if (str_starts_with($filename, '.')) {
                continue;
            }

            // 获取对应的中间件组
            $middleware = $middlewareGroups[$filename] ?? [];

            // 内部自动识别控制器命名空间
            $controllerNamespace = $this->autoDetectControllerNamespace($filename);

            // 构建完整控制器命名空间
            $fullNamespace = $this->module->getClassNamespace() . '\\Http\\Controllers' . $controllerNamespace;

            // 使用 Laravel 的路由系统加载文件
            $router = app('router');

            // 创建路由组
            $routeGroup = $router;

            if (! empty($middleware)) {
                $routeGroup = $routeGroup->middleware($middleware);
            }

            // 只有当检测到控制器命名空间时才设置
            if (! empty($controllerNamespace)) {
                $routeGroup = $routeGroup->namespace($fullNamespace);
            }

            // 加载路由文件
            try {
                $routeGroup->group(function () use ($routeFile) {
                    require $routeFile->getPathname();
                });

                $this->log("成功加载路由文件: {$filename}, 命名空间: {$fullNamespace}");
                $this->cache["route.{$filename}"] = true;
            } catch (\Throwable $e) {
                $this->log("加载路由文件失败: {$filename}, 错误: {$e->getMessage()}");
            }
        }
    }

    /**
     * 自动检测控制器命名空间
     *
     * 根据路由文件名自动检测对应的控制器命名空间
     * 检查对应的控制器子目录是否存在
     *
     * @param string $routeFilename 路由文件名（不含扩展名）
     * @return string 控制器命名空间（如 \\Web 或 ''）
     */
    protected function autoDetectControllerNamespace(string $routeFilename): string
    {
        // 标准化路由文件名
        $standardNames = ['web', 'api', 'admin'];

        if (in_array(strtolower($routeFilename), $standardNames)) {
            // 标准路由文件名，检查对应的控制器子目录
            $subNamespace = ucfirst($routeFilename);
            $controllerPath = $this->module->getPath('Http/Controllers/' . $subNamespace);

            if (is_dir($controllerPath)) {
                return '\\' . $subNamespace;
            }

            // 如果子目录不存在，返回空字符串（不应用特定命名空间）
            return '';
        }

        // 非标准路由文件名，使用首字母大写的文件名
        $subNamespace = ucfirst($routeFilename);
        $controllerPath = $this->module->getPath('Http/Controllers/' . $subNamespace);

        if (is_dir($controllerPath)) {
            return '\\' . $subNamespace;
        }

        return '';
    }

    /**
     * 发现并注册视图命名空间
     *
     * 扫描 Resources/views/ 目录并注册视图命名空间
     * 视图命名空间格式根据 config('modules.views.namespace_format') 配置
     *
     * 支持的命名空间格式：
     * - lower: blog (小写，默认）
     * - studly: Blog (首字母大写）
     * - camel: blogModule (驼峰命名）
     *
     * 使用方式：view('blog::view.name')
     *
     * @return void
     */
    protected function discoverViews(): void
    {
        if (! $this->shouldDiscover('views')) {
            return;
        }

        // Laravel 11+ 支持多种视图路径
        $possiblePaths = [
            $this->module->getPath('Resources/views'),
            $this->module->getPath('resources/views'),
            $this->module->getPath('views'),
        ];

        $viewsPath = null;
        foreach ($possiblePaths as $path) {
            if (is_dir($path)) {
                $viewsPath = $path;
                break;
            }
        }

        if (! $viewsPath) {
            $this->log("视图目录不存在，尝试创建: Resources/views");
            // 尝试创建视图目录
            $defaultViewsPath = $this->module->getPath('Resources/views');
            if (! is_dir($defaultViewsPath)) {
                File::makeDirectory($defaultViewsPath, 0755, true);
                $this->log("已创建视图目录: {$defaultViewsPath}");
            }
            $viewsPath = $defaultViewsPath;
        }

        // 获取命名空间格式
        $namespaceFormat = config('modules.views.namespace_format', 'lower');

        // 构建视图命名空间
        $viewNamespace = match($namespaceFormat) {
            'lower' => strtolower($this->module->getName()),
            'studly' => $this->module->getName(),
            'camel' => lcfirst($this->module->getName()),
            default => strtolower($this->module->getName()),
        };

        // 注册视图命名空间
        try {
            app('view')->addNamespace($viewNamespace, $viewsPath);
            $this->log("成功注册视图命名空间: {$viewNamespace} -> {$viewsPath}");
            $this->cache['view'] = true;
        } catch (\Throwable $e) {
            $this->log("注册视图命名空间失败: {$e->getMessage()}");
        }
    }

    /**
     * 发现并注册迁移路径
     *
     * 扫描 Database/Migrations/ 目录并注册迁移路径
     * 迁移会自动包含在 Laravel 的迁移系统中
     *
     * 运行：php artisan migrate
     * 回滚：php artisan migrate:rollback
     * 刷新：php artisan migrate:refresh
     * 重置：php artisan migrate:reset
     *
     * @return void
     */
    protected function discoverMigrations(): void
    {
        if (! $this->shouldDiscover('migrations')) {
            return;
        }

        try {
            // Laravel 11+ 支持多种迁移路径
            $possiblePaths = [
                $this->module->getPath('Database/Migrations'),
                $this->module->getPath('database/migrations'),
            ];

            $migrationsPath = null;
            foreach ($possiblePaths as $path) {
                if (is_dir($path)) {
                    $migrationsPath = $path;
                    break;
                }
            }

            if (! $migrationsPath) {
                return;
            }

            // 注册迁移路径
            $migrator = app('migrator');

            // 获取当前已注册的路径
            $existingPaths = $migrator->paths();

            // 检查是否已注册
            if (in_array($migrationsPath, $existingPaths)) {
                return;
            }

            // 注册新路径
            $migrator->path($migrationsPath);

            // 记录缓存
            $this->cache['migration'] = true;
            $this->log("成功注册迁移路径: {$migrationsPath}");
        } catch (\Throwable $e) {
            $this->log("注册迁移路径失败, 错误: {$e->getMessage()}");
        }
    }

    /**
     * 发现并注册翻译命名空间
     *
     * 扫描 Resources/lang/ 或 Lang/ 目录并注册翻译命名空间
     * Laravel 11+ 支持多种语言目录结构
     *
     * 支持的语言文件格式：
     * - JSON: zh-CN.json, en.json
     * - PHP: zh-CN/message.php, en/message.php
     * - 嵌套: zh-CN/common.php, zh-CN/validation.php
     *
     * 使用方式：__('blog::key') 或 __('blog::common.welcome')
     *
     * @return void
     */
    protected function discoverTranslations(): void
    {
        if (! $this->shouldDiscover('translations')) {
            return;
        }

        try {
            // Laravel 11+ 支持多种翻译路径
            $possiblePaths = [
                $this->module->getPath('Resources/lang'),
                $this->module->getPath('Lang'),
                $this->module->getPath('resources/lang'),
            ];

            $langPath = null;
            foreach ($possiblePaths as $path) {
                if (is_dir($path)) {
                    $langPath = $path;
                    break;
                }
            }

            if (! $langPath) {
                return;
            }

            // 注册翻译命名空间
            $translator = app('translator');

            // 检查 translator 是否有 addNamespace 方法
            if (! method_exists($translator, 'addNamespace')) {
                return;
            }

            // 检查 loader 是否有 addNamespace 方法
            $loader = $translator->getLoader();
            if (! method_exists($loader, 'addNamespace')) {
                return;
            }

            $namespace = $this->module->getLowerName();

            $translator->addNamespace(
                $namespace,
                $langPath
            );

            // 记录缓存
            $this->cache['translation'] = true;
        } catch (\Throwable $e) {
            // 静默失败，避免影响模块加载
        }
    }

    /**
     * 发现并注册 Artisan 命令
     *
     * 扫描 Console/Commands/ 目录，发现所有 Artisan 命令类
     * 使用 Laravel 11+ 的命令发现机制
     *
     * 命令类要求：
     * - 继承 Illuminate\Console\Command
     * - 定义 $signature 和 $description 属性
     * - 实现 handle() 方法
     *
     * @return void
     */
    protected function discoverCommands(): void
    {
        // 只在命令模式下注册命令
        if (! $this->app->runningInConsole()) {
            $this->log('当前不是命令模式，跳过命令注册');
            return;
        }

        if (! $this->shouldDiscover('commands')) {
            $this->log("命令发现已禁用");
            return;
        }

        $this->log("开始扫描模块 [{$this->module->getName()}] 的命令");

        // Laravel 11+ 支持多种命令路径
        $possiblePaths = [
            ['path' => $this->module->getPath('Console/Commands'), 'namespace' => '\\Console\\Commands'],
            ['path' => $this->module->getPath('Commands'), 'namespace' => '\\Commands'],
        ];

        $foundCommands = [];

        foreach ($possiblePaths as $pathInfo) {
            $commandsPath = $pathInfo['path'];
            $namespace = $pathInfo['namespace'];

            if (! is_dir($commandsPath)) {
                $this->log("命令目录不存在: {$commandsPath}");
                continue;
            }

            $this->log("扫描命令目录: {$commandsPath}");

            // 扫描所有命令文件
            try {
                $commandFiles = File::files($commandsPath);
                $this->log("在目录 [{$commandsPath}] 中找到 " . count($commandFiles) . " 个文件");

                foreach ($commandFiles as $commandFile) {
                    if ($commandFile->getExtension() !== 'php') {
                        continue;
                    }

                    $className = $commandFile->getBasename('.php');

                    // 构建完整类名
                    $commandClass = $this->module->getClassNamespace() . $namespace . '\\' . $className;

                    $this->log("检查命令类: {$commandClass}");

                    // 验证命令类是否有效
                    if (! class_exists($commandClass)) {
                        $this->log("命令类不存在: {$commandClass}");
                        continue;
                    }

                    try {
                        $reflection = new \ReflectionClass($commandClass);

                        // 检查是否继承自 Command 并且不是抽象类
                        if ($reflection->isSubclassOf(\Illuminate\Console\Command::class) && ! $reflection->isAbstract()) {
                            $foundCommands[] = $commandClass;
                            $this->log("✓ 发现有效命令: {$commandClass}");
                        } else {
                            $this->log("✗ 无效命令类: {$commandClass} (不是 Command 的子类或是抽象类)");
                        }
                    } catch (\ReflectionException $e) {
                        $this->log("✗ 反射错误: {$commandClass}, 错误: {$e->getMessage()}");
                    }
                }
            } catch (\Throwable $e) {
                $this->log("✗ 扫描命令目录失败: {$commandsPath}, 错误: {$e->getMessage()}");
            }
        }

        $this->log("命令扫描完成，共找到 " . count($foundCommands) . " 个有效命令");

        // 将发现的命令添加到全局缓存，供服务提供者注册使用
        foreach ($foundCommands as $commandClass) {
            if (! in_array($commandClass, self::$globalCommands, true)) {
                self::$globalCommands[] = $commandClass;
                $this->log("添加命令到全局缓存: {$commandClass}");
            }
        }

        // 注册命令到 Laravel
        if (! empty($foundCommands)) {
            try {
                // 使用 Laravel 推荐的方式注册命令
                // 将命令添加到应用的 commands 数组中，让 Laravel 自动处理注册
                $this->app->addCommands($foundCommands);
                
                foreach ($foundCommands as $commandClass) {
                    $this->log("成功注册命令: {$commandClass}");
                }
            } catch (\Throwable $e) {
                $this->log("注册命令失败, 错误: {$e->getMessage()}");
                // 降级方案：直接通过 Artisan 门面注册
                try {
                    $artisan = $this->app['artisan'];
                    foreach ($foundCommands as $commandClass) {
                        try {
                            $command = $this->app->make($commandClass);
                            $artisan->add($command);
                            $this->log("通过降级方案注册命令: {$commandClass}");
                        } catch (\Throwable $innerE) {
                            $this->log("降级方案注册命令失败: {$commandClass}, 错误: {$innerE->getMessage()}");
                        }
                    }
                } catch (\Throwable $fallbackE) {
                    $this->log("降级方案也失败, 错误: {$fallbackE->getMessage()}");
                }
            }
        }

        // 记录缓存
        $this->cache['commands'] = $foundCommands;
    }

    /**
     * 命令注册的降级方案
     *
     * 当主要的 addCommands 方法失败时使用此方案
     * 通过 Artisan Console Application 直接注册命令
     *
     * @param array $commandClasses 命令类名数组
     * @return void
     */
    protected function registerCommandsFallback(array $commandClasses): void
    {
        try {
            // 获取 Artisan Console Application 实例
            $artisan = $this->app['artisan'];
            
            foreach ($commandClasses as $commandClass) {
                try {
                    // 实例化命令对象
                    $command = $this->app->make($commandClass);
                    
                    // 将命令添加到 Artisan
                    $artisan->add($command);
                    
                    $this->log("通过降级方案注册命令: {$commandClass}");
                } catch (\Throwable $e) {
                    $this->log("降级方案注册命令失败: {$commandClass}, 错误: {$e->getMessage()}");
                }
            }
            
            $this->log("使用降级方案成功注册 " . count($commandClasses) . " 个命令");
        } catch (\Throwable $e) {
            $this->log("降级方案失败，错误: {$e->getMessage()}");
        }
    }

    /**
     * 发现并注册事件和监听器
     *
     * 扫描 Events/ 和 Listeners/ 目录
     * Laravel 11+ 会自动发现这些类，但这里可以进行验证
     *
     * @return void
     */
    protected function discoverEvents(): void
    {
        if (! $this->shouldDiscover('events')) {
            return;
        }

        try {
            // Laravel 11+ 会自动发现 Events 和 Listeners 目录中的类
            // 这里主要进行验证和记录

            $eventsPath = $this->module->getPath('Events');

            $eventClasses = [];

            // 扫描事件类
            if (is_dir($eventsPath)) {
                $eventFiles = File::files($eventsPath);
                foreach ($eventFiles as $eventFile) {
                    if ($eventFile->getExtension() !== 'php') {
                        continue;
                    }

                    $className = $eventFile->getBasename('.php');
                    $eventClass = $this->module->getClassNamespace() . '\\Events\\' . $className;

                    if (class_exists($eventClass)) {
                        $eventClasses[] = $eventClass;
                        $this->log("发现事件类: {$eventClass}");
                    }
                }
            }

            // 记录缓存
            $this->cache['events'] = $eventClasses;
        } catch (\Throwable $e) {
            $this->log("扫描事件类失败, 错误: {$e->getMessage()}");
        }
    }

    /**
     * 发现并注册中间件（过滤器）
     *
     * 扫描 Http/Middleware/ 目录，发现所有中间件类
     * 自动注册到 Laravel 的路由器中
     *
     * 中间件要求：
     * - 继承 Illuminate\Http\Middleware 或实现 HandleMiddleware 接口
     * - 定义 handle() 方法处理请求
     *
     * @return void
     */
    protected function discoverMiddlewares(): void
    {
        if (! $this->shouldDiscover('middlewares')) {
            return;
        }

        try {
            // 支持多种中间件路径
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

                        // 尝试 Http/Middleware 命名空间
                        $middlewareClass = $this->module->getClassNamespace() . '\\Http\\Middleware\\' . $className;

                        // 如果不存在，尝试 Http/Filters 命名空间
                        if (! class_exists($middlewareClass)) {
                            $middlewareClass = $this->module->getClassNamespace() . '\\Http\\Filters\\' . $className;
                        }

                        if (class_exists($middlewareClass)) {
                            // Laravel 会自动加载中间件类，这里主要用于记录
                            $this->cache["middleware.{$className}"] = $middlewareClass;
                            $this->log("发现中间件: {$middlewareClass}");
                        }
                    }
                } catch (\Throwable $e) {
                    $this->log("扫描中间件目录失败: {$middlewarePath}, 错误: {$e->getMessage()}");
                }
            }
        } catch (\Throwable $e) {
            $this->log("发现中间件失败, 错误: {$e->getMessage()}");
        }
    }

    /**
     * 发现并注册模型观察者
     *
     * 扫描 Observers/ 目录，发现所有观察者类
     * 自动注册到对应的模型中
     *
     * 观察者要求：
     * - 观察者类名格式：{ModelName}Observer
     * - 实现对应的模型监听方法（如 created, updated, deleted 等）
     *
     * @return void
     */
    protected function discoverObservers(): void
    {
        if (! $this->shouldDiscover('observers')) {
            return;
        }

        try {
            $observersPath = $this->module->getPath('Observers');

            if (! is_dir($observersPath)) {
                return;
            }

            $observerFiles = File::files($observersPath);
            $observers = [];

            foreach ($observerFiles as $observerFile) {
                if ($observerFile->getExtension() !== 'php') {
                    continue;
                }

                $className = $observerFile->getBasename('.php');
                $observerClass = $this->module->getClassNamespace() . '\\Observers\\' . $className;

                if (class_exists($observerClass)) {
                    $observers[$className] = $observerClass;
                    $this->log("发现观察者: {$observerClass}");
                }
            }

            // 注册观察者到模型
            // 观察者命名约定：BlogObserver 对应 Blog 模型
            foreach ($observers as $observerName => $observerClass) {
                $modelName = str_replace('Observer', '', $observerName);
                $modelClass = $this->module->getClassNamespace() . '\\Models\\' . $modelName;

                if (class_exists($modelClass)) {
                    try {
                        $modelClass::observe($observerClass);
                        $this->cache["observer.{$modelName}"] = $observerClass;
                        $this->log("注册观察者: {$observerClass} -> {$modelClass}");
                    } catch (\Throwable $e) {
                        $this->log("注册观察者失败: {$observerClass} -> {$modelClass}, 错误: {$e->getMessage()}");
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->log("发现观察者失败, 错误: {$e->getMessage()}");
        }
    }

    /**
     * 发现并注册策略类
     *
     * 扫描 Policies/ 目录，发现所有策略类
     * 自动注册到 Laravel 的策略系统中
     *
     * 策略要求：
     * - 策略类名格式：{ModelName}Policy
     * - 实现对应的权限验证方法（如 view, create, update, delete 等）
     *
     * @return void
     */
    protected function discoverPolicies(): void
    {
        if (! $this->shouldDiscover('policies')) {
            return;
        }

        try {
            $policiesPath = $this->module->getPath('Policies');

            if (! is_dir($policiesPath)) {
                return;
            }

            $policyFiles = File::files($policiesPath);
            $policies = [];

            foreach ($policyFiles as $policyFile) {
                if ($policyFile->getExtension() !== 'php') {
                    continue;
                }

                $className = $policyFile->getBasename('.php');
                $policyClass = $this->module->getClassNamespace() . '\\Policies\\' . $className;

                if (class_exists($policyClass)) {
                    $policies[$className] = $policyClass;
                    $this->log("发现策略: {$policyClass}");
                }
            }

            // 注册策略到 Gate
            foreach ($policies as $policyName => $policyClass) {
                $modelName = str_replace('Policy', '', $policyName);
                $modelClass = $this->module->getClassNamespace() . '\\Models\\' . $modelName;

                if (class_exists($modelClass)) {
                    try {
                        Gate::policy($modelClass, $policyClass);
                        $this->cache["policy.{$modelName}"] = $policyClass;
                        $this->log("注册策略: {$policyClass} -> {$modelClass}");
                    } catch (\Throwable $e) {
                        $this->log("注册策略失败: {$policyClass} -> {$modelClass}, 错误: {$e->getMessage()}");
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->log("发现策略失败, 错误: {$e->getMessage()}");
        }
    }

    /**
     * 发现并注册仓库类
     *
     * 扫描 Repositories/ 目录，发现所有仓库类
     * 仓库类可用于封装数据访问逻辑
     *
     * 仓库要求：
     * - 继承基类（如果有）或实现接口（如果有）
     * - 提供数据查询和操作方法
     *
     * 注意：仓库类不会自动注册到服务容器
     * 开发者需要在 ServiceProvider 的 register() 方法中手动注册
     *
     * @return void
     */
    protected function discoverRepositories(): void
    {
        if (! $this->shouldDiscover('repositories')) {
            return;
        }

        try {
            $repositoriesPath = $this->module->getPath('Repositories');

            if (! is_dir($repositoriesPath)) {
                return;
            }

            $repositoryFiles = File::files($repositoriesPath);
            $repositories = [];

            foreach ($repositoryFiles as $repositoryFile) {
                if ($repositoryFile->getExtension() !== 'php') {
                    continue;
                }

                $className = $repositoryFile->getBasename('.php');
                $repositoryClass = $this->module->getClassNamespace() . '\\Repositories\\' . $className;

                if (class_exists($repositoryClass)) {
                    $repositories[$className] = $repositoryClass;
                    $this->log("发现仓库: {$repositoryClass}");
                }
            }

            // 仓库类不会自动注册，只在缓存中记录
            $this->cache['repositories'] = $repositories;
        } catch (\Throwable $e) {
            $this->log("发现仓库失败, 错误: {$e->getMessage()}");
        }
    }

    /**
     * 记录日志
     *
     * 记录发现过程中的信息
     * 用于调试和问题追踪
     *
     * @param string $message 日志消息
     * @return void
     */
    /**
     * 记录日志信息
     *
     * 记录自动发现过程中的详细日志，用于调试和问题追踪
     * 日志格式：时间戳 => 消息内容
     *
     * @param string $message 日志消息
     * @return void
     */
    protected function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $this->logs[$timestamp] = $message;
    }

    /**
     * 判断是否应该发现指定类型的组件
     *
     * 根据配置文件中的发现设置判断是否执行发现
     * 配置路径：config('modules.discovery.{type}')
     *
     * @param string $type 发现类型（如：config、routes、views、commands、events等）
     * @return bool 是否应该发现该类型组件
     */
    protected function shouldDiscover(string $type): bool
    {
        return config("modules.discovery.{$type}", true);
    }

    /**
     * 获取发现缓存
     *
     * 返回所有已发现的组件缓存数据
     * 缓存键格式：组件类型.具体标识（如 config.blog、route.web 等）
     *
     * @return array<string, mixed> 缓存数据
     */
    public function getCache(): array
    {
        return $this->cache;
    }

    /**
     * 清空发现缓存
     *
     * 清空所有已发现的组件缓存
     * 通常在模块更新或重新加载时调用
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * 获取发现日志
     *
     * 返回发现过程中的所有日志记录
     * 用于调试和问题追踪，查看模块加载的详细过程
     *
     * @return array<string, string> 日志记录（时间戳 => 消息）
     */
    public function getLogs(): array
    {
        return $this->logs;
    }

    /**
     * 获取全局命令缓存
     *
     * 返回所有模块已发现的命令类名
     *
     * @return array<string> 命令类名数组
     */
    public static function getGlobalCommands(): array
    {
        return self::$globalCommands;
    }

    /**
     * 清空全局命令缓存
     *
     * @return void
     */
    public static function clearGlobalCommands(): void
    {
        self::$globalCommands = [];
    }

    /**
     * 获取模块的发现摘要
     *
     * 返回模块已发现的所有组件的摘要信息
     * 可用于调试和日志记录
     *
     * @return array<string, mixed> 发现摘要
     */
    public function getDiscoverySummary(): array
    {
        return [
            'module' => $this->module->getName(),
            'enabled' => $this->module->isEnabled(),
            'providers_count' => count(array_filter($this->cache, fn ($k) => str_starts_with($k, 'provider.'), ARRAY_FILTER_USE_KEY)),
            'configs' => array_keys(array_filter($this->cache, fn ($k) => str_starts_with($k, 'config.'), ARRAY_FILTER_USE_KEY)),
            'routes' => array_keys(array_filter($this->cache, fn ($k) => str_starts_with($k, 'route.'), ARRAY_FILTER_USE_KEY)),
            'middlewares' => array_keys(array_filter($this->cache, fn ($k) => str_starts_with($k, 'middleware.'), ARRAY_FILTER_USE_KEY)),
            'views' => isset($this->cache['view']) ? 'registered' : 'not found',
            'migrations' => isset($this->cache['migration']) ? 'registered' : 'not found',
            'translations' => isset($this->cache['translation']) ? 'registered' : 'not found',
            'commands_count' => is_array($this->cache['commands'] ?? null) ? count($this->cache['commands']) : 0,
            'events_count' => is_array($this->cache['events'] ?? null) ? count($this->cache['events']) : 0,
            'observers_count' => count(array_filter($this->cache, fn ($k) => str_starts_with($k, 'observer.'), ARRAY_FILTER_USE_KEY)),
            'policies_count' => count(array_filter($this->cache, fn ($k) => str_starts_with($k, 'policy.'), ARRAY_FILTER_USE_KEY)),
            'repositories_count' => is_array($this->cache['repositories'] ?? null) ? count($this->cache['repositories']) : 0,
            'logs_count' => count($this->logs),
        ];
    }
}
