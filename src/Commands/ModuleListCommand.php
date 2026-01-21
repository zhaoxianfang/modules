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
    protected $description = '列出所有已安装的模块及其状态';

    /**
     * 表头
     *
     * @var array
     */
    protected array $headers = ['#', '模块名称', '状态', '路径', '命名空间'];

    /**
     * 执行命令
     *
     * 显示所有模块的详细信息，包括：
     * - 模块名称
     * - 启用/禁用状态
     * - 模块路径
     * - 命名空间
     *
     * @return int
     */
    public function handle(): int
    {
        $modules = Module::all();

        if (empty($modules)) {
            $this->warn('未找到任何模块');
            $this->line("");
            $this->line("提示：使用 php artisan module:make <ModuleName> 创建第一个模块");
            return Command::SUCCESS;
        }

        $this->table($this->headers, $this->getModuleRows($modules));

        $this->newLine();
        $this->info("总计: " . count($modules) . ' 个模块');
        $this->info("已启用: " . count(Module::allEnabled()) . ' 个');
        $this->info("已禁用: " . count(Module::allDisabled()) . ' 个');
        $this->newLine();
        $this->line("使用 php artisan module:info <ModuleName> 查看模块详细信息");

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
                $module->isEnabled() ? '<fg=green>✓ 已启用</>' : '<fg=red>✗ 已禁用</>',
                $module->getPath(),
                $module->getNamespace(),
            ];
        }

        return $rows;
    }
}
