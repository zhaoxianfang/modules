<?php

namespace zxf\Modules\Console\Commands;

class MakeModuleMiddlewareCommand extends ModuleGeneratorCommand
{
    /**
     * 控制台命令的名称和签名。
     *
     * @var string
     */
    protected $signature = 'module:make:middleware {module : 模块名称} {name : 中间件名称} {--force : 如果文件已存在，则强制覆盖}';

    /**
     * 控制台命令描述。
     *
     * @var string
     */
    protected $description = '为模块创建新的中间件类';

    /**
     * 获取目标类路径。
     */
    protected function getDestinationPath(string $moduleName, string $name): string
    {
        return $this->getModulePath($moduleName) . '/Http/Middleware/' . $name . '.php';
    }

    /**
     * 获取存根文件路径。
     */
    protected function getStubFile(): string
    {
        return $this->getStubPath('middleware.stub');
    }
}