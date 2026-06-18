<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建 API 资源命令
 *
 * 在指定模块中创建 Laravel API 资源转换器类。
 * 资源类用于将 Eloquent 模型转换为 JSON 响应格式，
 * 支持基础的 Resource 和 ResourceCollection。
 *
 * 使用示例：
 *   php artisan module:make-resource Blog PostResource
 *
 * @package zxf\Modules\Commands
 */
class ResourceMakeCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'module:make-resource
                            {module : 模块名称（必需，例如：Blog）}
                            {name : 资源名称（必需，例如：PostResource）}
                            {--collection : 创建资源集合类}
                            {--force : 覆盖已存在的文件}';

    /**
     * @var string
     */
    protected $description = '在指定模块中创建 API 资源转换器';

    /**
     * 执行命令
     */
    public function handle(): int
    {
        $moduleName = Str::studly($this->argument('module'));
        $resourceName = Str::studly($this->argument('name'));
        $force = $this->option('force');

        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在");
            return Command::FAILURE;
        }

        $resourcePath = $module->getPath('Http/Resources/' . $resourceName . '.php');

        if (File::exists($resourcePath) && ! $force) {
            $this->error("模块 [{$moduleName}] 中已存在资源 [{$resourceName}]");
            $this->line('提示：使用 --force 选项覆盖已存在的资源');
            return Command::FAILURE;
        }

        if (File::exists($resourcePath) && $force) {
            $this->warn("正在覆盖模块 [{$moduleName}] 中已存在的资源 [{$resourceName}]");
        }

        $namespace = config('modules.namespace', 'Modules');

        $stubGenerator = new StubGenerator($moduleName);
        $stubGenerator->addReplacements([
            '{{CLASS}}' => $resourceName,
            '{{NAMESPACE}}' => $namespace,
            '{{NAME}}' => $moduleName,
        ]);

        // 确保目录存在
        $resourceDir = $module->getPath('Http/Resources');
        if (! is_dir($resourceDir)) {
            File::makeDirectory($resourceDir, 0755, true);
        }

        $result = $stubGenerator->generate(
            'resource.stub',
            'Http/Resources/' . $resourceName . '.php',
            $force
        );

        if ($result) {
            $this->info("✓ 成功在模块 [{$moduleName}] 中创建资源 [{$resourceName}]");
            return Command::SUCCESS;
        }

        $this->error("创建资源 [{$resourceName}] 失败");
        return Command::FAILURE;
    }
}
