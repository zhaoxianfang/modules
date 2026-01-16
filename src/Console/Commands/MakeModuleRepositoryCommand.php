<?php

namespace zxf\Modules\Console\Commands;

use Illuminate\Support\Str;

class MakeModuleRepositoryCommand extends ModuleGeneratorCommand
{
    /**
     * 控制台命令的名称和签名。
     *
     * @var string
     */
    protected $signature = 'module:make:repository {module : 模块名称} {name : 仓库名称} {--force : 覆盖已存在的文件}';

    /**
     * 控制台命令描述。
     *
     * @var string
     */
    protected $description = '为模块创建新的仓库';

    /**
     * 获取目标类路径。
     */
    protected function getDestinationPath(string $moduleName, string $name): string
    {
        $repositoryName = Str::studly($name);
        $repositoryPath = $this->getModulePath($moduleName) . '/Repositories/' . $repositoryName . '.php';

        return $repositoryPath;
    }

    /**
     * 获取存根文件路径。
     */
    protected function getStubFile(): string
    {
        return $this->getStubPath('repository.stub');
    }

    /**
     * 获取存根文件的替换变量。
     */
    protected function getReplacements(string $moduleName, string $name): array
    {
        $replacements = parent::getReplacements($moduleName, $name);
        // 为仓库存根添加任何额外的替换
        return $replacements;
    }
}