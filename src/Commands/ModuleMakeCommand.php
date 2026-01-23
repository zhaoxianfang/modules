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
 * 支持所有 28 个 stub 文件的自动生成
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
     * Stub 映射配置
     *
     * 定义所有 stub 文件与目标文件的映射关系
     *
     * @var array<string, array{stub: string, destination: string, replacements?: array<string, mixed>}>
     */
    protected array $stubMapping = [];

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        try {
            $name = Str::studly($this->argument('name'));
            $force = $this->option('force');

            // 检查多模块发布并发布用户指南
            $this->publishModulesUserGuide();

            // 创建 Stub 生成器
            $stubGenerator = new StubGenerator($name);
            $modulePath = $stubGenerator->getModulePath();

            if (is_dir($modulePath) && ! $force) {
                $this->error("模块 [{$name}] 已存在");
                $this->line("提示：使用 --force 选项覆盖已存在的模块");

                return Command::FAILURE;
            }

            if (is_dir($modulePath) && $force) {
                $this->warn("正在覆盖已存在的模块 [{$name}]");
                if (! $this->confirm("Are you sure you want to overwrite module [{$name}]? All files will be deleted.", true)) {
                    $this->info('Operation cancelled.');

                    return Command::SUCCESS;
                }

                // 删除现有模块目录
                File::deleteDirectory($modulePath);
            }

            $this->info("Creating module [{$name}]...");

            // 初始化 stub 映射
            $this->initializeStubMapping($stubGenerator);

            // 创建目录结构
            $this->createModuleStructure($stubGenerator);

            // 根据 stub 映射生成所有文件
            $this->generateFilesFromStubMapping($stubGenerator);

            $this->info("模块 [{$name}] 创建成功");
            $this->line("用户指南位置: " . config('modules.path', base_path('Modules')) . '/ModulesUserGuide.md');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("创建模块时发生错误: {$e->getMessage()}");
            $this->line("错误位置: {$e->getFile()}:{$e->getLine()}");
            if (app()->environment('local')) {
                $this->line("堆栈跟踪:\n" . $e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    /**
     * 发布多模块用户指南
     *
     * 检查多模块路径下是否已存在 ModulesUserGuide.md，如果不存在则发布
     *
     * @return void
     */
    protected function publishModulesUserGuide(): void
    {
        $modulesPath = config('modules.path', base_path('Modules'));
        $guidePath = $modulesPath . '/ModulesUserGuide.md';

        // 检查是否已存在用户指南
        if (file_exists($guidePath)) {
            $this->line("多模块用户指南已存在: {$guidePath}");
            return;
        }

        // 确保模块目录存在
        if (! is_dir($modulesPath)) {
            File::makeDirectory($modulesPath, 0755, true);
        }

        // 读取 stub 文件内容
        $stubPath = __DIR__ . '/stubs/modules-user-guide.stub';
        if (! file_exists($stubPath)) {
            $this->warn("用户指南模板文件不存在: {$stubPath}");
            return;
        }

        $content = file_get_contents($stubPath);

        // 写入用户指南
        File::put($guidePath, $content);
        $this->info("多模块用户指南已发布: {$guidePath}");
    }

    /**
     * 初始化 Stub 映射配置
     *
     * 定义所有 stub 文件到目标文件的映射关系
     * 确保所有 28 个 stub 文件都能正确生成
     *
     * @param StubGenerator $generator
     * @return void
     */
    protected function initializeStubMapping(StubGenerator $generator): void
    {
        $moduleName = $generator->getModuleName();
        $namespace = $generator->getNamespace();
        $lowerName = strtolower($moduleName);

        // 从配置中获取 generator 配置
        $generatorConfig = config('modules.paths.generator', []);

        // === 服务提供者 ===
        $generatorConfig['provider']['generate'] && $this->stubMapping[] = [
            'stub' => 'provider.stub',
            'destination' => $generatorConfig['provider']['path'] . '/' . $moduleName . 'ServiceProvider.php',
            'replacements' => [
                '{{CLASS}}' => $moduleName . 'ServiceProvider',
                '{{LOWER_NAME}}' => $lowerName,
                '{{NAME}}' => $moduleName,
            ],
        ];

        // === 配置文件 ===
        $generatorConfig['config']['generate'] && $this->stubMapping[] = [
            'stub' => 'config.stub',
            'destination' => $generatorConfig['config']['path'] . '/' . $lowerName . '.php',
            'replacements' => [
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
            ],
        ];

        // === 路由文件 ===
        $generatorConfig['route']['generate'] && $this->stubMapping[] = [
            'stub' => 'route/web.stub',
            'destination' => $generatorConfig['route']['path'] . '/web.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
                '{{ROUTE_PREFIX_VALUE}}' => $lowerName,
                '{{ROUTE_NAME_PREFIX_VALUE}}' => "web.{$lowerName}.",
            ],
        ];

        $generatorConfig['route']['generate'] && $this->stubMapping[] = [
            'stub' => 'route/api.stub',
            'destination' => $generatorConfig['route']['path'] . '/api.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
                '{{ROUTE_PREFIX_VALUE}}' => "api/{$lowerName}",
                '{{ROUTE_NAME_PREFIX_VALUE}}' => "api.{$lowerName}.",
            ],
        ];

        $generatorConfig['route']['generate'] && in_array('admin', config('modules.routes.default_files', ['web', 'api'])) && $this->stubMapping[] = [
            'stub' => 'route/admin.stub',
            'destination' => $generatorConfig['route']['path'] . '/admin.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
                '{{ROUTE_PREFIX_VALUE}}' => "admin/{$lowerName}",
                '{{ROUTE_NAME_PREFIX_VALUE}}' => "admin.{$lowerName}.",
            ],
        ];

        // === 基础控制器 ===
        $generatorConfig['controller']['generate'] && $this->stubMapping[] = [
            'stub' => 'controller.base.stub',
            'destination' => $generatorConfig['controller']['path'] . '/Controller.php',
            'replacements' => [
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
            ],
        ];

        // === Web 控制器 ===
        $generatorConfig['controller.web']['generate'] && $this->stubMapping[] = [
            'stub' => 'controller.stub',
            'destination' => $generatorConfig['controller.web']['path'] . '/' . $moduleName . 'Controller.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{CONTROLLER_SUBNAMESPACE}}' => '\\Web',
                '{{CLASS}}' => $moduleName . 'Controller',
            ],
        ];

        // === Api 控制器 ===
        $generatorConfig['controller.api']['generate'] && $this->stubMapping[] = [
            'stub' => 'controller.stub',
            'destination' => $generatorConfig['controller.api']['path'] . '/' . $moduleName . 'Controller.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{CONTROLLER_SUBNAMESPACE}}' => '\\Api',
                '{{CLASS}}' => $moduleName . 'Controller',
            ],
        ];

        // === Admin 控制器 ===
        $generatorConfig['controller.admin']['generate'] && $this->stubMapping[] = [
            'stub' => 'controller.stub',
            'destination' => $generatorConfig['controller.admin']['path'] . '/' . $moduleName . 'Controller.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{CONTROLLER_SUBNAMESPACE}}' => '\\Admin',
                '{{CLASS}}' => $moduleName . 'Controller',
            ],
        ];

        // === 模型 ===
        $generatorConfig['model']['generate'] && $this->stubMapping[] = [
            'stub' => 'model.stub',
            'destination' => $generatorConfig['model']['path'] . '/' . $moduleName . '.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
            ],
        ];

        // === 观察者 ===
        $generatorConfig['observer']['generate'] && $this->stubMapping[] = [
            'stub' => 'observer.stub',
            'destination' => $generatorConfig['observer']['path'] . '/' . $moduleName . 'Observer.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{CLASS}}' => $moduleName . 'Observer',
            ],
        ];

        // === 策略 ===
        $generatorConfig['policy']['generate'] && $this->stubMapping[] = [
            'stub' => 'policy.stub',
            'destination' => $generatorConfig['policy']['path'] . '/' . $moduleName . 'Policy.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{CLASS}}' => $moduleName . 'Policy',
            ],
        ];

        // === 仓库 ===
        $generatorConfig['repository']['generate'] && $this->stubMapping[] = [
            'stub' => 'repository.stub',
            'destination' => $generatorConfig['repository']['path'] . '/' . $moduleName . 'Repository.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{CLASS}}' => $moduleName . 'Repository',
            ],
        ];

        // === 请求验证 ===
        $generatorConfig['request']['generate'] && $this->stubMapping[] = [
            'stub' => 'request.stub',
            'destination' => $generatorConfig['request']['path'] . '/' . $moduleName . 'Request.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{CLASS}}' => $moduleName . 'Request',
            ],
        ];

        // === 资源类 ===
        $generatorConfig['resource']['generate'] && $this->stubMapping[] = [
            'stub' => 'resource.stub',
            'destination' => $generatorConfig['resource']['path'] . '/' . $moduleName . 'Resource.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{CLASS}}' => $moduleName . 'Resource',
            ],
        ];

        // === 中间件 ===
        $generatorConfig['middleware']['generate'] && $this->stubMapping[] = [
            'stub' => 'middleware.stub',
            'destination' => $generatorConfig['middleware']['path'] . '/' . $moduleName . 'Middleware.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{CLASS}}' => $moduleName . 'Middleware',
            ],
        ];

        // === 命令 ===
        $generatorConfig['command']['generate'] && $this->stubMapping[] = [
            'stub' => 'command.stub',
            'destination' => $generatorConfig['command']['path'] . '/' . $moduleName . 'Command.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{CLASS}}' => $moduleName . 'Command',
                '{{LOWER_NAME}}' => $lowerName,
                '{{SIGNATURE}}' => "{$lowerName}:command",
                '{{DESCRIPTION}}' => "模块 {$moduleName} 的示例命令",
            ],
        ];

        // === 事件 ===
        $generatorConfig['event']['generate'] && $this->stubMapping[] = [
            'stub' => 'event.stub',
            'destination' => $generatorConfig['event']['path'] . '/' . $moduleName . 'Event.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{CLASS}}' => $moduleName . 'Event',
            ],
        ];

        // === 监听器 ===
        $generatorConfig['listener']['generate'] && $this->stubMapping[] = [
            'stub' => 'listener.stub',
            'destination' => $generatorConfig['listener']['path'] . '/' . $moduleName . 'Listener.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{CLASS}}' => $moduleName . 'Listener',
                '{{EVENT_NAMESPACE}}' => $namespace . '\\' . $moduleName . '\\Events',
                '{{EVENT}}' => $moduleName . 'Event',
            ],
        ];

        // === 数据填充器 ===
        $generatorConfig['seeder']['generate'] && $this->stubMapping[] = [
            'stub' => 'seeder.stub',
            'destination' => $generatorConfig['seeder']['path'] . '/' . $moduleName . 'Seeder.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{CLASS}}' => $moduleName . 'Seeder',
            ],
        ];

        // === 示例迁移 ===
        $generatorConfig['migration']['generate'] && $this->stubMapping[] = [
            'stub' => 'migration/create.stub',
            'destination' => $generatorConfig['migration']['path'] . '/' . date('Y_m_d_His').'_create_' . $lowerName . '_table.php',
            'replacements' => [
                '{{TABLE}}' => $lowerName,
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
            ],
        ];

        // === 视图文件 ===
        $generatorConfig['views']['generate'] && $this->stubMapping[] = [
            'stub' => 'view.stub',
            'destination' => $generatorConfig['views']['path'] . '/welcome.blade.php',
            'replacements' => [
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
            ],
        ];

        $generatorConfig['views']['generate'] && $this->stubMapping[] = [
            'stub' => 'view.index.stub',
            'destination' => $generatorConfig['views']['path'] . '/index.blade.php',
            'replacements' => [
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
            ],
        ];

        $generatorConfig['views']['generate'] && $this->stubMapping[] = [
            'stub' => 'view.show.stub',
            'destination' => $generatorConfig['views']['path'] . '/show.blade.php',
            'replacements' => [
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
            ],
        ];

        // === 布局文件 ===
        $generatorConfig['views']['generate'] && $this->stubMapping[] = [
            'stub' => 'layout.app.stub',
            'destination' => $generatorConfig['views']['path'] . '/layouts/app.blade.php',
            'replacements' => [
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
            ],
        ];

        $generatorConfig['views']['generate'] && $this->stubMapping[] = [
            'stub' => 'layout.simple.stub',
            'destination' => $generatorConfig['views']['path'] . '/layouts/simple.blade.php',
            'replacements' => [
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
            ],
        ];

        // === 语言文件 ===
        $generatorConfig['lang']['generate'] && $this->stubMapping[] = [
            'stub' => 'lang.stub',
            'destination' => $generatorConfig['lang']['path'] . '/zh-CN/messages.php',
            'replacements' => [
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
            ],
        ];
        // === 测试 ===
        $generatorConfig['test']['generate'] && $this->stubMapping[] = [
            'stub' => 'test.stub',
            'destination' => $generatorConfig['test']['path'] . '/' . $moduleName . 'Test.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{CLASS}}' => $moduleName . 'Test',
            ],
        ];
    }

    /**
     * 创建模块目录结构
     *
     * 根据 modules.paths.generator 配置动态生成目录
     * 如果配置项被注释或删除，则不会生成对应的目录
     *
     * @param StubGenerator $generator
     * @return void
     */
    protected function createModuleStructure(StubGenerator $generator): void
    {
        $this->info("创建模块结构...");

        try {
            // 从配置中获取 generator 配置
            $generatorConfig = config('modules.paths.generator', []);

            // 如果没有配置，使用默认目录
            if (empty($generatorConfig)) {
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
            } else {
                // 根据 generator 配置动态生成目录
                $directories = [];

                foreach ($generatorConfig as $generatorItem) {
                    // 只生成有 generate 为开启的 path 目录
                    if (is_array($generatorItem) && !empty($generatorItem['generate'])) {
                        $directories[] = $generatorItem['path'];
                    }
                }
            }

            $generator->generateDirectories($directories);
        } catch (\Exception $e) {
            throw new \Exception("创建目录结构失败: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * 根据 stub 映射生成所有文件
     *
     * 统一的文件生成逻辑，避免变量替换相互干扰
     *
     * @param StubGenerator $generator
     * @return void
     */
    protected function generateFilesFromStubMapping(StubGenerator $generator): void
    {
        $this->info("从 stubs 中生成文件...");

        try {
            $generatedCount = 0;
            $failedFiles = [];

            foreach ($this->stubMapping as $mapping) {
                try {
                    // 获取 stub 文件的完整路径
                    $stubPath = $generator->getStubPath($mapping['stub']);

                    if (! file_exists($stubPath)) {
                        $failedFiles[] = "Stub 文件不存在: {$mapping['stub']}";
                        continue;
                    }

                    // 读取 stub 内容
                    $content = file_get_contents($stubPath);

                    // 获取当前替换变量的副本（避免污染 generator 的状态）
                    $replacements = array_merge($generator->getReplacements(), $mapping['replacements'] ?? []);

                    // 执行变量替换（使用临时副本，避免相互干扰）
                    foreach ($replacements as $search => $replace) {
                        $content = str_replace($search, $replace, $content);
                    }

                    // 构建目标文件的完整路径
                    $fullPath = $generator->getFullPath($mapping['destination']);

                    // 确保目录存在
                    $directory = dirname($fullPath);
                    if (! is_dir($directory)) {
                        File::makeDirectory($directory, 0755, true);
                    }

                    // 写入文件
                    File::put($fullPath, $content);
                    $generatedCount++;
                } catch (\Exception $e) {
                    $failedFiles[] = "生成文件失败 [{$mapping['destination']}]: {$e->getMessage()}";
                }
            }

            // 显示生成结果
            if ($generatedCount > 0) {
                $this->info("成功生成 {$generatedCount} 个文件");
            }

            if (! empty($failedFiles)) {
                $this->warn("以下文件生成失败:");
                foreach ($failedFiles as $error) {
                    $this->line("  - {$error}");
                }
            }
        } catch (\Exception $e) {
            throw new \Exception("生成文件失败: {$e->getMessage()}", 0, $e);
        }
    }
}
