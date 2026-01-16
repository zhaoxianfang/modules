<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use zxf\Modules\Facades\Module;

class MigrateResetCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:migrate-reset
                            {module? : 模块名称（可选）}
                            {--force : 强制运行，不提示确认}
                            {--path= : 指定迁移路径}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '回滚所有模块的最后一次数据库迁移';

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $moduleName = $this->argument('module');
        $force = $this->option('force');
        $customPath = $this->option('path');

        $this->info('正在回滚模块迁移...');

        if ($moduleName) {
            $this->resetModule($moduleName, $force, $customPath);
        } else {
            $this->resetAllModules($force, $customPath);
        }

        $this->info('迁移回滚完成！');

        return Command::SUCCESS;
    }

    /**
     * 回滚指定模块的迁移
     *
     * @param string $moduleName
     * @param bool $force
     * @param string|null $customPath
     * @return void
     */
    protected function resetModule(string $moduleName, bool $force, ?string $customPath = null): void
    {
        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在。");

            return;
        }

        if (! $module->isEnabled()) {
            $this->warn("模块 [{$moduleName}] 未启用，跳过。");

            return;
        }

        $this->info("正在回滚模块 [{$moduleName}] 的迁移...");

        $migrationPath = $customPath ?: $module->getMigrationsPath();

        if (! is_dir($migrationPath)) {
            $this->warn("模块 [{$moduleName}] 没有迁移文件。");

            return;
        }

        if (! $force && ! $this->confirm("确定要回滚模块 [{$moduleName}] 的最后一次迁移吗？")) {
            $this->info('操作已取消。');

            return;
        }

        $this->call('migrate:rollback', [
            '--path' => $this->getRelativePath($migrationPath),
            '--force' => true,
        ]);
    }

    /**
     * 回滚所有模块的迁移
     *
     * @param bool $force
     * @param string|null $customPath
     * @return void
     */
    protected function resetAllModules(bool $force, ?string $customPath = null): void
    {
        $modules = Module::allEnabled();

        if (empty($modules)) {
            $this->warn('没有已启用的模块。');

            return;
        }

        foreach ($modules as $module) {
            $this->resetModule($module->getName(), $force, $customPath);
        }
    }

    /**
     * 获取相对路径
     *
     * @param string $absolutePath
     * @return string
     */
    protected function getRelativePath(string $absolutePath): string
    {
        return str_replace(base_path() . DIRECTORY_SEPARATOR, '', $absolutePath);
    }
}
