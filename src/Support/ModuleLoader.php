<?php

declare(strict_types=1);

namespace zxf\Modules\Support;

use Illuminate\Contracts\Foundation\Application;
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Contracts\RepositoryInterface;

/**
 * 模块加载器
 *
 * 负责模块生命周期管理和自动发现调度。
 *
 * 工作流程：
 * 1. Repository::scan() - 扫描并注册所有模块
 * 2. 按优先级遍历已启用模块
 * 3. ModuleAutoDiscovery::discoverAll() - 自动发现并加载模块组件
 *
 * @package zxf\Modules
 * @version 4.0.0
 */
class ModuleLoader
{
    /**
     * 模块仓库
     */
    protected RepositoryInterface $repository;

    /**
     * Laravel 应用实例
     */
    protected Application $app;

    /**
     * 已加载的模块名称列表
     *
     * @var array<string>
     */
    protected array $loadedModules = [];

    /**
     * 创建新实例
     */
    public function __construct(RepositoryInterface $repository, Application $app)
    {
        $this->repository = $repository;
        $this->app = $app;
    }

    /**
     * 加载所有模块
     *
     * 执行顺序：
     * 1. 扫描所有模块路径
     * 2. 按优先级排序已启用模块
     * 3. 依次加载每个模块
     */
    public function loadAll(): void
    {
        // 扫描模块目录（含缓存检查）
        $this->repository->scan();

        // 按优先级获取已启用模块
        $modules = $this->repository->allEnabled();

        if (empty($modules)) {
            return;
        }

        // 触发加载前事件
        $this->triggerBeforeLoad($modules);

        // 依次加载每个模块
        foreach ($modules as $module) {
            $this->loadModule($module);
        }

        // 触发加载后事件
        $this->triggerAfterLoad($modules);

        $this->loadedModules = $this->repository->getEnabledNames();
    }

    /**
     * 加载单个模块
     *
     * 使用智能自动发现机制加载模块的所有组件
     */
    public function loadModule(ModuleInterface $module): void
    {
        // 检查模块是否启用
        if (! $module->isEnabled()) {
            return;
        }

        // 模块实例化时可能需要初始化
        if (method_exists($module, 'initialize')) {
            $module->initialize();
        }

        // 使用智能自动发现器加载模块组件
        $discovery = new ModuleAutoDiscovery($module);
        $discovery->discoverAll();

        // 调试日志（仅在 debug 模式）
        if (config('app.debug', false) && config('modules.debug', false)) {
            $this->logDiscovery($module, $discovery);
        }
    }

    /**
     * 重新加载所有模块
     */
    public function reload(): void
    {
        // 清除全局命令缓存
        ModuleAutoDiscovery::clearGlobalCommands();

        // 清除自动发现清单缓存（避免复用过期清单）
        ModuleAutoDiscovery::clearDiscoveryCache();

        // 清除模块上下文缓存
        ModuleContext::clearCache();

        // 重新扫描
        $this->repository->rescan();

        // 重新加载
        $this->loadAll();
    }

    /**
     * 重新加载指定模块
     */
    public function reloadModule(ModuleInterface $module): void
    {
        // 清除模块缓存
        $module->clearCache();

        // 重新加载
        $this->loadModule($module);
    }

    /**
     * 获取已加载的模块列表
     *
     * @return array<string, ModuleInterface>
     */
    public function getLoadedModules(): array
    {
        $allModules = $this->repository->all();
        $result = [];

        foreach ($this->loadedModules as $name) {
            if (isset($allModules[$name])) {
                $result[$name] = $allModules[$name];
            }
        }

        return $result;
    }

    /**
     * 获取已加载模块名称
     *
     * @return array<string>
     */
    public function getLoadedModuleNames(): array
    {
        return $this->loadedModules;
    }

    /**
     * 获取模块加载统计
     */
    public function getStats(): array
    {
        $total = $this->repository->count();
        $enabled = $this->repository->countEnabled();
        $loaded = count($this->loadedModules);

        return [
            'total_modules' => $total,
            'enabled_modules' => $enabled,
            'disabled_modules' => $total - $enabled,
            'loaded_modules' => $loaded,
            'module_names' => $this->repository->getNames(),
            'enabled_names' => $this->repository->getEnabledNames(),
        ];
    }

    /**
     * 记录发现日志
     */
    protected function logDiscovery(ModuleInterface $module, ModuleAutoDiscovery $discovery): void
    {
        $summary = $discovery->getDiscoverySummary();

        if (function_exists('logger')) {
            logger()->debug("Module [{$module->getName()}] discovered", $summary);
        }
    }

    /**
     * 触发加载前事件
     */
    protected function triggerBeforeLoad(array $modules): void
    {
        // 可扩展：在模块加载前执行自定义逻辑
        // 例如：触发的自定义事件、数据库预热、权限初始化等
        if (config('modules.debug', false) && function_exists('logger')) {
            logger()->debug('Loading ' . count($modules) . ' enabled modules');
        }
    }

    /**
     * 触发加载后事件
     */
    protected function triggerAfterLoad(array $modules): void
    {
        // 可扩展：在模块加载后执行自定义逻辑
        // 例如：后加载校验、缓存预热、统计上报等
        if (config('modules.debug', false) && function_exists('logger')) {
            logger()->debug('Loaded ' . count($modules) . ' modules successfully');
        }
    }
}
