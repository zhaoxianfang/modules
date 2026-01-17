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
            $this->error("Module [{$moduleName}] does not exist.");

            return Command::FAILURE;
        }

        $middlewarePath = $module->getPath('Http/Middleware/' . $middlewareName . '.php');

        if (File::exists($middlewarePath) && ! $force) {
            $this->error("Middleware [{$middlewareName}] already exists in module [{$moduleName}].");
            $this->line("Use --force flag to overwrite the existing middleware.");

            return Command::FAILURE;
        }

        if (File::exists($middlewarePath) && $force) {
            $this->warn("Overwriting existing middleware [{$middlewareName}] in module [{$moduleName}].");
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
            $this->info("Middleware [{$middlewareName}] created successfully in module [{$moduleName}].");

            return Command::SUCCESS;
        }

        $this->error("Failed to create middleware [{$middlewareName}].");

        return Command::FAILURE;
    }
}
