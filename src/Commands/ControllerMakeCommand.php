<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建控制器命令
 *
 * 在指定模块中创建控制器
 */
class ControllerMakeCommand extends AbstractMakeCommand
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:make-controller
                            {module : 模块名称}
                            {name : 控制器名称}
                            {--type=web : 控制器类型（可自定义，如web、api、admin、mobile等）}
                            {--force : 覆盖已存在的控制器}
                            {--plain : 创建空控制器（无CRUD方法）}
                            {--attributes : 使用 Laravel 13 属性路由/中间件风格（#[Get]/#[Post]/#[Middleware]）生成控制器}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '在指定模块中创建一个控制器';

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $module = $this->resolveModule();
        if (! $module) {
            return Command::FAILURE;
        }

        $controllerName = Str::studly($this->argument('name'));
        $type = Str::studly($this->option('type'));
        $force = $this->option('force');
        $plain = $this->option('plain');
        $useAttributes = $this->option('attributes');

        // 类型不再限制，允许任意自定义类型（作为子命名空间目录）
        $subDir = $type !== 'Web' ? $type . '/' : '';
        $relativePath = 'Http/Controllers/' . $subDir . $controllerName . '.php';

        $generator = $this->makeStubGenerator();
        $generator->addReplacement('{{CLASS}}', $controllerName);
        $generator->addReplacement('{{NAMESPACE}}', $this->module->getNamespace());
        $generator->addReplacement('{{CONTROLLER_SUBNAMESPACE}}', $type !== 'Web' ? '\\' . $type : '');
        $generator->addReplacement('{{BASE_CLASS}}', $module->getName() . 'BaseController');

        // 选择 stub 文件
        if ($plain) {
            $stubFile = 'controller.plain.stub';
        } elseif ($useAttributes) {
            $stubFile = 'controller.attributes.stub';
        } else {
            $stubFile = 'controller.stub';
        }

        return $this->writeStub($generator, $stubFile, $relativePath, '控制器', $force);
    }
}
