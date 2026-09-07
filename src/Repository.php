<?php

declare(strict_types=1);

namespace zxf\Modules;

use Illuminate\Filesystem\Filesystem;
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Contracts\RepositoryInterface;
use zxf\Modules\Exceptions\ModuleNotFoundException;
use zxf\Modules\Support\ModuleCacheStore;
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
     * 启用模块内存缓存 [name => ModuleInterface]
     *
     * 由 allEnabled() 惰性填充，invalidateStatusCache() 失效。
     *
     * @var array<string, ModuleInterface>|null
     */
    protected ?array $enabledModules = null;

    /**
     * 禁用模块内存缓存 [name => ModuleInterface]
     *
     * 由 allDisabled() 惰性填充，invalidateStatusCache() 失效。
     *
     * @var array<string, ModuleInterface>|null
     */
    protected ?array $disabledModules = null;

    /**
     * 缓存存储路径
     */
    protected ?string $cachePath = null;

    /**
     * 缓存过期时间（秒）
     */
    protected int $cacheTtl = 3600;

    /**
     * 是否绕过缓存强制从磁盘扫描
     *
     * 用于 module:delete 等需要确保与磁盘状态一致的场景，
     * 避免读取到过期的模块缓存而误判“模块不存在”。
     */
    protected bool $bypassCache = false;

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
        $this->ensureScanned();

        if ($this->sortByPriority) {
            return $this->sortByPriority($this->modules);
        }

        return $this->modules;
    }

    public function allEnabled(): array
    {
        $this->ensureScanned();

        if ($this->enabledModules !== null) {
            return $this->enabledModules;
        }

        $enabledModules = [];

        foreach ($this->modules as $name => $module) {
            if ($module->isEnabled()) {
                $enabledModules[$name] = $module;
            }
        }

        if ($this->sortByPriority) {
            $enabledModules = $this->sortByPriority($enabledModules);
        }

        return $this->enabledModules = $enabledModules;
    }

    public function allDisabled(): array
    {
        $this->ensureScanned();

        if ($this->disabledModules !== null) {
            return $this->disabledModules;
        }

        $disabledModules = [];

        foreach ($this->modules as $name => $module) {
            if (! $module->isEnabled()) {
                $disabledModules[$name] = $module;
            }
        }

        if ($this->sortByPriority) {
            $disabledModules = $this->sortByPriority($disabledModules);
        }

        return $this->disabledModules = $disabledModules;
    }

    /**
     * 失效「启用/禁用模块」缓存
     *
     * 运行时调用 Module::setEnabled 改变模块状态后，需调用此方法使
     * allEnabled()/allDisabled() 的缓存结果失效，否则会返回过时的集合。
     */
    public function invalidateStatusCache(): void
    {
        $this->enabledModules = null;
        $this->disabledModules = null;
    }

    public function find(string $name): ?ModuleInterface
    {
        $this->ensureScanned();

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
        $this->ensureScanned();

        return array_keys($this->modules);
    }

    public function getEnabledNames(): array
    {
        return array_keys($this->allEnabled());
    }

    public function count(): int
    {
        $this->ensureScanned();

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

        // 尝试从缓存加载（除非显式要求绕过缓存）
        if (! $this->bypassCache && $this->loadFromCache()) {
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
     * 确保已完成至少一次扫描
     *
     * 所有公开查询方法都会先调用此方法，
     * 保证即使服务提供者 boot 阶段因故未触发扫描，
     * 或命令在扫描之前被调用，也能基于最新磁盘状态返回正确结果，
     * 避免“模块明明存在却提示不存在”的问题。
     */
    public function ensureScanned(): void
    {
        if (! $this->scanned) {
            $this->scan();
        }
    }

    /**
     * 强制重新扫描，忽略缓存
     *
     * 用于模块被新增/删除后需要以磁盘真实状态为准的场景。
     * 会临时绕过模块缓存，扫描结束后恢复正常缓存策略。
     */
    public function rescan(): void
    {
        $this->modules = [];
        $this->aliases = [];
        $this->scanned = false;
        $this->bypassCache = true;

        foreach ($this->paths as $path) {
            $this->scanPath($path, $this->namespace);
        }

        $this->saveToCache();
        $this->scanned = true;
        $this->bypassCache = false;
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
        // 清除模块内部缓存
        foreach ($this->modules as $module) {
            $module->clearCache();
        }

        // 删除模块元数据缓存文件
        $this->deleteCacheFile();

        // 同步清除自动发现清单缓存（与元数据缓存同生命周期）
        try {
            \zxf\Modules\Support\ModuleAutoDiscovery::clearDiscoveryCache();
        } catch (\Throwable) {
            // 忽略：自动发现未启用时不阻断清缓存
        }

        $this->invalidateStatusCache();
    }

    /**
     * 清除仓库级模块注册清单缓存并强制下次访问重新扫描磁盘
     *
     * 与 clearCache() 的区别：本方法额外重置「已扫描」标记与内存中的模块注册表，
     * 使下一次 any 查询必然重新扫描磁盘，适用于
     * module:make / module:delete / module:clear 等改变了磁盘模块集合的场景。
     */
    public function clearRepositoryCache(): void
    {
        $this->modules = [];
        $this->aliases = [];
        $this->scanned = false;
        $this->bypassCache = true;

        $this->clearCache();

        $this->bypassCache = false;
    }

    /**
     * 使缓存失效
     */
    protected function invalidateCache(): void
    {
        $this->invalidateStatusCache();
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
            // 以 JSON 读取（避免 require 被 OPcache 缓存、并杜绝缓存文件被篡改后
            // 执行任意 PHP 代码的风险）。读取失败一律安全回退到实时扫描。
            $data = ModuleCacheStore::read($cacheFile);

            if ($data === null || ! isset($data['modules'])) {
                return false;
            }

            // 防过期校验：若任意模块根目录的 mtime 发生变化（例如新增/删除了模块目录、
            // 或 git 部署拉取了新模块），说明缓存已不能反映磁盘真实状态，直接失效并
            // 回退到真实扫描，避免“新模块路由 404 / 已删模块仍被加载”等问题。
            // 仅做一次廉价的 filemtime 探测（远轻于已消除的逐模块目录扫描）。
            if (! $this->pathMtimesUnchanged($data['path_mtimes'] ?? [])) {
                @unlink($cacheFile);
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
                        // 缓存中始终以布尔值存储（saveToCache 写入 isEnabled() 结果），
                        // 默认 true 以避免 null 触发惰性重新读取配置文件。
                        'enabled' => $moduleData['enabled'] ?? true,
                    ]
                );

                $name = $module->getName();
                $this->modules[$name] = $module;

                foreach ($module->getAliases() as $alias) {
                    $this->aliases[$alias] = $name;
                }
            }

            // 缓存文件有效即视为命中（即使模块列表为空，也避免重复磁盘扫描）
            return true;
        } catch (\Throwable) {
            @unlink($cacheFile);
            return false;
        }
    }

    /**
     * 收集各模块根目录的修改时间（用于缓存防过期校验）
     *
     * 模块目录的增删（以及 git 部署拉取）都会改变其父目录的 mtime，
     * 因此比对这些 mtime 即可在廉价开销下判断模块集合是否发生变化。
     *
     * @return array<string, int|null>
     */
    protected function collectPathMtimes(): array
    {
        $mtimes = [];

        foreach ($this->paths as $path) {
            $mtimes[$path] = is_dir($path) ? @filemtime($path) : null;
        }

        return $mtimes;
    }

    /**
     * 判断缓存中记录的模块根目录 mtime 是否与磁盘当前一致
     */
    protected function pathMtimesUnchanged(array $stored): bool
    {
        foreach ($this->paths as $path) {
            $storedMtime = $stored[$path] ?? null;
            $currentMtime = is_dir($path) ? @filemtime($path) : null;

            if ($storedMtime !== $currentMtime) {
                return false;
            }
        }

        return true;
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

            ModuleCacheStore::write($cacheFile, [
                'modules' => $modulesData,
                'cached_at' => time(),
                'path_mtimes' => $this->collectPathMtimes(),
            ]);
        } catch (\Throwable) {
            // 缓存写入失败不中断
        }
    }

    /**
     * 获取缓存文件路径
     *
     * 注意：使用 .json 而非 .php —— 早期版本把缓存写成可执行的 PHP 文件并 require，
     * 在生产环境（opcache.validate_timestamps=0）会导致缓存永久不失效，
     * 且缓存文件一旦被篡改即可执行任意代码。
     */
    protected function getCacheFilePath(): string
    {
        return ($this->cachePath ?? storage_path('framework/cache/modules')) . '/modules.json';
    }

    /**
     * 删除缓存文件
     */
    protected function deleteCacheFile(): void
    {
        ModuleCacheStore::delete($this->getCacheFilePath());
    }
}
