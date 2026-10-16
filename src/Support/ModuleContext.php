<?php

declare(strict_types=1);

namespace zxf\Modules\Support;

use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Contracts\RepositoryInterface;

/**
 * 模块上下文解析器
 * 抽象框架依赖，提供统一的上下文解析接口。
 * 这是降低 Laravel 框架依赖的核心类——所有 helper 函数通过此类间接访问框架功能。
 * 设计原则：
 * - 使用静态缓存减少重复调用
 * - 自动降级：框架功能不可用时返回安全默认值
 * - 零异常传播：所有方法内部捕获异常
 *
 * @version 1.13.9
 */
class ModuleContext
{
    /**
     * Repository 实例缓存
     */
    protected static ?RepositoryInterface $repository = null;

    /**
     * 模块名缓存
     */
    protected static ?string $cachedModuleName = null;

    /**
     * 模块配置值静态缓存（{小写模块名}:{key} => value）
     * 存于此而非 helper 函数内的 static，是为了让 module_set_config 等写入
     * 操作能定位并清除对应模块的缓存项，避免读到过期值。
     *
     * @var array<string, mixed>
     */
    protected static array $moduleConfigCache = [];

    /**
     * 从模块配置缓存取值，未命中时通过加载器计算并缓存
     *
     * @param  string  $cacheKey  缓存键（{小写模块名}:{key}）
     * @param  \Closure  $loader  未命中时的取值加载器
     */
    public static function getModuleConfigCached(string $cacheKey, \Closure $loader): mixed
    {
        if (array_key_exists($cacheKey, self::$moduleConfigCache)) {
            return self::$moduleConfigCache[$cacheKey];
        }

        return self::$moduleConfigCache[$cacheKey] = $loader();
    }

    /**
     * 清除模块配置缓存
     *
     * @param  string|null  $module  模块名（null 清除全部）
     */
    public static function clearModuleConfigCache(?string $module = null): void
    {
        if ($module === null) {
            self::$moduleConfigCache = [];

            return;
        }

        $prefix = strtolower($module).':';
        foreach (array_keys(self::$moduleConfigCache) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset(self::$moduleConfigCache[$key]);
            }
        }
    }

    /**
     * 获取模块仓库实例
     */
    public static function getRepository(): RepositoryInterface
    {
        if (self::$repository !== null) {
            return self::$repository;
        }

        if (function_exists('app')) {
            try {
                self::$repository = app(RepositoryInterface::class);

                return self::$repository;
            } catch (\Throwable) {
                // 降级：返回空仓库
            }
        }

        // 极端情况：框架未初始化
        throw new \RuntimeException('Module repository not available. Ensure ModulesServiceProvider is registered.');
    }

    /**
     * 获取所有模块
     *
     * @return array<string, ModuleInterface>
     */
    public static function getModules(): array
    {
        try {
            return self::getRepository()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 获取配置值
     *
     * @param  string  $key  配置键
     * @param  mixed  $default  默认值
     */
    public static function getConfig(string $key, mixed $default = null): mixed
    {
        if (function_exists('config')) {
            try {
                return config($key, $default);
            } catch (\Throwable) {
                return $default;
            }
        }

        return $default;
    }

    /**
     * 设置配置值
     *
     * @param  string  $key  配置键
     * @param  mixed  $value  配置值
     */
    public static function setConfig(string $key, mixed $value): void
    {
        if (function_exists('config')) {
            try {
                config([$key => $value]);
            } catch (\Throwable) {
                // 静默失败
            }
        }
    }

    /**
     * 检测当前是否在命令行模式运行
     */
    public static function runningInConsole(): bool
    {
        if (function_exists('app')) {
            try {
                return app()->runningInConsole();
            } catch (\Throwable) {
                return PHP_SAPI === 'cli';
            }
        }

        return PHP_SAPI === 'cli';
    }

    /**
     * 检测当前模块名称
     * 自动识别当前代码或请求所在的模块上下文。
     *
     * @param  bool  $toLower  是否返回小写蛇形命名
     * @param  bool  $requestModule  路由检测模式
     */
    public static function detectModuleName(bool $toLower = false, bool $requestModule = true): string
    {
        // 命令行环境
        if (self::runningInConsole()) {
            return $toLower ? 'command' : 'Command';
        }

        // 路由检测模式
        if ($requestModule) {
            return self::detectByRoute($toLower);
        }

        // 文件检测模式
        return self::detectByFile($toLower);
    }

    /**
     * 通过路由检测模块
     */
    protected static function detectByRoute(bool $toLower): string
    {
        if (self::$cachedModuleName !== null) {
            return self::applyFormat(self::$cachedModuleName, $toLower);
        }

        $result = 'App';

        try {
            if (! function_exists('request') || ! function_exists('config')) {
                return $toLower ? 'app' : 'App';
            }

            $request = request();
            if (! $request || ! ($route = $request->route())) {
                return $toLower ? 'app' : 'App';
            }

            $action = $route->getActionName();

            // 从控制器类解析模块
            if ($action !== 'Closure') {
                $class = explode('@', $action)[0];
                $namespace = self::getConfig('modules.namespace', 'Modules');

                if (preg_match('/^'.preg_quote($namespace, '/').'\\\\([^\\\\]+)/', $class, $matches)) {
                    $result = $matches[1];
                } elseif (preg_match('/^Modules\\\\([^\\\\]+)/', $class, $matches)) {
                    $result = $matches[1];
                }
            }

            // 兜底：从路由名或 URL 解析
            if ($result === 'App') {
                $modules = self::getModules();
                $moduleMap = [];

                foreach ($modules as $m) {
                    $moduleMap[$m->getLowerName()] = $m->getName();
                }

                // 路由名解析
                if ($name = $route->getName()) {
                    $firstSegment = strtok($name, '.');
                    if ($firstSegment && isset($moduleMap[$firstSegment])) {
                        $result = $moduleMap[$firstSegment];
                    }
                }

                // URL 路径解析
                if ($result === 'App') {
                    $path = $request->path();
                    $firstSegment = strtok($path, '/');
                    if ($firstSegment && isset($moduleMap[$firstSegment])) {
                        $result = $moduleMap[$firstSegment];
                    }
                }
            }

            // 验证模块存在
            if ($result !== 'App' && $result !== 'Command') {
                try {
                    if (! self::getRepository()->has($result)) {
                        $result = 'App';
                    }
                } catch (\Throwable) {
                    $result = 'App';
                }
            }
        } catch (\Throwable) {
            $result = 'App';
        }

        self::$cachedModuleName = $result;

        return self::applyFormat($result, $toLower);
    }

    /**
     * 通过文件路径检测模块
     */
    protected static function detectByFile(bool $toLower): string
    {
        $configured = self::getConfig('modules.path');

        // 不能写成 getConfig('modules.path', base_path('Modules'))：
        // PHP 默认参数在调用前求值，容器未就绪时 base_path() 会先抛异常，
        // getConfig 内的降级保护没有机会执行；function_exists('base_path') 兜底
        // 同样无效（助手函数已加载，但它依赖 Foundation 应用实例）。
        // 统一走「能力感知」的 basePath()，任何环境下都不抛。
        if (! is_string($configured) || $configured === '') {
            $configured = self::basePath('Modules');
        }
        $real = realpath($configured);
        $modulePath = rtrim(str_replace('\\', '/', $real !== false ? $real : $configured), '/').'/';
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
        $result = 'App';

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';
            if (! $file || str_contains($file, '/vendor/')) {
                continue;
            }

            $file = str_replace('\\', '/', $file);
            if (! str_starts_with($file, $modulePath)) {
                continue;
            }

            $dir = strtok(substr($file, strlen($modulePath)), '/');

            // getRepository() 在框架不可用时会抛出异常，需降级处理：
            // 无法判断仓库时视为「非已知模块」，继续往后查找。
            try {
                $known = self::getRepository()->has($dir);
            } catch (\Throwable $e) {
                $known = false;
            }

            if ($dir && $known) {
                $result = $dir;
                break;
            }
        }

        return self::applyFormat($result, $toLower);
    }

    /**
     * 格式化模块名称
     */
    protected static function applyFormat(string $name, bool $toLower): string
    {
        if (! $toLower) {
            return $name;
        }

        if ($name === 'App') {
            return 'app';
        }

        if ($name === 'Command') {
            return 'command';
        }

        return strtolower(preg_replace('/(?<=[a-z])([A-Z])/', '_$1', $name));
    }

    /**
     * 清除所有缓存
     */
    public static function clearCache(): void
    {
        self::$repository = null;
        self::$cachedModuleName = null;
        self::$moduleConfigCache = [];
    }

    /**
     * 清理「请求作用域」的静态缓存
     *
     * clearCache() 会连 $repository 一起置空，只有 reload() 这类
     * 「主动重扫」场景才该调用。
     * 但另外两项是**纯请求作用域**的：
     *  - $cachedModuleName：detectModuleName() 的结果（当前请求是哪个模块）
     *  - $moduleConfigCache：module_config() 的值缓存
     *
     * 在 php-fpm 下每请求新进程，静态变量天然重置，没问题；
     * 但在 Octane / queue worker / supervisor 这类长驻进程里，
     * 这些静态会**跨请求存活**：
     *  - 第二个请求若没走到检测分支，会直接复用上一个请求的模块名；
     *  - A 请求改了配置，B 请求读到的是 A 请求留下的旧值。
     * 于是「模块识别」与「模块配置」在长驻进程下会串台，
     * 且很难复现（只在 Octane/常驻 worker 上出现）。
     *
     * 因此单独提供本方法，只清请求作用域的两项（保留 repository），
     * 并由 ModulesServiceProvider 挂到 $app->terminating() 上，
     * 让每个请求/每个任务结束后自动归零。
     */
    public static function flushRequestScopedCache(): void
    {
        self::$cachedModuleName = null;
        self::$moduleConfigCache = [];
    }

    // ==========================================================
    //  「能力感知」路径助手
    //
    //
    //  Laravel 的全局助手 base_path() / storage_path() / config_path() /
    //  resource_path() 内部实现为 `return app()->basePath($path)`，
    //  而 basePath() 只存在于 Illuminate\Foundation\Application，
    //  裸 Container 调用会抛
    //      Call to undefined method Illuminate\Container\Container::basePath()
    //
    //  注意：这些助手**函数本身始终存在**（helpers.php 已由 autoload 加载），
    //  因此用 function_exists() 作兜底是无效的（返回 true 后照样崩），
    //  正确判据是「容器实例上有没有对应方法」。
    //
    //  下面这组方法统一封装该判据，全包统一使用，任何环境下都不抛异常。
    // ==========================================================

    /**
     * 当前容器是否支持 Laravel 的 Foundation 路径助手
     *
     * 判据：Illuminate\Foundation\Application 提供了 basePath() / storagePath()
     * / configPath() / resourcePath() 实例方法，裸 Container 没有。
     */
    public static function supportsFoundationPaths(): bool
    {
        try {
            if (! function_exists('app')) {
                return false;
            }

            $app = app();

            return is_object($app)
                && method_exists($app, 'basePath')
                && method_exists($app, 'configPath');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 安全的 base_path()
     */
    public static function basePath(string $path = ''): string
    {
        if (self::supportsFoundationPaths()) {
            try {
                return base_path($path);
            } catch (\Throwable) {
                // 落到下面的兜底
            }
        }

        return (getcwd() ?: '.').($path !== '' ? '/'.ltrim($path, '/') : '');
    }

    /**
     * 安全的 storage_path()
     */
    public static function storagePath(string $path = ''): string
    {
        if (self::supportsFoundationPaths() && method_exists(app(), 'storagePath')) {
            try {
                return storage_path($path);
            } catch (\Throwable) {
                // 落到下面的兜底
            }
        }

        return self::basePath('storage'.($path !== '' ? '/'.ltrim($path, '/') : ''));
    }

    /**
     * 安全的 config_path()
     */
    public static function configPath(string $path = ''): string
    {
        if (self::supportsFoundationPaths()) {
            try {
                return config_path($path);
            } catch (\Throwable) {
                // 落到下面的兜底
            }
        }

        return self::basePath('config'.($path !== '' ? '/'.ltrim($path, '/') : ''));
    }

    /**
     * 安全的 resource_path()
     */
    public static function resourcePath(string $path = ''): string
    {
        if (self::supportsFoundationPaths() && method_exists(app(), 'resourcePath')) {
            try {
                return resource_path($path);
            } catch (\Throwable) {
                // 落到下面的兜底
            }
        }

        return self::basePath('resources'.($path !== '' ? '/'.ltrim($path, '/') : ''));
    }
}
