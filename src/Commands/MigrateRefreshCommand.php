<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Facades\Module;

class MigrateRefreshCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:migrate-refresh
                            {module? : 模块名称（可选）}
                            {--force : 强制运行}
                            {--seed : 运行数据填充}
                            {--seeder= : 指定数据填充器}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '重置并重新运行所有模块的迁移';

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

        $this->info('正在重置并重新运行模块迁移...');

        if ($moduleName) {
            $this->refreshModule($moduleName, $force, $seed, $seeder);
        } else {
            $this->refreshAllModules($force, $seed, $seeder);
        }

        $this->info('迁移重置完成！');

        return Command::SUCCESS;
    }

    /**
     * 重置并重新运行指定模块的迁移
     *
     * @param string $moduleName
     * @param bool $force
     * @param bool $seed
     * @param string|null $seeder
     * @return void
     */
    protected function refreshModule(string $moduleName, bool $force, bool $seed, ?string $seeder = null): void
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

        $this->info("正在重置并重新运行模块 [{$moduleName}] 的迁移...");

        $migrationPath = $this->getRelativePath($module->getMigrationsPath());

        // 回滚迁移
        $this->call('migrate:rollback', [
            '--path' => $migrationPath,
            '--force' => $force,
        ]);

        // 运行迁移
        $this->call('module:migrate', [
            'module' => $moduleName,
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
     * 重置并重新运行所有模块的迁移
     *
     * @param bool $force
     * @param bool $seed
     * @param string|null $seeder
     * @return void
     */
    protected function refreshAllModules(bool $force, bool $seed, ?string $seeder = null): void
    {
        $modules = Module::allEnabled();

        if (empty($modules)) {
            $this->warn('没有已启用的模块。');

            return;
        }

        foreach ($modules as $module) {
            $this->refreshModule($module->getName(), $force, $seed, $seeder);
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
