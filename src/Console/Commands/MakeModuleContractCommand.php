<?php

namespace zxf\Modules\Console\Commands;

use Illuminate\Support\Str;

class MakeModuleContractCommand extends ModuleGeneratorCommand
{
    /**
     * 控制台命令的名称和签名。
     *
     * @var string
     */
    protected $signature = 'module:make:contract {module : 模块名称} {name : 契约名称} {--force : 覆盖现有文件}';

    /**
     * 控制台命令的描述。
     *
     * @var string
     */
    protected $description = '为模块创建新的契约';

    /**
     * 获取目标类路径。
     */
    protected function getDestinationPath(string $moduleName, string $name): string
    {
        $contractName = Str::studly($name);
        $contractPath = $this->getModulePath($moduleName) . '/Contracts/' . $contractName . '.php';

        return $contractPath;
    }

    /**
     * 获取存根文件路径。
     */
    protected function getStubFile(): string
    {
        return $this->getStubPath('contract.stub');
    }

    /**
     * 获取存根文件的替换变量。
     */
    protected function getReplacements(string $moduleName, string $name): array
    {
        $replacements = parent::getReplacements($moduleName, $name);
        // 为契约存根添加任何额外的替换变量
        return $replacements;
    }
}