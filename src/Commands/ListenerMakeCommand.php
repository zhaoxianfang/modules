<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建监听器命令
 *
 * 在指定模块中创建事件监听器
 */
class ListenerMakeCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:make-listener
                            {module : 模块名称}
                            {name : 监听器类名称}
                            {--event= : 要监听的事件类}
                            {--force : 覆盖已存在的监听器类}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '在指定模块中创建一个事件监听器';

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $moduleName = Str::studly($this->argument('module'));
        $listenerName = Str::studly($this->argument('name'));
        $eventName = $this->option('event');
        $force = $this->option('force');

        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在");

            return Command::FAILURE;
        }

        $listenerPath = $module->getPath('Listeners/' . $listenerName . '.php');

        if (File::exists($listenerPath) && ! $force) {
            $this->error("模块 [{$moduleName}] 中已存在监听器 [{$listenerName}]");
            $this->line("提示：使用 --force 选项覆盖已存在的监听器");

            return Command::FAILURE;
        }

        if (File::exists($listenerPath) && $force) {
            $this->warn("正在覆盖模块 [{$moduleName}] 中已存在的监听器 [{$listenerName}]");
        }

        $namespace = config('modules.namespace', 'Modules');

        $stubGenerator = new StubGenerator($moduleName);
        $stubGenerator->addReplacement('{{CLASS}}', $listenerName);
        $stubGenerator->addReplacement('{{NAMESPACE}}', $namespace);
        $stubGenerator->addReplacement('{{NAME}}', $moduleName);
        // 添加事件命名空间变量
        $stubGenerator->addReplacement('{{EVENT_NAMESPACE}}', $namespace . '\\' . $moduleName . '\\Events');

        // 如果没有指定事件，使用通用事件类
        if (empty($eventName)) {
            $eventName = $moduleName.'Event';
            // 创建通用事件类
            $eventStub = new StubGenerator($moduleName);
            $eventStub->addReplacement('{{CLASS}}', $eventName);
            $eventStub->addReplacement('{{NAMESPACE}}', $namespace);
            $eventStub->addReplacement('{{NAME}}', $moduleName);

            // 确保事件目录存在
            $eventDir = $module->getPath('Events');
            if (! is_dir($eventDir)) {
                File::makeDirectory($eventDir, 0755, true);
            }

            $eventStub->generate('event.stub', 'Events/' . $eventName . '.php', true);
        } else {
            $eventName = Str::studly($eventName);
        }

        $stubGenerator->addReplacement('{{EVENT}}', $eventName);

        // 确保监听器目录存在
        $listenerDir = $module->getPath('Listeners');
        if (! is_dir($listenerDir)) {
            File::makeDirectory($listenerDir, 0755, true);
        }

        $result = $stubGenerator->generate(
            'listener.stub',
            'Listeners/' . $listenerName . '.php',
            $force
        );

        if ($result) {
            $this->info("成功在模块 [{$moduleName}] 中创建监听器 [{$listenerName}]");

            return Command::SUCCESS;
        }

        $this->error("创建监听器 [{$listenerName}] 失败");

        return Command::FAILURE;
    }
}
