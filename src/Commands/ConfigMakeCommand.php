<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;

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
            $this->error("Module [{$moduleName}] does not exist.");

            return Command::FAILURE;
        }

        $configPath = $module->getConfigPath() . DIRECTORY_SEPARATOR . $configName . '.php';

        if (File::exists($configPath) && ! $force) {
            $this->error("Config file [{$configName}] already exists in module [{$moduleName}].");

            return Command::FAILURE;
        }

        $content = "<?php\n\nreturn [\n    /*\n    |--------------------------------------------------------------------------\n    | {$configName} 配置\n    |--------------------------------------------------------------------------\n    |\n    | {$configName} 模块的配置选项\n    |\n    */\n    'enable' => true,\n\n    /*\n    |--------------------------------------------------------------------------\n    | 自定义配置\n    |--------------------------------------------------------------------------\n    |\n    | 在这里添加模块的自定义配置\n    |\n    */\n    'options' => [\n        // 'key' => 'value',\n    ],\n];\n";

        $result = File::put($configPath, $content);

        if ($result) {
            $this->info("Config file [{$configName}] created successfully in module [{$moduleName}].");

            return Command::SUCCESS;
        }

        $this->error("Failed to create config file [{$configName}].");

        return Command::FAILURE;
    }
}
