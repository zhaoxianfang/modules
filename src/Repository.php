<?php

namespace zxf\Modules;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Contracts\RepositoryInterface;

class Repository implements RepositoryInterface
{
    /**
     * 文件系统实例
     *
     * @var Filesystem
     */
    protected Filesystem $files;

    /**
     * 所有模块
     *
     * @var array
     */
    protected array $modules = [];

    /**
     * 已启用模块
     *
     * @var array
     */
    protected array $enabledModules = [];

    /**
     * 已禁用模块
     *
     * @var array
     */
    protected array $disabledModules = [];

    /**
     * 创建新实例
     *
     * @param Filesystem $files
     */
    public function __construct(Filesystem $files)
    {
        $this->files = $files;
    }

    /**
     * 获取所有模块
     *
     * @return array
     */
    public function all(): array
    {
        return $this->modules;
    }

    /**
     * 获取所有已启用的模块
     *
     * @return array
     */
    public function allEnabled(): array
    {
        if (empty($this->enabledModules)) {
            $this->enabledModules = array_filter($this->modules, function (ModuleInterface $module) {
                return $module->isEnabled();
            });
        }

        return $this->enabledModules;
    }

    /**
     * 获取所有已禁用的模块
     *
     * @return array
     */
    public function allDisabled(): array
    {
        if (empty($this->disabledModules)) {
            $this->disabledModules = array_filter($this->modules, function (ModuleInterface $module) {
                return ! $module->isEnabled();
            });
        }

        return $this->disabledModules;
    }

    /**
     * 获取指定模块
     *
     * @param string $name
     * @return ModuleInterface|null
     */
    public function find(string $name): ?ModuleInterface
    {
        return $this->modules[$name] ?? null;
    }

    /**
     * 检查模块是否存在
     *
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->modules[$name]);
    }

    /**
     * 获取所有模块名称
     *
     * @return array
     */
    public function getNames(): array
    {
        return array_keys($this->modules);
    }

    /**
     * 获取所有已启用模块名称
     *
     * @return array
     */
    public function getEnabledNames(): array
    {
        return array_keys($this->allEnabled());
    }

    /**
     * 扫描并注册所有模块
     *
     * @return void
     */
    public function scan(): void
    {
        $paths = config('modules.scan.paths', []);
        $namespace = config('modules.namespace', 'Modules');

        foreach ($paths as $path) {
            $this->scanPath($path, $namespace);
        }
    }

    /**
     * 扫描指定路径
     *
     * @param string $path
     * @param string $namespace
     * @return void
     */
    protected function scanPath(string $path, string $namespace): void
    {
        if (! is_dir($path)) {
            return;
        }

        $directories = $this->files->directories($path);

        foreach ($directories as $directory) {
            $moduleName = basename($directory);

            $module = new Module($moduleName, $directory, $namespace);

            $this->modules[$moduleName] = $module;
        }
    }

    /**
     * 获取模块路径
     *
     * @param string $name
     * @param string|null $path
     * @return string
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
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->enabledModules = [];
        $this->disabledModules = [];
    }
}
