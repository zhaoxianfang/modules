<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\MigrationPathHelper;

/**
 * 模块迁移清空命令
 *
 * 删除所有数据表后重新运行迁移（相当于 migrate:fresh 的模块版本）。
 *
 * 参照 nWidart/laravel-modules 的 MigrateFreshCommand 设计：
 * - 删除所有表后重新迁移
 * - 支持 --seed + --seeder 自动填充
 * - 生产环境确认
 * - 支持 --database 选项
 *
 * 使用示例：
 *   php artisan module:migrate-fresh Blog                        # 清空并重建 Blog 模块
 *   php artisan module:migrate-fresh Blog --seed                 # 清空重建后运行 Seeder
 *   php artisan module:migrate-fresh Blog --seeder=PostSeeder    # 清空重建后运行指定 Seeder
 *   php artisan module:migrate-fresh                             # 清空并重建所有模块
 *
 * @package zxf\Modules\Commands
 */
class MigrateFreshCommand extends Command
{
    use MigrationPathHelper;

    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:migrate-fresh
                            {module? : 模块名称（可选）}
                            {--database= : 指定数据库连接}
                            {--force : 强制运行，不提示确认}
                            {--seed : 清空重建后运行数据填充}
                            {--seeder= : 指定特定的数据填充器（仅类名，不含命名空间）}
                            {--drop-views : 同时删除所有视图}
                            {--drop-types : 同时删除所有自定义类型（PostgreSQL）}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '清空所有数据表后重新运行模块迁移';

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
        $seed = $this->option('seed');
        $seeder = $this->option('seeder');
        $dropViews = $this->option('drop-views');
        $dropTypes = $this->option('drop-types');

        // 生产环境确认
        if (! $force && app()->environment('production')) {
            $this->components->warn('⚠ 当前运行在生产环境！');
            $this->components->warn('此操作将删除所有数据表！');
            $confirmed = $this->components->confirm('确定要继续吗？');

            if (! $confirmed) {
                $this->components->info('操作已取消。');

                return Command::SUCCESS;
            }
        }

        $this->components->info('正在清空数据库并重新运行模块迁移...');

        if ($moduleName) {
            return $this->freshModule($moduleName, $database, $force, $seed, $seeder, $dropViews, $dropTypes);
        }

        return $this->freshAllModules($database, $force, $seed, $dropViews, $dropTypes);
    }

    /**
     * 清空并重建指定模块的迁移
     *
     * @param string $moduleName
     * @param string|null $database
     * @param bool $force
     * @param bool $seed
     * @param string|null $seeder
     * @param bool $dropViews
     * @param bool $dropTypes
     * @return int
     */
    protected function freshModule(
        string $moduleName,
        ?string $database,
        bool $force,
        bool $seed,
        ?string $seeder,
        bool $dropViews,
        bool $dropTypes
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

        $this->components->info("正在清空并重建模块 [{$moduleName}]...");

        $migrationPath = $this->getRelativePath($module->getMigrationsPath());

        // Step 1: 调用 Laravel 原生 migrate:fresh
        $freshParams = [
            '--path' => $migrationPath,
            '--force' => $force,
        ];

        if ($database) {
            $freshParams['--database'] = $database;
        }

        if ($dropViews) {
            $freshParams['--drop-views'] = true;
        }

        if ($dropTypes) {
            $freshParams['--drop-types'] = true;
        }

        $this->call('migrate:fresh', $freshParams);

        // Step 2: 运行数据填充（委托给 module:seed）
        if ($seed || $seeder) {
            $this->newLine();
            $this->components->info('正在运行数据填充...');

            $seedParams = [
                'module' => $moduleName,
            ];

            if ($seeder) {
                $seedParams['--class'] = $seeder;
            }

            if ($database) {
                $seedParams['--database'] = $database;
            }

            if ($force) {
                $seedParams['--force'] = true;
            }

            $this->call('module:seed', $seedParams);
        }

        return Command::SUCCESS;
    }

    /**
     * 清空并重建所有模块的迁移
     *
     * @param string|null $database
     * @param bool $force
     * @param bool $seed
     * @param bool $dropViews
     * @param bool $dropTypes
     * @return int
     */
    protected function freshAllModules(
        ?string $database,
        bool $force,
        bool $seed,
        bool $dropViews,
        bool $dropTypes
    ): int {
        $modules = Module::allEnabled();

        if (empty($modules)) {
            $this->components->warn('没有已启用的模块。');

            return Command::SUCCESS;
        }

        // 全模块模式 + --seeder：警告用户不会传递
        if ($seeder) {
            $this->components->warn('⚠ 全模块模式不支持 --seeder 选项，已忽略。');
            $this->components->warn('  提示: 使用 module:seed <ModuleName> --class=' . $seeder . ' 单独运行指定 Seeder。');
        }

        $hasFailures = false;

        foreach ($modules as $module) {
            $result = $this->freshModule(
                $module->getName(),
                $database,
                $force,
                $seed,
                // 全模块模式不传递 --seeder（避免在所有模块运行同一个 Seeder）
                null,
                $dropViews,
                $dropTypes
            );

            if ($result === Command::FAILURE) {
                $hasFailures = true;
            }
        }

        $this->components->info('清空并重建完成！');

        return $hasFailures ? Command::FAILURE : Command::SUCCESS;
    }
}
