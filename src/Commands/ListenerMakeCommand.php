<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建监听器命令
 *
 * 在指定模块中创建事件监听器
 */
class ListenerMakeCommand extends AbstractMakeCommand
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
        $module = $this->resolveModule();
        if (! $module) {
            return Command::FAILURE;
        }

        $listenerName = Str::studly($this->argument('name'));
        $eventName = $this->option('event');
        $force = $this->option('force');

        $namespace = $this->module->getNamespace();

        // 如果没有指定事件，使用通用事件类并自动创建
        if (empty($eventName)) {
            $eventName = $module->getName() . 'Event';

            $eventStub = $this->makeStubGenerator();
            $eventStub->addReplacement('{{CLASS}}', $eventName);
            $eventStub->addReplacement('{{NAMESPACE}}', $namespace);
            $eventStub->addReplacement('{{NAME}}', $module->getName());

            $this->generateFile($eventStub, 'event.stub', 'Events/' . $eventName . '.php', true);
        } else {
            $eventName = Str::studly($eventName);
        }

        $stubGenerator = $this->makeStubGenerator();
        $stubGenerator->addReplacement('{{CLASS}}', $listenerName);
        $stubGenerator->addReplacement('{{NAMESPACE}}', $namespace);
        $stubGenerator->addReplacement('{{NAME}}', $module->getName());
        $stubGenerator->addReplacement('{{EVENT_NAMESPACE}}', $namespace . '\\' . $module->getName() . '\\Events');
        $stubGenerator->addReplacement('{{EVENT}}', $eventName);

        return $this->writeStub($stubGenerator, 'listener.stub', 'Listeners/' . $listenerName . '.php', '监听器', $force);
    }
}
