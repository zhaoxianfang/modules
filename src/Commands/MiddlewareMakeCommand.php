<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建中间件命令
 *
 * 在指定模块中创建中间件
 */
class MiddlewareMakeCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:make-middleware
                            {module : 模块名称}
                            {name : 中间件类名称}
                            {--force : 覆盖已存在的中间件类}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '在指定模块中创建一个中间件';

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $moduleName = Str::studly($this->argument('module'));
        $middlewareName = Str::studly($this->argument('name'));
        $force = $this->option('force');

        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在");

            return Command::FAILURE;
        }

        $middlewarePath = $module->getPath('Http/Middleware/' . $middlewareName . '.php');

        if (File::exists($middlewarePath) && ! $force) {
            $this->error("模块 [{$moduleName}] 中已存在中间件 [{$middlewareName}]");
            $this->line("提示：使用 --force 选项覆盖已存在的中间件");

            return Command::FAILURE;
        }

        if (File::exists($middlewarePath) && $force) {
            $this->warn("正在覆盖模块 [{$moduleName}] 中已存在的中间件 [{$middlewareName}]");
        }

        $namespace = config('modules.namespace', 'Modules');

        $stubGenerator = new StubGenerator($moduleName);
        $stubGenerator->addReplacement('{{CLASS}}', $middlewareName);
        $stubGenerator->addReplacement('{{NAMESPACE}}', $namespace);
        $stubGenerator->addReplacement('{{NAME}}', $moduleName);

        // 确保中间件目录存在
        $middlewareDir = $module->getPath('Http/Middleware');
        if (! is_dir($middlewareDir)) {
            File::makeDirectory($middlewareDir, 0755, true);
        }

        $result = $stubGenerator->generate(
            'middleware.stub',
            'Http/Middleware/' . $middlewareName . '.php',
            $force
        );

        if ($result) {
            $this->info("成功在模块 [{$moduleName}] 中创建中间件 [{$middlewareName}]");

            return Command::SUCCESS;
        }

        $this->error("创建中间件 [{$middlewareName}] 失败");

        return Command::FAILURE;
    }
}
