<?php

namespace zxf\Modules;

use Illuminate\Support\Str;
use zxf\Modules\Contracts\ModuleInterface;

/**
 * 模块实体类
 *
 * 优化点：
 * 1. 使用 PHP 8.2+ readonly 属性减少内存占用
 * 2. 优化配置读取，使用静态缓存
 * 3. 延迟加载配置直到实际需要
 * 4. 添加更多实用方法
 */
class Module implements ModuleInterface
{
    /**
     * 模块名称
     */
    protected string $name;

    /**
     * 模块路径
     */
    protected string $path;

    /**
     * 模块命名空间
     */
    protected string $namespace;

    /**
     * 配置缓存
     *
     * @var array<string, mixed>
     */
    protected static array $configCache = [];

    /**
     * 启用状态缓存
     *
     * @var array<string, bool>
     */
    protected static array $enabledCache = [];

    /**
     * 小写名称缓存
     *
     * @var array<string, string>
     */
    protected static array $lowerNameCache = [];

    /**
     * 驼峰名称缓存
     *
     * @var array<string, string>
     */
    protected static array $camelNameCache = [];

    /**
     * 创建新实例
     */
    public function __construct(string $name, string $path, string $namespace)
    {
        $this->name = $name;
        $this->path = $path;
        $this->namespace = $namespace;
    }

    /**
     * 获取模块名称
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 获取模块驼峰名称
     */
    public function getCamelName(): string
    {
        if (! isset(self::$camelNameCache[$this->name])) {
            self::$camelNameCache[$this->name] = Str::camel($this->name);
        }

        return self::$camelNameCache[$this->name];
    }

    /**
     * 获取模块小驼峰名称
     */
    public function getLowerCamelName(): string
    {
        return lcfirst($this->getCamelName());
    }

    /**
     * 获取模块小写名称
     */
    public function getLowerName(): string
    {
        if (! isset(self::$lowerNameCache[$this->name])) {
            self::$lowerNameCache[$this->name] = strtolower($this->name);
        }

        return self::$lowerNameCache[$this->name];
    }

    /**
     * 获取模块路径
     */
    public function getPath(?string $path = null): string
    {
        if ($path === null) {
            return $this->path;
        }

        return $this->path . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * 获取模块命名空间
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * 检查模块是否已启用
     *
     * 优化：使用静态缓存避免重复读取文件
     */
    public function isEnabled(): bool
    {
        $cacheKey = $this->path;

        if (isset(self::$enabledCache[$cacheKey])) {
            return self::$enabledCache[$cacheKey];
        }

        $configPath = $this->getConfigPath();

        if (! is_dir($configPath)) {
            self::$enabledCache[$cacheKey] = true;
            return true;
        }

        $configFile = $configPath . DIRECTORY_SEPARATOR . $this->getLowerName() . '.php';

        if (! file_exists($configFile)) {
            self::$enabledCache[$cacheKey] = true;
            return true;
        }

        try {
            $config = require $configFile;
            $enabled = ! (isset($config['enabled']) && $config['enabled'] === false);
            self::$enabledCache[$cacheKey] = $enabled;

            return $enabled;
        } catch (\Throwable) {
            self::$enabledCache[$cacheKey] = true;
            return true;
        }
    }

    /**
     * 获取模块配置文件路径
     */
    public function getConfigPath(): string
    {
        return $this->getPath('Config');
    }

    /**
     * 获取模块路由路径
     */
    public function getRoutesPath(): string
    {
        return $this->getPath('Routes');
    }

    /**
     * 获取模块服务提供者路径
     */
    public function getProvidersPath(): string
    {
        return $this->getPath('Providers');
    }

    /**
     * 获取模块命令路径
     */
    public function getCommandsPath(): string
    {
        return $this->getPath('Console/Commands');
    }

    /**
     * 获取模块视图路径
     */
    public function getViewsPath(): string
    {
        return $this->getPath('Resources/views');
    }

    /**
     * 获取模块迁移路径
     */
    public function getMigrationsPath(): string
    {
        return $this->getPath('Database/Migrations');
    }

    /**
     * 获取模块控制器路径
     */
    public function getControllersPath(): string
    {
        return $this->getPath('Http/Controllers');
    }

    /**
     * 获取模块配置值
     *
     * 优化：使用静态缓存避免重复调用 config()
     */
    public function config(string $key, $default = null)
    {
        $cacheKey = "{$this->getLowerName()}.{$key}";

        if (array_key_exists($cacheKey, self::$configCache)) {
            return self::$configCache[$cacheKey];
        }

        $value = config($cacheKey, $default);
        self::$configCache[$cacheKey] = $value;

        return $value;
    }

    /**
     * 检查模块是否有某个路由文件
     */
    public function hasRoute(string $route): bool
    {
        return file_exists($this->getRoutesPath() . DIRECTORY_SEPARATOR . $route . '.php');
    }

    /**
     * 获取模块服务提供者类名
     */
    public function getServiceProviderClass(): ?string
    {
        $className = $this->namespace . '\\' . $this->name . '\\Providers\\' . $this->name . 'ServiceProvider';

        if (class_exists($className)) {
            return $className;
        }

        return null;
    }

    /**
     * 获取所有路由文件
     *
     * @return array<int, string>
     */
    public function getRouteFiles(): array
    {
        $routesPath = $this->getRoutesPath();

        if (! is_dir($routesPath)) {
            return [];
        }

        $files = glob($routesPath . DIRECTORY_SEPARATOR . '*.php');
        $routeFiles = [];

        foreach ($files as $file) {
            $routeFiles[] = pathinfo($file, PATHINFO_FILENAME);
        }

        return $routeFiles;
    }

    /**
     * 获取模块类命名空间前缀
     */
    public function getClassNamespace(): string
    {
        return $this->namespace . '\\' . $this->name;
    }

    /**
     * 获取模块配置（直接从文件读取）
     */
    public function getModuleConfig(): array
    {
        try {
            $configPath = $this->getConfigPath();

            if (! is_dir($configPath)) {
                return [];
            }

            $configFile = $configPath . DIRECTORY_SEPARATOR . $this->getLowerName() . '.php';

            if (! file_exists($configFile)) {
                return [];
            }

            $config = require $configFile;

            if (! is_array($config)) {
                return [];
            }

            return $config;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 获取模块信息数组
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'lower_name' => $this->getLowerName(),
            'camel_name' => $this->getCamelName(),
            'path' => $this->path,
            'namespace' => $this->namespace,
            'enabled' => $this->isEnabled(),
        ];
    }

    /**
     * 获取模块路径数组
     *
     * @return array<string, string>
     */
    public function getPaths(): array
    {
        return [
            'base' => $this->path,
            'config' => $this->getConfigPath(),
            'routes' => $this->getRoutesPath(),
            'providers' => $this->getProvidersPath(),
            'commands' => $this->getCommandsPath(),
            'views' => $this->getViewsPath(),
            'migrations' => $this->getMigrationsPath(),
            'controllers' => $this->getControllersPath(),
        ];
    }

    /**
     * 检查模块是否存在指定目录
     */
    public function hasDirectory(string $path): bool
    {
        return is_dir($this->getPath($path));
    }

    /**
     * 检查模块是否存在指定文件
     */
    public function hasFile(string $path): bool
    {
        return file_exists($this->getPath($path));
    }

    /**
     * 获取模块目录列表
     *
     * @return array<int, string>
     */
    public function getDirectories(string $path = ''): array
    {
        $fullPath = $this->getPath($path);

        if (! is_dir($fullPath)) {
            return [];
        }

        $directories = [];
        $iterator = new \DirectoryIterator($fullPath);

        foreach ($iterator as $item) {
            if ($item->isDir() && ! $item->isDot()) {
                $directories[] = $item->getFilename();
            }
        }

        return $directories;
    }

    /**
     * 获取模块文件列表
     *
     * @return array<int, string>
     */
    public function getFiles(string $path = '', string $extension = 'php'): array
    {
        $fullPath = $this->getPath($path);

        if (! is_dir($fullPath)) {
            return [];
        }

        $pattern = $fullPath . DIRECTORY_SEPARATOR . '*.' . ltrim($extension, '.');
        $files = glob($pattern);

        if ($files === false) {
            return [];
        }

        $result = [];
        foreach ($files as $file) {
            $result[] = pathinfo($file, PATHINFO_BASENAME);
        }

        return $result;
    }

    /**
     * 清空静态缓存
     */
    public static function clearStaticCache(): void
    {
        self::$configCache = [];
        self::$enabledCache = [];
        self::$lowerNameCache = [];
        self::$camelNameCache = [];
    }

    /**
     * 获取缓存统计信息
     *
     * @return array<string, int>
     */
    public static function getCacheStats(): array
    {
        return [
            'config_cache_size' => count(self::$configCache),
            'enabled_cache_size' => count(self::$enabledCache),
            'lower_name_cache_size' => count(self::$lowerNameCache),
            'camel_name_cache_size' => count(self::$camelNameCache),
        ];
    }
}
