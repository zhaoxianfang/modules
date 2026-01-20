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
            $this->error("模块 [{$moduleName}] 不存在");

            return Command::FAILURE;
        }

        $requestPath = $module->getPath('Http/Requests/' . $requestName . '.php');

        if (File::exists($requestPath) && ! $force) {
            $this->error("模块 [{$moduleName}] 中已存在请求类 [{$requestName}]");
            $this->line("提示：使用 --force 选项覆盖已存在的请求类");

            return Command::FAILURE;
        }

        if (File::exists($requestPath) && $force) {
            $this->warn("正在覆盖模块 [{$moduleName}] 中已存在的请求类 [{$requestName}]");
        }

        $namespace = config('modules.namespace', 'Modules');

        $stubGenerator = new StubGenerator($moduleName);
        $stubGenerator->addReplacement('{{CLASS}}', $requestName);
        $stubGenerator->addReplacement('{{NAMESPACE}}', $namespace);
        $stubGenerator->addReplacement('{{NAME}}', $moduleName);

        // 确保请求类目录存在
        $requestDir = $module->getPath('Http/Requests');
        if (! is_dir($requestDir)) {
            File::makeDirectory($requestDir, 0755, true);
        }

        $result = $stubGenerator->generate(
            'request.stub',
            'Http/Requests/' . $requestName . '.php',
            $force
        );

        if ($result) {
            $this->info("成功在模块 [{$moduleName}] 中创建请求类 [{$requestName}]");

            return Command::SUCCESS;
        }

        $this->error("创建请求类 [{$requestName}] 失败");

        return Command::FAILURE;
    }
}
