<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
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
class ResourceMakeCommand extends AbstractMakeCommand
{
    /**
     * @var string
     */
    protected $signature = 'module:make-resource
                            {module : 模块名称（必需，例如：Blog）}
                            {name : 资源名称（必需，例如：PostResource）}
                            {--collection : 创建资源集合类}
                            {--json-api : 生成 Laravel 13 一等公民 JSON:API 资源（继承 JsonApiResource）}
                            {--force : 覆盖已存在的文件}';

    /**
     * @var string
     */
    protected $description = '在指定模块中创建 API 资源转换器（支持 --json-api 生成 Laravel 13 JSON:API 资源）';

    /**
     * 执行命令
     */
    public function handle(): int
    {
        $module = $this->resolveModule();
        if (! $module) {
            return Command::FAILURE;
        }

        $resourceName = Str::studly($this->argument('name'));
        $force = $this->option('force');
        $jsonApi = $this->option('json-api');

        $generator = $this->makeStubGenerator();
        $generator->addReplacements([
            '{{CLASS}}' => $resourceName,
            '{{NAMESPACE}}' => $this->module->getNamespace(),
            '{{NAME}}' => $module->getName(),
        ]);

        return $this->writeStub(
            $generator,
            $jsonApi ? 'resource.json-api.stub' : 'resource.stub',
            'Http/Resources/' . $resourceName . '.php',
            '资源',
            $force
        );
    }
}
