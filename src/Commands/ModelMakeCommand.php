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
 * 在指定模块中创建模型
 */
class ModelMakeCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:make-model
                            {module : 模块名称}
                            {name : 模型名称}
                            {--migration : 创建对应的迁移文件}
                            {--factory : 创建对应的工厂类}
                            {--force : 覆盖已存在的模型}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '在指定模块中创建一个模型';

    /**
     * 执行命令
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

        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在");

            return Command::FAILURE;
        }

        $modelPath = $module->getPath('Models/' . $modelName . '.php');

        if (File::exists($modelPath) && ! $force) {
            $this->error("模块 [{$moduleName}] 中已存在模型 [{$modelName}]");
            $this->line("提示：使用 --force 选项覆盖已存在的模型");

            return Command::FAILURE;
        }

        if (File::exists($modelPath) && $force) {
            $this->warn("正在覆盖模块 [{$moduleName}] 中已存在的模型 [{$modelName}]");
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
            $this->error("创建模型 [{$modelName}] 失败");

            return Command::FAILURE;
        }

        $this->info("成功在模块 [{$moduleName}] 中创建模型 [{$modelName}]");

        if ($createMigration) {
            $this->call('module:make-migration', [
                'module' => $moduleName,
                'name' => 'create_' . $tableName . '_table',
            ]);
        }

        if ($createFactory) {
            $this->call('module:make-seeder', [
                'module' => $moduleName,
                'name' => $modelName . 'Factory',
            ]);
        }

        return Command::SUCCESS;
    }
}
