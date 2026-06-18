<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;
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
class RepositoryMakeCommand extends Command
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
        $moduleName = Str::studly($this->argument('module'));
        $repoName = Str::studly($this->argument('name'));
        $force = $this->option('force');

        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在");
            return Command::FAILURE;
        }

        $repoPath = $module->getPath('Repositories/' . $repoName . '.php');

        if (File::exists($repoPath) && ! $force) {
            $this->error("模块 [{$moduleName}] 中已存在仓库 [{$repoName}]");
            $this->line('提示：使用 --force 选项覆盖已存在的仓库');
            return Command::FAILURE;
        }

        if (File::exists($repoPath) && $force) {
            $this->warn("正在覆盖模块 [{$moduleName}] 中已存在的仓库 [{$repoName}]");
        }

        $namespace = config('modules.namespace', 'Modules');

        $stubGenerator = new StubGenerator($moduleName);
        $stubGenerator->addReplacements([
            '{{CLASS}}' => $repoName,
            '{{NAMESPACE}}' => $namespace,
            '{{NAME}}' => $moduleName,
        ]);

        // 确保目录存在
        $repoDir = $module->getPath('Repositories');
        if (! is_dir($repoDir)) {
            File::makeDirectory($repoDir, 0755, true);
        }

        $result = $stubGenerator->generate(
            'repository.stub',
            'Repositories/' . $repoName . '.php',
            $force
        );

        if ($result) {
            $this->info("✓ 成功在模块 [{$moduleName}] 中创建仓库 [{$repoName}]");
            return Command::SUCCESS;
        }

        $this->error("创建仓库 [{$repoName}] 失败");
        return Command::FAILURE;
    }
}
