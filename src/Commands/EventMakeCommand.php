<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建事件命令
 *
 * 在指定模块中创建事件类
 */
class EventMakeCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:make-event
                            {module : 模块名称}
                            {name : 事件类名称}
                            {--force : 覆盖已存在的事件类}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '在指定模块中创建一个事件类';

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $moduleName = Str::studly($this->argument('module'));
        $eventName = Str::studly($this->argument('name'));
        $force = $this->option('force');

        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("Module [{$moduleName}] does not exist.");

            return Command::FAILURE;
        }

        $eventPath = $module->getPath('Events/' . $eventName . '.php');

        if (File::exists($eventPath) && ! $force) {
            $this->error("Event [{$eventName}] already exists in module [{$moduleName}].");
            $this->line("Use --force flag to overwrite the existing event.");

            return Command::FAILURE;
        }

        if (File::exists($eventPath) && $force) {
            $this->warn("Overwriting existing event [{$eventName}] in module [{$moduleName}].");
        }

        $namespace = config('modules.namespace', 'Modules');

        $stubGenerator = new StubGenerator($moduleName);
        $stubGenerator->addReplacement('{{CLASS}}', $eventName);
        $stubGenerator->addReplacement('{{NAMESPACE}}', $namespace);
        $stubGenerator->addReplacement('{{NAME}}', $moduleName);

        // 确保事件目录存在
        $eventDir = $module->getPath('Events');
        if (! is_dir($eventDir)) {
            File::makeDirectory($eventDir, 0755, true);
        }

        $result = $stubGenerator->generate(
            'event.stub',
            'Events/' . $eventName . '.php',
            $force
        );

        if ($result) {
            $this->info("Event [{$eventName}] created successfully in module [{$moduleName}].");

            return Command::SUCCESS;
        }

        $this->error("Failed to create event [{$eventName}].");

        return Command::FAILURE;
    }
}
