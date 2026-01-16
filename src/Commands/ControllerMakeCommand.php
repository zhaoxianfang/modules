<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建控制器命令
 *
 * 在指定模块中创建控制器
 */
class ControllerMakeCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:make-controller
                            {module : 模块名称}
                            {name : 控制器名称}
                            {--type=web : 控制器类型 (web|api|admin)}
                            {--force : 覆盖已存在的控制器}
                            {--plain : 创建空控制器（无CRUD方法）}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '在指定模块中创建一个控制器';

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $moduleName = Str::studly($this->argument('module'));
        $controllerName = Str::studly($this->argument('name'));
        $type = strtolower($this->option('type'));
        $force = $this->option('force');
        $plain = $this->option('plain');

        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("Module [{$moduleName}] does not exist.");

            return Command::FAILURE;
        }

        if (! in_array($type, ['web', 'api', 'admin'])) {
            $this->error('Invalid controller type. Valid types are: web, api, admin');

            return Command::FAILURE;
        }

        $controllerPath = $module->getPath('Http/Controllers/' . Str::studly($type) . '/' . $controllerName . '.php');

        if (File::exists($controllerPath) && ! $force) {
            $this->error("Controller [{$controllerName}] already exists in module [{$moduleName}].");

            return Command::FAILURE;
        }

        $namespace = config('modules.namespace', 'Modules');
        $stubGenerator = new StubGenerator($moduleName);

        $stubGenerator->addReplacement('{{CLASS}}', $controllerName);
        $stubGenerator->addReplacement('{{NAMESPACE}}', $namespace . '\\' . $moduleName);

        // 确保控制器目录存在
        $controllerDir = $module->getPath('Http/Controllers/' . Str::studly($type));
        if (! is_dir($controllerDir)) {
            File::makeDirectory($controllerDir, 0755, true);
        }

        // 选择 stub 文件
        $stubFile = $plain ? 'controller.plain.stub' : 'controller.stub';

        // 如果没有 plain stub，使用普通的 controller.stub
        $stubPath = __DIR__ . '/stubs/' . $stubFile;
        if (! file_exists($stubPath)) {
            $stubFile = 'controller.stub';
        }

        $result = $stubGenerator->generate($stubFile, 'Http/Controllers/' . Str::studly($type) . '/' . $controllerName . '.php', $force);

        if ($result) {
            $this->info("Controller [{$controllerName}] created successfully in module [{$moduleName}].");

            return Command::SUCCESS;
        }

        $this->error("Failed to create controller [{$controllerName}].");

        return Command::FAILURE;
    }
}
