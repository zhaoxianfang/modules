<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建事件命令
 *
 * 在指定模块中创建事件类
 */
class EventMakeCommand extends AbstractMakeCommand
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
        $module = $this->resolveModule();
        if (! $module) {
            return Command::FAILURE;
        }

        $eventName = Str::studly($this->argument('name'));
        $force = $this->option('force');

        $generator = $this->makeStubGenerator();
        $generator->addReplacement('{{CLASS}}', $eventName);
        $generator->addReplacement('{{NAMESPACE}}', $this->module->getNamespace());
        $generator->addReplacement('{{NAME}}', $module->getName());

        return $this->writeStub($generator, 'event.stub', 'Events/' . $eventName . '.php', '事件类', $force);
    }
}
