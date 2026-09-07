<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\MigrationPathHelper;

/**
 * 模块迁移命令
 *
 * 运行指定模块或全部模块的数据库迁移，支持迁移后自动数据填充。
 *
 * 参照 nWidart/laravel-modules 的 MigrateCommand 设计：
 * - 通过路径过滤运行模块迁移（--path + --realpath）
 * - --seed 标志触发 module:seed 命令（而非直接调用 db:seed）
 * - --seeder 指定特定 Seeder 类，内部委托给 module:seed --class=...
 * - 生产环境强制确认（app()->environment('production') 时提示）
 *
 * 使用示例：
 *   php artisan module:migrate Blog                          # 运行 Blog 模块迁移
 *   php artisan module:migrate Blog --seed                   # 迁移后运行 Blog 模块 Seeder
 *   php artisan module:migrate Blog --seeder=PostSeeder      # 迁移后运行指定 Seeder
 *   php artisan module:migrate                               # 运行所有已启用模块的迁移
 *
 * @package zxf\Modules\Commands
 */
class MigrateCommand extends Command
{
    use MigrationPathHelper;

    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:migrate
                            {module? : 模块名称（可选，不指定则运行所有模块）}
                            {--force : 强制运行，不提示确认（生产环境使用时请谨慎）}
                            {--path= : 指定自定义迁移文件路径}
                            {--seed : 迁移完成后自动运行数据填充}
                            {--seeder= : 指定特定的数据填充器类（仅类名，不含命名空间）}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '运行所有模块或指定模块的数据库迁移，可配合数据填充一起使用';

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
        $seed = $this->option('seed');
        $seeder = $this->option('seeder');

        // nWidart 设计：生产环境需要确认
        if (! $force && app()->environment('production')) {
            $this->components->warn('⚠ 当前运行在生产环境！');
            $confirmed = $this->components->confirm('确定要运行迁移吗？');

            if (! $confirmed) {
                $this->components->info('操作已取消。');

                return Command::SUCCESS;
            }
        }

        $this->components->info('正在运行模块迁移...');

        if ($moduleName) {
            return $this->migrateModule($moduleName, $force, $customPath, $seed, $seeder);
        }

        return $this->migrateAllModules($force, $customPath, $seed, $seeder);
    }

    /**
     * 运行指定模块的迁移
     *
     * @param string $moduleName
     * @param bool $force
     * @param string|null $customPath
     * @param bool $seed
     * @param string|null $seeder
     * @return int
     */
    protected function migrateModule(
        string $moduleName,
        bool $force,
        ?string $customPath = null,
        bool $seed = false,
        ?string $seeder = null
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

        $this->components->info("正在运行模块 [{$moduleName}] 的迁移...");

        $migrationPath = $customPath ?: $module->getMigrationsPath();

        if (! is_dir($migrationPath)) {
            $this->components->warn("模块 [{$moduleName}] 没有迁移文件。");

            return Command::SUCCESS;
        }

        $relativePath = $this->getRelativePath($migrationPath);

        $exitCode = $this->call('migrate', [
            '--path' => $relativePath,
            '--force' => $force,
        ]);

        if ($exitCode !== Command::SUCCESS) {
            return Command::FAILURE;
        }

        // 运行数据填充（委托给 module:seed 命令，参照 nWidart 设计）
        if ($seed || $seeder) {
            $this->newLine();
            $this->components->info('正在运行数据填充...');

            $seedParams = [
                'module' => $moduleName,
            ];

            if ($seeder) {
                $seedParams['--class'] = $seeder;
            }

            if ($force) {
                $seedParams['--force'] = true;
            }

            $seedExit = $this->call('module:seed', $seedParams);

            if ($seedExit !== Command::SUCCESS) {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * 运行所有模块的迁移
     *
     * @param bool $force
     * @param string|null $customPath
     * @param bool $seed
     * @param string|null $seeder
     * @return int
     */
    protected function migrateAllModules(
        bool $force,
        ?string $customPath = null,
        bool $seed = false,
        ?string $seeder = null
    ): int {
        $modules = Module::allEnabled();

        if (empty($modules)) {
            $this->components->warn('没有已启用的模块。');

            return Command::SUCCESS;
        }

        // 全模块模式 + --seeder：警告用户不会传递
        $this->warnSeederIgnoredForAllModules($seeder);

        $hasFailures = false;

        foreach ($modules as $module) {
            $result = $this->migrateModule(
                $module->getName(),
                $force,
                $customPath,
                // 全模块模式：--seed 自动运行每个模块的 Seeder
                // --seeder 不传递（避免在所有模块运行同一个 Seeder）
                $seed,
                null
            );

            if ($result === Command::FAILURE) {
                $hasFailures = true;
            }
        }

        $this->components->info('迁移完成！');

        return $hasFailures ? Command::FAILURE : Command::SUCCESS;
    }
}
