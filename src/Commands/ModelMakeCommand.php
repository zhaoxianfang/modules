<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建模型命令
 *
 * 在指定模块中创建 Eloquent 模型类
 * 可选创建对应的迁移文件和数据工厂
 */
class ModelMakeCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:make-model
                            {module : 模块名称（例如：Blog）}
                            {name : 模型名称（例如：Post）}
                            {--migration : 同时创建对应的数据库迁移文件}
                            {--factory : 同时创建对应的数据工厂类}
                            {--force : 覆盖已存在的模型}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '在指定模块中创建 Eloquent 模型类';

    /**
     * 执行命令
     *
     * 创建步骤：
     * 1. 验证模块是否存在
     * 2. 检查模型是否已存在（除非使用 --force）
     * 3. 创建模型文件
     * 4. 可选：创建迁移文件
     * 5. 可选：创建数据工厂
     *
     * @return int
     */
    public function handle(): int
    {
        $moduleName = Str::studly($this->argument('module'));
        $modelName = Str::studly($this->argument('name'));
        $createMigration = $this->option('migration');
        $createFactory = $this->option('factory');
        $force = $this->option('force');

        // 验证模块是否存在
        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在");
            $this->line("提示：请先创建模块，使用 php artisan module:make {$moduleName}");
            return Command::FAILURE;
        }

        $modelPath = $module->getPath('Models/' . $modelName . '.php');

        // 检查模型是否已存在
        if (File::exists($modelPath) && ! $force) {
            $this->error("模块 [{$moduleName}] 中已存在模型类 [{$modelName}]");
            $this->line("文件位置: {$modelPath}");
            $this->line("提示：使用 --force 选项覆盖已存在的模型");
            return Command::FAILURE;
        }

        if (File::exists($modelPath) && $force) {
            $this->warn("正在覆盖模块 [{$moduleName}] 中已存在的模型类 [{$modelName}]");
        }

        $namespace = config('modules.namespace', 'Modules');
        $tableName = Str::snake(Str::plural($modelName));

        $stubGenerator = new StubGenerator($moduleName);
        $stubGenerator->addReplacement('{{CLASS}}', $modelName);
        $stubGenerator->addReplacement('{{NAMESPACE}}', $namespace);
        $stubGenerator->addReplacement('{{TABLE}}', $tableName);
        $stubGenerator->addReplacement('{{NAME}}', $moduleName);

        // 确保模型目录存在
        $modelDir = $module->getPath('Models');
        if (! is_dir($modelDir)) {
            File::makeDirectory($modelDir, 0755, true);
        }

        $result = $stubGenerator->generate('model.stub', 'Models/' . $modelName . '.php', $force);

        if (! $result) {
            $this->error("创建模型类 [{$modelName}] 失败");
            $this->line("提示：检查文件权限和磁盘空间");
            return Command::FAILURE;
        }

        $this->info("✓ 成功在模块 [{$moduleName}] 中创建模型类 [{$modelName}]");
        $this->line("模型位置: {$modelPath}");
        $this->line("数据表: {$tableName}");

        // 可选：创建迁移文件
        if ($createMigration) {
            $this->line("");
            $this->line("正在创建迁移文件...");
            $this->call('module:make-migration', [
                'module' => $moduleName,
                'name' => 'create_' . $tableName . '_table',
                '--create' => $tableName,
            ]);
        }

        // 可选：创建数据工厂
        if ($createFactory) {
            $this->line("");
            $this->line("正在创建数据工厂...");
            $this->call('module:make-seeder', [
                'module' => $moduleName,
                'name' => $modelName . 'Seeder',
            ]);
        }

        return Command::SUCCESS;
    }
}
