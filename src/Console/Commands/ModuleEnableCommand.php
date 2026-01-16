<?php

namespace zxf\Modules\Console\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Managers\ModuleManager;

class ModuleEnableCommand extends Command
{
    /**
     * 控制台命令的名称和签名。
     *
     * @var string
     */
    protected $signature = 'module:enable {name : 模块名称}';

    /**
     * 控制台命令描述。
     *
     * @var string
     */
    protected $description = '启用模块';

    /**
     * 执行控制台命令。
     */
    public function handle(ModuleManager $manager): int
    {
        $name = $this->argument('name');

        try {
            $manager->enable($name);
            $this->info("模块 [{$name}] 已成功启用。");
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}