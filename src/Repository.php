<?php

namespace zxf\Modules;

use Illuminate\Filesystem\Filesystem;
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Contracts\RepositoryInterface;
use zxf\Modules\Support\ModuleCacheManager;

/**
 * 模块仓库
 *
 * 优化点：
 * 1. 使用 ModuleCacheManager 缓存模块列表，避免重复扫描文件系统
 * 2. 延迟扫描，只在需要时执行
 * 3. 批量操作优化
 * 4. 支持缓存失效机制
 */
class Repository implements RepositoryInterface
{
    /**
     * 文件系统实例
     */
    protected Filesystem $files;

    /**
     * 缓存管理器
     */
    protected ModuleCacheManager $cache;

    /**
     * 所有模块
     *
     * @var array<string, ModuleInterface>
     */
    protected array $modules = [];

    /**
     * 是否已扫描
     */
    protected bool $scanned = false;

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
     * 模块基础路径
     */
    protected string $basePath;

    /**
     * 模块命名空间
     */
    protected string $namespace;

    /**
     * 创建新实例
     */
    public function __construct(Filesystem $files)
    {
        $this->files = $files;
        $this->basePath = config('modules.path', base_path('Modules'));
        $this->namespace = config('modules.namespace', 'Modules');
    }

    /**
     * 设置缓存管理器
     */
    public function setCacheManager(ModuleCacheManager $cache): void
    {
        $this->cache = $cache;
    }

    /**
     * 获取缓存管理器
     */
    protected function getCache(): ModuleCacheManager
    {
        if (! isset($this->cache)) {
            $this->cache = app(ModuleCacheManager::class);
        }

        return $this->cache;
    }

    /**
     * 获取所有模块
     *
     * @return array<string, ModuleInterface>
     */
    public function all(): array
    {
        $this->ensureScanned();

        return $this->modules;
    }

    /**
     * 获取所有已启用的模块
     *
     * @return array<string, ModuleInterface>
     */
    public function allEnabled(): array
    {
        if ($this->enabledModules !== null) {
            return $this->enabledModules;
        }

        $this->ensureScanned();

        $this->enabledModules = [];
        foreach ($this->modules as $name => $module) {
            if ($module->isEnabled()) {
                $this->enabledModules[$name] = $module;
            }
        }

        return $this->enabledModules;
    }

    /**
     * 获取所有已禁用的模块
     *
     * @return array<string, ModuleInterface>
     */
    public function allDisabled(): array
    {
        if ($this->disabledModules !== null) {
            return $this->disabledModules;
        }

        $this->ensureScanned();

        $this->disabledModules = [];
        foreach ($this->modules as $name => $module) {
            if (! $module->isEnabled()) {
                $this->disabledModules[$name] = $module;
            }
        }

        return $this->disabledModules;
    }

    /**
     * 获取指定模块
     */
    public function find(string $name): ?ModuleInterface
    {
        $this->ensureScanned();

        return $this->modules[$name] ?? null;
    }

    /**
     * 检查模块是否存在
     */
    public function has(string $name): bool
    {
        $this->ensureScanned();

        return isset($this->modules[$name]);
    }

    /**
     * 获取所有模块名称
     *
     * @return array<string>
     */
    public function getNames(): array
    {
        $this->ensureScanned();

        return array_keys($this->modules);
    }

    /**
     * 获取所有已启用模块名称
     *
     * @return array<string>
     */
    public function getEnabledNames(): array
    {
        return array_keys($this->allEnabled());
    }

    /**
     * 确保已扫描
     * 优化：先检查缓存，避免重复扫描文件系统
     */
    protected function ensureScanned(): void
    {
        if ($this->scanned) {
            return;
        }

        // 尝试从缓存获取
        $cached = $this->getCache()->getAllModules();
        if ($cached !== null) {
            $this->modules = $this->hydrateModules($cached);
            $this->scanned = true;

            return;
        }

        // 执行扫描
        $this->scan();
    }

    /**
     * 扫描并注册所有模块
     * 优化：支持缓存扫描结果
     */
    public function scan(): void
    {
        if ($this->scanned) {
            return;
        }

        try {
            // 自动扫描模块根路径
            $this->scanPath($this->basePath, $this->namespace);

            // 缓存扫描结果
            if ($this->getCache()->isEnabled()) {
                $cacheData = $this->dehydrateModules($this->modules);
                $this->getCache()->setAllModules($cacheData);
            }

            $this->scanned = true;
        } catch (\Throwable $e) {
            // 扫描失败时不抛出异常，只是记录日志
            logger()->error('扫描模块目录失败', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
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

            foreach ($directories as $directory) {
                try {
                    $moduleName = basename($directory);

                    // 跳过隐藏目录
                    if (str_starts_with($moduleName, '.')) {
                        continue;
                    }

                    $module = new Module($moduleName, $directory, $namespace);

                    $this->modules[$moduleName] = $module;
                } catch (\Throwable $e) {
                    // 单个模块加载失败不影响其他模块
                    logger()->warning('加载模块失败: ' . basename($directory), [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            logger()->error('扫描路径失败: ' . $path, [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 序列化模块数据用于缓存
     *
     * @param array<string, ModuleInterface> $modules
     * @return array<string, array>
     */
    protected function dehydrateModules(array $modules): array
    {
        $data = [];
        foreach ($modules as $name => $module) {
            $data[$name] = [
                'name' => $module->getName(),
                'path' => $module->getPath(),
                'namespace' => $module->getNamespace(),
                'enabled' => $module->isEnabled(),
            ];
        }

        return $data;
    }

    /**
     * 从缓存数据反序列化模块
     *
     * @param array<string, array> $data
     * @return array<string, ModuleInterface>
     */
    protected function hydrateModules(array $data): array
    {
        $modules = [];
        foreach ($data as $name => $moduleData) {
            try {
                $module = new Module(
                    $moduleData['name'],
                    $moduleData['path'],
                    $moduleData['namespace']
                );
                $modules[$name] = $module;
            } catch (\Throwable $e) {
                logger()->warning('恢复模块缓存失败: ' . $name, [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $modules;
    }

    /**
     * 获取模块路径
     */
    public function getModulePath(string $name, ?string $path = null): string
    {
        $module = $this->find($name);

        if (! $module) {
            throw new \RuntimeException("Module [{$name}] not found.");
        }

        return $module->getPath($path);
    }

    /**
     * 清除缓存
     */
    public function clearCache(): void
    {
        $this->enabledModules = null;
        $this->disabledModules = null;
        $this->getCache()->clear();
    }

    /**
     * 刷新模块列表
     * 清除缓存并重新扫描
     */
    public function refresh(): void
    {
        $this->clearCache();
        $this->modules = [];
        $this->scanned = false;
        $this->scan();
    }
}
