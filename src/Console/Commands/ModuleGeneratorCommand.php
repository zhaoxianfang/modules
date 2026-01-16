<?php

namespace zxf\Modules\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

abstract class ModuleGeneratorCommand extends Command
{
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
     * 获取模块名称。
     */
    protected function getModuleName(): string
    {
        return Str::studly($this->argument('module'));
    }

    /**
     * 获取模块路径。
     */
    protected function getModulePath(?string $moduleName = null): string
    {
        $moduleName = $moduleName ?? $this->getModuleName();
        $modulesPath = $this->laravel['config']->get('modules.path', $this->laravel->basePath('modules'));

        return $modulesPath . '/' . $moduleName;
    }

    /**
     * 获取模块命名空间。
     */
    protected function getModuleNamespace(?string $moduleName = null): string
    {
        $moduleName = $moduleName ?? $this->getModuleName();
        $namespace = $this->laravel['config']->get('modules.namespace', 'Modules');

        return $namespace . '\\' . $moduleName;
    }

    /**
     * 获取目标类路径。
     */
    abstract protected function getDestinationPath(string $moduleName, string $name): string;

    /**
     * 获取存根文件路径。
     */
    abstract protected function getStubFile(): string;

    /**
     * 获取存根文件的替换变量。
     */
    protected function getReplacements(string $moduleName, string $name): array
    {
        $namespace = $this->getModuleNamespace($moduleName);
        $className = Str::studly($name);

        return [
            '{{namespace}}' => $namespace,
            '{{class}}' => $className,
        ];
    }

    /**
     * 获取存根文件路径。
     * 首先检查 modules.stubs_path 配置，然后检查 base_path('stubs/modules/...')，最后回退到包的默认存根。
     *
     * @param string $stubName 存根文件名（例如 'controller.stub'）
     * @return string 存根文件的完整路径
     */
    protected function getStubPath(string $stubName): string
    {
        // 检查是否配置了自定义存根路径
        $stubsPath = $this->laravel['config']->get('modules.stubs_path');
        if ($stubsPath) {
            $customStubPath = $stubsPath . '/' . $stubName;
            if ($this->files->exists($customStubPath)) {
                return $customStubPath;
            }
        }

        // 检查 Laravel 自定义存根路径
        $laravelStubPath = $this->laravel->basePath('stubs/modules/' . $stubName);
        if ($this->files->exists($laravelStubPath)) {
            return $laravelStubPath;
        }

        // 回退到包的默认存根
        return __DIR__ . '/../../../stubs/' . $stubName;
    }

    /**
     * 检查模块是否允许生成文件。
     */
    protected function isModuleAllowed(string $moduleName): bool
    {
        $modulesConfig = $this->laravel['config']->get('modules.modules', []);
        $defaultGenerate = $this->laravel['config']->get('modules.default_generate', true);
        
        if (array_key_exists($moduleName, $modulesConfig)) {
            return $modulesConfig[$moduleName]['generate'] ?? $defaultGenerate;
        }
        
        return $defaultGenerate;
    }

    /**
     * 使用给定名称构建类。
     */
    protected function buildClass(string $moduleName, string $name): string
    {
        $stub = $this->files->get($this->getStubFile());
        $replacements = $this->getReplacements($moduleName, $name);

        return str_replace(array_keys($replacements), array_values($replacements), $stub);
    }

    /**
     * 执行控制台命令。
     */
    public function handle(): int
    {
        $moduleName = $this->getModuleName();
        $name = $this->argument('name');

        // 检查模块是否允许生成文件
        if (!$this->isModuleAllowed($moduleName)) {
            $this->error("模块 [{$moduleName}] 的文件生成已被配置禁用。");
            return Command::FAILURE;
        }

        if (! $this->files->exists($this->getModulePath($moduleName))) {
            $this->error("模块 [{$moduleName}] 不存在！");
            return Command::FAILURE;
        }

        $path = $this->getDestinationPath($moduleName, $name);

        if ($this->files->exists($path) && ! $this->option('force')) {
            $this->error("文件 [{$path}] 已存在！");
            return Command::FAILURE;
        }

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $this->buildClass($moduleName, $name));

        $this->info("文件 [{$path}] 创建成功。");

        return Command::SUCCESS;
    }
}