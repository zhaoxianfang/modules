<?php

declare(strict_types=1);

namespace zxf\Modules\Support;

use Illuminate\Contracts\Container\Container;
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Contracts\RepositoryInterface;

/**
 * 模块加载器
 * 负责模块生命周期管理和自动发现调度。
 * 工作流程：
 * 1. Repository::scan() - 扫描并注册所有模块
 * 2. 按优先级遍历已启用模块
 * 3. ModuleAutoDiscovery::discoverAll() - 自动发现并加载模块组件
 *
 * @version 1.13.9
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
    /**
     * 容器实例
     *
     * 类型为 Container 基类契约（原为 Foundation\Application）。
     * 注意：本命名空间下还存在一个 zxf\Modules\Support\Application 类，
     * 若不显式声明类型，属性会解析到它并导致赋值 TypeError。
     */
    protected ?Container $app = null;

    /**
     * 已加载的模块名称列表
     *
     * @var array<string>
     */
    protected array $loadedModules = [];

    /**
     * 「已进入加载流程」的模块名集合（幂等守卫）
     *
     * 与 $loadedModules 区分：
     *  - $loadedSet 是**过程守卫**，loadAll() 一开始就置位，
     *    用于阻止同一模块被重复 discoverAll()（重复 require 路由文件）；
     *  - $loadedModules 是**结果快照**，在整轮结束后才写入，
     *    供 getLoadedModules() / countLoadedModules() 读取。
     *
     * @var array<string, bool>
     */
    protected array $loadedSet = [];

    /**
     * 创建新实例
     */
    /**
     * @param  RepositoryInterface  $repository  模块仓库
     * @param  Container|null  $app  容器（可选，仅用于后续扩展）
     *
     * 原形参类型是 `Illuminate\Contracts\Foundation\Application`。
     * 该契约**只有** Illuminate\Foundation\Application 实现，
     * 裸 Container（测试用容器、精简内核）传入时会直接抛 TypeError，
     * 而且报错完全指不到「模块加载器」这一层。
     * 本类内部并未使用 $app，放宽为 Container 基类契约即可。
     */
    public function __construct(RepositoryInterface $repository, ?Container $app = null)
    {
        $this->repository = $repository;

        if ($app !== null) {
            $this->app = $app;
        }
    }

    /**
     * 加载所有模块
     * 执行顺序：
     * 1. 扫描所有模块路径
     * 2. 按优先级排序已启用模块
     * 3. 依次加载每个模块
     */
    public function loadAll(): void
    {
        // 每次都会调 loadAll()。在以下场景会对同一模块执行两次 loadModule()：
        //   - 同一请求内 provider 被重复注册/boot 两次（如测试中重复 boot、
        //     某些包的 registerProviders 合并逻辑）；
        //   - reload() 与 boot() 交叉调用；
        //   - 长驻进程（Octane / queue worker）重新 boot 应用。
        // 后果不是「多做一遍无用功」而是**直接报错**：模块路由文件会被
        // 二次 require，php artisan route:cache 会抛
        //     LogicException: Unable to prepare route [/x] for serialization.
        //     Another route has already been assigned name [x].
        // 现在：已加载过的模块名直接跳过；reload() 会显式清空该集合。
        $this->repository->scan();

        // 按优先级获取已启用模块
        $modules = $this->repository->allEnabled();

        if (empty($modules)) {
            // 提前返回时必须同步清空 loadedModules，否则它会残留上一次的结果
            // （例如上一轮有模块、本轮全被禁用），
            // getLoadedModules() / countLoadedModules() 就会给出过期数据。
            $this->loadedModules = [];

            return;
        }

        $this->triggerBeforeLoad($modules);

        foreach ($modules as $module) {
            $this->loadModule($module);
        }

        $this->triggerAfterLoad($modules);

        $this->loadedModules = $this->repository->getEnabledNames();
    }

    /**
     * 加载单个模块
     * 使用智能自动发现机制加载模块的所有组件
     */
    public function loadModule(ModuleInterface $module): void
    {
        // 检查模块是否启用
        if (! $module->isEnabled()) {
            return;
        }

        // 同一模块只加载一次。
        // 详见 loadAll() 的说明：重复加载会导致路由被二次 require，
        // route:cache 抛 LogicException（路由名重复占用）。
        $name = $module->getName();

        if (isset($this->loadedSet[$name])) {
            return;
        }

        $this->loadedSet[$name] = true;

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

        // 清空幂等守卫，否则 loadAll() 会把所有模块当成
        // 「已加载」而全部跳过，reload() 变成空操作。
        $this->loadedSet = [];
        $this->loadedModules = [];

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

        // reloadModule() 是「显式重载单个模块」，
        // 必须先解除该模块的幂等守卫，否则 loadModule() 会直接 return，
        // 让 reloadModule() 变成空操作（与 reload() 同类问题）。
        unset($this->loadedSet[$module->getName()]);

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
            logger()->debug('Loading '.count($modules).' enabled modules');
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
            logger()->debug('Loaded '.count($modules).' modules successfully');
        }
    }
}
