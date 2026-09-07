<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建数据填充器命令
 *
 * 在指定模块中创建数据填充器
 */
class SeederMakeCommand extends AbstractMakeCommand
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:make-seeder
                            {module : 模块名称}
                            {name : 填充器名称}
                            {--force : 覆盖已存在的填充器}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '在指定模块中创建一个数据填充器';

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

        $seederName = Str::studly($this->argument('name'));
        $force = $this->option('force');

        $generator = $this->makeStubGenerator();
        $generator->addReplacement('{{CLASS}}', $seederName);
        $generator->addReplacement('{{NAMESPACE}}', $this->module->getNamespace());
        $generator->addReplacement('{{NAME}}', $module->getName());

        return $this->writeStub($generator, 'seeder.stub', 'Database/Seeders/' . $seederName . '.php', '填充器', $force);
    }
}
