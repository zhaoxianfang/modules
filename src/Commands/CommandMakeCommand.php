<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建 Artisan 命令类
 *
 * 在指定模块中创建 Artisan 命令
 */
class CommandMakeCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:make-command
                            {module : 模块名称}
                            {name : 命令类名称}
                            {--command= : 命令签名}
                            {--force : 覆盖已存在的命令}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '在指定模块中创建一个Artisan命令';

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $moduleName = Str::studly($this->argument('module'));
        $commandName = Str::studly($this->argument('name'));
        $commandSignature = $this->option('command');
        $force = $this->option('force');

        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在");

            return Command::FAILURE;
        }

        $commandPath = $module->getPath('Console/Commands/' . $commandName . '.php');

        if (File::exists($commandPath) && ! $force) {
            $this->error("模块 [{$moduleName}] 中已存在命令 [{$commandName}]");
            $this->line("提示：使用 --force 选项覆盖已存在的命令");

            return Command::FAILURE;
        }

        if (File::exists($commandPath) && $force) {
            $this->warn("正在覆盖模块 [{$moduleName}] 中已存在的命令 [{$commandName}]");
        }

        if (empty($commandSignature)) {
            // 使用模块名小写作为命令命名空间，不添加 module: 前缀
            $commandSignature = Str::snake($moduleName) . ':command-name';
        }

        $namespace = config('modules.namespace', 'Modules');

        // 确保命令目录存在
        $commandDir = $module->getPath('Console/Commands');
        if (! is_dir($commandDir)) {
            File::makeDirectory($commandDir, 0755, true);
        }

        // 使用 StubGenerator 生成命令文件
        $stubGenerator = new StubGenerator($moduleName);
        $stubGenerator->addReplacement('{{CLASS}}', $commandName);
        $stubGenerator->addReplacement('{{NAMESPACE}}', $namespace);
        $stubGenerator->addReplacement('{{NAME}}', $moduleName);
        $stubGenerator->addReplacement('{{SIGNATURE}}', $commandSignature);
        $stubGenerator->addReplacement('{{DESCRIPTION}}', $commandName . ' 命令');
        $stubGenerator->addReplacement('{{LOWER_NAME}}', Str::snake($moduleName));

        $result = $stubGenerator->generate(
            'command.stub',
            'Console/Commands/' . $commandName . '.php',
            $force
        );

        if ($result) {
            $this->info("成功在模块 [{$moduleName}] 中创建命令 [{$commandName}]");

            return Command::SUCCESS;
        }

        $this->error("创建命令 [{$commandName}] 失败");

        return Command::FAILURE;
    }
}
