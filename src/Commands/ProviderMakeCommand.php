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
            $this->error("Module [{$moduleName}] does not exist.");

            return Command::FAILURE;
        }

        $providerPath = $module->getPath('Providers/' . $providerName . '.php');

        if (File::exists($providerPath) && ! $force) {
            $this->error("Provider [{$providerName}] already exists in module [{$moduleName}].");

            return Command::FAILURE;
        }

        $namespace = config('modules.namespace', 'Modules');

        $stubGenerator = new StubGenerator($moduleName);
        $stubGenerator->addReplacement('{{CLASS}}', $providerName);
        $stubGenerator->addReplacement('{{NAMESPACE}}', $namespace . '\\' . $moduleName);

        // 确保服务提供者目录存在
        $providerDir = $module->getPath('Providers');
        if (! is_dir($providerDir)) {
            File::makeDirectory($providerDir, 0755, true);
        }

        $result = $stubGenerator->generate('provider.stub', 'Providers/' . $providerName . '.php', $force);

        if ($result) {
            $this->info("Provider [{$providerName}] created successfully in module [{$moduleName}].");

            return Command::SUCCESS;
        }

        $this->error("Failed to create provider [{$providerName}].");

        return Command::FAILURE;
    }
}
