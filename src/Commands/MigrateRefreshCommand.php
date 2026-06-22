<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\MigrationPathHelper;

/**
 * 模块迁移刷新命令
 *
 * 回滚并重新运行指定模块或全部模块的迁移（reset + migrate）。
 *
 * 参照 nWidart/laravel-modules 的 MigrateRefreshCommand 设计：
 * - 先 module:migrate-reset，后 module:migrate
 * - --seed 标志触发 module:seed
 * - --seeder 指定特定 Seeder，内部委托给 module:seed --class=...
 * - 生产环境强制确认
 *
 * 使用示例：
 *   php artisan module:migrate-refresh Blog                    # 刷新 Blog 模块
 *   php artisan module:migrate-refresh Blog --seed             # 刷新后运行 Seeder
 *   php artisan module:migrate-refresh Blog --seeder=PostSeeder # 刷新后运行指定 Seeder
 *   php artisan module:migrate-refresh                         # 刷新所有模块
 *
 * @package zxf\Modules\Commands
 */
class MigrateRefreshCommand extends Command
{
    use MigrationPathHelper;

    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:migrate-refresh
                            {module? : 模块名称（可选）}
                            {--force : 强制运行，不提示确认}
                            {--seed : 刷新后运行数据填充}
                            {--seeder= : 指定特定的数据填充器（仅类名，不含命名空间）}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '重置并重新运行所有模块的数据库迁移';

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $moduleName = $this->argument('module');
        $force = $this->option('force');
        $seed = $this->option('seed');
        $seeder = $this->option('seeder');

        // 生产环境确认
        if (! $force && app()->environment('production')) {
            $this->components->warn('⚠ 当前运行在生产环境！');
            $this->components->warn('此操作将回滚并重新运行所有迁移，可能导致数据丢失！');
            $confirmed = $this->components->confirm('确定要继续吗？');

            if (! $confirmed) {
                $this->components->info('操作已取消。');

                return Command::SUCCESS;
            }
        }

        $this->components->info('正在重置并重新运行模块迁移...');

        if ($moduleName) {
            return $this->refreshModule($moduleName, $force, $seed, $seeder);
        }

        return $this->refreshAllModules($force, $seed, $seeder);
    }

    /**
     * 重置并重新运行指定模块的迁移
     *
     * @param string $moduleName
     * @param bool $force
     * @param bool $seed
     * @param string|null $seeder
     * @return int
     */
    protected function refreshModule(string $moduleName, bool $force, bool $seed, ?string $seeder = null): int
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

        $this->components->info("正在重置并重新运行模块 [{$moduleName}] 的迁移...");

        $migrationPath = $this->getRelativePath($module->getMigrationsPath());

        // Step 1: 回滚迁移
        $this->call('migrate:rollback', [
            '--path' => $migrationPath,
            '--force' => $force,
        ]);

        // Step 2: 运行迁移（传递 --seed 和 --seeder 参数）
        $migrateParams = [
            'module' => $moduleName,
            '--force' => $force,
        ];

        if ($seed) {
            $migrateParams['--seed'] = true;
        }

        if ($seeder) {
            $migrateParams['--seeder'] = $seeder;
        }

        $this->call('module:migrate', $migrateParams);

        return Command::SUCCESS;
    }

    /**
     * 重置并重新运行所有模块的迁移
     *
     * @param bool $force
     * @param bool $seed
     * @param string|null $seeder
     * @return int
     */
    protected function refreshAllModules(bool $force, bool $seed, ?string $seeder = null): int
    {
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
            $result = $this->refreshModule(
                $module->getName(),
                $force,
                // 全模块模式：--seed 自动运行每个模块的 Seeder
                // --seeder 不传递（避免在所有模块运行同一个 Seeder）
                $seed,
                null
            );

            if ($result === Command::FAILURE) {
                $hasFailures = true;
            }
        }

        $this->components->info('迁移重置完成！');

        return $hasFailures ? Command::FAILURE : Command::SUCCESS;
    }
}
