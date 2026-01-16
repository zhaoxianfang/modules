<?php

namespace zxf\Modules\Console\Commands;

class MakeModuleFactoryCommand extends ModuleGeneratorCommand
{
    /**
     * 控制台命令的名称和签名。
     *
     * @var string
     */
    protected $signature = 'module:make:factory {module : 模块名称} {name : 工厂名称} {--force : 如果文件存在则覆盖}';

    /**
     * 控制台命令的描述。
     *
     * @var string
     */
    protected $description = '为模块创建新的工厂类';

    /**
     * 获取目标类路径。
     */
    protected function getDestinationPath(string $moduleName, string $name): string
    {
        return $this->getModulePath($moduleName) . '/Database/Factories/' . $name . '.php';
    }

    /**
     * 获取存根文件路径。
     */
    protected function getStubFile(): string
    {
        return $this->getStubPath('factory.stub');
    }
}