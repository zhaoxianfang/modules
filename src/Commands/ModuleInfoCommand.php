<?php

declare(strict_types=1);

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
    protected $signature = 'module:info
                            {name : 模块名称（例如：Blog）}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '显示指定模块的详细信息和统计数据';

    /**
     * 执行命令
     *
     * 显示模块的以下信息：
     * 1. 基本信息（名称、路径、命名空间等）
     * 2. 功能信息（配置、路由、视图等）
     * 3. 路由文件列表
     * 4. 统计信息（文件数量、占用空间）
     * 5. 服务提供者信息
     *
     * @return int
     */
    public function handle(): int
    {
        $moduleName = Str::studly($this->argument('name'));

        // 验证模块是否存在
        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在");
            $this->line("提示：使用 php artisan module:list 查看所有可用模块");
            return Command::FAILURE;
        }

        // 获取模块信息
        $info = ModuleInfo::getInfo($module);

        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("  模块 [{$moduleName}] 详细信息");
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->newLine();

        // 基本信息
        $this->line('<comment>📋 基本信息：</comment>');
        $this->table(
            ['属性', '值'],
            [
                ['模块名称', $info['name']],
                ['小写名称', $info['lower_name']],
                ['驼峰名称', $info['camel_name']],
                ['小驼峰名称', $info['lower_camel_name']],
                ['模块路径', $info['path']],
                ['命名空间', $info['namespace']],
                ['启用状态', $info['enabled'] ? '<fg=green>✓ 已启用</>' : '<fg=red>✗ 已禁用</>'],
            ]
        );

        $this->newLine();

        // 功能信息
        $this->line('<comment>⚙️  功能信息：</comment>');
        $this->table(
            ['功能组件', '状态'],
            [
                ['配置文件', $info['has_config'] ? '<fg=green>✓ 存在</>' : '<fg=red>✗ 不存在</>'],
                ['路由文件', $info['has_routes'] ? '<fg=green>✓ 存在</>' : '<fg=red>✗ 不存在</>'],
                ['视图文件', $info['has_views'] ? '<fg=green>✓ 存在</>' : '<fg=red>✗ 不存在</>'],
                ['迁移文件', $info['has_migrations'] ? '<fg=green>✓ 存在</>' : '<fg=red>✗ 不存在</>'],
                ['数据填充器', $info['has_seeders'] ? '<fg=green>✓ 存在</>' : '<fg=red>✗ 不存在</>'],
                ['命令类', $info['has_commands'] ? '<fg=green>✓ 存在</>' : '<fg=red>✗ 不存在</>'],
            ]
        );

        $this->newLine();

        // 路由文件列表
        if (! empty($info['route_files'])) {
            $this->line('<comment>🛣️  路由文件：</comment>');
            foreach ($info['route_files'] as $routeFile) {
                $this->line("  • {$routeFile}.php");
            }
            $this->newLine();
        }

        // 统计信息
        $this->line('<comment>📊 统计信息：</comment>');
        $this->table(
            ['统计项', '数值'],
            [
                ['文件总数', $info['files_count']],
                ['占用空间', $info['size']],
            ]
        );

        // 服务提供者
        if ($info['service_provider']) {
            $this->newLine();
            $this->line('<comment>🔧 服务提供者：</comment>');
            $this->line("  {$info['service_provider']}");
        }

        $this->newLine();
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        return Command::SUCCESS;
    }
}
