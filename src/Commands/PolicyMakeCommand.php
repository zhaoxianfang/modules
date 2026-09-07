<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Support\Facades\File;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建授权策略命令
 *
 * 在指定模块中创建 Laravel 授权策略类。
 * 策略类用于定义模型的访问控制和授权逻辑（查看、创建、更新、删除、恢复等）。
 *
 * 使用示例：
 *   php artisan module:make-policy Blog PostPolicy
 *
 * @package zxf\Modules\Commands
 */
class PolicyMakeCommand extends AbstractMakeCommand
{
    /**
     * @var string
     */
    protected $signature = 'module:make-policy
                            {module : 模块名称（必需，例如：Blog）}
                            {name : 策略名称（必需，例如：PostPolicy）}
                            {--model= : 关联的模型名称（可选，自动从策略名推导）}
                            {--force : 覆盖已存在的文件}';

    /**
     * @var string
     */
    protected $description = '在指定模块中创建授权策略类';

    /**
     * 执行命令
     */
    public function handle(): int
    {
        $module = $this->resolveModule();
        if (! $module) {
            return Command::FAILURE;
        }

        $policyName = Str::studly($this->argument('name'));
        $force = $this->option('force');

        // 推导模型名称
        $modelName = Str::studly($this->option('model') ?: preg_replace('/Policy$/i', '', $policyName));
        $namespace = $this->module->getNamespace();

        $generator = $this->makeStubGenerator();
        $generator->addReplacements([
            '{{CLASS}}' => $policyName,
            '{{NAMESPACE}}' => $namespace,
            // {{NAME}} 在 policy.stub 中代表「模型类名」（如 Models\{{NAME}}、{{NAME}} $model），
            // 必须替换为模型名而非模块名。
            '{{NAME}}' => $modelName,
            '{{MODEL}}' => $modelName,
            '{{MODEL_NAMESPACE}}' => $namespace . '\\' . $module->getName() . '\\Models',
        ]);

        $result = $this->writeStub($generator, 'policy.stub', 'Policies/' . $policyName . '.php', '策略', $force);

        if ($result === Command::SUCCESS) {
            // 检查对应模型是否存在
            $modelPath = $this->targetPath('Models/' . $modelName . '.php');
            if (! File::exists($modelPath)) {
                $this->warn("提示：对应的模型 [{$modelName}] 尚未创建");
            }
        }

        return $result;
    }
}
