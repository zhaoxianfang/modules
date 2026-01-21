<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use zxf\Modules\Facades\Module;

class ModuleDeleteCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:delete
                            {name : 模块名称（例如：Blog）}
                            {--force : 强制删除，不提示确认，谨慎使用！}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '删除一个模块（包括所有文件和数据）';

    /**
     * 执行命令
     *
     * 删除模块前会进行确认（除非使用 --force）
     * 注意：删除操作不可恢复，请谨慎操作
     *
     * @return int
     */
    public function handle(): int
    {
        $name = Str::studly($this->argument('name'));
        $force = $this->option('force');

        // 验证模块是否存在
        $module = Module::find($name);

        if (! $module) {
            $this->error("模块 [{$name}] 不存在");
            $this->line("提示：使用 php artisan module:list 查看所有可用模块");
            return Command::FAILURE;
        }

        $modulePath = $module->getPath();

        // 显示警告信息
        $this->warn("⚠️  警告：此操作将永久删除模块 [{$name}]");
        $this->line("模块路径: {$modulePath}");
        $this->line("");

        if (! $force) {
            if (! $this->confirm("确定要删除模块 [{$name}] 及其所有文件吗？此操作不可恢复！", false)) {
                $this->info('操作已取消');
                return Command::SUCCESS;
            }
        }

        // 删除模块目录
        if (! File::deleteDirectory($modulePath)) {
            $this->error("删除模块 [{$name}] 失败");
            $this->line("提示：检查文件权限");
            return Command::FAILURE;
        }

        $this->info("✓ 模块 [{$name}] 已成功删除");
        $this->line("删除的路径: {$modulePath}");
        $this->line("");
        $this->line("提示：如果该模块有数据库迁移，请手动执行数据库回滚");

        return Command::SUCCESS;
    }
}
