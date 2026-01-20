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
            $this->error("模块 [{$moduleName}] 不存在");

            return Command::FAILURE;
        }

        $eventPath = $module->getPath('Events/' . $eventName . '.php');

        if (File::exists($eventPath) && ! $force) {
            $this->error("模块 [{$moduleName}] 中已存在事件类 [{$eventName}]");
            $this->line("提示：使用 --force 选项覆盖已存在的事件类");

            return Command::FAILURE;
        }

        if (File::exists($eventPath) && $force) {
            $this->warn("正在覆盖模块 [{$moduleName}] 中已存在的事件类 [{$eventName}]");
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
            $this->info("成功在模块 [{$moduleName}] 中创建事件类 [{$eventName}]");

            return Command::SUCCESS;
        }

        $this->error("创建事件类 [{$eventName}] 失败");

        return Command::FAILURE;
    }
}
