<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建路由文件命令
 *
 * 在指定模块中创建路由文件
 */
class RouteMakeCommand extends Command
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
     *
     * @return int
     */
    public function handle(): int
    {
        $moduleName = Str::studly($this->argument('module'));
        $routeName = strtolower($this->argument('name'));
        $type = $this->option('type');
        $force = $this->option('force');

        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在");

            return Command::FAILURE;
        }

        // 类型不再限制，允许任意自定义类型

        $routePath = $module->getRoutesPath() . DIRECTORY_SEPARATOR . $routeName . '.php';

        if (File::exists($routePath) && ! $force) {
            $this->error("模块 [{$moduleName}] 中已存在路由文件 [{$routeName}]");
            $this->line("提示：使用 --force 选项覆盖已存在的文件");

            return Command::FAILURE;
        }

        if (File::exists($routePath) && $force) {
            $this->warn("正在覆盖已存在的路由文件 [{$routeName}]");
        }

        $namespace = config('modules.namespace', 'Modules');

        // 使用不同的 stub 模板
        $stubFile = "route/{$type}.stub";

        $stubGenerator = new StubGenerator($moduleName);

        // 检查是否有对应的 stub
        $stubPath = __DIR__ . '/stubs/' . $stubFile;
        if (! file_exists($stubPath)) {
            // 使用通用模板
            $content = $this->getGenericRouteContent($moduleName, $routeName, $type);
        } else {
            $content = $stubGenerator->getStubContent($stubPath);
        }

        // 确保路由目录存在
        $routeDir = $module->getRoutesPath();
        if (! is_dir($routeDir)) {
            File::makeDirectory($routeDir, 0755, true);
        }

        $result = File::put($routePath, $content);

        if ($result) {
            $this->info("成功在模块 [{$moduleName}] 中创建路由文件 [{$routeName}]");

            return Command::SUCCESS;
        }

        $this->error("创建路由文件 [{$routeName}] 失败");

        return Command::FAILURE;
    }

    /**
     * 获取通用路由文件内容
     *
     * @param string $moduleName
     * @param string $routeName
     * @param string $type
     * @return string
     */
    protected function getGenericRouteContent(string $moduleName, string $routeName, string $type): string
    {
        $lowerName = strtolower($moduleName);

        return "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n/*\n|--------------------------------------------------------------------------\n| {$routeName} Routes ({$type})\n|--------------------------------------------------------------------------\n|\n| 在这里注册 {$moduleName} 模块的 {$routeName} 路由\n| 路由前缀: {$lowerName}\n| 路由名称前缀: {$lowerName}.\n|\n*/\n\nRoute::prefix('{$lowerName}')\n    ->name('{$lowerName}.')\n    ->group(function () {\n        // 在这里定义路由\n        Route::get('/', function () {\n            return response()->json([\n                'message' => 'Welcome to {$moduleName} {$routeName} routes',\n            ]);\n        })->name('{$routeName}');\n    });\n";
    }
}
