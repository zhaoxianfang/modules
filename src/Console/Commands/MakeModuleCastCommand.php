<?php

namespace zxf\Modules\Console\Commands;

use Illuminate\Support\Str;

class MakeModuleCastCommand extends ModuleGeneratorCommand
{
    /**
     * 控制台命令的名称和签名。
     *
     * @var string
     */
    protected $signature = 'module:make:cast {module : 模块名称} {name : 类型转换名称} {--force : 覆盖现有文件}';

    /**
     * 控制台命令的描述。
     *
     * @var string
     */
    protected $description = '为模块创建新的类型转换';

    /**
     * 获取目标类路径。
     */
    protected function getDestinationPath(string $moduleName, string $name): string
    {
        $castName = Str::studly($name);
        $castPath = $this->getModulePath($moduleName) . '/Casts/' . $castName . '.php';

        return $castPath;
    }

    /**
     * 获取存根文件路径。
     */
    protected function getStubFile(): string
    {
        return $this->getStubPath('cast.stub');
    }

    /**
     * 获取存根文件的替换变量。
     */
    protected function getReplacements(string $moduleName, string $name): array
    {
        $replacements = parent::getReplacements($moduleName, $name);
        // 为类型转换存根添加任何额外的替换变量
        return $replacements;
    }
}