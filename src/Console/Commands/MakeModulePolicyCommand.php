<?php

namespace zxf\Modules\Console\Commands;

class MakeModulePolicyCommand extends ModuleGeneratorCommand
{
    /**
     * 控制台命令的名称和签名。
     *
     * @var string
     */
    protected $signature = 'module:make:policy {module : 模块名称} {name : 策略类名称} {--force : 如果文件已存在，则强制覆盖}';

    /**
     * 控制台命令描述。
     *
     * @var string
     */
    protected $description = '为模块创建新的策略类';

    /**
     * 获取目标类路径。
     */
    protected function getDestinationPath(string $moduleName, string $name): string
    {
        return $this->getModulePath($moduleName) . '/Policies/' . $name . '.php';
    }

    /**
     * 获取存根文件路径。
     */
    protected function getStubFile(): string
    {
        return $this->getStubPath('policy.stub');
    }
}