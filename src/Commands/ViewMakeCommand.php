<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * 创建视图命令
 *
 * 在指定模块中创建 Blade 视图文件。
 * 支持创建普通视图和布局视图。
 *
 * 使用示例：
 *   php artisan module:make-view Blog posts.index
 *   php artisan module:make-view Blog layouts.sidebar
 *
 * @package zxf\Modules\Commands
 */
class ViewMakeCommand extends AbstractMakeCommand
{
    /**
     * @var string
     */
    protected $signature = 'module:make-view
                            {module : 模块名称（必需，例如：Blog）}
                            {name : 视图名称（必需，支持点号分隔，例如：posts.index）}
                            {--type= : 视图模板类型（index=列表页 / show=详情页），不指定则生成空白视图}
                            {--force : 覆盖已存在的文件}';

    /**
     * @var string
     */
    protected $description = '在指定模块中创建 Blade 视图文件';

    /**
     * 执行命令
     */
    public function handle(): int
    {
        $module = $this->resolveModule();
        if (! $module) {
            return Command::FAILURE;
        }

        $moduleName = $module->getName();
        $viewName = $this->argument('name');
        $force = $this->option('force');

        // 指定 --type 时改用预置模板（列表页 / 详情页）
        $type = $this->option('type');
        if ($type !== null && $type !== '') {
            return $this->generateFromTemplate((string) $type, $viewName, $force);
        }

        // 将点号转换为路径分隔符
        $viewPath = 'Resources/views/' . str_replace('.', '/', $viewName) . '.blade.php';
        $fullPath = $this->targetPath($viewPath);

        if (File::exists($fullPath) && ! $force) {
            $this->error("模块 [{$moduleName}] 中已存在视图 [{$viewName}]");
            $this->line('提示：使用 --force 选项覆盖已存在的视图');
            return Command::FAILURE;
        }

        if (File::exists($fullPath) && $force) {
            $this->warn("正在覆盖模块 [{$moduleName}] 中已存在的视图 [{$viewName}]");
        }

        // 确保目录存在
        $viewDir = dirname($fullPath);
        if (! is_dir($viewDir)) {
            File::makeDirectory($viewDir, 0755, true);
        }

        // 生成视图内容
        $viewNamespace = config('modules.views.namespace_format', 'lower') === 'lower'
            ? strtolower($moduleName)
            : $moduleName;

        $content = $this->generateViewContent($moduleName, $viewName);

        if (! File::put($fullPath, $content)) {
            $this->error("创建视图 [{$viewName}] 失败");
            return Command::FAILURE;
        }

        $this->info("✓ 成功在模块 [{$moduleName}] 中创建视图 [{$viewName}]");
        $this->line("引用方式: @include('{$viewNamespace}::{$viewName}')");

        return Command::SUCCESS;
    }

    /**
     * 使用预置模板生成视图
     *
     * @param string $type     模板类型：index（列表页）或 show（详情页）
     * @param string $viewName 视图名称（点号分隔）
     * @param bool   $force    是否覆盖已存在文件
     * @return int 退出码
     */
    protected function generateFromTemplate(string $type, string $viewName, bool $force): int
    {
        $stubMap = [
            'index' => 'view.index.stub',
            'show' => 'view.show.stub',
        ];

        if (! isset($stubMap[$type])) {
            $this->error("不支持的视图类型 [{$type}]，可选值: " . implode(' / ', array_keys($stubMap)));

            return Command::FAILURE;
        }

        $relativePath = 'Resources/views/' . str_replace('.', '/', $viewName) . '.blade.php';

        return $this->writeStub(
            $this->makeStubGenerator(),
            $stubMap[$type],
            $relativePath,
            '视图',
            $force
        );
    }

    /**
     * 生成 Blade 视图内容
     *
     * 生成带布局扩展的标准视图文件，开发者可按需修改。
     *
     * @param string $moduleName 模块名称
     * @param string $viewName   视图名称（点号分隔）
     * @return string Blade 视图文件内容
     */
    protected function generateViewContent(string $moduleName, string $viewName): string
    {
        $parts = explode('.', $viewName);
        $lastPart = end($parts);
        $titleParts = array_map(function (string $p): string {
            return Str::title($p);
        }, $parts);
        $title = implode(' - ', $titleParts);

        $bladeCommentStart = '{' . '{--';
        $bladeCommentEnd = '--}' . '}';
        $extendsLine = '@extends(' . "'layouts.app'" . ')';
        $sectionTitle = "@section('title', '{$title}')";
        $sectionContent = "@section('content')";

        return <<<BLADE
{$bladeCommentStart}
  {$moduleName} 模块 - {$lastPart} 视图
  视图名称: {$moduleName}::{$viewName}
{$bladeCommentEnd}

{$extendsLine}

{$sectionTitle}

{$sectionContent}
<div class="container">
    <h1>{$title}</h1>
    <p>模块视图已创建成功。</p>
</div>
@endsection

BLADE;
    }
}
