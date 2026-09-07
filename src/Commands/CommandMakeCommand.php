<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建 Artisan 命令类命令
 *
 * 在指定模块中创建 Artisan 命令类
 * 支持自定义命令签名和描述
 * 创建的命令会自动注册到模块中
 */
class CommandMakeCommand extends AbstractMakeCommand
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
        $module = $this->resolveModule();
        if (! $module) {
            return Command::FAILURE;
        }

        $commandName = Str::studly($this->argument('name'));
        $commandSignature = $this->option('command');
        $force = $this->option('force');

        // 生成命令签名
        if (empty($commandSignature)) {
            // 使用模块名小写作为命令命名空间，不添加 module: 前缀
            $commandSignature = Str::snake($module->getName()) . ':command-name';
            $this->line("命令签名: {$commandSignature}");
            $this->line("提示：你可以使用 --command 选项自定义命令签名");
        }

        $generator = $this->makeStubGenerator();
        $generator->addReplacement('{{CLASS}}', $commandName);
        $generator->addReplacement('{{NAMESPACE}}', $this->module->getNamespace());
        $generator->addReplacement('{{NAME}}', $module->getName());
        $generator->addReplacement('{{SIGNATURE}}', $commandSignature);
        $generator->addReplacement('{{DESCRIPTION}}', $commandName . ' 命令');
        $generator->addReplacement('{{LOWER_NAME}}', Str::snake($module->getName()));

        $result = $this->writeStub($generator, 'command.stub', 'Console/Commands/' . $commandName . '.php', '命令类', $force);

        if ($result === Command::SUCCESS) {
            $this->line("命令位置: " . $this->targetPath('Console/Commands/' . $commandName . '.php'));
            $this->line("");
            $this->line("使用命令:");
            $this->line("  php artisan {$commandSignature}");
        } else {
            $this->line("提示：检查文件权限和磁盘空间");
        }

        return $result;
    }
}
