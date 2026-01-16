<?php

namespace zxf\Modules\Console\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Facades\Module;

class ModuleUninstallCommand extends Command
{
    /**
     * 控制台命令的名称和签名。
     *
     * @var string
     */
    protected $signature = 'module:uninstall 
                            {name : 模块名称}
                            {--migrations : 回滚迁移}
                            {--force : 强制卸载（忽略依赖关系）}';

    /**
     * 控制台命令描述。
     *
     * @var string
     */
    protected $description = '卸载模块（回滚迁移、清理资源）';

    /**
     * 执行控制台命令。
     */
    public function handle(): int
    {
        $name = $this->argument('name');

        if (!Module::exists($name)) {
            $this->error("模块 [{$name}] 未找到。");
            return Command::FAILURE;
        }

        $module = Module::find($name);

        $this->info("正在卸载模块: {$name}");

        // 检查是否有其他模块依赖此模块
        if (!$this->option('force')) {
            $dependents = $this->getDependentModules($name);
            if (!empty($dependents)) {
                $this->error("无法卸载模块 [{$name}]。以下模块依赖于此模块: " . implode(', ', $dependents));
                return Command::FAILURE;
            }
        }

        // 运行模块卸载逻辑
        $module->uninstall();

        // 可选回滚迁移
        if ($this->option('migrations')) {
            $this->call('migrate:rollback', [
                '--path' => $module->getMigrations(),
            ]);
        }

        // 禁用模块
        if ($module->isEnabled()) {
            $this->info("正在禁用模块...");
            Module::disable($name);
        }

        // 触发模块已卸载事件（如果配置允许）
        $dispatchEvents = config('modules.dispatch_events', true);
        if ($dispatchEvents) {
            event(new \zxf\Modules\Events\ModuleUninstalled($module));
        }

        $this->info("模块 [{$name}] 卸载成功。");

        return Command::SUCCESS;
    }

    /**
     * 获取依赖给定模块的模块列表。
     *
     * @return array<string>
     */
    protected function getDependentModules(string $moduleName): array
    {
        $dependents = [];

        foreach (Module::all() as $name => $module) {
            $dependencies = $module->getDependencies();
            if (in_array($moduleName, $dependencies)) {
                $dependents[] = $name;
            }
        }

        return $dependents;
    }
}