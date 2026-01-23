<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Facades\Module;

class MigrateCommand extends Command
{
    /**
     * 命令签名
     *
     * 定义命令的名称、参数和选项
     *
     * 参数：
     * - module: 可选参数，指定要运行的模块名称，不指定则运行所有模块
     *
     * 选项：
     * - --force: 强制运行，在生产环境使用时请谨慎
     * - --path: 指定自定义的迁移文件路径
     * - --seed: 运行迁移后自动执行数据填充
     * - --seeder: 指定特定的数据填充器类
     *
     * @var string
     */
    protected $signature = 'module:migrate
                            {module? : 模块名称（可选，不指定则运行所有模块）}
                            {--force : 强制运行，不提示确认（生产环境使用时请谨慎）}
                            {--path= : 指定自定义迁移文件路径}
                            {--seed : 迁移完成后自动运行数据填充}
                            {--seeder= : 指定特定的数据填充器类}';

    /**
     * 命令描述
     *
     * 执行数据库迁移，将迁移文件应用到数据库
     * 可以运行所有模块或指定模块的迁移
     *
     * @var string
     */
    protected $description = '运行所有模块或指定模块的数据库迁移，可配合数据填充一起使用';

    /**
     * 执行命令
     *
     * 主要逻辑：
     * 1. 获取命令参数和选项
     * 2. 根据是否指定模块调用不同的方法
     * 3. 执行迁移并显示结果
     *
     * @return int 命令执行状态码
     */
    public function handle(): int
    {
        $moduleName = $this->argument('module');
        $force = $this->option('force');
        $customPath = $this->option('path');
        $seed = $this->option('seed');
        $seeder = $this->option('seeder');

        $this->info('正在运行模块迁移...');

        if ($moduleName) {
            $this->migrateModule($moduleName, $force, $customPath, $seed, $seeder);
        } else {
            $this->migrateAllModules($force, $customPath, $seed, $seeder);
        }

        $this->info('迁移完成！');

        return Command::SUCCESS;
    }

    /**
     * 运行指定模块的迁移
     *
     * @param string $moduleName
     * @param bool $force
     * @param string|null $customPath
     * @param bool $seed
     * @param string|null $seeder
     * @return void
     */
    protected function migrateModule(string $moduleName, bool $force, ?string $customPath = null, bool $seed = false, ?string $seeder = null): void
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

        $this->info("正在运行模块 [{$moduleName}] 的迁移...");

        $migrationPath = $customPath ?: $module->getMigrationsPath();

        if (! is_dir($migrationPath)) {
            $this->warn("模块 [{$moduleName}] 没有迁移文件。");

            return;
        }

        $relativePath = $this->getRelativePath($migrationPath);

        $this->call('migrate', [
            '--path' => $relativePath,
            '--force' => $force,
        ]);

        // 运行数据填充
        if ($seed || $seeder) {
            $this->info('正在运行数据填充...');

            if ($seeder) {
                $this->call('db:seed', [
                    '--class' => $seeder,
                    '--force' => $force,
                ]);
            } else {
                $this->runModuleSeeders($module, $force);
            }
        }
    }

    /**
     * 运行所有模块的迁移
     *
     * @param bool $force
     * @param string|null $customPath
     * @param bool $seed
     * @param string|null $seeder
     * @return void
     */
    protected function migrateAllModules(bool $force, ?string $customPath = null, bool $seed = false, ?string $seeder = null): void
    {
        $modules = Module::allEnabled();

        if (empty($modules)) {
            $this->warn('没有已启用的模块。');

            return;
        }

        foreach ($modules as $module) {
            $this->migrateModule($module->getName(), $force, $customPath, $seed, $seeder);
        }
    }

    /**
     * 运行模块的数据填充器
     *
     * @param ModuleInterface $module
     * @param bool $force
     * @return void
     */
    protected function runModuleSeeders(ModuleInterface $module, bool $force): void
    {
        $seederPath = $module->getPath('Database/Seeders');

        if (! is_dir($seederPath)) {
            return;
        }

        $files = glob($seederPath . DIRECTORY_SEPARATOR . '*.php');

        foreach ($files as $file) {
            $seederClass = $module->getClassNamespace() . '\\Database\\Seeders\\' . basename($file, '.php');

            if (class_exists($seederClass)) {
                $this->call('db:seed', [
                    '--class' => $seederClass,
                    '--force' => $force,
                ]);
            }
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
