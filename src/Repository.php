<?php

declare(strict_types=1);

namespace zxf\Modules;

use Illuminate\Filesystem\Filesystem;
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Contracts\RepositoryInterface;
use zxf\Modules\Exceptions\ModuleNotFoundException;
use zxf\Modules\Support\ModuleContext;

/**
 * 模块仓库
 *
 * 负责模块的扫描、注册、存储和查询。支持:
 * - 多路径模块扫描
 * - 模块缓存（文件缓存 + 内存缓存）
 * - 优先级排序
 * - 批量操作
 * - 模块别名解析
 *
 * @package zxf\Modules
 * @version 4.0.0
 */
class Repository implements RepositoryInterface
{
    /**
     * 文件系统实例
     */
    protected Filesystem $files;

    /**
     * 所有已注册模块 [name => ModuleInterface]
     *
     * @var array<string, ModuleInterface>
     */
    protected array $modules = [];

    /**
     * 已启用模块缓存
     *
     * @var array<string, ModuleInterface>|null
     */
    protected ?array $enabledModules = null;

    /**
     * 已禁用模块缓存
     *
     * @var array<string, ModuleInterface>|null
     */
    protected ?array $disabledModules = null;

    /**
     * 模块别名映射 [alias => name]
     *
     * @var array<string, string>
     */
    protected array $aliases = [];

    /**
     * 模块根路径列表
     *
     * @var array<string>
     */
    protected array $paths = [];

    /**
     * 模块根命名空间
     */
    protected string $namespace;

    /**
     * 是否按优先级排序
     */
    protected bool $sortByPriority = true;

    /**
     * 是否已扫描
     */
    protected bool $scanned = false;

    /**
     * 缓存存储路径
     */
    protected ?string $cachePath = null;

    /**
     * 缓存过期时间（秒）
     */
    protected int $cacheTtl = 3600;

    /**
     * 创建新实例
     *
     * @param Filesystem     $files     文件系统
     * @param array|null     $paths     模块根路径列表
     * @param string|null    $namespace 模块根命名空间
     */
    public function __construct(
        Filesystem $files,
        ?array $paths = null,
        ?string $namespace = null
    ) {
        $this->files = $files;
        $this->namespace = $namespace ?? ModuleContext::getConfig('modules.namespace', 'Modules');
        $this->paths = $paths ?? [ModuleContext::getConfig('modules.path', base_path('Modules'))];
        $this->cachePath = ModuleContext::getConfig('modules.cache.path', storage_path('framework/cache/modules'));
        $this->cacheTtl = (int) ModuleContext::getConfig('modules.cache.ttl', 3600);
        $this->sortByPriority = ModuleContext::getConfig('modules.sort_by_priority', true);
    }

    /**
     * 添加模块扫描路径
     */
    public function addPath(string $path): self
    {
        $path = rtrim($path, '/\\');

        if (! in_array($path, $this->paths, true)) {
            $this->paths[] = $path;
        }

        return $this;
    }

    /**
     * 获取所有扫描路径
     */
    public function getPaths(): array
    {
        return $this->paths;
    }

    // ========================================================================
    //  查询操作
    // ========================================================================

    public function all(): array
    {
        if ($this->sortByPriority) {
            return $this->sortByPriority($this->modules);
        }

        return $this->modules;
    }

    public function allEnabled(): array
    {
        if ($this->enabledModules === null) {
            $this->enabledModules = [];

            foreach ($this->modules as $name => $module) {
                if ($module->isEnabled()) {
                    $this->enabledModules[$name] = $module;
                }
            }
        }

        if ($this->sortByPriority) {
            return $this->sortByPriority($this->enabledModules);
        }

        return $this->enabledModules;
    }

    public function allDisabled(): array
    {
        if ($this->disabledModules === null) {
            $this->disabledModules = [];

            foreach ($this->modules as $name => $module) {
                if (! $module->isEnabled()) {
                    $this->disabledModules[$name] = $module;
                }
            }
        }

        return $this->disabledModules;
    }

    public function find(string $name): ?ModuleInterface
    {
        // 直接匹配
        if (isset($this->modules[$name])) {
            return $this->modules[$name];
        }

        // 别名匹配
        if (isset($this->aliases[$name])) {
            return $this->modules[$this->aliases[$name]] ?? null;
        }

        // 不区分大小写匹配
        $lowerName = strtolower($name);
        foreach ($this->modules as $moduleName => $module) {
            if (strtolower($moduleName) === $lowerName) {
                return $module;
            }
        }

        return null;
    }

    /**
     * 查找模块，若不存在则抛出异常
     *
     * @throws ModuleNotFoundException
     */
    public function findOrFail(string $name): ModuleInterface
    {
        $module = $this->find($name);

        if ($module === null) {
            throw new ModuleNotFoundException($name);
        }

        return $module;
    }

    public function has(string $name): bool
    {
        return $this->find($name) !== null;
    }

    public function getNames(): array
    {
        return array_keys($this->modules);
    }

    public function getEnabledNames(): array
    {
        return array_keys($this->allEnabled());
    }

    public function count(): int
    {
        return count($this->modules);
    }

    public function countEnabled(): int
    {
        return count($this->allEnabled());
    }

    // ========================================================================
    //  扫描与注册
    // ========================================================================

    public function scan(): void
    {
        if ($this->scanned) {
            return;
        }

        // 尝试从缓存加载
        if ($this->loadFromCache()) {
            $this->scanned = true;
            return;
        }

        // 扫描所有模块路径
        foreach ($this->paths as $path) {
            $this->scanPath($path, $this->namespace);
        }

        // 保存到缓存
        $this->saveToCache();
        $this->scanned = true;
    }

    /**
     * 强制重新扫描，忽略缓存
     */
    public function rescan(): void
    {
        $this->modules = [];
        $this->aliases = [];
        $this->enabledModules = null;
        $this->disabledModules = null;
        $this->scanned = false;

        foreach ($this->paths as $path) {
            $this->scanPath($path, $this->namespace);
        }

        $this->saveToCache();
        $this->scanned = true;
    }

    /**
     * 扫描指定路径
     */
    protected function scanPath(string $path, string $namespace): void
    {
        if (! is_dir($path)) {
            return;
        }

        try {
            $directories = $this->files->directories($path);
        } catch (\Throwable) {
            return;
        }

        foreach ($directories as $directory) {
            $moduleName = basename($directory);

            // 跳过隐藏目录和特殊目录
            if (str_starts_with($moduleName, '.')) {
                continue;
            }

            // 跳过已注册的模块
            if (isset($this->modules[$moduleName])) {
                continue;
            }

            try {
                $module = new Module($moduleName, $directory, $namespace);
                $module->initialize();

                $this->modules[$moduleName] = $module;

                // 注册别名
                foreach ($module->getAliases() as $alias) {
                    $this->aliases[$alias] = $moduleName;
                }
            } catch (\Throwable $e) {
                // 单个模块加载失败不影响其他
                if (function_exists('logger')) {
                    logger()->warning("Failed to load module: {$moduleName}", [
                        'error' => $e->getMessage(),
                        'path' => $directory,
                    ]);
                }
            }
        }
    }

    /**
     * 手动注册模块
     */
    public function registerModule(ModuleInterface $module): self
    {
        $name = $module->getName();
        $this->modules[$name] = $module;
        $this->invalidateCache();

        // 注册别名
        foreach ($module->getAliases() as $alias) {
            $this->aliases[$alias] = $name;
        }

        return $this;
    }

    /**
     * 注销模块
     */
    public function unregisterModule(string $name): bool
    {
        if (! isset($this->modules[$name])) {
            return false;
        }

        $module = $this->modules[$name];

        // 清除别名
        foreach ($module->getAliases() as $alias) {
            unset($this->aliases[$alias]);
        }

        unset($this->modules[$name]);
        $this->invalidateCache();

        return true;
    }

    // ========================================================================
    //  路径获取
    // ========================================================================

    public function getModulePath(string $name, ?string $path = null): string
    {
        $module = $this->find($name);

        if ($module === null) {
            throw new \RuntimeException("Module [{$name}] not found.");
        }

        return $module->getPath($path);
    }

    // ========================================================================
    //  缓存管理
    // ========================================================================

    public function clearCache(): void
    {
        $this->enabledModules = null;
        $this->disabledModules = null;

        // 清除模块内部缓存
        foreach ($this->modules as $module) {
            $module->clearCache();
        }

        // 删除文件缓存
        $this->deleteCacheFile();
    }

    /**
     * 使缓存失效
     */
    protected function invalidateCache(): void
    {
        $this->enabledModules = null;
        $this->disabledModules = null;
    }

    /**
     * 按优先级排序
     */
    protected function sortByPriority(array $modules): array
    {
        uasort($modules, function (ModuleInterface $a, ModuleInterface $b): int {
            $priorityA = $a->getPriority();
            $priorityB = $b->getPriority();

            if ($priorityA === $priorityB) {
                return strcasecmp($a->getName(), $b->getName());
            }

            return $priorityA <=> $priorityB;
        });

        return $modules;
    }

    /**
     * 从文件缓存加载模块列表
     */
    protected function loadFromCache(): bool
    {
        if (! ModuleContext::getConfig('modules.cache.enabled', false)) {
            return false;
        }

        $cacheFile = $this->getCacheFilePath();

        if (! file_exists($cacheFile)) {
            return false;
        }

        // 检查缓存是否过期
        $mtime = filemtime($cacheFile);
        if ($this->cacheTtl > 0 && (time() - $mtime) > $this->cacheTtl) {
            @unlink($cacheFile);
            return false;
        }

        try {
            $data = require $cacheFile;

            if (! is_array($data) || ! isset($data['modules'])) {
                return false;
            }

            foreach ($data['modules'] as $moduleData) {
                if (! is_array($moduleData)) {
                    continue;
                }

                $module = new Module(
                    $moduleData['name'] ?? '',
                    $moduleData['path'] ?? '',
                    $moduleData['namespace'] ?? $this->namespace,
                    [
                        'priority' => $moduleData['priority'] ?? 1000,
                        'description' => $moduleData['description'] ?? '',
                        'aliases' => $moduleData['aliases'] ?? [],
                        'author' => $moduleData['author'] ?? '',
                        'version' => $moduleData['version'] ?? '1.0.0',
                        'enabled' => $moduleData['enabled'] ?? null,
                    ]
                );

                $name = $module->getName();
                $this->modules[$name] = $module;

                foreach ($module->getAliases() as $alias) {
                    $this->aliases[$alias] = $name;
                }
            }

            return ! empty($this->modules);
        } catch (\Throwable) {
            @unlink($cacheFile);
            return false;
        }
    }

    /**
     * 保存模块列表到文件缓存
     */
    protected function saveToCache(): void
    {
        if (! ModuleContext::getConfig('modules.cache.enabled', false)) {
            return;
        }

        if ($this->cachePath === null) {
            return;
        }

        $cacheFile = $this->getCacheFilePath();

        try {
            if (! is_dir($this->cachePath)) {
                @mkdir($this->cachePath, 0755, true);
            }

            $modulesData = [];
            foreach ($this->modules as $module) {
                $modulesData[] = [
                    'name' => $module->getName(),
                    'path' => $module->getPath(),
                    'namespace' => $module->getNamespace(),
                    'priority' => $module->getPriority(),
                    'description' => $module->getDescription(),
                    'aliases' => $module->getAliases(),
                    'author' => $module->getAuthor(),
                    'version' => $module->getVersion(),
                    'enabled' => $module->isEnabled(),
                ];
            }

            $content = '<?php return ' . var_export([
                'modules' => $modulesData,
                'cached_at' => time(),
            ], true) . ';';

            file_put_contents($cacheFile, $content, LOCK_EX);
        } catch (\Throwable) {
            // 缓存写入失败不中断
        }
    }

    /**
     * 获取缓存文件路径
     */
    protected function getCacheFilePath(): string
    {
        return ($this->cachePath ?? storage_path('framework/cache/modules')) . '/modules.php';
    }

    /**
     * 删除缓存文件
     */
    protected function deleteCacheFile(): void
    {
        $cacheFile = $this->getCacheFilePath();
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }
    }
}
