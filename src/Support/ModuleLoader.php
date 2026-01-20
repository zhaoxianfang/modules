<?php

namespace zxf\Modules\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Contracts\RepositoryInterface;

/**
 * 模块加载器类
 *
 * 负责自动发现和加载所有模块组件
 * 包括配置、服务提供者、路由、视图、命令、迁移、翻译等
 *
 * 工作流程：
 * 1. 扫描模块目录，发现所有模块
 * 2. 检查模块是否启用
 * 3. 使用 ModuleAutoDiscovery 自动发现并加载模块的所有组件
 * 4. 记录发现摘要（调试模式下）
 *
 * 架构优化：
 * - 所有发现逻辑统一由 ModuleAutoDiscovery 处理
 * - ModuleLoader 仅负责模块的生命周期管理
 * - 避免代码重复，提高可维护性
 */
class ModuleLoader
{
    /**
     * 模块仓库
     *
     * 负责模块的扫描、存储和管理
     *
     * @var RepositoryInterface
     */
    protected RepositoryInterface $repository;

    /**
     * Laravel 应用实例
     *
     * @var Application
     */
    protected Application $app;

    /**
     * 创建新实例
     *
     * @param RepositoryInterface $repository 模块仓库实例
     * @param Application $app Laravel 应用实例
     */
    public function __construct(RepositoryInterface $repository, Application $app)
    {
        $this->repository = $repository;
        $this->app = $app;
    }

    /**
     * 加载所有模块
     *
     * 执行流程：
     * 1. 扫描模块目录，发现所有可用模块
     * 2. 遍历所有模块
     * 3. 对每个模块执行加载操作
     *
     * @return void
     */
    public function loadAll(): void
    {
        // 扫描模块目录
        $this->repository->scan();

        // 获取所有模块
        $modules = $this->repository->all();

        // 加载每个模块
        foreach ($modules as $module) {
            $this->loadModule($module);
        }
    }

    /**
     * 加载单个模块
     *
     * 使用智能自动发现机制加载模块的所有组件
     * 无需手动指定加载哪些文件
     *
     * 加载流程：
     * 1. 检查模块是否启用
     * 2. 使用 ModuleAutoDiscovery 自动发现并加载所有组件
     * 3. 记录发现摘要（调试模式下）
     *
     * 自动发现的组件包括：
     * - 配置文件（Config/ 目录）
     * - 中间件（Http/Middleware/ 目录）
     * - 路由文件（Routes/ 目录）
     * - 视图文件（Resources/views/ 目录）
     * - 迁移文件（Database/Migrations/ 目录）
     * - 翻译文件（Resources/lang/ 目录）
     * - Artisan 命令（Console/Commands/ 目录）
     * - 事件和监听器（Events/ 和 Listeners/ 目录）
     * - 模型观察者（Observers/ 目录）
     * - 策略类（Policies/ 目录）
     * - 仓库类（Repositories/ 目录）
     *
     * @param ModuleInterface $module 模块实例
     * @return void
     */
    public function loadModule(ModuleInterface $module): void
    {
        // 检查模块是否启用
        if (! $module->isEnabled()) {
            return;
        }

        // 使用智能自动发现器加载模块的所有组件
        // 自动发现：配置、中间件、路由、视图、迁移、翻译、命令、事件等
        // 注意：服务提供者也由 ModuleAutoDiscovery 统一管理
        $discovery = new ModuleAutoDiscovery($module);
        $discovery->discoverAll();

        // 可选：记录发现摘要（用于调试）
        if (config('app.debug', false)) {
            $summary = $discovery->getDiscoverySummary();
            $logs = $discovery->getLogs();
            logger()->info("Module [{$module->getName()}] discovered", $summary);
            if (! empty($logs)) {
                logger()->info("Module [{$module->getName()}] discovery logs", $logs);
            }
        }
    }



    /**
     * 重新加载所有模块
     *
     * @return void
     */
    public function reload(): void
    {
        $this->loadAll();
    }

    /**
     * 重新加载指定模块
     *
     * @param ModuleInterface $module
     * @return void
     */
    public function reloadModule(ModuleInterface $module): void
    {
        $this->loadModule($module);
    }

    /**
     * 获取已加载的模块列表
     *
     * @return array
     */
    public function getLoadedModules(): array
    {
        return $this->repository->all();
    }
}
