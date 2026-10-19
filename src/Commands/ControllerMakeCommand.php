<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * 创建控制器命令
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

        // --type 允许任意「单段」自定义类型（作为子命名空间目录），
        // 但必须是一段合法的 PHP 标识符：否则 --type=a/b 会生成
        // namespace ...\A\B 与路径 Http/Controllers/A/B 两种不一致的形态，
        // 产生非法类名（PSR-4 永久无法加载）或写到意料之外的目录。
        if ($type !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $type) !== 1) {
            $this->error("控制器类型 [{$this->esc((string) $this->option('type'))}] 不合法");
            $this->line('提示：--type 只能是单个标识符（如 Web / Api / Admin），不能包含 / \\ . - 等字符');

            return Command::FAILURE;
        }

        $subDir = $type !== 'Web' ? $type.'/' : '';
        $relativePath = 'Http/Controllers/'.$subDir.$controllerName.'.php';

        // 统一取自生成器路径配置，避免与 module:make 各自硬编码而生成到不同目录
        $configuredBase = rtrim((string) config('modules.paths.generator.controller.api.path', 'Http/Controllers/Api'), '/\\');

        if ($type !== 'Web' && $configuredBase !== '' && $configuredBase !== 'Http/Controllers/Api') {
            $relativePath = $configuredBase.'/'.$controllerName.'.php';
        }

        $generator = $this->makeStubGenerator();
        $generator->addReplacement('{{CLASS}}', $controllerName);
        $generator->addReplacement('{{NAMESPACE}}', $this->module->getNamespace());
        $generator->addReplacement('{{CONTROLLER_SUBNAMESPACE}}', $type !== 'Web' ? '\\'.$type : '');
        $generator->addReplacement('{{BASE_CLASS}}', $module->getName().'BaseController');

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
