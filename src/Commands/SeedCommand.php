<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Facades\Module;

/**
 * 模块数据填充命令
 *
 * 执行指定模块或全部模块的数据填充器（Seeder）。
 *
 * 参照 nWidart/laravel-modules 的 SeedCommand 设计：
 * - 支持 --class 选项单独运行某个 Seeder 类
 * - 自动从模块配置发现 seeder
 * - 回退到约定命名：{ModuleName}DatabaseSeeder
 * - 支持 --database 和 --force 选项
 *
 * 使用示例：
 *   php artisan module:seed Blog                           # 运行 Blog 模块所有 Seeder
 *   php artisan module:seed Blog --class=PostSeeder        # 运行指定 Seeder
 *   php artisan module:seed Blog --class=UsersTableSeeder  # 运行指定 Seeder
 *   php artisan module:seed                                # 运行所有模块的 Seeder
 *
 * @package zxf\Modules\Commands
 */
class SeedCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:seed
                            {module? : 模块名称（可选，不指定则运行所有已启用模块）}
                            {--class= : 指定特定的 Seeder 类名（仅类名，不含命名空间）}
                            {--database= : 指定数据库连接}
                            {--force : 强制运行，不提示确认}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '运行指定模块或所有模块的数据填充器';

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $moduleName = $this->argument('module');

        if ($moduleName) {
            return $this->seedModule($moduleName);
        }

        return $this->seedAllModules();
    }

    /**
     * 运行指定模块的数据填充
     *
     * @param string $moduleName
     * @return int
     */
    protected function seedModule(string $moduleName): int
    {
        $module = Module::find($moduleName);

        if (! $module) {
            $this->components->error("模块 [{$moduleName}] 不存在。");
            $this->components->info('提示：使用 \'php artisan module:list\' 查看所有可用模块');

            return Command::FAILURE;
        }

        if (! $module->isEnabled()) {
            $this->components->warn("模块 [{$moduleName}] 未启用，跳过。");

            return Command::SUCCESS;
        }

        $this->components->info("正在运行模块 [{$moduleName}] 的数据填充...");

        $specificClass = $this->option('class');

        if ($specificClass) {
            return $this->runSpecificSeeder($module, $specificClass);
        }

        return $this->runAllModuleSeeders($module);
    }

    /**
     * 运行所有已启用模块的数据填充
     *
     * @return int
     */
    protected function seedAllModules(): int
    {
        $modules = Module::allEnabled();

        if (empty($modules)) {
            $this->components->warn('没有已启用的模块。');

            return Command::SUCCESS;
        }

        // 如果有 --class 选项，拒绝全模块模式（避免在所有模块运行同一个 Seeder）
        $specificClass = $this->option('class');
        if ($specificClass) {
            $this->components->error('使用 --class 选项时必须指定模块名称。');
            $this->components->info("提示：php artisan module:seed <模块名> --class={$specificClass}");

            return Command::FAILURE;
        }

        $this->components->info('正在运行所有已启用模块的数据填充...');
        $this->newLine();

        $totalSuccess = 0;
        $totalFail = 0;

        foreach ($modules as $module) {
            $result = $this->runAllModuleSeeders($module);

            if ($result === Command::SUCCESS) {
                $totalSuccess++;
            } else {
                $totalFail++;
            }
        }

        $this->newLine();
        $this->components->info("数据填充完成：{$totalSuccess} 个模块成功" . ($totalFail > 0 ? "，{$totalFail} 个模块失败" : ''));

        return $totalFail === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * 运行指定的 Seeder 类
     *
     * 拼接模块完整命名空间：{namespace}\{ModuleName}\Database\Seeders\{ClassName}
     *
     * @param \zxf\Modules\Contracts\ModuleInterface $module
     * @param string $className  仅类名（如 PostSeeder）
     * @return int
     */
    protected function runSpecificSeeder($module, string $className): int
    {
        // 拼接完整类名：Modules\Blog\Database\Seeders\PostSeeder
        $fullClass = $module->getClassNamespace() . '\\Database\\Seeders\\' . $className;

        if (! class_exists($fullClass)) {
            $this->error("数据填充器 [{$fullClass}] 不存在。");
            $this->line("提示：确保文件位于 " . $module->getPath('Database/Seeders/' . $className . '.php'));

            return Command::FAILURE;
        }

        $this->line("  类名: {$fullClass}");

        return $this->callDbSeed($fullClass);
    }

    /**
     * 运行模块的所有 Seeder
     *
     * 发现策略（参照 nWidart）：
     * 1. 优先：模块配置文件中的 seeds 数组（完整类名）
     * 2. 回退：扫描 Database/Seeders 目录下的所有 .php 文件（PSR-4 约定命名）
     *
     * @param \zxf\Modules\Contracts\ModuleInterface $module
     * @return int
     */
    protected function runAllModuleSeeders($module): int
    {
        $seeders = $this->discoverSeeders($module);

        if (empty($seeders)) {
            $this->components->warn("模块 [{$module->getName()}] 没有数据填充器，跳过。");

            return Command::SUCCESS;
        }

        $this->components->info("  发现 " . count($seeders) . " 个数据填充器");

        $hasFailures = false;
        foreach ($seeders as $seederClass) {
            $result = $this->callDbSeed($seederClass);
            if ($result !== Command::SUCCESS) {
                $hasFailures = true;
            }
        }

        return $hasFailures ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * 发现模块的 Seeder 类列表
     *
     * @param \zxf\Modules\Contracts\ModuleInterface $module
     * @return array<int, string>
     */
    protected function discoverSeeders($module): array
    {
        // 策略 1：从模块配置文件读取 seeds 数组（nWidart 兼容）
        $config = $module->getModuleConfig();
        if (isset($config['seeds']) && is_array($config['seeds'])) {
            $seeders = [];
            foreach ($config['seeds'] as $class) {
                if (is_string($class) && class_exists($class)) {
                    $seeders[] = $class;
                }
            }
            if (! empty($seeders)) {
                return $seeders;
            }
        }

        // 策略 2：扫描 Database/Seeders 目录
        $seederPath = $module->getSeedersPath();

        if (! is_dir($seederPath)) {
            return [];
        }

        $files = glob($seederPath . DIRECTORY_SEPARATOR . '*.php');
        if (empty($files)) {
            return [];
        }

        $seeders = [];
        foreach ($files as $file) {
            $className = basename($file, '.php');
            $fullClass = $module->getClassNamespace() . '\\Database\\Seeders\\' . $className;

            if (class_exists($fullClass)) {
                $seeders[] = $fullClass;
            }
        }

        return $seeders;
    }

    /**
     * 调用 Laravel 原生 db:seed 命令
     *
     * @param string $fullClass 完整的 Seeder 类名（含命名空间）
     * @return int
     */
    protected function callDbSeed(string $fullClass): int
    {
        $params = [
            '--class' => $fullClass,
        ];

        if ($database = $this->option('database')) {
            $params['--database'] = $database;
        }

        if ($this->option('force')) {
            $params['--force'] = true;
        }

        try {
            $this->call('db:seed', $params);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("运行数据填充器 [{$fullClass}] 失败: " . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
