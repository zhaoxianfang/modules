<?php

declare(strict_types=1);

namespace zxf\Modules\Support;

use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Contracts\RepositoryInterface;

/**
 * 模块上下文解析器
 *
 * 抽象框架依赖，提供统一的上下文解析接口。
 * 这是降低 Laravel 框架依赖的核心类——所有 helper 函数通过此类间接访问框架功能。
 *
 * 设计原则：
 * - 使用静态缓存减少重复调用
 * - 自动降级：框架功能不可用时返回安全默认值
 * - 零异常传播：所有方法内部捕获异常
 *
 * @package zxf\Modules
 * @version 4.0.0
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
     * @param string $key     配置键
     * @param mixed  $default 默认值
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
     * @param string $key   配置键
     * @param mixed  $value 配置值
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
     *
     * 自动识别当前代码或请求所在的模块上下文。
     *
     * @param bool $toLower        是否返回小写蛇形命名
     * @param bool $requestModule  路由检测模式
     * @return string
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

                if (preg_match('/^' . preg_quote($namespace, '/') . '\\\\([^\\\\]+)/', $class, $matches)) {
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
        $modulePath = rtrim(str_replace('\\', '/', self::getConfig('modules.path', base_path('Modules'))), '/') . '/';
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
            if ($dir && self::getRepository()->has($dir)) {
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
    }
}
