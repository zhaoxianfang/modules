<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建服务提供者命令
 *
 * 在指定模块中创建服务提供者
 */
class ProviderMakeCommand extends AbstractMakeCommand
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:make-provider
                            {module : 模块名称}
                            {name : 服务提供者名称}
                            {--force : 覆盖已存在的服务提供者}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '在指定模块中创建一个服务提供者';

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

        $providerName = Str::studly($this->argument('name'));
        $force = $this->option('force');

        $generator = $this->makeStubGenerator();
        $generator->addReplacement('{{CLASS}}', $providerName);
        $generator->addReplacement('{{NAMESPACE}}', $this->module->getNamespace());
        $generator->addReplacement('{{NAME}}', $module->getName());

        return $this->writeStub($generator, 'provider.stub', 'Providers/' . $providerName . '.php', '服务提供者', $force);
    }
}
