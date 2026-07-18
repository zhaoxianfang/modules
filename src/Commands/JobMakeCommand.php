<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建队列任务命令
 *
 * 在指定模块中创建 Laravel 13 风格的队列任务类（Queue Job），
 * 默认演示 #[Tries] / #[Backoff] 等基于 PHP 属性的声明式任务配置。
 */
class JobMakeCommand extends Command
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
        $moduleName = Str::studly($this->argument('module'));
        $jobName = Str::studly($this->argument('name'));
        $force = $this->option('force');

        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在");
            $this->line("提示：请先创建模块，使用 php artisan module:make {$moduleName}");
            return Command::FAILURE;
        }

        $jobPath = $module->getPath('Jobs/' . $jobName . '.php');

        if (File::exists($jobPath) && ! $force) {
            $this->error("模块 [{$moduleName}] 中已存在任务类 [{$jobName}]");
            $this->line("文件位置: {$jobPath}");
            $this->line("提示：使用 --force 选项覆盖已存在的任务类");
            return Command::FAILURE;
        }

        if (File::exists($jobPath) && $force) {
            $this->warn("正在覆盖模块 [{$moduleName}] 中已存在的任务类 [{$jobName}]");
        }

        $namespace = config('modules.namespace', 'Modules');

        // 确保任务目录存在
        $jobDir = $module->getPath('Jobs');
        if (! is_dir($jobDir)) {
            File::makeDirectory($jobDir, 0755, true);
        }

        $stubGenerator = new StubGenerator($moduleName);
        $stubGenerator->addReplacement('{{CLASS}}', $jobName);
        $stubGenerator->addReplacement('{{NAMESPACE}}', $namespace);
        $stubGenerator->addReplacement('{{NAME}}', $moduleName);

        $result = $stubGenerator->generate(
            'job.stub',
            'Jobs/' . $jobName . '.php',
            $force
        );

        if ($result) {
            $this->info("成功在模块 [{$moduleName}] 中创建队列任务类 [{$jobName}]");
            $this->line("任务位置: {$jobPath}");
            $this->line("");
            $this->line("调度任务示例:");
            $this->line("  \\{$namespace}\\{$moduleName}\\Jobs\\{$jobName}::dispatch();");
            return Command::SUCCESS;
        }

        $this->error("创建任务类 [{$jobName}] 失败");
        $this->line("提示：检查文件权限和磁盘空间");

        return Command::FAILURE;
    }
}
