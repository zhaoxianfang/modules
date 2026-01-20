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
                            {--type=web : 控制器类型（可自定义，如web、api、admin、mobile等）}
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
            $this->error("模块 [{$moduleName}] 不存在");

            return Command::FAILURE;
        }

        // 类型不再限制，允许任意自定义类型

        $controllerPath = $module->getPath('Http/Controllers/' . Str::studly($type) . '/' . $controllerName . '.php');

        if (File::exists($controllerPath) && ! $force) {
            $this->error("模块 [{$moduleName}] 中已存在控制器 [{$controllerName}]");
            $this->line("提示：使用 --force 选项覆盖已存在的控制器");

            return Command::FAILURE;
        }

        if (File::exists($controllerPath) && $force) {
            $this->warn("正在覆盖模块 [{$moduleName}] 中已存在的控制器 [{$controllerName}]");
        }

        $namespace = config('modules.namespace', 'Modules');
        $stubGenerator = new StubGenerator($moduleName);

        $stubGenerator->addReplacement('{{CLASS}}', $controllerName);
        $stubGenerator->addReplacement('{{NAMESPACE}}', $namespace);
        $stubGenerator->addReplacement('{{CONTROLLER_SUBNAMESPACE}}', '\\' . Str::studly($type));

        // 确保控制器目录存在
        $controllerDir = $module->getPath('Http/Controllers/' . Str::studly($type));
        if (! is_dir($controllerDir)) {
            File::makeDirectory($controllerDir, 0755, true);
        }

        // 选择 stub 文件
        $stubFile = $plain ? 'controller.plain.stub' : 'controller.stub';

        $result = $stubGenerator->generate(
            $stubFile,
            'Http/Controllers/' . Str::studly($type) . '/' . $controllerName . '.php',
            $force
        );

        if ($result) {
            $this->info("成功在模块 [{$moduleName}] 中创建控制器 [{$controllerName}]");

            return Command::SUCCESS;
        }

        $this->error("创建控制器 [{$controllerName}] 失败");

        return Command::FAILURE;
    }
}
