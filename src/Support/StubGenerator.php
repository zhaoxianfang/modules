<?php

namespace zxf\Modules\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Stub 基类
 *
 * 提供模板文件处理和变量替换功能
 */
class StubGenerator
{
    /**
     * 模块名称
     *
     * @var string
     */
    protected string $moduleName;

    /**
     * 模块路径
     *
     * @var string
     */
    protected string $modulePath;

    /**
     * 模块命名空间
     *
     * @var string
     */
    protected string $namespace;

    /**
     * Stub 路径
     *
     * @var string
     */
    protected string $stubPath;

    /**
     * 替换变量
     *
     * @var array
     */
    protected array $replacements = [];

    /**
     * 创建新实例
     *
     * @param string $moduleName
     * @param string|null $modulePath
     * @param string|null $namespace
     */
    public function __construct(
        string $moduleName,
        ?string $modulePath = null,
        ?string $namespace = null
    ) {
        $this->moduleName = Str::studly($moduleName);
        $this->modulePath = $modulePath ?? config('modules.path', base_path('Modules')) . '/' . $this->moduleName;
        $this->namespace = $namespace ?? config('modules.namespace', 'Modules');
        $this->stubPath = __DIR__ . '/../Commands/stubs';

        $this->setReplacements();
    }

    /**
     * 设置默认替换变量
     *
     * @return void
     */
    protected function setReplacements(): void
    {
        $this->replacements = [
            '{{NAME}}' => $this->moduleName,
            '{{NAME_FIRST_LETTER}}' => substr($this->moduleName, 0, 1),  // 首字母
            '{{CAMEL_NAME}}' => Str::camel($this->moduleName),
            '{{LOWER_CAMEL_NAME}}' => lcfirst(Str::camel($this->moduleName)),
            '{{LOWER_NAME}}' => strtolower($this->moduleName),
            '{{UPPER_NAME}}' => strtoupper($this->moduleName),
            '{{NAMESPACE}}' => $this->namespace,
            '{{MODULE_NAMESPACE}}' => $this->namespace . '\\' . $this->moduleName,
            '{{CONTROLLER_SUBNAMESPACE}}' => '',
            '{{DATE}}' => date('Y-m-d'),
            '{{YEAR}}' => date('Y'),
            '{{TIME}}' => date('H:i:s'),
        ];
    }

    /**
     * 添加替换变量
     *
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function addReplacement(string $key, mixed $value): self
    {
        $this->replacements[$key] = $value;

        return $this;
    }

    /**
     * 批量添加替换变量
     *
     * @param array $replacements
     * @return self
     */
    public function addReplacements(array $replacements): self
    {
        $this->replacements = array_merge($this->replacements, $replacements);

        return $this;
    }

    /**
     * 获取替换变量
     *
     * @return array
     */
    public function getReplacements(): array
    {
        return $this->replacements;
    }

    /**
     * 生成文件
     *
     * @param string $stub
     * @param string $destination
     * @param bool $overwrite
     * @return bool
     */
    public function generate(string $stub, string $destination, bool $overwrite = false): bool
    {
        $stubPath = $this->getStubPath($stub);

        if (! file_exists($stubPath)) {
            return false;
        }

        $content = $this->getStubContent($stubPath);

        // 确保使用绝对路径
        $fullPath = $this->getFullPath($destination);

        if (file_exists($fullPath) && ! $overwrite) {
            return false;
        }

        // 确保目录存在
        $directory = dirname($fullPath);
        if (! is_dir($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        return File::put($fullPath, $content) !== false;
    }

    /**
     * 检查文件是否存在
     *
     * @param string $destination
     * @return bool
     */
    public function fileExists(string $destination): bool
    {
        return file_exists($this->getFullPath($destination));
    }

    /**
     * 删除文件
     *
     * @param string $destination
     * @return bool
     */
    public function deleteFile(string $destination): bool
    {
        $fullPath = $this->getFullPath($destination);

        if (file_exists($fullPath)) {
            return File::delete($fullPath);
        }

        return false;
    }

    /**
     * 检查目录是否存在
     *
     * @param string $destination
     * @return bool
     */
    public function directoryExists(string $destination): bool
    {
        return is_dir($this->getFullPath($destination));
    }

    /**
     * 获取完整路径
     *
     * @param string $path
     * @return string
     */
    protected function getFullPath(string $path): string
    {
        // 如果已经是绝对路径，直接返回
        if (str_starts_with($path, '/') || str_starts_with($path, 'C:') || str_starts_with($path, 'D:')) {
            return $path;
        }

        return $this->modulePath . '/' . $path;
    }

    /**
     * 获取 Stub 路径
     *
     * @param string $stub
     * @return string
     */
    protected function getStubPath(string $stub): string
    {
        return $this->stubPath . '/' . $stub;
    }

    /**
     * 获取 Stub 内容并替换变量
     *
     * @param string $stubPath
     * @return string
     */
    protected function getStubContent(string $stubPath): string
    {
        $content = File::get($stubPath);

        foreach ($this->replacements as $search => $replace) {
            $content = str_replace($search, $replace, $content);
        }

        return $content;
    }

    /**
     * 从自定义路径生成文件
     *
     * @param string $stubPath
     * @param string $destination
     * @param array|null $replacements
     * @param bool $overwrite
     * @return bool
     */
    public static function generateFromPath(
        string $stubPath,
        string $destination,
        ?array $replacements = null,
        bool $overwrite = false
    ): bool {
        if (! file_exists($stubPath)) {
            return false;
        }

        $content = File::get($stubPath);

        if ($replacements) {
            foreach ($replacements as $search => $replace) {
                $content = str_replace($search, $replace, $content);
            }
        }

        if (file_exists($destination) && ! $overwrite) {
            return false;
        }

        $directory = dirname($destination);
        if (! is_dir($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        return File::put($destination, $content) !== false;
    }

    /**
     * 生成目录结构
     *
     * @param array $directories
     * @return void
     */
    public function generateDirectories(array $directories): void
    {
        foreach ($directories as $directory) {
            $path = $this->modulePath . '/' . $directory;

            if (! is_dir($path)) {
                File::makeDirectory($path, 0755, true);
            }
        }
    }

    /**
     * 批量生成文件
     *
     * @param array $files
     * @param bool $overwrite
     * @return array 生成结果
     */
    public function generateFiles(array $files, bool $overwrite = false): array
    {
        $results = [];

        foreach ($files as $stub => $destination) {
            $fullPath = $this->modulePath . '/' . $destination;
            $results[$destination] = $this->generate($stub, $fullPath, $overwrite);
        }

        return $results;
    }

    /**
     * 获取模块路径
     *
     * @return string
     */
    public function getModulePath(): string
    {
        return $this->modulePath;
    }

    /**
     * 获取模块名称
     *
     * @return string
     */
    public function getModuleName(): string
    {
        return $this->moduleName;
    }
}
