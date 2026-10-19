<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Commands\Concerns\EscapesConsoleOutput;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\MigrationPathHelper;

/**
 * 模块迁移命令
 * 运行指定模块或全部模块的数据库迁移，支持迁移后自动数据填充。
 * 参照 nWidart/laravel-modules 的 MigrateCommand 设计：
 * - 通过路径过滤运行模块迁移（--path + --realpath）
 * - --seed 标志触发 module:seed 命令（而非直接调用 db:seed）
 * - --seeder 指定特定 Seeder 类，内部委托给 module:seed --class=...
 * - 生产环境强制确认（app()->environment('production') 时提示）
 * 使用示例：
 *   php artisan module:migrate Blog                          # 运行 Blog 模块迁移
 *   php artisan module:migrate Blog --seed                   # 迁移后运行 Blog 模块 Seeder
 *   php artisan module:migrate Blog --seeder=PostSeeder      # 迁移后运行指定 Seeder
 *   php artisan module:migrate                               # 运行所有已启用模块的迁移
 */
class MigrateCommand extends Command
{
    use EscapesConsoleOutput;
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
            $this->components->error("模块 [{$this->esc($moduleName)}] 不存在。");

            return Command::FAILURE;
        }

        if (! $module->isEnabled()) {
            $this->components->warn("模块 [{$this->esc($moduleName)}] 未启用，跳过。");

            return Command::SUCCESS;
        }

        $this->components->info("正在运行模块 [{$this->esc($moduleName)}] 的迁移...");

        $migrationPath = $customPath ?: $module->getMigrationsPath();

        if (! is_dir($migrationPath)) {
            $this->components->warn("模块 [{$this->esc($moduleName)}] 没有迁移文件。");

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

        // --path 在全模块模式下与模块无关，只跑一次（详见下方说明）。
        if (! empty($customPath)) {
            return $this->migrateExplicitPath($force, $customPath, $seed, $seeder);
        }

        // 收集所有已启用模块的迁移目录。
        $paths = [];
        $migratable = [];
        foreach ($modules as $module) {
            if (! $module->isEnabled()) {
                continue;
            }

            $migrationPath = $module->getMigrationsPath();
            if (! is_dir($migrationPath)) {
                // 无迁移文件的模块跳过（与单模块模式一致的语义）
                $this->components->warn("模块 [{$this->esc($module->getName())}] 没有迁移文件，跳过。");

                continue;
            }

            $paths[] = $this->getRelativePath($migrationPath);
            $migratable[] = $module;
        }

        if (empty($paths)) {
            $this->components->info('没有需要执行的迁移。');

            return Command::SUCCESS;
        }

        /**
         * 一次 migrate 调用覆盖全部模块目录，而不是"每模块启动一次子命令"。
         *
         * 原实现是 foreach + $this->call('migrate', ...)：每启动一次子命令，
         * Laravel 都要重新构造 Migrator、解析 --path、跑一遍 MigrationRepository 的
         * 已执行列表查询、并独立提交事务。20 个模块就是 20 倍的这套开销，
         * 而绝大多数模块根本没有待执行的迁移（多数调用只是打印 "Nothing to migrate"）。
         *
         * migrate 的 --path 本就是【数组选项】（--path=*），
         * 传入多个路径时框架会把它们合并成一次待执行的迁移集合，
         * 再按文件名字节序统一排序 —— 跨模块的先后依赖反而更符合时间戳语义。
         */
        $this->components->info(sprintf(
            '正在运行 %d 个模块的迁移（%d 个迁移目录）...',
            count($migratable),
            count($paths)
        ));

        $databaseOption = $this->safeOption('database');

        $migrateParams = [
            '--force' => $force,
            '--path' => $paths,
        ];

        if ($databaseOption) {
            $migrateParams['--database'] = $databaseOption;
        }

        $exitCode = $this->call('migrate', $migrateParams);

        // 迁移失败：直接返回失败，不再跑 Seeder（在未完成的库结构上填充数据只会放大损坏）
        if ($exitCode !== Command::SUCCESS) {
            return Command::FAILURE;
        }

        $this->components->info('迁移完成！');

        // 数据填充：逐模块执行（每个模块用自己的 Seeder，不存在"一个 Seeder 跑全部"的可能）
        if (! $seed) {
            return Command::SUCCESS;
        }

        $this->newLine();
        $this->components->info('正在运行数据填充...');

        $hasSeedFailures = false;
        foreach ($migratable as $module) {
            $seedParams = ['module' => $module->getName()];
            if ($force) {
                $seedParams['--force'] = true;
            }

            if ($this->call('module:seed', $seedParams) !== Command::SUCCESS) {
                $hasSeedFailures = true;
            }
        }

        return $hasSeedFailures ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * 全模块模式下显式传入 --path 的执行分支。
     *
     * --path 表达的是「扫描哪个目录下的迁移文件」，与模块无关：
     *   - 第 1 次执行完迁移后，后续 N-1 次都只是打印 "Nothing to migrate"；
     *   - 若该目录不属于任何模块，这份「无意义」的工作同样会重复 N 次。
     * 因此只跑一次，不套进模块循环。
     */
    protected function migrateExplicitPath(
        bool $force,
        string $customPath,
        bool $seed,
        ?string $seeder
    ): int {
        $this->components->info("--path 已指定，跳过模块循环，仅对 [{$this->esc($customPath)}] 执行一次迁移。");

        // 本命令签名里没有 --database；Symfony 的 option() 遇到未声明选项会抛
        // InvalidArgumentException，这里必须用 MigrationPathHelper::safeOption()。
        $databaseOption = $this->safeOption('database');

        if ($seed || $seeder) {
            $this->components->warn('--path 模式不执行数据填充，--seed / --seeder 已被忽略。');
        }

        $migrateParams = ['--force' => true];

        if ($databaseOption) {
            $migrateParams['--database'] = $databaseOption;
        }

        $migrateParams['--path'] = method_exists($this, 'getRelativePath')
            ? $this->getRelativePath($customPath)
            : $customPath;

        return $this->call('migrate', $migrateParams) === Command::SUCCESS
            ? Command::SUCCESS
            : Command::FAILURE;
    }
}
