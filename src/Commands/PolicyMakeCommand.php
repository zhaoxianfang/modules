<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;
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
class PolicyMakeCommand extends Command
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
        $moduleName = Str::studly($this->argument('module'));
        $policyName = Str::studly($this->argument('name'));
        $force = $this->option('force');

        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在");
            return Command::FAILURE;
        }

        $policyPath = $module->getPath('Policies/' . $policyName . '.php');

        if (File::exists($policyPath) && ! $force) {
            $this->error("模块 [{$moduleName}] 中已存在策略 [{$policyName}]");
            $this->line('提示：使用 --force 选项覆盖已存在的策略');
            return Command::FAILURE;
        }

        if (File::exists($policyPath) && $force) {
            $this->warn("正在覆盖模块 [{$moduleName}] 中已存在的策略 [{$policyName}]");
        }

        // 推导模型名称
        $modelName = $this->option('model') ?: preg_replace('/Policy$/i', '', $policyName);
        $namespace = config('modules.namespace', 'Modules');

        $stubGenerator = new StubGenerator($moduleName);
        $stubGenerator->addReplacements([
            '{{CLASS}}' => $policyName,
            '{{NAMESPACE}}' => $namespace,
            '{{NAME}}' => $moduleName,
            '{{MODEL}}' => $modelName,
            '{{MODEL_NAMESPACE}}' => $namespace . '\\' . $moduleName . '\\Models',
        ]);

        // 确保目录存在
        $policyDir = $module->getPath('Policies');
        if (! is_dir($policyDir)) {
            File::makeDirectory($policyDir, 0755, true);
        }

        $result = $stubGenerator->generate(
            'policy.stub',
            'Policies/' . $policyName . '.php',
            $force
        );

        if ($result) {
            $this->info("✓ 成功在模块 [{$moduleName}] 中创建策略 [{$policyName}]");

            // 检查对应模型是否存在
            $modelPath = $module->getPath('Models/' . $modelName . '.php');
            if (! File::exists($modelPath)) {
                $this->warn("提示：对应的模型 [{$modelName}] 尚未创建");
            }

            return Command::SUCCESS;
        }

        $this->error("创建策略 [{$policyName}] 失败");
        return Command::FAILURE;
    }
}
