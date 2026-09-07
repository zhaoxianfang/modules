<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建数据仓库命令
 *
 * 在指定模块中创建数据仓库类。
 * 数据仓库封装数据访问逻辑，解耦 Controller 和 Model 之间的直接依赖，
 * 便于单元测试和实现数据源切换。
 *
 * 使用示例：
 *   php artisan module:make-repository Blog PostRepository
 *
 * @package zxf\Modules\Commands
 */
class RepositoryMakeCommand extends AbstractMakeCommand
{
    /**
     * @var string
     */
    protected $signature = 'module:make-repository
                            {module : 模块名称（必需，例如：Blog）}
                            {name : 仓库名称（必需，例如：PostRepository）}
                            {--force : 覆盖已存在的文件}';

    /**
     * @var string
     */
    protected $description = '在指定模块中创建数据仓库类';

    /**
     * 执行命令
     */
    public function handle(): int
    {
        $module = $this->resolveModule();
        if (! $module) {
            return Command::FAILURE;
        }

        $repoName = Str::studly($this->argument('name'));
        $force = $this->option('force');

        $generator = $this->makeStubGenerator();
        $generator->addReplacements([
            '{{CLASS}}' => $repoName,
            '{{NAMESPACE}}' => $this->module->getNamespace(),
            '{{NAME}}' => $module->getName(),
        ]);

        return $this->writeStub($generator, 'repository.stub', 'Repositories/' . $repoName . '.php', '仓库', $force);
    }
}
