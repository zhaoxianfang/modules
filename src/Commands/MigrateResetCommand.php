<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\MigrationPathHelper;

/**
 * 模块迁移重置命令
 *
 * 回滚指定模块或全部模块的数据库迁移。
 *
 * 参照 nWidart/laravel-modules 的 MigrateResetCommand 设计：
 * - 通过路径过滤回滚模块迁移（--path 选项）
 * - 生产环境确认
 * - 返回正确的退出码
 *
 * 与 MigrateRollbackCommand 的区别：
 * - migrate:rollback 只回滚最近一次迁移批次
 * - migrate:reset   回滚所有迁移（重复调用直至全部回滚）
 *
 * 使用示例：
 *   php artisan module:migrate-reset Blog              # 回滚 Blog 模块所有迁移
 *   php artisan module:migrate-reset Blog --force      # 强制回滚，不提示确认
 *   php artisan module:migrate-reset                   # 回滚所有模块的迁移
 *
 * @package zxf\Modules\Commands
 */
class MigrateResetCommand extends Command
{
    use MigrationPathHelper;

    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:migrate-reset
                            {module? : 模块名称（可选）}
                            {--force : 强制运行，不提示确认（生产环境使用时请谨慎）}
                            {--path= : 指定迁移路径}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '回滚指定模块或所有模块的全部数据库迁移';

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

        // 生产环境确认
        if (! $force && app()->environment('production')) {
            $this->components->warn('⚠ 当前运行在生产环境！');
            $this->components->warn('此操作将回滚所有迁移，可能导致数据丢失！');
            $confirmed = $this->components->confirm('确定要回滚迁移吗？');

            if (! $confirmed) {
                $this->components->info('操作已取消。');

                return Command::SUCCESS;
            }
        }

        $this->components->info('正在回滚模块迁移...');

        if ($moduleName) {
            return $this->resetModule($moduleName, $force, $customPath);
        }

        return $this->resetAllModules($force, $customPath);
    }

    /**
     * 回滚指定模块的迁移
     *
     * @param string $moduleName
     * @param bool $force
     * @param string|null $customPath
     * @return int
     */
    protected function resetModule(string $moduleName, bool $force, ?string $customPath = null): int
    {
        $module = Module::find($moduleName);

        if (! $module) {
            $this->components->error("模块 [{$moduleName}] 不存在。");

            return Command::FAILURE;
        }

        if (! $module->isEnabled()) {
            $this->components->warn("模块 [{$moduleName}] 未启用，跳过。");

            return Command::SUCCESS;
        }

        $this->components->info("正在回滚模块 [{$moduleName}] 的迁移...");

        $migrationPath = $customPath ?: $module->getMigrationsPath();

        if (! is_dir($migrationPath)) {
            $this->components->warn("模块 [{$moduleName}] 没有迁移文件。");

            return Command::SUCCESS;
        }

        $exitCode = $this->call('migrate:rollback', [
            '--path' => $this->getRelativePath($migrationPath),
            '--force' => $force,
        ]);

        return $exitCode === Command::SUCCESS ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * 回滚所有模块的迁移
     *
     * @param bool $force
     * @param string|null $customPath
     * @return int
     */
    protected function resetAllModules(bool $force, ?string $customPath = null): int
    {
        $modules = Module::allEnabled();

        if (empty($modules)) {
            $this->components->warn('没有已启用的模块。');

            return Command::SUCCESS;
        }

        $hasFailures = false;

        foreach ($modules as $module) {
            $result = $this->resetModule(
                $module->getName(),
                $force,
                $customPath
            );

            if ($result === Command::FAILURE) {
                $hasFailures = true;
            }
        }

        $this->components->info('迁移回滚完成！');

        return $hasFailures ? Command::FAILURE : Command::SUCCESS;
    }
}
