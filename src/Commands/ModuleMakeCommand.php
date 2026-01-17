<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建新模块命令
 *
 * 使用 stubs 模板创建模块目录结构和文件
 */
class ModuleMakeCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:make
                            {name : 模块名称}
                            {--force : 覆盖已存在的模块}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '创建一个新的模块';

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $name = Str::studly($this->argument('name'));
        $force = $this->option('force');

        // 创建 Stub 生成器
        $stubGenerator = new StubGenerator($name);
        $modulePath = $stubGenerator->getModulePath();

        if (is_dir($modulePath) && ! $force) {
            $this->error("Module [{$name}] already exists.");
            $this->line("Use --force flag to overwrite the existing module.");

            return Command::FAILURE;
        }

        if (is_dir($modulePath) && $force) {
            $this->warn("Overwriting existing module [{$name}].");
            if (! $this->confirm("Are you sure you want to overwrite module [{$name}]? All files will be deleted.", true)) {
                $this->info('Operation cancelled.');

                return Command::SUCCESS;
            }

            // 删除现有模块目录
            File::deleteDirectory($modulePath);
        }

        $this->info("Creating module [{$name}]...");

        // 创建目录结构
        $this->createModuleStructure($stubGenerator);

        // 创建服务提供者
        $this->createServiceProvider($stubGenerator);

        // 创建配置文件
        $this->createConfigFile($stubGenerator);

        // 创建路由文件
        $this->createRouteFiles($stubGenerator);

        // 创建基础控制器
        $this->createBaseController($stubGenerator);

        // 创建示例控制器
        $this->createExampleControllers($stubGenerator);

        // 创建示例视图
        $this->createExampleView($stubGenerator);

        // 创建 README
        $this->createReadme($stubGenerator);

        $this->info("Module [{$name}] created successfully.");

        return Command::SUCCESS;
    }

    /**
     * 创建模块目录结构
     *
     * @param StubGenerator $generator
     * @return void
     */
    protected function createModuleStructure(StubGenerator $generator): void
    {
        $this->info("Creating module structure...");

        $directories = [
            'Config',
            'Routes',
            'Providers',
            'Console/Commands',
            'Http/Controllers/Web',
            'Http/Controllers/Api',
            'Http/Controllers/Admin',
            'Http/Middleware',
            'Http/Requests',
            'Http/Resources',
            'Database/Migrations',
            'Database/Seeders',
            'Models',
            'Resources/views',
            'Resources/views/layouts',
            'Resources/assets',
            'Resources/lang',
            'Events',
            'Listeners',
            'Observers',
            'Policies',
            'Repositories',
            'Tests',
        ];

        $generator->generateDirectories($directories);
    }

    /**
     * 创建服务提供者
     *
     * @param StubGenerator $generator
     * @return void
     */
    protected function createServiceProvider(StubGenerator $generator): void
    {
        $this->info("Creating service provider...");

        $moduleName = $generator->getModuleName();
        $lowerName = strtolower($moduleName);

        $generator->addReplacement('{{CLASS}}', $moduleName . 'ServiceProvider');
        $generator->addReplacement('{{LOWER_NAME}}', $lowerName);
        $generator->addReplacement('{{NAME}}', $moduleName);

        $generator->generate(
            'provider.stub',
            'Providers/' . $moduleName . 'ServiceProvider.php'
        );
    }

    /**
     * 创建配置文件
     *
     * @param StubGenerator $generator
     * @return void
     */
    protected function createConfigFile(StubGenerator $generator): void
    {
        $this->info("Creating config file...");

        $moduleName = $generator->getModuleName();
        $lowerName = strtolower($moduleName);

        $generator->addReplacement('{{NAME}}', $moduleName);
        $generator->addReplacement('{{LOWER_NAME}}', $lowerName);
        $generator->generate('config.stub', 'Config/' . $lowerName . '.php');
    }

    /**
     * 创建路由文件
     *
     * @param StubGenerator $generator
     * @return void
     */
    protected function createRouteFiles(StubGenerator $generator): void
    {
        $this->info("Creating route files...");

        $moduleName = $generator->getModuleName();
        $namespace = config('modules.namespace', 'Modules');
        $lowerName = strtolower($moduleName);

        // 获取 modules.php 配置
        $routeConfig = config('modules.routes', []);
        $shouldPrefix = $routeConfig['prefix'] ?? true;
        $shouldAddNamePrefix = $routeConfig['name_prefix'] ?? true;

        // 设置路由文件需要的变量
        $generator->addReplacement('{{NAMESPACE}}', $namespace);
        $generator->addReplacement('{{NAME}}', $moduleName);
        $generator->addReplacement('{{LOWER_NAME}}', $lowerName);

        // 根据配置生成路由前缀注释
        $files = [
            'web' => 'Routes/web.php',
            'api' => 'Routes/api.php',
            'admin' => 'Routes/admin.php',
        ];

        foreach ($files as $routeType => $destination) {
            $stubPath = __DIR__ . '/stubs/route/' . $routeType . '.stub';
            $fullPath = $generator->getModulePath() . '/' . $destination;

            // 获取 stub 内容并替换所有变量
            $stubContent = file_get_contents($stubPath);

            // 获取当前 replacements 的副本
            $replacements = $generator->getReplacements();

            // 动态添加当前路由类型的替换变量（不会影响 generator 的 replacements）
            $replacements['{{ROUTE_PREFIX_VALUE}}'] = $shouldPrefix ? $this->getRoutePrefix($routeType, $lowerName) : $lowerName;
            $replacements['{{ROUTE_NAME_PREFIX_VALUE}}'] = $shouldAddNamePrefix ? strtolower($routeType) . ".{$lowerName}." : '';

            foreach ($replacements as $search => $replace) {
                $stubContent = str_replace($search, $replace, $stubContent);
            }

            // 动态生成路由注释（根据配置）
            $stubContent = $this->generateRouteComments($stubContent, $routeType, $lowerName, $shouldPrefix, $shouldAddNamePrefix);

            // 确保目录存在
            $directory = dirname($fullPath);
            if (! is_dir($directory)) {
                File::makeDirectory($directory, 0755, true);
            }

            File::put($fullPath, $stubContent);
        }
    }

    /**
     * 生成路由注释
     *
     * @param string $stubContent
     * @param string $routeType
     * @param string $lowerName
     * @param bool $shouldPrefix
     * @param bool $shouldAddNamePrefix
     * @return string
     */
    protected function generateRouteComments(string $stubContent, string $routeType, string $lowerName, bool $shouldPrefix, bool $shouldAddNamePrefix): string
    {
        // 生成路由前缀注释
        if ($shouldPrefix) {
            $prefix = $this->getRoutePrefix($routeType, $lowerName);
            $stubContent = str_replace('{{ROUTE_PREFIX_COMMENT}}', "路由前缀: {$prefix}", $stubContent);
        } else {
            $stubContent = str_replace('{{ROUTE_PREFIX_COMMENT}}', "路由前缀: {$lowerName}（未自动添加类型前缀）", $stubContent);
        }

        // 生成路由名称前缀注释
        if ($shouldAddNamePrefix) {
            $namePrefix = strtolower($routeType) . ".{$lowerName}.";
            $stubContent = str_replace('{{ROUTE_NAME_PREFIX_COMMENT}}', "路由名称前缀: {$namePrefix}", $stubContent);
        } else {
            $stubContent = str_replace('{{ROUTE_NAME_PREFIX_COMMENT}}', '路由名称前缀: 未配置（不自动添加）', $stubContent);
        }

        return $stubContent;
    }

    /**
     * 获取路由前缀
     *
     * @param string $routeType
     * @param string $lowerName
     * @return string
     */
    protected function getRoutePrefix(string $routeType, string $lowerName): string
    {
        return match($routeType) {
            'api' => "api/{$lowerName}",
            'admin' => "{$lowerName}/admin",
            default => $lowerName,
        };
    }

    /**
     * 创建基础控制器
     *
     * @param StubGenerator $generator
     * @return void
     */
    protected function createBaseController(StubGenerator $generator): void
    {
        $this->info("Creating base controller...");

        $moduleName = $generator->getModuleName();
        $namespace = config('modules.namespace', 'Modules');

        // 基础控制器需要临时修改 stub 内容
        $stubPath = __DIR__ . '/stubs/controller.base.stub';
        $stubContent = file_get_contents($stubPath);

        // 添加所有必要的替换变量
        $generator->addReplacement('{{NAME}}', $moduleName);
        $generator->addReplacement('{{LOWER_NAME}}', strtolower($moduleName));

        // 遍历所有替换变量进行替换
        foreach ($generator->getReplacements() as $search => $replace) {
            $stubContent = str_replace($search, $replace, $stubContent);
        }

        // 创建基础控制器
        $controllerPath = $generator->getModulePath() . '/Http/Controllers/Controller.php';
        $directory = dirname($controllerPath);
        if (! is_dir($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($controllerPath, $stubContent);
    }

    /**
     * 创建示例控制器
     *
     * @param StubGenerator $generator
     * @return void
     */
    protected function createExampleControllers(StubGenerator $generator): void
    {
        $this->info("Creating example controllers...");

        $moduleName = $generator->getModuleName();
        $namespace = config('modules.namespace', 'Modules');

        // 创建 Web 控制器
        $generator->addReplacement('{{NAMESPACE}}', $namespace);
        $generator->addReplacement('{{CONTROLLER_SUBNAMESPACE}}', '\\Web');
        $generator->addReplacement('{{CLASS}}', $moduleName . 'Controller');
        $generator->generate('controller.stub', 'Http/Controllers/Web/' . $moduleName . 'Controller.php');

        // 创建 API 控制器
        $generator->addReplacement('{{NAMESPACE}}', $namespace);
        $generator->addReplacement('{{CONTROLLER_SUBNAMESPACE}}', '\\Api');
        $generator->addReplacement('{{CLASS}}', $moduleName . 'Controller');
        $generator->generate('controller.stub', 'Http/Controllers/Api/' . $moduleName . 'Controller.php');

        // 创建 Admin 控制器
        $generator->addReplacement('{{NAMESPACE}}', $namespace);
        $generator->addReplacement('{{CONTROLLER_SUBNAMESPACE}}', '\\Admin');
        $generator->addReplacement('{{CLASS}}', $moduleName . 'Controller');
        $generator->generate('controller.stub', 'Http/Controllers/Admin/' . $moduleName . 'Controller.php');
    }

    /**
     * 创建示例视图
     *
     * @param StubGenerator $generator
     * @return void
     */
    protected function createExampleView(StubGenerator $generator): void
    {
        $this->info("Creating example view...");

        $generator->generate('view.stub', 'Resources/views/welcome.blade.php');
    }

    /**
     * 创建 README 文件
     *
     * @param StubGenerator $generator
     * @return void
     */
    protected function createReadme(StubGenerator $generator): void
    {
        $this->info("Creating README...");

        $generator->generate('readme.stub', 'README.md');
    }
}
