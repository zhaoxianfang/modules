<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建模型观察者命令
 *
 * 在指定模块中创建 Eloquent 模型观察者类。
 * 观察者用于监听模型的创建、更新、删除、恢复等生命周期事件。
 *
 * 自动检测对应模型是否存在，若不存在会给出提示。
 *
 * 使用示例：
 *   php artisan module:make-observer Blog PostObserver
 *
 * @package zxf\Modules\Commands
 */
class ObserverMakeCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'module:make-observer
                            {module : 模块名称（必需，例如：Blog）}
                            {name : 观察者名称（必需，例如：PostObserver）}
                            {--model= : 关联的模型名称（可选，自动从观察者名推导）}
                            {--force : 覆盖已存在的文件}';

    /**
     * @var string
     */
    protected $description = '在指定模块中创建模型观察者类';

    /**
     * 执行命令
     */
    public function handle(): int
    {
        $moduleName = Str::studly($this->argument('module'));
        $observerName = Str::studly($this->argument('name'));
        $force = $this->option('force');

        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在");
            return Command::FAILURE;
        }

        $observerPath = $module->getPath('Observers/' . $observerName . '.php');

        if (File::exists($observerPath) && ! $force) {
            $this->error("模块 [{$moduleName}] 中已存在观察者 [{$observerName}]");
            $this->line('提示：使用 --force 选项覆盖已存在的观察者');
            return Command::FAILURE;
        }

        if (File::exists($observerPath) && $force) {
            $this->warn("正在覆盖模块 [{$moduleName}] 中已存在的观察者 [{$observerName}]");
        }

        // 推导模型名称
        $modelName = $this->option('model') ?: preg_replace('/Observer$/i', '', $observerName);
        $namespace = config('modules.namespace', 'Modules');

        $stubGenerator = new StubGenerator($moduleName);
        $stubGenerator->addReplacements([
            '{{CLASS}}' => $observerName,
            '{{NAMESPACE}}' => $namespace,
            '{{NAME}}' => $moduleName,
            '{{MODEL}}' => $modelName,
            '{{MODEL_NAMESPACE}}' => $namespace . '\\' . $moduleName . '\\Models',
        ]);

        // 确保目录存在
        $observerDir = $module->getPath('Observers');
        if (! is_dir($observerDir)) {
            File::makeDirectory($observerDir, 0755, true);
        }

        $result = $stubGenerator->generate(
            'observer.stub',
            'Observers/' . $observerName . '.php',
            $force
        );

        if ($result) {
            $this->info("✓ 成功在模块 [{$moduleName}] 中创建观察者 [{$observerName}]");

            // 检查对应模型是否存在
            $modelPath = $module->getPath('Models/' . $modelName . '.php');
            if (! File::exists($modelPath)) {
                $this->warn("提示：对应的模型 [{$modelName}] 尚未创建");
                $this->line("      可使用 php artisan module:make-model {$moduleName} {$modelName} 创建");
            }

            return Command::SUCCESS;
        }

        $this->error("创建观察者 [{$observerName}] 失败");
        return Command::FAILURE;
    }
}
