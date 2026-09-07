<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\MigrationPathHelper;

/**
 * 模块迁移回滚命令
 *
 * 回滚指定模块或全部模块的最近一次迁移。
 *
 * 参照 nWidart/laravel-modules 的 MigrateRollbackCommand 设计：
 * - 通过路径过滤回滚模块迁移（--path 选项）
 * - 支持 --step 选项控制回滚步数
 * - 生产环境确认
 *
 * 与 MigrateResetCommand 的区别：
 * - migrate:rollback 只回滚最近一次迁移批次
 * - migrate:reset   回滚所有迁移
 *
 * 使用示例：
 *   php artisan module:migrate-rollback Blog          # 回滚 Blog 模块最近一次迁移
 *   php artisan module:migrate-rollback Blog --step=2 # 回滚最近 2 次迁移
 *   php artisan module:migrate-rollback               # 回滚所有模块的最近一次迁移
 *
 * @package zxf\Modules\Commands
 */
class MigrateRollbackCommand extends Command
{
    use MigrationPathHelper;

    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:migrate-rollback
                            {module? : 模块名称（可选）}
                            {--database= : 指定数据库连接}
                            {--force : 强制运行，不提示确认}
                            {--step= : 回滚的迁移步数（默认为 1）}
                            {--path= : 指定自定义迁移文件路径}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '回滚指定模块或所有模块的最近一次数据库迁移';

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $moduleName = $this->argument('module');
        $database = $this->option('database');
        $force = $this->option('force');
        $step = $this->option('step');
        $customPath = $this->option('path');

        // 生产环境确认
        if (! $force && app()->environment('production')) {
            $this->components->warn('⚠ 当前运行在生产环境！');
            $confirmed = $this->components->confirm('确定要回滚迁移吗？');

            if (! $confirmed) {
                $this->components->info('操作已取消。');

                return Command::SUCCESS;
            }
        }

        $this->components->info('正在回滚模块迁移...');

        if ($moduleName) {
            return $this->rollbackModule($moduleName, $database, $force, $step, $customPath);
        }

        return $this->rollbackAllModules($database, $force, $step, $customPath);
    }

    /**
     * 回滚指定模块的迁移
     *
     * @param string $moduleName
     * @param string|null $database
     * @param bool $force
     * @param string|null $step
     * @param string|null $customPath
     * @return int
     */
    protected function rollbackModule(
        string $moduleName,
        ?string $database,
        bool $force,
        ?string $step,
        ?string $customPath = null
    ): int {
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

        $params = [
            '--path' => $this->getRelativePath($migrationPath),
            '--force' => $force,
        ];

        if ($database) {
            $params['--database'] = $database;
        }

        if ($step) {
            $params['--step'] = (int) $step;
        }

        $exitCode = $this->call('migrate:rollback', $params);

        return $exitCode === Command::SUCCESS ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * 回滚所有模块的迁移
     *
     * @param string|null $database
     * @param bool $force
     * @param string|null $step
     * @param string|null $customPath
     * @return int
     */
    protected function rollbackAllModules(
        ?string $database,
        bool $force,
        ?string $step,
        ?string $customPath = null
    ): int {
        $modules = Module::allEnabled();

        if (empty($modules)) {
            $this->components->warn('没有已启用的模块。');

            return Command::SUCCESS;
        }

        $hasFailures = false;

        foreach ($modules as $module) {
            $result = $this->rollbackModule(
                $module->getName(),
                $database,
                $force,
                $step,
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
