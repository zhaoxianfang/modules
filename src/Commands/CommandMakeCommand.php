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
            $this->error("Module [{$moduleName}] does not exist.");

            return Command::FAILURE;
        }

        $commandPath = $module->getPath('Console/Commands/' . $commandName . '.php');

        if (File::exists($commandPath) && ! $force) {
            $this->error("Command [{$commandName}] already exists in module [{$moduleName}].");

            return Command::FAILURE;
        }

        if (empty($commandSignature)) {
            $commandSignature = 'module:' . Str::snake($moduleName) . ':' . Str::snake(str_replace('Command', '', $commandName));
        }

        $namespace = config('modules.namespace', 'Modules');

        // 确保命令目录存在
        $commandDir = $module->getPath('Console/Commands');
        if (! is_dir($commandDir)) {
            File::makeDirectory($commandDir, 0755, true);
        }

        $stubGenerator = new StubGenerator($moduleName);
        $stubGenerator->addReplacement('{{CLASS}}', $commandName);
        $stubGenerator->addReplacement('{{NAMESPACE}}', $namespace . '\\' . $moduleName);
        $stubGenerator->addReplacement('{{SIGNATURE}}', $commandSignature);
        $stubGenerator->addReplacement('{{DESCRIPTION}}', 'Command description');

        $commandContent = "<?php\n\nnamespace {$namespace}\\{$moduleName}\\Console\\Commands;\n\nuse Illuminate\\Console\\Command;\n\nclass {$commandName} extends Command\n{\n    /**\n     * 命令签名\n     *\n     * @var string\n     */\n    protected \$signature = '{$commandSignature}';\n\n    /**\n     * 命令描述\n     *\n     * @var string\n     */\n    protected \$description = 'Command description';\n\n    /**\n     * 执行命令\n     */\n    public function handle(): int\n    {\n        // 在这里实现命令逻辑\n\n        return Command::SUCCESS;\n    }\n}\n";

        $result = File::put($commandPath, $commandContent);

        if ($result) {
            $this->info("Command [{$commandName}] created successfully in module [{$moduleName}].");

            return Command::SUCCESS;
        }

        $this->error("Failed to create command [{$commandName}].");

        return Command::FAILURE;
    }
}
