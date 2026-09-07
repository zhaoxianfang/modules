<?php

declare(strict_types=1);

namespace zxf\Modules;

use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Support\ModuleContext;

/**
 * 模块实体类
 *
 * 代表一个具体的模块实例，封装模块的所有元数据和操作。
 *
 * 特性:
 * - 模块配置文件驱动（Config/{lower}.php 或 Config/config.php）
 * - 多级缓存（静态缓存 + 属性缓存）
 * - 模块状态管理（启用/禁用/优先级）
 * - 目录结构灵活配置
 * - 严格的类型安全和空值检查
 *
 * @package zxf\Modules
 * @version 5.0.0
 */
class Module implements ModuleInterface
{
    /**
     * 模块名称 (StudlyCase)
     */
    protected string $name;

    /**
     * 模块根路径
     */
    protected string $path;

    /**
     * 模块根命名空间
     */
    protected string $namespace;

    /**
     * 模块优先级（数字越小优先级越高）
     */
    protected int $priority = 1000;

    /**
     * 模块描述
     */
    protected string $description = '';

    /**
     * 模块别名列表
     */
    protected array $aliases = [];

    /**
     * 模块作者
     */
    protected string $author = '';

    /**
     * 模块版本
     */
    protected string $version = '1.0.0';

    /**
     * 是否启用
     */
    protected ?bool $enabled = null;

    /**
     * 模块主配置文件数据缓存（完整数组）
     */
    protected ?array $moduleConfigData = null;

    /**
     * 配置缓存
     */
    protected array $configCache = [];

    /**
     * 是否已初始化
     */
    protected bool $initialized = false;

    /**
     * 创建新模块实例
     *
     * @param string $name      模块名称 (StudlyCase)
     * @param string $path      模块根路径
     * @param string $namespace 根命名空间
     * @param array  $options   额外选项 [priority, description, aliases, author, version, enabled]
     */
    public function __construct(
        string $name,
        string $path,
        string $namespace,
        array $options = []
    ) {
        $this->name = $name;
        $this->path = rtrim($path, '/\\');
        $this->namespace = $namespace;

        // 从选项初始化属性（可由配置文件覆盖）
        $this->priority = (int) ($options['priority'] ?? $this->priority);
        $this->description = (string) ($options['description'] ?? $this->description);
        $this->aliases = (array) ($options['aliases'] ?? $this->aliases);
        $this->author = (string) ($options['author'] ?? $this->author);
        $this->version = (string) ($options['version'] ?? $this->version);

        // 如果提供了 enabled 状态，直接设置
        if (array_key_exists('enabled', $options)) {
            $this->enabled = (bool) $options['enabled'];
        }
    }

    /**
     * 初始化模块：加载模块配置文件并合并元数据
     *
     * 配置文件优先级：
     * 1. Config/{lower_name}.php
     * 2. Config/config.php
     *
     * 配置文件中可定义的模块元数据键：
     * - enabled: bool 是否启用
     * - priority: int 加载优先级
     * - description: string 模块描述
     * - author: string 作者
     * - version: string 版本号
     * - aliases: array 模块别名
     * - providers: array 额外的服务提供者类名
     * - laravel_aliases: array Laravel 门面别名
     */
    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->loadModuleConfigData();
        $this->initialized = true;
    }

    /**
     * 加载模块的配置文件并提取元数据
     *
     * 这是 composer.json 的替代方案，所有模块元数据从 Config/ 目录下的
     * PHP 配置文件读取，无需额外维护 composer.json。
     */
    protected function loadModuleConfigData(): void
    {
        $config = $this->getRawModuleConfig();

        if (empty($config) || ! is_array($config)) {
            $this->moduleConfigData = [];
            return;
        }

        $this->moduleConfigData = $config;

        // 从配置文件提取元数据（构造函数传入的值优先作为默认值）
        // 仅当构造函数未显式设置时才从配置文件取值
        $this->description = $this->description ?: ((string) ($config['description'] ?? ''));
        $this->version = $this->version !== '1.0.0' ? $this->version : ((string) ($config['version'] ?? '1.0.0'));
        $this->author = $this->author ?: ((string) ($config['author'] ?? ''));
        $this->priority = $this->priority !== 1000 ? $this->priority : ((int) ($config['priority'] ?? 1000));
        $this->aliases = $this->aliases ?: ((array) ($config['aliases'] ?? []));

        // enabled 状态：配置文件优先（构造函数传入的 options 优先级最高，
        // 因为构造函数会将 null 转为 bool false）
        if ($this->enabled === null && array_key_exists('enabled', $config)) {
            $this->enabled = (bool) $config['enabled'];
        }
    }

    /**
     * 读取模块原始配置文件（不经任何处理后处理）
     */
    protected function getRawModuleConfig(): array
    {
        $configPath = $this->getConfigPath();

        if (! is_dir($configPath)) {
            return [];
        }

        // 优先读取 {lower_name}.php
        $configFile = $configPath . DIRECTORY_SEPARATOR . $this->getLowerName() . '.php';

        if (! file_exists($configFile)) {
            // 回退到 config.php
            $configFile = $configPath . DIRECTORY_SEPARATOR . 'config.php';
        }

        if (! file_exists($configFile)) {
            return [];
        }

        try {
            $config = require $configFile;
            return is_array($config) ? $config : [];
        } catch (\Throwable) {
            return [];
        }
    }

    // ========================================================================
    //  基本信息获取
    // ========================================================================

    public function getName(): string
    {
        return $this->name;
    }

    public function getLowerName(): string
    {
        return strtolower($this->name);
    }

    public function getSnakeName(): string
    {
        return strtolower(preg_replace('/(?<=[a-z])([A-Z])/', '_$1', $this->name));
    }

    public function getStudlyName(): string
    {
        return $this->name;
    }

    public function getCamelName(): string
    {
        return lcfirst($this->name);
    }

    public function getLowerCamelName(): string
    {
        return lcfirst($this->name);
    }

    public function getSlugName(): string
    {
        return strtolower(preg_replace('/(?<=[a-z])([A-Z])/', '-$1', $this->name));
    }

    // ========================================================================
    //  路径和命名空间
    // ========================================================================

    /**
     * 按模块名查找模块（便捷静态工厂，委托给仓库）
     *
     * 兼容命令行中常见的 `Module::find($name)` 调用约定，避免各处重复
     * 写 `ModuleContext::getRepository()->find(...)`。
     *
     * @param string $name 模块名（任意大小写/命名风格均可，内部会归一化）
     */
    public static function find(string $name): ?ModuleInterface
    {
        return ModuleContext::getRepository()->find($name);
    }

    /**
     * 获取所有已注册模块（便捷静态委托）
     *
     * @return array<string, ModuleInterface>
     */
    public static function all(): array
    {
        return ModuleContext::getRepository()->all();
    }

    /**
     * 获取所有已启用模块（便捷静态委托）
     *
     * @return array<string, ModuleInterface>
     */
    public static function allEnabled(): array
    {
        return ModuleContext::getRepository()->allEnabled();
    }

    /**
     * 获取所有已禁用模块（便捷静态委托）
     *
     * @return array<string, ModuleInterface>
     */
    public static function allDisabled(): array
    {
        return ModuleContext::getRepository()->allDisabled();
    }

    /**
     * 已注册模块数量（便捷静态委托）
     */
    public static function count(): int
    {
        return ModuleContext::getRepository()->count();
    }

    /**
     * 重新扫描模块目录（便捷静态委托）
     */
    public static function rescan(): void
    {
        ModuleContext::getRepository()->rescan();
    }

    /**
     * 清除全局模块发现缓存（便捷静态委托）
     *
     * 与实例方法 clearCache()（清除单个模块的内部缓存）区分：
     * 本方法清除仓库层面的模块注册清单缓存，供命令行在重建/删除模块后调用。
     */
    public static function clearRepositoryCache(): void
    {
        ModuleContext::getRepository()->clearRepositoryCache();
    }

    public function getPath(?string $path = null): string
    {
        if ($path === null) {
            return $this->path;
        }

        return $this->path . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function getClassNamespace(): string
    {
        return $this->namespace . '\\' . $this->name;
    }

    // ========================================================================
    //  模块状态
    // ========================================================================

    public function isEnabled(): bool
    {
        if ($this->enabled !== null) {
            return $this->enabled;
        }

        // 确保配置文件已加载
        if (! $this->initialized) {
            $this->initialize();
        }

        $config = $this->moduleConfigData ?? [];

        // 配置文件显式禁用
        if (array_key_exists('enabled', $config) && $config['enabled'] === false) {
            $this->enabled = false;
            return false;
        }

        // 默认启用
        $this->enabled = true;
        return true;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    // ========================================================================
    //  元数据
    // ========================================================================

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getAliases(): array
    {
        return $this->aliases;
    }

    public function getAuthor(): string
    {
        return $this->author;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * @deprecated 5.0 已移除 composer.json 支持，此方法始终返回 null
     */
    public function getComposerData(): ?array
    {
        return null;
    }

    // ========================================================================
    //  目录路径
    // ========================================================================

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
        // 兼容两种目录约定：约定俗成的 Database/Migrations 与部分项目使用的
        // database/migrations（小写）。优先返回真实存在的目录，使迁移命令与
        // 自动发现走同一来源，避免「discovery 能识别但 module:migrate 看不到」。
        $primary = $this->getPath('Database/Migrations');
        if (is_dir($primary)) {
            return $primary;
        }

        $fallback = $this->getPath('database/migrations');
        if (is_dir($fallback)) {
            return $fallback;
        }

        return $primary;
    }

    public function getControllersPath(): string
    {
        return $this->getPath('Http/Controllers');
    }

    public function getSeedersPath(): string
    {
        return $this->getPath('Database/Seeders');
    }

    /**
     * 获取模型工厂目录路径
     */
    public function getFactoriesPath(): string
    {
        return $this->getPath('Database/Factories');
    }

    /**
     * 获取测试目录路径
     */
    public function getTestsPath(): string
    {
        return $this->getPath('Tests');
    }

    /**
     * 获取语言文件目录路径
     */
    public function getLangPath(): string
    {
        return $this->getPath('Resources/lang');
    }

    /**
     * 获取资源文件目录路径
     */
    public function getAssetsPath(): string
    {
        return $this->getPath('Resources/assets');
    }

    // ========================================================================
    //  文件/目录检查
    // ========================================================================

    /**
     * 检查指定路由文件是否存在
     */
    public function hasRoute(string $route): bool
    {
        return file_exists($this->getRoutesPath() . DIRECTORY_SEPARATOR . $route . '.php');
    }

    /**
     * 检查指定相对路径的目录是否存在
     */
    public function hasDirectory(string $relativePath): bool
    {
        return is_dir($this->getPath($relativePath));
    }

    /**
     * 检查指定相对路径的文件是否存在
     */
    public function hasFile(string $relativePath): bool
    {
        return file_exists($this->getPath($relativePath));
    }

    /**
     * 获取所有路由文件名（不含扩展名）
     *
     * @return array<string>
     */
    public function getRouteFiles(): array
    {
        $routesPath = $this->getRoutesPath();

        if (! is_dir($routesPath)) {
            return [];
        }

        $files = glob($routesPath . DIRECTORY_SEPARATOR . '*.php');

        if ($files === false) {
            return [];
        }

        return array_map(fn(string $file): string => pathinfo($file, PATHINFO_FILENAME), $files);
    }

    // ========================================================================
    //  配置操作
    // ========================================================================

    public function config(string $key, mixed $default = null): mixed
    {
        $fullKey = "{$this->getLowerName()}.{$key}";

        if (! array_key_exists($fullKey, $this->configCache)) {
            $this->configCache[$fullKey] = ModuleContext::getConfig($fullKey, $default);
        }

        return $this->configCache[$fullKey];
    }

    /**
     * 获取模块主配置文件完整数组
     *
     * 与初始化时加载的 moduleConfigData 保持一致，
     * 避免重复加载同一文件。
     *
     * @return array<string, mixed>
     */
    public function getModuleConfig(): array
    {
        if ($this->moduleConfigData !== null) {
            return $this->moduleConfigData;
        }

        // 未初始化时触发加载
        $this->loadModuleConfigData();
        return $this->moduleConfigData ?? [];
    }

    public function getServiceProviderClass(): ?string
    {
        // 优先尝试 {Module}ServiceProvider
        $className = $this->namespace . '\\' . $this->name . '\\Providers\\' . $this->name . 'ServiceProvider';

        if (class_exists($className)) {
            return $className;
        }

        // 回退到 ModuleServiceProvider
        $className = $this->namespace . '\\' . $this->name . '\\Providers\\ModuleServiceProvider';

        if (class_exists($className)) {
            return $className;
        }

        // 回退到 Providers/ 目录下的任意 ServiceProvider。
        // 优先选取与模块名同构的 {Name}ServiceProvider，避免 glob 返回顺序
        // 不确定时误选其它 Provider 类。
        $providersPath = $this->getProvidersPath();

        if (is_dir($providersPath)) {
            $files = glob($providersPath . '/*ServiceProvider.php') ?: [];
            $preferred = $this->name . 'ServiceProvider';
            $matched = null;

            foreach ($files as $file) {
                $basename = basename($file, '.php');
                if ($basename === $preferred) {
                    $matched = $basename;
                    break;
                }
                $matched ??= $basename;
            }

            if ($matched !== null) {
                $className = $this->namespace . '\\' . $this->name . '\\Providers\\' . $matched;
                if (class_exists($className)) {
                    return $className;
                }
            }
        }

        return null;
    }

    /**
     * 获取模块的额外 Laravel 服务提供者
     *
     * 从配置文件的 providers 键读取
     *
     * @return array<int, string>
     */
    public function getLaravelProviders(): array
    {
        return (array) ($this->getModuleConfig()['providers'] ?? []);
    }

    /**
     * 获取模块的额外 Laravel 门面别名
     *
     * 从配置文件的 laravel_aliases 键读取
     *
     * @return array<string, string>
     */
    public function getLaravelAliases(): array
    {
        return (array) ($this->getModuleConfig()['laravel_aliases'] ?? []);
    }

    /**
     * 清除模块内部缓存
     */
    public function clearCache(): void
    {
        $this->configCache = [];
        $this->moduleConfigData = null;
        $this->enabled = null;
        $this->initialized = false;
    }

    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        return [
            'name' => $this->getName(),
            'lower_name' => $this->getLowerName(),
            'snake_name' => $this->getSnakeName(),
            'studly_name' => $this->getStudlyName(),
            'camel_name' => $this->getCamelName(),
            'slug_name' => $this->getSlugName(),
            'path' => $this->getPath(),
            'namespace' => $this->getNamespace(),
            'class_namespace' => $this->getClassNamespace(),
            'enabled' => $this->isEnabled(),
            'priority' => $this->getPriority(),
            'description' => $this->getDescription(),
            'author' => $this->getAuthor(),
            'version' => $this->getVersion(),
            'aliases' => $this->getAliases(),
        ];
    }
}
