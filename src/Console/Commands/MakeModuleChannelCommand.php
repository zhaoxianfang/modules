<?php

namespace zxf\Modules\Console\Commands;

use Illuminate\Support\Str;

class MakeModuleChannelCommand extends ModuleGeneratorCommand
{
    /**
     * 控制台命令的名称和签名。
     *
     * @var string
     */
    protected $signature = 'module:make:channel {module : 模块名称} {name : 频道名称} {--force : 覆盖现有文件}';

    /**
     * 控制台命令的描述。
     *
     * @var string
     */
    protected $description = '为模块创建新的频道';

    /**
     * 获取目标类路径。
     */
    protected function getDestinationPath(string $moduleName, string $name): string
    {
        $channelName = Str::studly($name);
        $channelPath = $this->getModulePath($moduleName) . '/Broadcasting/Channels/' . $channelName . '.php';

        return $channelPath;
    }

    /**
     * 获取存根文件路径。
     */
    protected function getStubFile(): string
    {
        return $this->getStubPath('channel.stub');
    }

    /**
     * 获取存根文件的替换变量。
     */
    protected function getReplacements(string $moduleName, string $name): array
    {
        $replacements = parent::getReplacements($moduleName, $name);
        // 为频道存根添加任何额外的替换变量
        return $replacements;
    }
}