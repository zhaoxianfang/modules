<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建表单请求命令
 *
 * 在指定模块中创建表单请求类
 */
class RequestMakeCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:make-request
                            {module : 模块名称}
                            {name : 请求类名称}
                            {--force : 覆盖已存在的请求类}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '在指定模块中创建一个表单请求类';

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $moduleName = Str::studly($this->argument('module'));
        $requestName = Str::studly($this->argument('name'));
        $force = $this->option('force');

        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("Module [{$moduleName}] does not exist.");

            return Command::FAILURE;
        }

        $requestPath = $module->getPath('Http/Requests/' . $requestName . '.php');

        if (File::exists($requestPath) && ! $force) {
            $this->error("Request [{$requestName}] already exists in module [{$moduleName}].");

            return Command::FAILURE;
        }

        $namespace = config('modules.namespace', 'Modules');

        $stubGenerator = new StubGenerator($moduleName);
        $stubGenerator->addReplacement('{{CLASS}}', $requestName);
        $stubGenerator->addReplacement('{{NAMESPACE}}', $namespace . '\\' . $moduleName);

        // 确保请求类目录存在
        $requestDir = $module->getPath('Http/Requests');
        if (! is_dir($requestDir)) {
            File::makeDirectory($requestDir, 0755, true);
        }

        $result = $stubGenerator->generate('request.stub', 'Http/Requests/' . $requestName . '.php', $force);

        if ($result) {
            $this->info("Request [{$requestName}] created successfully in module [{$moduleName}].");

            return Command::SUCCESS;
        }

        $this->error("Failed to create request [{$requestName}].");

        return Command::FAILURE;
    }
}
