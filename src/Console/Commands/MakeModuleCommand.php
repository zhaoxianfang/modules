<?php

namespace zxf\Modules\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakeModuleCommand extends Command
{
    /**
     * 控制台命令的名称和签名。
     *
     * @var string
     */
    protected $signature = 'module:make {name : 模块名称} {--force : 覆盖现有文件}';

    /**
     * 控制台命令的描述。
     *
     * @var string
     */
    protected $description = '创建新模块';

    /**
     * 文件系统实例。
     */
    protected Filesystem $files;

    /**
     * 创建一个新的命令实例。
     */
    public function __construct(Filesystem $files)
    {
        parent::__construct();

        $this->files = $files;
    }

    /**
     * 执行控制台命令。
     */
    public function handle(): int
    {
        $name = $this->argument('name');
        $studlyName = Str::studly($name);
        $modulePath = $this->getModulePath($studlyName);

        // 验证模块名称是否符合允许的模式
        $allowedPattern = $this->laravel['config']->get('modules.allowed_module_names', '/^[a-zA-Z_][a-zA-Z0-9_]*$/');
        if (!preg_match($allowedPattern, $name)) {
            $this->error("模块名称 [{$name}] 不符合允许的命名模式。");
            return Command::FAILURE;
        }

        // 检查模块是否允许生成
        $modulesConfig = $this->laravel['config']->get('modules.modules', []);
        $defaultGenerate = $this->laravel['config']->get('modules.default_generate', true);
        
        if (isset($modulesConfig[$name])) {
            $moduleConfig = $modulesConfig[$name];
            if (isset($moduleConfig['generate']) && $moduleConfig['generate'] === false) {
                $this->error("模块 [{$name}] 配置为不生成（generate = false）。");
                return Command::FAILURE;
            }
        } elseif (!$defaultGenerate) {
            $this->error("模块生成默认已禁用（default_generate = false）。");
            return Command::FAILURE;
        }

        if ($this->files->exists($modulePath) && ! $this->option('force')) {
            $this->error("模块 [{$studlyName}] 已存在！");
            return Command::FAILURE;
        }

        $this->createDirectoryStructure($modulePath);
        $this->createModuleClass($studlyName, $modulePath);
        $this->createDirectories($modulePath);
        $this->createStubFiles($studlyName, $modulePath);

        $this->info("模块 [{$studlyName}] 创建成功。");

        return Command::SUCCESS;
    }

    /**
     * 获取模块路径。
     */
    protected function getModulePath(string $studlyName): string
    {
        $modulesPath = $this->laravel['config']->get('modules.path', $this->laravel->basePath('modules'));

        return $modulesPath . '/' . $studlyName;
    }

    /**
     * 创建模块目录结构。
     */
    protected function createDirectoryStructure(string $modulePath): void
    {
        $directories = [
            'config',
            'database/migrations',
            'database/seeders',
            'resources/views',
            'resources/lang',
            'routes',
            'Http/Controllers',
            'Http/Middleware',
            'Http/Requests',
            'Http/Resources',
            'Models',
            'Exceptions',
            'Jobs',
            'Mail',
            'Notifications',
            'Providers',
            'Console/Commands',
            'Events',
            'Listeners',
            'Policies',
            'Rules',
            'Services',
            'Observers',
            'Contracts',
            'Repositories',
            'Broadcasting/Channels',
            'View/Components',
            'Casts',
        ];

        foreach ($directories as $directory) {
            $path = $modulePath . '/' . $directory;
            $this->files->ensureDirectoryExists($path);
            $this->line("<info>Created</info> {$path}");
        }
    }

    /**
     * 创建模块类。
     */
    protected function createModuleClass(string $studlyName, string $modulePath): void
    {
        $namespace = $this->laravel['config']->get('modules.namespace', 'Modules');
        $className = $studlyName . 'Module';
        $path = $modulePath . '/' . $className . '.php';

        $stub = $this->files->get($this->getStubPath('module-class.stub'));
        $stub = str_replace(
            ['{{namespace}}', '{{class}}', '{{name}}'],
            [$namespace . '\\' . $studlyName, $className, Str::snake($studlyName)],
            $stub
        );

        $this->files->put($path, $stub);
        $this->line("<info>Created</info> {$path}");
    }

    /**
     * 创建额外的目录。
     */
    protected function createDirectories(string $modulePath): void
    {
        // 可以在此添加额外的目录
        // $modulePath 变量在此可用
        // 示例：$this->files->ensureDirectoryExists($modulePath . '/CustomDir');
        unset($modulePath); // 消除未使用变量警告
    }

    /**
     * 创建存根文件。
     */
    protected function createStubFiles(string $studlyName, string $modulePath): void
    {
        $namespace = $this->laravel['config']->get('modules.namespace', 'Modules');
        $moduleSnake = Str::snake($studlyName);

        $stubs = [
            'config' => [
                'stub' => 'config.stub',
                'path' => $modulePath . '/config/' . $moduleSnake . '.php',
                'replacements' => [
                    '{{namespace}}' => $namespace . '\\' . $studlyName,
                    '{{name}}' => $moduleSnake,
                ],
            ],
            'routes-web' => [
                'stub' => 'routes-web.stub',
                'path' => $modulePath . '/routes/web.php',
                'replacements' => [
                    '{{namespace}}' => $namespace . '\\' . $studlyName,
                ],
            ],
            'routes-api' => [
                'stub' => 'routes-api.stub',
                'path' => $modulePath . '/routes/api.php',
                'replacements' => [
                    '{{namespace}}' => $namespace . '\\' . $studlyName,
                ],
            ],
            'provider' => [
                'stub' => 'provider.stub',
                'path' => $modulePath . '/Providers/' . $studlyName . 'ServiceProvider.php',
                'replacements' => [
                    '{{namespace}}' => $namespace . '\\' . $studlyName,
                    '{{class}}' => $studlyName . 'ServiceProvider',
                    '{{name}}' => $moduleSnake,
                ],
            ],
        ];

        foreach ($stubs as $stubConfig) {
            if (! $this->files->exists($stubConfig['path']) || $this->option('force')) {
                $stub = $this->files->get($this->getStubPath($stubConfig['stub']));
                $stub = str_replace(
                    array_keys($stubConfig['replacements']),
                    array_values($stubConfig['replacements']),
                    $stub
                );
                $this->files->put($stubConfig['path'], $stub);
                $this->line("<info>Created</info> {$stubConfig['path']}");
            }
        }
    }

    /**
     * 获取存根路径。
     */
    protected function getStubPath(string $stub): string
    {
        // 检查是否配置了自定义存根路径
        $stubsPath = $this->laravel['config']->get('modules.stubs_path');
        if ($stubsPath) {
            $customStubPath = $stubsPath . '/' . $stub;
            if ($this->files->exists($customStubPath)) {
                return $customStubPath;
            }
        }
        
        // 默认自定义存根路径
        $customStubPath = $this->laravel->basePath('stubs/modules/' . $stub);
        if ($this->files->exists($customStubPath)) {
            return $customStubPath;
        }

        // 包内默认存根
        return __DIR__ . '/../../../stubs/' . $stub;
    }
}