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

        // === 服务提供者 ===
        $this->stubMapping[] = [
            'stub' => 'provider.stub',
            'destination' => 'Providers/' . $moduleName . 'ServiceProvider.php',
            'replacements' => [
                '{{CLASS}}' => $moduleName . 'ServiceProvider',
                '{{LOWER_NAME}}' => $lowerName,
                '{{NAME}}' => $moduleName,
            ],
        ];

        // === 配置文件 ===
        $this->stubMapping[] = [
            'stub' => 'config.stub',
            'destination' => 'Config/' . $lowerName . '.php',
            'replacements' => [
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
            ],
        ];

        // === 路由文件 ===
        $this->stubMapping[] = [
            'stub' => 'route/web.stub',
            'destination' => 'Routes/web.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
                '{{ROUTE_PREFIX_VALUE}}' => $lowerName,
                '{{ROUTE_NAME_PREFIX_VALUE}}' => "web.{$lowerName}.",
            ],
        ];

        $this->stubMapping[] = [
            'stub' => 'route/api.stub',
            'destination' => 'Routes/api.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
                '{{ROUTE_PREFIX_VALUE}}' => "api/{$lowerName}",
                '{{ROUTE_NAME_PREFIX_VALUE}}' => "api.{$lowerName}.",
            ],
        ];

        $this->stubMapping[] = [
            'stub' => 'route/admin.stub',
            'destination' => 'Routes/admin.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
                '{{ROUTE_PREFIX_VALUE}}' => "{$lowerName}/admin",
                '{{ROUTE_NAME_PREFIX_VALUE}}' => "admin.{$lowerName}.",
            ],
        ];

        // === 基础控制器 ===
        $this->stubMapping[] = [
            'stub' => 'controller.base.stub',
            'destination' => 'Http/Controllers/Controller.php',
            'replacements' => [
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
            ],
        ];

        // === Web 控制器 ===
        $this->stubMapping[] = [
            'stub' => 'controller.stub',
            'destination' => 'Http/Controllers/Web/' . $moduleName . 'Controller.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{CONTROLLER_SUBNAMESPACE}}' => '\\Web',
                '{{CLASS}}' => $moduleName . 'Controller',
            ],
        ];

        // === Api 控制器 ===
        $this->stubMapping[] = [
            'stub' => 'controller.stub',
            'destination' => 'Http/Controllers/Api/' . $moduleName . 'Controller.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{CONTROLLER_SUBNAMESPACE}}' => '\\Api',
                '{{CLASS}}' => $moduleName . 'Controller',
            ],
        ];

        // === Admin 控制器 ===
        $this->stubMapping[] = [
            'stub' => 'controller.stub',
            'destination' => 'Http/Controllers/Admin/' . $moduleName . 'Controller.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{CONTROLLER_SUBNAMESPACE}}' => '\\Admin',
                '{{CLASS}}' => $moduleName . 'Controller',
            ],
        ];

        // === 模型 ===
        $this->stubMapping[] = [
            'stub' => 'model.stub',
            'destination' => 'Models/' . $moduleName . '.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
            ],
        ];

        // === 观察者 ===
        $this->stubMapping[] = [
            'stub' => 'observer.stub',
            'destination' => 'Observers/' . $moduleName . 'Observer.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{CLASS}}' => $moduleName . 'Observer',
            ],
        ];

        // === 策略 ===
        $this->stubMapping[] = [
            'stub' => 'policy.stub',
            'destination' => 'Policies/' . $moduleName . 'Policy.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{CLASS}}' => $moduleName . 'Policy',
            ],
        ];

        // === 仓库 ===
        $this->stubMapping[] = [
            'stub' => 'repository.stub',
            'destination' => 'Repositories/' . $moduleName . 'Repository.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{CLASS}}' => $moduleName . 'Repository',
            ],
        ];

        // === 请求验证 ===
        $this->stubMapping[] = [
            'stub' => 'request.stub',
            'destination' => 'Http/Requests/' . $moduleName . 'Request.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{CLASS}}' => $moduleName . 'Request',
            ],
        ];

        // === 资源类 ===
        $this->stubMapping[] = [
            'stub' => 'resource.stub',
            'destination' => 'Http/Resources/' . $moduleName . 'Resource.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{CLASS}}' => $moduleName . 'Resource',
            ],
        ];

        // === 中间件 ===
        $this->stubMapping[] = [
            'stub' => 'middleware.stub',
            'destination' => 'Http/Middleware/' . $moduleName . 'Middleware.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{CLASS}}' => $moduleName . 'Middleware',
            ],
        ];

        // === 命令 ===
        $this->stubMapping[] = [
            'stub' => 'command.stub',
            'destination' => 'Console/Commands/' . $moduleName . 'Command.php',
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
        $this->stubMapping[] = [
            'stub' => 'event.stub',
            'destination' => 'Events/' . $moduleName . 'Event.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{CLASS}}' => $moduleName . 'Event',
            ],
        ];

        // === 监听器 ===
        $this->stubMapping[] = [
            'stub' => 'listener.stub',
            'destination' => 'Listeners/' . $moduleName . 'Listener.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{CLASS}}' => $moduleName . 'Listener',
                '{{EVENT_NAMESPACE}}' => $namespace . '\\' . $moduleName . '\\Events',
                '{{EVENT}}' => $moduleName . 'Event',
            ],
        ];

        // === 数据填充器 ===
        $this->stubMapping[] = [
            'stub' => 'seeder.stub',
            'destination' => 'Database/Seeders/' . $moduleName . 'Seeder.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{CLASS}}' => $moduleName . 'Seeder',
            ],
        ];

        // === 测试 ===
        $this->stubMapping[] = [
            'stub' => 'test.stub',
            'destination' => 'Tests/' . $moduleName . 'Test.php',
            'replacements' => [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $moduleName,
                '{{CLASS}}' => $moduleName . 'Test',
            ],
        ];

        // === 示例迁移 ===
        $this->stubMapping[] = [
            'stub' => 'migration/create.stub',
            'destination' => 'Database/Migrations/'.date('Y_m_d_His').'_create_' . $lowerName . '_table.php',
            'replacements' => [
                '{{TABLE}}' => $lowerName,
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
            ],
        ];

        // === 视图文件 ===
        $this->stubMapping[] = [
            'stub' => 'view.stub',
            'destination' => 'Resources/views/welcome.blade.php',
            'replacements' => [
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
            ],
        ];

        $this->stubMapping[] = [
            'stub' => 'view.index.stub',
            'destination' => 'Resources/views/index.blade.php',
            'replacements' => [
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
            ],
        ];

        $this->stubMapping[] = [
            'stub' => 'view.show.stub',
            'destination' => 'Resources/views/show.blade.php',
            'replacements' => [
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
            ],
        ];

        // === 布局文件 ===
        $this->stubMapping[] = [
            'stub' => 'layout.app.stub',
            'destination' => 'Resources/views/layouts/app.blade.php',
            'replacements' => [
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
            ],
        ];

        $this->stubMapping[] = [
            'stub' => 'layout.simple.stub',
            'destination' => 'Resources/views/layouts/simple.blade.php',
            'replacements' => [
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
            ],
        ];

        // === README ===
        $this->stubMapping[] = [
            'stub' => 'readme.stub',
            'destination' => 'README.md',
            'replacements' => [
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
            ],
        ];

        // === 语言文件 ===
        $this->stubMapping[] = [
            'stub' => 'lang.stub',
            'destination' => 'Resources/lang/zh-CN/messages.php',
            'replacements' => [
                '{{NAME}}' => $moduleName,
                '{{LOWER_NAME}}' => $lowerName,
            ],
        ];
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

        try {
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
        $this->info("Generating files from stubs...");

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
