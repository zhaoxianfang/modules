<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\ModuleInfo;

class ModuleInfoCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:info {name : 模块名称}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '显示指定模块的详细信息';

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $moduleName = Str::studly($this->argument('name'));

        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在。");

            return Command::FAILURE;
        }

        $info = ModuleInfo::getInfo($module);

        $this->info("模块 [{$moduleName}] 的详细信息：");
        $this->newLine();

        // 基本信息
        $this->line('<comment>基本信息：</comment>');
        $this->table(
            ['属性', '值'],
            [
                ['名称', $info['name']],
                ['小写名称', $info['lower_name']],
                ['驼峰名称', $info['camel_name']],
                ['小驼峰名称', $info['lower_camel_name']],
                ['路径', $info['path']],
                ['命名空间', $info['namespace']],
                ['状态', $info['enabled'] ? '<fg=green>已启用</>' : '<fg=red>已禁用</>'],
            ]
        );

        $this->newLine();

        // 功能信息
        $this->line('<comment>功能信息：</comment>');
        $this->table(
            ['功能', '状态'],
            [
                ['配置文件', $info['has_config'] ? '<fg=green>是</>' : '<fg=red>否</>'],
                ['路由文件', $info['has_routes'] ? '<fg=green>是</>' : '<fg=red>否</>'],
                ['视图文件', $info['has_views'] ? '<fg=green>是</>' : '<fg=red>否</>'],
                ['迁移文件', $info['has_migrations'] ? '<fg=green>是</>' : '<fg=red>否</>'],
                ['数据填充器', $info['has_seeders'] ? '<fg=green>是</>' : '<fg=red>否</>'],
                ['命令类', $info['has_commands'] ? '<fg=green>是</>' : '<fg=red>否</>'],
            ]
        );

        $this->newLine();

        // 路由文件列表
        if (! empty($info['route_files'])) {
            $this->line('<comment>路由文件：</comment>');
            foreach ($info['route_files'] as $routeFile) {
                $this->line("  - {$routeFile}.php");
            }
            $this->newLine();
        }

        // 统计信息
        $this->line('<comment>统计信息：</comment>');
        $this->table(
            ['属性', '值'],
            [
                ['文件数量', $info['files_count']],
                ['占用空间', $info['size']],
            ]
        );

        // 服务提供者
        if ($info['service_provider']) {
            $this->newLine();
            $this->line('<comment>服务提供者：</comment>');
            $this->line("  {$info['service_provider']}");
        }

        return Command::SUCCESS;
    }
}
