<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建服务提供者命令
 *
 * 在指定模块中创建服务提供者
 */
class ProviderMakeCommand extends Command
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
        $moduleName = Str::studly($this->argument('module'));
        $providerName = Str::studly($this->argument('name'));
        $force = $this->option('force');

        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在");

            return Command::FAILURE;
        }

        $providerPath = $module->getPath('Providers/' . $providerName . '.php');

        if (File::exists($providerPath) && ! $force) {
            $this->error("模块 [{$moduleName}] 中已存在服务提供者 [{$providerName}]");
            $this->line("提示：使用 --force 选项覆盖已存在的服务提供者");

            return Command::FAILURE;
        }

        if (File::exists($providerPath) && $force) {
            $this->warn("正在覆盖模块 [{$moduleName}] 中已存在的服务提供者 [{$providerName}]");
        }

        $namespace = config('modules.namespace', 'Modules');

        $stubGenerator = new StubGenerator($moduleName);
        $stubGenerator->addReplacement('{{CLASS}}', $providerName);
        $stubGenerator->addReplacement('{{NAMESPACE}}', $namespace);
        $stubGenerator->addReplacement('{{NAME}}', $moduleName);

        // 确保服务提供者目录存在
        $providerDir = $module->getPath('Providers');
        if (! is_dir($providerDir)) {
            File::makeDirectory($providerDir, 0755, true);
        }

        $result = $stubGenerator->generate(
            'provider.stub',
            'Providers/' . $providerName . '.php',
            $force
        );

        if ($result) {
            $this->info("成功在模块 [{$moduleName}] 中创建服务提供者 [{$providerName}]");

            return Command::SUCCESS;
        }

        $this->error("创建服务提供者 [{$providerName}] 失败");

        return Command::FAILURE;
    }
}
