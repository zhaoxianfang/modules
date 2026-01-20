<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建配置文件命令
 *
 * 在指定模块中创建配置文件
 */
class ConfigMakeCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:make-config
                            {module : 模块名称}
                            {name : 配置文件名称}
                            {--force : 覆盖已存在的配置文件}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '在指定模块中创建一个配置文件';

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $moduleName = Str::studly($this->argument('module'));
        $configName = strtolower($this->argument('name'));
        $force = $this->option('force');

        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在");

            return Command::FAILURE;
        }

        $configPath = $module->getConfigPath() . DIRECTORY_SEPARATOR . $configName . '.php';

        if (File::exists($configPath) && ! $force) {
            $this->error("模块 [{$moduleName}] 中已存在配置文件 [{$configName}]");
            $this->line("提示：使用 --force 选项覆盖已存在的配置文件");

            return Command::FAILURE;
        }

        if (File::exists($configPath) && $force) {
            $this->warn("正在覆盖模块 [{$moduleName}] 中已存在的配置文件 [{$configName}]");
        }

        // 使用 StubGenerator 生成配置文件
        $stubGenerator = new StubGenerator($moduleName);
        $stubGenerator->addReplacement('{{NAME}}', $configName);
        $stubGenerator->addReplacement('{{LOWER_NAME}}', strtolower($configName));
        $stubGenerator->addReplacement('{{MODULE_NAME}}', $moduleName);

        $result = $stubGenerator->generate(
            'config.stub',
            'Config/' . $configName . '.php',
            $force
        );

        if ($result) {
            $this->info("成功在模块 [{$moduleName}] 中创建配置文件 [{$configName}]");

            return Command::SUCCESS;
        }

        $this->error("创建配置文件 [{$configName}] 失败");

        return Command::FAILURE;
    }
}
