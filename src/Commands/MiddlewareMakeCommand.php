<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建中间件命令
 *
 * 在指定模块中创建中间件
 */
class MiddlewareMakeCommand extends AbstractMakeCommand
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:make-middleware
                            {module : 模块名称}
                            {name : 中间件类名称}
                            {--force : 覆盖已存在的中间件类}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '在指定模块中创建一个中间件';

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

        $middlewareName = Str::studly($this->argument('name'));
        $force = $this->option('force');

        $generator = $this->makeStubGenerator();
        $generator->addReplacement('{{CLASS}}', $middlewareName);
        $generator->addReplacement('{{NAMESPACE}}', $this->module->getNamespace());
        $generator->addReplacement('{{NAME}}', $module->getName());

        return $this->writeStub($generator, 'middleware.stub', 'Http/Middleware/' . $middlewareName . '.php', '中间件', $force);
    }
}
