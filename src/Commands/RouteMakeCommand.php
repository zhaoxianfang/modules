<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建路由文件命令
 * 在指定模块中创建路由文件
 */
class RouteMakeCommand extends AbstractMakeCommand
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:make-route
                            {module : 模块名称}
                            {name : 路由文件名称}
                            {--type=web : 路由类型（可自定义，如web、api、admin、mobile等）}
                            {--force : 覆盖已存在的路由文件}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '在指定模块中创建一个路由文件';

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
        $routeName = strtolower($this->argument('name'));
        $type = $this->option('type');
        $force = $this->option('force');

        // 路由名会被 strtolower 后拼进 'Routes/{name}.php'，
        // 因此必须做实体名校验，防 "../../config/app" 造成路径穿越。
        if (! $this->isValidEntityName($routeName)) {
            $this->error("路由名称格式不合法: [{$this->esc($routeName)}]");
            $this->line('提示：只允许字母、数字、下划线与中划线');

            return Command::FAILURE;
        }

        // 类型不再限制，允许任意自定义类型

        $routePath = $this->targetPath('Routes/'.$routeName.'.php');

        if (File::exists($routePath) && ! $force) {
            $this->error("模块 [{$this->esc($moduleName)}] 中已存在路由文件 [{$this->esc($routeName)}]");
            $this->line('提示：使用 --force 选项覆盖已存在的文件');

            return Command::FAILURE;
        }

        if (File::exists($routePath) && $force) {
            $this->warn("正在覆盖已存在的路由文件 [{$this->esc($routeName)}]");
        }

        // 使用不同的 stub 模板
        $stubFile = "route/{$type}.stub";

        // 安全校验模板类型
        //
        // 必须直接校验 $type 而非拼好的 $stubFile：
        // StubGenerator::getStubPath() 不做 '..' 校验
        // （它只保证**目标文件**不越界，不保证 **stub 读取路径**不越界），
        // 若 $type 允许 '/'，--type=../../foo 就能读到模块目录之外的 .stub 文件。
        // 故只允许字母、数字、下划线与中划线，不允许斜杠、点号与空值。
        if (! is_string($type) || preg_match('/^[a-zA-Z0-9_-]+$/', $type) !== 1) {
            $this->error("路由类型格式不合法: [{$this->esc($type)}]");
            $this->line('提示：只允许字母、数字、下划线与中划线（内置类型：web / api / admin）');

            return Command::FAILURE;
        }

        // 检查是否有对应的 stub
        // 走 StubGenerator 以支持项目自定义 stub（自定义 → 包内 的逐级回退）
        $stubPath = (new StubGenerator($moduleName))->getStubPath($stubFile);
        if (! file_exists($stubPath)) {
            // 使用通用模板
            $content = $this->getGenericRouteContent($moduleName, $routeName, $type);
        } else {
            // 安全读取 stub 文件内容
            try {
                $content = File::get($stubPath);
                // 应用变量替换
                $stubGenerator = new StubGenerator($moduleName);
                $content = $this->applyReplacements($content, $stubGenerator->getReplacements());
            } catch (\Throwable $e) {
                $this->error('读取 stub 文件失败: '.$e->getMessage());
                // 回退到通用模板
                $content = $this->getGenericRouteContent($moduleName, $routeName, $type);
            }
        }

        // 确保路由目录存在
        $routeDir = dirname($routePath);
        if (! is_dir($routeDir)) {
            File::makeDirectory($routeDir, 0755, true);
        }

        $result = File::put($routePath, $content);

        if ($result) {
            $this->info("成功在模块 [{$this->esc($moduleName)}] 中创建路由文件 [{$this->esc($routeName)}]");

            return Command::SUCCESS;
        }

        $this->error("创建路由文件 [{$this->esc($routeName)}] 失败");

        return Command::FAILURE;
    }

    /**
     * 获取通用路由文件内容
     */
    protected function getGenericRouteContent(string $moduleName, string $routeName, string $type): string
    {
        $lowerName = strtolower($moduleName);

        return "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n/*\n|--------------------------------------------------------------------------\n| {$routeName} Routes ({$type})\n|--------------------------------------------------------------------------\n|\n| 在这里注册 {$moduleName} 模块的 {$routeName} 路由\n| 路由前缀: {$lowerName}\n| 路由名称前缀: {$lowerName}.\n|\n*/\n\nRoute::prefix('{$lowerName}')\n    ->name('{$lowerName}.')\n    ->group(function () {\n        // 在这里定义路由\n        Route::get('/', function () {\n            return response()->json([\n                'message' => 'Welcome to {$moduleName} {$routeName} routes',\n            ]);\n        })->name('{$routeName}');\n    });\n";
    }

    /**
     * 应用变量替换
     */
    protected function applyReplacements(string $content, array $replacements): string
    {
        foreach ($replacements as $search => $replace) {
            $content = str_replace($search, $replace, $content);
        }

        return $content;
    }
}
