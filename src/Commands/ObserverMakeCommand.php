<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Support\Facades\File;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
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
class ObserverMakeCommand extends AbstractMakeCommand
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
        $module = $this->resolveModule();
        if (! $module) {
            return Command::FAILURE;
        }

        $observerName = Str::studly($this->argument('name'));
        $force = $this->option('force');

        // 推导模型名称
        $modelName = Str::studly($this->option('model') ?: preg_replace('/Observer$/i', '', $observerName));
        $namespace = $this->module->getNamespace();

        $generator = $this->makeStubGenerator();
        $generator->addReplacements([
            '{{CLASS}}' => $observerName,
            '{{NAMESPACE}}' => $namespace,
            // {{NAME}} 在 observer.stub 中代表「模型类名」（如 Models\{{NAME}}、{{NAME}} $model），
            // 必须替换为模型名而非模块名，否则生成的类型提示会引用错误的类。
            '{{NAME}}' => $modelName,
            '{{MODEL}}' => $modelName,
            '{{MODEL_NAMESPACE}}' => $namespace . '\\' . $module->getName() . '\\Models',
        ]);

        $result = $this->writeStub($generator, 'observer.stub', 'Observers/' . $observerName . '.php', '观察者', $force);

        if ($result === Command::SUCCESS) {
            // 检查对应模型是否存在
            $modelPath = $this->targetPath('Models/' . $modelName . '.php');
            if (! File::exists($modelPath)) {
                $this->warn("提示：对应的模型 [{$modelName}] 尚未创建");
                $this->line("      可使用 php artisan module:make-model {$module->getName()} {$modelName} 创建");
            }
        }

        return $result;
    }
}
