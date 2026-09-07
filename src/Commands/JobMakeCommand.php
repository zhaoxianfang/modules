<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建队列任务命令
 *
 * 在指定模块中创建 Laravel 13 风格的队列任务类（Queue Job），
 * 默认演示 #[Tries] / #[Backoff] 等基于 PHP 属性的声明式任务配置。
 */
class JobMakeCommand extends AbstractMakeCommand
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:make-job
                            {module : 模块名称（例如：Blog）}
                            {name : 任务类名称（例如：SendEmail）}
                            {--force : 覆盖已存在的任务类}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '在指定模块中创建一个队列任务类（支持 Laravel 13 属性化配置）';

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

        $jobName = Str::studly($this->argument('name'));
        $force = $this->option('force');

        $generator = $this->makeStubGenerator();
        $generator->addReplacement('{{CLASS}}', $jobName);
        $generator->addReplacement('{{NAMESPACE}}', $this->module->getNamespace());
        $generator->addReplacement('{{NAME}}', $module->getName());

        $result = $this->writeStub($generator, 'job.stub', 'Jobs/' . $jobName . '.php', '任务类', $force);

        if ($result === Command::SUCCESS) {
            $namespace = $this->module->getNamespace();
            $this->line("任务位置: " . $this->targetPath('Jobs/' . $jobName . '.php'));
            $this->line("");
            $this->line("调度任务示例:");
            $this->line("  \\{$namespace}\\{$module->getName()}\\Jobs\\{$jobName}::dispatch();");
        }

        return $result;
    }
}
