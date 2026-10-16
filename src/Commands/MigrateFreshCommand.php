<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use zxf\Modules\Commands\Concerns\EscapesConsoleOutput;
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\MigrationPathHelper;

/**
 * 模块迁移清空命令
 *
 * 两种模式语义完全不同，请务必区分：
 *
 * 1. 全模块模式（不传 module）：`migrate:fresh` —— **删除整个数据库的所有表**，
 *    再逐个模块重新迁移。这是「重建整库」的语义。
 *
 * 2. 单模块模式（传 module）：只删除**该模块自己的迁移文件里声明过的表**，
 *    再重跑该模块的迁移；其它模块的表和数据完全不受影响。
 *
 * 不可直接调用 `migrate:fresh --path=...`：
 * migrate:fresh 内部是 `db:wipe` + `migrate --path`，
 * 而 `--path` 只约束「跑哪些迁移」，不约束「删哪些表」，
 * 于是 `module:migrate-fresh Blog` 会删光整库所有模块的表 —— 严重数据丢失。
 * 详见 freshModule() 的注释。
 *
 * 使用示例：
 *   php artisan module:migrate-fresh Blog                     # 仅重建 Blog 模块
 *   php artisan module:migrate-fresh Blog --seed              # 重建后运行 Seeder
 *   php artisan module:migrate-fresh Blog --seeder=PostSeeder # 重建后运行指定 Seeder
 *   php artisan module:migrate-fresh                          # ⚠ 清空整库后重建所有模块
 */
class MigrateFreshCommand extends Command
{
    use EscapesConsoleOutput;
    use MigrationPathHelper;

    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:migrate-fresh
                            {module? : 模块名称（可选；指定则仅重建该模块，不影响其它模块）}
                            {--database= : 指定数据库连接}
                            {--force : 强制运行，不提示确认}
                            {--seed : 清空重建后运行数据填充}
                            {--seeder= : 指定特定的数据填充器（仅类名，不含命名空间）}
                            {--drop-views : 同时删除所有视图（仅全模块模式生效）}
                            {--drop-types : 同时删除所有自定义类型（PostgreSQL，仅全模块模式生效）}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '重新运行模块迁移；指定模块时仅重建该模块的表，不影响其它模块';

    /**
     * 执行命令
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

        if ($moduleName) {
            // 单模块模式：只删本模块的表，确认由 freshModule() 内部统一处理
            // （任何环境都要确认，因为它确实会销毁数据）。
            return $this->freshModule($moduleName, $database, $force, $seed, $seeder, $dropViews, $dropTypes);
        }

        // 全模块模式 = 整库清空，生产环境额外提示一次
        if (! $force && app()->environment('production')) {
            $this->components->warn('⚠ 当前运行在生产环境！');
            $this->components->warn('全模块模式将删除**整个数据库**的所有表！');
            $confirmed = $this->components->confirm('确定要继续吗？');

            if (! $confirmed) {
                $this->components->info('操作已取消。');

                return Command::SUCCESS;
            }
        }

        $this->components->info('正在清空数据库并重新运行模块迁移...');

        return $this->freshAllModules($database, $force, $seed, $seeder, $dropViews, $dropTypes);
    }

    /**
     * 清空并重建指定模块的迁移
     *
     * 但 `migrate:fresh` 的语义是「**删除整个连接的所有表**后重新迁移」，
     * --path 只影响「随后要执行哪些迁移文件」，并不影响清库范围。
     * 见 Laravel 源码 FreshCommand::handle()：
     * 于是 `php artisan module:migrate-fresh Blog` 的真实效果是
     * **删光数据库里所有模块的所有表**，然后只重建 Blog —— 其余模块数据全部丢失，
     * 且命令帮助语「清空并重建 Blog 模块」完全掩盖了这一点。
     * 加上原本只在 production 才提示，等于 local/staging 下静默执行。
     *
     * 因此单模块模式采用「模块范围重建」：
     *   1. 解析该模块迁移文件里出现过的表名（只删本模块自己声明的表）
     *   2. 明确列出待删表并要求确认（--force 可跳过）
     *   3. 删除这些表 + 清掉 migrations 表里本模块的记录
     *   4. 只跑本模块的迁移
     * 全模块模式保持使用 migrate:fresh（全库清空本来就是它的语义）。
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
            $this->components->error("模块 [{$this->esc($moduleName)}] 不存在。");

            return Command::FAILURE;
        }

        if (! $module->isEnabled()) {
            $this->components->warn("模块 [{$this->esc($moduleName)}] 未启用，跳过。");

            return Command::SUCCESS;
        }

        // --drop-views / --drop-types 作用于整库，模块模式下必须忽略，
        // 否则与「只动本模块表」的承诺相矛盾。
        if ($dropViews || $dropTypes) {
            $this->components->warn('--drop-views / --drop-types 只在「全模块模式」下生效，单模块模式已忽略。');
        }

        $this->components->info("正在清空并重建模块 [{$this->esc($moduleName)}]...");

        $tables = $this->collectModuleTables($module);

        if (empty($tables)) {
            // 解析不出表名时，宁可什么都不删，只补跑迁移，
            // 绝不能退回到 migrate:fresh（那会清空整库）。
            $this->components->warn('未能从迁移文件中解析出任何表名，已跳过删表步骤，仅补跑未执行的迁移。');
            $this->newLine();

            return $this->migrateModule($moduleName, $database, $force, $seed, $seeder);
        }

        $this->components->info('以下表将被删除（共 '.count($tables).' 张）：');

        foreach (array_chunk($tables, 4) as $chunk) {
            $this->line('  - '.implode("\n  - ", $chunk));
        }

        // 该操作会销毁模块内全部数据，任何环境都必须确认
        if (! $force) {
            $confirmed = $this->components->confirm('确定要删除以上数据表并重建模块吗？');

            if (! $confirmed) {
                $this->components->info('操作已取消。');

                return Command::SUCCESS;
            }
        }

        $this->newLine();
        $failed = $this->dropModuleTables($tables, $module, $database);

        if ($failed) {
            $this->components->error('部分数据表删除失败，已中止重建。');

            return Command::FAILURE;
        }

        $this->newLine();

        return $this->migrateModule($moduleName, $database, $force, $seed, $seeder);
    }

    /**
     * 只对本模块执行迁移（不再触碰其它模块的表）
     */
    protected function migrateModule(
        string $moduleName,
        ?string $database,
        bool $force,
        bool $seed,
        ?string $seeder
    ): int {
        $module = Module::find($moduleName);

        if (! $module) {
            $this->components->error("模块 [{$this->esc($moduleName)}] 不存在。");

            return Command::FAILURE;
        }

        $migrationPath = $this->getRelativePath($module->getMigrationsPath());

        $params = [
            '--path' => $migrationPath,
            '--force' => $force,
        ];

        if ($database) {
            $params['--database'] = $database;
        }

        if ($this->call('migrate', $params) !== Command::SUCCESS) {
            return Command::FAILURE;
        }

        if ($seed || $seeder) {
            $this->newLine();
            $this->components->info('正在运行数据填充...');

            $seedParams = ['module' => $moduleName];

            if ($seeder) {
                $seedParams['--class'] = $seeder;
            }

            if ($database) {
                $seedParams['--database'] = $database;
            }

            if ($force) {
                $seedParams['--force'] = true;
            }

            return $this->call('module:seed', $seedParams) === Command::SUCCESS
                ? Command::SUCCESS
                : Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * 解析模块迁移文件中出现过的表名
     *
     * 只认 Schema Builder 的显式调用（create / drop / dropIfExists / table / rename），
     * 因此不会误伤其它模块的表。
     *
     * @return array<int, string>
     */
    protected function collectModuleTables(ModuleInterface $module): array
    {
        $dir = $module->getMigrationsPath();

        if (! is_dir($dir)) {
            return [];
        }

        // 覆盖三种主流写法：
        //   Schema::create('tbl', ...)
        //   Schema::dropIfExists('tbl')
        //   Schema::connection('x')->create('tbl', ...)   ← 多连接项目的主流写法
        //   Schema::connection('x')->table('tbl')
        $pattern = '/Schema::(?:connection\(\s*[\'"](?:[^\'"]*)[\'"]\s*\)\s*->\s*)?'
            .'(?:create|drop|dropIfExists|table|rename)\s*\(\s*[\'"]([A-Za-z0-9_]+)[\'"]/';

        $tables = [];

        foreach ((array) glob($dir.DIRECTORY_SEPARATOR.'*.php') as $file) {
            if (! is_string($file)) {
                continue;
            }

            $content = (string) @file_get_contents($file);

            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] as $table) {
                    $tables[$table] = true;
                }
            }
        }

        // 逆序删除：后建的表通常依赖先建的表，反序可减少外键约束报错
        return array_reverse(array_keys($tables));
    }

    /**
     * 删除模块自有表，并清除 migrations 表中该模块的记录
     *
     * @param  array<int, string>  $tables  待删除的表名（建议为逆序）
     * @return bool 是否有删除失败
     */
    protected function dropModuleTables(array $tables, ModuleInterface $module, ?string $database): bool
    {
        $schema = $database ? Schema::connection($database) : Schema::getFacadeRoot();

        if (! $schema) {
            $this->components->error('无法获取 Schema 实例。');

            return true;
        }

        $failed = false;

        foreach ($tables as $table) {
            try {
                if (! $schema->hasTable($table)) {
                    continue;
                }

                $this->components->task("删除表 {$table}", function () use ($schema, $table): bool {
                    $schema->dropIfExists($table);

                    return true;
                });
            } catch (\Throwable $e) {
                $this->components->error("删除表 [{$this->esc($table)}] 失败: ".$e->getMessage());
                $failed = true;
            }
        }

        // 表没删干净时**绝不能**清 migrations 记录：
        // 否则重跑迁移会被判定为「已执行」而跳过，或与残留表冲突报
        // "Base table or view already exists"，表结构停留在旧版本。
        if ($failed) {
            $this->components->error('仍有数据表未删除，已保留 migrations 表记录以免破坏迁移状态。');

            return true;
        }

        // 清掉 migrations 表中该模块的记录，否则重新 migrate 时会被判定为「已执行」而跳过
        $names = $this->collectModuleMigrationNames($module);

        if (! empty($names)) {
            try {
                $query = DB::connection($database)->table('migrations');

                $query->whereIn('migration', $names)->delete();
            } catch (\Throwable $e) {
                $this->components->error('清理 migrations 表记录失败: '.$e->getMessage());
                $failed = true;
            }
        }

        return $failed;
    }

    /**
     * 收集模块迁移文件在 migrations 表中对应的名称（文件名去扩展名）
     *
     * @return array<int, string>
     */
    protected function collectModuleMigrationNames(ModuleInterface $module): array
    {
        $dir = $module->getMigrationsPath();

        if (! is_dir($dir)) {
            return [];
        }

        $names = [];

        foreach ((array) glob($dir.DIRECTORY_SEPARATOR.'*.php') as $file) {
            if (is_string($file)) {
                $names[] = basename($file, '.php');
            }
        }

        return $names;
    }

    /**
     * 清空并重建所有模块的迁移
     */
    protected function freshAllModules(
        ?string $database,
        bool $force,
        bool $seed,
        ?string $seeder,
        bool $dropViews,
        bool $dropTypes
    ): int {
        $modules = Module::allEnabled();

        if (empty($modules)) {
            $this->components->warn('没有已启用的模块。');

            return Command::SUCCESS;
        }

        // 全模块模式 + --seeder：警告用户不会传递
        $this->warnSeederIgnoredForAllModules($seeder);

        // 全模块模式不可对每个模块分别执行 migrate:fresh——每次 fresh 都会先 DROP
        // 全库所有表，导致后一个模块删除前一个模块刚建的表，最终只剩最后模块的表。
        // 正确做法：先统一清空全库一次，再逐个模块迁移、填充。
        $this->components->info('正在清空数据库...');

        $dropParams = [
            '--force' => $force,
        ];

        if ($database) {
            $dropParams['--database'] = $database;
        }

        if ($dropViews) {
            $dropParams['--drop-views'] = true;
        }

        if ($dropTypes) {
            $dropParams['--drop-types'] = true;
        }

        if ($this->call('migrate:fresh', $dropParams) !== Command::SUCCESS) {
            $this->components->error('清空数据库失败。');

            return Command::FAILURE;
        }

        $hasFailures = false;

        foreach ($modules as $module) {
            $moduleName = $module->getName();
            $this->components->info("正在迁移模块 [{$this->esc($moduleName)}]...");

            $migrateParams = [
                '--path' => $this->getRelativePath($module->getMigrationsPath()),
                '--force' => $force,
            ];

            if ($database) {
                $migrateParams['--database'] = $database;
            }

            if ($this->call('migrate', $migrateParams) !== Command::SUCCESS) {
                $hasFailures = true;

                continue;
            }

            // 运行模块数据填充
            if ($seed) {
                $this->newLine();
                $this->components->info("正在填充模块 [{$this->esc($moduleName)}] 数据...");

                $seedParams = ['module' => $moduleName];

                if ($database) {
                    $seedParams['--database'] = $database;
                }

                if ($force) {
                    $seedParams['--force'] = true;
                }

                if ($this->call('module:seed', $seedParams) !== Command::SUCCESS) {
                    $hasFailures = true;
                }
            }
        }

        $this->components->info('清空并重建完成！');

        return $hasFailures ? Command::FAILURE : Command::SUCCESS;
    }
}
