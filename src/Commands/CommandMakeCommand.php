<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建 Artisan 命令类命令
 *
 * 在指定模块中创建 Artisan 命令类
 * 支持自定义命令签名和描述
 * 创建的命令会自动注册到模块中
 */
class CommandMakeCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:make-command
                            {module : 模块名称（例如：Blog）}
                            {name : 命令类名称（例如：SendEmail）}
                            {--command= : 命令签名（例如：email:send），不指定则自动生成}
                            {--force : 覆盖已存在的命令}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '在指定模块中创建一个 Artisan 命令类';

    /**
     * 执行命令
     *
     * 创建步骤：
     * 1. 验证模块是否存在
     * 2. 检查命令是否已存在（除非使用 --force）
     * 3. 生成命令签名（如果未指定）
     * 4. 创建命令文件
     * 5. 命令会自动注册到模块中
     *
     * @return int
     */
    public function handle(): int
    {
        $moduleName = Str::studly($this->argument('module'));
        $commandName = Str::studly($this->argument('name'));
        $commandSignature = $this->option('command');
        $force = $this->option('force');

        // 验证模块是否存在
        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在");
            $this->line("提示：请先创建模块，使用 php artisan module:make {$moduleName}");
            return Command::FAILURE;
        }

        // 检查命令是否已存在
        $commandPath = $module->getPath('Console/Commands/' . $commandName . '.php');

        if (File::exists($commandPath) && ! $force) {
            $this->error("模块 [{$moduleName}] 中已存在命令类 [{$commandName}]");
            $this->line("文件位置: {$commandPath}");
            $this->line("提示：使用 --force 选项覆盖已存在的命令");
            return Command::FAILURE;
        }

        if (File::exists($commandPath) && $force) {
            $this->warn("正在覆盖模块 [{$moduleName}] 中已存在的命令类 [{$commandName}]");
        }

        // 生成命令签名
        if (empty($commandSignature)) {
            // 使用模块名小写作为命令命名空间，不添加 module: 前缀
            $commandSignature = Str::snake($moduleName) . ':command-name';
            $this->line("命令签名: {$commandSignature}");
            $this->line("提示：你可以使用 --command 选项自定义命令签名");
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
            $this->info("成功在模块 [{$moduleName}] 中创建命令类 [{$commandName}]");
            $this->line("命令位置: {$commandPath}");
            $this->line("");
            $this->line("使用命令:");
            $this->line("  php artisan {$commandSignature}");
            return Command::SUCCESS;
        }

        $this->error("创建命令类 [{$commandName}] 失败");
        $this->line("提示：检查文件权限和磁盘空间");

        return Command::FAILURE;
    }
}
