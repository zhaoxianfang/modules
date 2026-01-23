<?php

namespace zxf\Modules;

use Illuminate\Support\Str;
use zxf\Modules\Contracts\ModuleInterface;

class Module implements ModuleInterface
{
    /**
     * 模块名称
     *
     * @var string
     */
    protected string $name;

    /**
     * 模块路径
     *
     * @var string
     */
    protected string $path;

    /**
     * 模块命名空间
     *
     * @var string
     */
    protected string $namespace;

    /**
     * 配置缓存
     *
     * @var array
     */
    protected array $configCache = [];

    /**
     * 创建新实例
     *
     * @param string $name
     * @param string $path
     * @param string $namespace
     */
    public function __construct(string $name, string $path, string $namespace)
    {
        $this->name = $name;
        $this->path = $path;
        $this->namespace = $namespace;
    }

    /**
     * 获取模块名称
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 获取模块驼峰名称
     *
     * @return string
     */
    public function getCamelName(): string
    {
        return Str::camel($this->name);
    }

    /**
     * 获取模块小驼峰名称
     *
     * @return string
     */
    public function getLowerCamelName(): string
    {
        return lcfirst($this->getCamelName());
    }

    /**
     * 获取模块小写名称
     *
     * @return string
     */
    public function getLowerName(): string
    {
        return strtolower($this->name);
    }

    /**
     * 获取模块路径
     *
     * @param string|null $path
     * @return string
     */
    public function getPath(?string $path = null): string
    {
        if ($path) {
            return $this->path . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
        }

        return $this->path;
    }

    /**
     * 获取模块命名空间
     *
     * @return string
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * 检查模块是否已启用
     *
     * 直接读取模块配置文件中的 enabled 选项，不依赖 Laravel config
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        $configPath = $this->getConfigPath();

        if (! is_dir($configPath)) {
            // 如果没有配置目录，默认启用
            return true;
        }

        // 查找模块配置文件（优先使用小写名称）
        $configFile = $configPath . DIRECTORY_SEPARATOR . $this->getLowerName() . '.php';

        if (! file_exists($configFile)) {
            // 如果配置文件不存在，默认启用
            return true;
        }

        // 加载配置文件
        $config = require $configFile;

        // 检查 enabled 是否存在且为 false
        if (isset($config['enabled']) && $config['enabled'] === false) {
            return false;
        }

        // 默认启用（enabled 不存在或为 true）
        return true;
    }

    /**
     * 获取模块配置文件路径
     *
     * @return string
     */
    public function getConfigPath(): string
    {
        return $this->getPath('Config');
    }

    /**
     * 获取模块路由路径
     *
     * @return string
     */
    public function getRoutesPath(): string
    {
        return $this->getPath('Routes');
    }

    /**
     * 获取模块服务提供者路径
     *
     * @return string
     */
    public function getProvidersPath(): string
    {
        return $this->getPath('Providers');
    }

    /**
     * 获取模块命令路径
     *
     * @return string
     */
    public function getCommandsPath(): string
    {
        return $this->getPath('Console/Commands');
    }

    /**
     * 获取模块视图路径
     *
     * @return string
     */
    public function getViewsPath(): string
    {
        return $this->getPath('Resources/views');
    }

    /**
     * 获取模块迁移路径
     *
     * @return string
     */
    public function getMigrationsPath(): string
    {
        return $this->getPath('Database/Migrations');
    }

    /**
     * 获取模块控制器路径
     *
     * @return string
     */
    public function getControllersPath(): string
    {
        return $this->getPath('Http/Controllers');
    }

    /**
     * 获取模块配置值
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function config(string $key, $default = null)
    {
        $configKey = "{$this->getLowerName()}.{$key}";

        if (! isset($this->configCache[$configKey])) {
            $this->configCache[$configKey] = config($configKey, $default);
        }

        return $this->configCache[$configKey];
    }

    /**
     * 检查模块是否有某个路由文件
     *
     * @param string $route
     * @return bool
     */
    public function hasRoute(string $route): bool
    {
        return file_exists($this->getRoutesPath() . DIRECTORY_SEPARATOR . $route . '.php');
    }

    /**
     * 获取模块服务提供者类名
     *
     * @return string|null
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
     * @return array
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
     *
     * @return string
     */
    public function getClassNamespace(): string
    {
        return $this->namespace . '\\' . $this->name;
    }

    /**
     * 获取模块配置（直接从文件读取）
     *
     * @return array
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
}
