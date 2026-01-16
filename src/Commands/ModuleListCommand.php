<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Facades\Module;

class ModuleListCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:list';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '列出所有模块';

    /**
     * 表头
     *
     * @var array
     */
    protected array $headers = ['#', 'Name', 'Status', 'Path', 'Namespace'];

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $modules = Module::all();

        if (empty($modules)) {
            $this->warn('No modules found.');

            return Command::SUCCESS;
        }

        $this->table($this->headers, $this->getModuleRows($modules));

        $this->info("Total: " . count($modules) . ' module(s)');
        $this->info("Enabled: " . count(Module::allEnabled()) . ' module(s)');
        $this->info("Disabled: " . count(Module::allDisabled()) . ' module(s)');

        return Command::SUCCESS;
    }

    /**
     * 获取模块行数据
     *
     * @param array $modules
     * @return array
     */
    protected function getModuleRows(array $modules): array
    {
        $rows = [];
        $index = 1;

        foreach ($modules as $module) {
            $rows[] = [
                $index++,
                $module->getName(),
                $module->isEnabled() ? '<fg=green>Enabled</>' : '<fg=red>Disabled</>',
                $module->getPath(),
                $module->getNamespace(),
            ];
        }

        return $rows;
    }
}
