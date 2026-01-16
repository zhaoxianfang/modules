<?php

namespace zxf\Modules\Console\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Facades\Module;

class ModuleInstallCommand extends Command
{
    /**
     * 控制台命令的名称和签名。
     *
     * @var string
     */
    protected $signature = 'module:install 
                            {name : 模块名称}
                            {--migrations : 运行迁移}
                            {--seeders : 运行数据填充器}
                            {--publish : 发布资源}';

    /**
     * 控制台命令描述。
     *
     * @var string
     */
    protected $description = '安装模块（运行迁移、数据填充器、发布资源）';

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

        $this->info("正在安装模块: {$name}");

        // 如果模块尚未启用，则启用模块
        if (!$module->isEnabled()) {
            $this->info("正在启用模块...");
            Module::enable($name);
        }

        // 运行模块安装逻辑
        $module->install();

        // 可选运行迁移
        if ($this->option('migrations')) {
            $this->call('migrate', [
                '--path' => $module->getMigrations(),
            ]);
        }

        // 可选运行数据填充器
        if ($this->option('seeders')) {
            $seeders = $module->getSeeders();
            foreach ($seeders as $seeder) {
                if (class_exists($seeder)) {
                    $this->call('db:seed', ['--class' => $seeder]);
                }
            }
        }

        // 可选发布资源
        if ($this->option('publish')) {
            $this->call('module:publish', ['name' => $name]);
        }

        // 触发模块已安装事件（如果配置允许）
        $dispatchEvents = config('modules.dispatch_events', true);
        if ($dispatchEvents) {
            event(new \zxf\Modules\Events\ModuleInstalled($module));
        }

        $this->info("模块 [{$name}] 安装成功。");

        return Command::SUCCESS;
    }
}