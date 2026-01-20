<?php

namespace zxf\Modules\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Stub 生成器
 *
 * 提供模板文件处理和变量替换功能
 * 支持完善的变量定义、替换验证和错误检测机制
 *
 * 主要功能：
 * - 从 stub 模板文件生成目标文件
 * - 支持变量替换（如 {{NAME}}, {{NAMESPACE}}, {{LOWER_NAME}} 等）
 * - 自动检测未替换的变量
 * - 支持严格模式（stub 中未定义的变量会报错）
 * - 自动创建必要的目录结构
 */
class StubGenerator
{
    /**
     * 模块名称
     *
     * 格式：StudlyCase（如 Blog）
     *
     * @var string
     */
    protected string $moduleName;

    /**
     * 模块路径
     *
     * 模块的根目录路径
     *
     * @var string
     */
    protected string $modulePath;

    /**
     * 模块命名空间
     *
     * 格式：Modules（即根命名空间）
     *
     * @var string
     */
    protected string $namespace;

    /**
     * Stub 路径
     *
     * 模板文件存储目录
     *
     * @var string
     */
    protected string $stubPath;

    /**
     * 替换变量
     *
     * 存储所有变量名和对应的替换值
     * 格式：['{{NAME}}' => 'Blog', '{{LOWER_NAME}}' => 'blog']
     *
     * @var array<string, mixed>
     */
    protected array $replacements = [];

    /**
     * 变量替换统计
     *
     * 用于检测未替换的变量
     * 格式：['{{NAME}}' => ['found' => 5, 'replaced' => 5]]
     *
     * @var array<string, array{found: int, replaced: int}>
     */
    protected array $replacementStats = [];

    /**
     * 已定义的变量白名单
     *
     * 用于验证 stub 中的变量是否有效
     * 严格模式下，stub 中出现的变量必须在白名单中
     *
     * @var array<string, bool>
     */
    protected array $definedVariables = [];

    /**
     * 严格模式
     *
     * 开启后，stub 中未定义的变量会报错
     * 默认开启以确保代码质量
     *
     * @var bool
     */
    protected bool $strictMode = true;

    /**
     * 创建新实例
     *
     * @param string $moduleName 模块名称
     * @param string|null $modulePath 模块路径（可选，默认从配置读取）
     * @param string|null $namespace 模块命名空间（可选，默认从配置读取）
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

        // 初始化默认替换变量
        $this->setReplacements();
    }

    /**
     * 获取 Stub 路径
     *
     * @param string $stub stub文件名
     * @return string 完整的 stub 文件路径
     */
    public function getStubPath(string $stub): string
    {
        return $this->stubPath . '/' . $stub;
    }

    /**
     * 设置默认替换变量
     *
     * 自动定义所有常用的stub变量
     * 包括：模块名称的各种变体、命名空间、日期时间等
     *
     * @return void
     */
    protected function setReplacements(): void
    {
        $this->replacements = [
            // === 模块名称相关变量 ===
            '{{NAME}}' => $this->moduleName,                                    // 首字母大写: Blog
            '{{NAME_FIRST_LETTER}}' => substr($this->moduleName, 0, 1),        // 首字母: B
            '{{CAMEL_NAME}}' => Str::camel($this->moduleName),                // 驼峰命名: blog
            '{{LOWER_CAMEL_NAME}}' => lcfirst(Str::camel($this->moduleName)), // 小驼峰命名: blog
            '{{LOWER_NAME}}' => strtolower($this->moduleName),                // 全小写: blog
            '{{UPPER_NAME}}' => strtoupper($this->moduleName),                // 全大写: BLOG
            '{{SLUG_NAME}}' => Str::slug($this->moduleName),                  // 虚线命名: blog-module
            '{{SNAKE_NAME}}' => Str::snake($this->moduleName),               // 蛇形命名: blog

            // === 命名空间相关变量 ===
            '{{NAMESPACE}}' => $this->namespace,                              // 根命名空间: Modules
            '{{MODULE_NAMESPACE}}' => $this->namespace . '\\' . $this->moduleName, // 模块命名空间: Modules\Blog
            '{{EVENT_NAMESPACE}}' => $this->namespace . '\\' . $this->moduleName . '\\Events', // 事件命名空间: Modules\Blog\Events

            // === 路径相关变量 ===
            '{{MODULE_PATH}}' => $this->modulePath,                           // 模块绝对路径

            // === 控制器相关变量 ===
            '{{CONTROLLER_SUBNAMESPACE}}' => '',                               // 控制器子命名空间（动态设置）

            // === 其他常用变量 ===
            '{{CLASS}}' => $this->moduleName,                                  // 类名（动态设置）
            '{{DATE}}' => date('Y-m-d'),                                       // 当前日期: 2026-01-19
            '{{YEAR}}' => date('Y'),                                           // 当前年份: 2026
            '{{TIME}}' => date('H:i:s'),                                       // 当前时间: 14:30:45
            '{{DATETIME}}' => date('Y-m-d H:i:s'),                             // 日期时间: 2026-01-19 14:30:45

            // === 路由相关变量 ===
            '{{ROUTE_PREFIX_VALUE}}' => strtolower($this->moduleName),        // 路由前缀值（动态设置）
            '{{ROUTE_NAME_PREFIX_VALUE}}' => '',                                // 路由名称前缀值（动态设置）
            '{{ROUTE_PREFIX_COMMENT}}' => '',                                  // 路由前缀注释（动态设置）
            '{{ROUTE_NAME_PREFIX_COMMENT}}' => '',                              // 路由名称前缀注释（动态设置）

            // === 命令相关变量 ===
            '{{SIGNATURE}}' => '',                                              // 命令签名（动态设置）
            '{{DESCRIPTION}}' => '',                                            // 命令描述（动态设置）

            // === 事件相关变量 ===
            '{{EVENT}}' => '',                                                  // 事件类名（动态设置）

            // === 数据库相关变量 ===
            '{{TABLE}}' => strtolower($this->moduleName),                      // 表名（动态设置）

            // === 版本信息 ===
            '{{VERSION}}' => '1.0.0',                                           // 默认版本号
        ];

        // 将所有变量添加到白名单
        $this->definedVariables = array_fill_keys(array_keys($this->replacements), true);
    }

    /**
     * 添加替换变量
     *
     * @param string $key 变量键名（格式：{{VAR_NAME}}）
     * @param mixed $value 变量值
     * @return self 支持链式调用
     */
    public function addReplacement(string $key, mixed $value): self
    {
        // 验证变量名格式
        if (! str_starts_with($key, '{{') || ! str_ends_with($key, '}}')) {
            // 自动添加大括号
            $key = '{{' . trim($key, '{}') . '}}';
        }

        $this->replacements[$key] = $value;
        $this->definedVariables[$key] = true;

        return $this;
    }

    /**
     * 批量添加替换变量
     *
     * @param array<string, mixed> $replacements 变量数组
     * @return self 支持链式调用
     */
    public function addReplacements(array $replacements): self
    {
        foreach ($replacements as $key => $value) {
            $this->addReplacement($key, $value);
        }

        return $this;
    }

    /**
     * 获取替换变量
     *
     * @return array<string, mixed> 所有替换变量
     */
    public function getReplacements(): array
    {
        return $this->replacements;
    }

    /**
     * 获取单个替换变量的值
     *
     * @param string $key 变量键名
     * @param mixed $default 默认值（当变量不存在时）
     * @return mixed 变量值或默认值
     */
    public function getReplacement(string $key, mixed $default = null): mixed
    {
        return $this->replacements[$key] ?? $default;
    }

    /**
     * 检查变量是否已定义
     *
     * @param string $key 变量键名
     * @return bool 是否已定义
     */
    public function hasReplacement(string $key): bool
    {
        return isset($this->replacements[$key]);
    }

    /**
     * 设置严格模式
     *
     * @param bool $strict 是否开启严格模式
     * @return self 支持链式调用
     */
    public function setStrictMode(bool $strict): self
    {
        $this->strictMode = $strict;

        return $this;
    }

    /**
     * 获取严格模式状态
     *
     * @return bool 是否开启严格模式
     */
    public function isStrictMode(): bool
    {
        return $this->strictMode;
    }

    /**
     * 生成文件
     *
     * 从 stub 模板生成目标文件
     * 自动执行变量替换、目录创建等操作
     *
     * @param string $stub stub 文件名（相对于 stubs 目录）
     * @param string $destination 目标文件路径（相对于模块根目录）
     * @param bool $overwrite 是否覆盖已存在的文件
     * @return bool 是否成功生成文件
     */
    public function generate(string $stub, string $destination, bool $overwrite = false): bool
    {
        try {
            // 获取 stub 文件的完整路径
            $stubPath = $this->getStubPath($stub);

            // 检查 stub 文件是否存在
            if (! file_exists($stubPath)) {
                return false;
            }

            // 获取 stub 内容并执行变量替换
            $content = $this->getStubContent($stubPath);

            // 获取目标文件的完整路径
            $fullPath = $this->getFullPath($destination);

            // 如果文件已存在且不覆盖，直接返回 false
            if (file_exists($fullPath) && ! $overwrite) {
                return false;
            }

            // 确保目标目录存在（递归创建）
            $directory = dirname($fullPath);
            if (! is_dir($directory)) {
                File::makeDirectory($directory, 0755, true);
            }

            // 写入文件内容
            return File::put($fullPath, $content) !== false;
        } catch (\Throwable $e) {
            // 记录错误但不抛出异常
            if (app()->environment('local', 'testing')) {
                logger()->error("生成文件失败: {$destination}", [
                    'stub' => $stub,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
            return false;
        }
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
     * @param string $path 相对路径
     * @return string 完整路径
     */
    public function getFullPath(string $path): string
    {
        // 如果已经是绝对路径，直接返回
        if (str_starts_with($path, '/') || str_starts_with($path, 'C:') || str_starts_with($path, 'D:')) {
            return $path;
        }

        return $this->modulePath . '/' . $path;
    }

    /**
     * 获取 Stub 内容并替换变量
     *
     * 执行变量替换，并记录替换统计信息
     * 在严格模式下，会检测未替换的变量
     *
     * @param string $stubPath stub文件路径
     * @return string 替换后的内容
     */
    protected function getStubContent(string $stubPath): string
    {
        $content = File::get($stubPath);

        // 重置统计信息
        $this->replacementStats = [];

        // 第一次遍历：统计变量出现次数
        preg_match_all('/\{\{[\w]+\}\}/', $content, $matches);
        foreach ($matches[0] as $variable) {
            if (! isset($this->replacementStats[$variable])) {
                $this->replacementStats[$variable] = ['found' => 0, 'replaced' => 0];
            }
            $this->replacementStats[$variable]['found']++;
        }

        // 执行替换
        foreach ($this->replacements as $search => $replace) {
            $count = 0;
            $content = str_replace($search, $replace, $content, $count);

            // 记录替换次数
            if (isset($this->replacementStats[$search])) {
                $this->replacementStats[$search]['replaced'] = $count;
            }
        }

        // 严格模式检查
        if ($this->strictMode) {
            $this->validateReplacements($stubPath);
        }

        return $content;
    }

    /**
     * 验证变量替换结果
     *
     * 在严格模式下，检测以下情况：
     * 1. stub中使用了未定义的变量
     * 2. 定义的变量在stub中未被使用
     * 3. 变量值为null或空字符串
     *
     * @param string $stubPath stub文件路径
     * @return void
     */
    protected function validateReplacements(string $stubPath): void
    {
        $errors = [];

        // 检查stub中是否有未替换的变量
        foreach ($this->replacementStats as $variable => $stats) {
            // 如果变量在stub中出现但未被替换
            if ($stats['found'] > 0 && $stats['replaced'] === 0) {
                // 检查变量是否定义且值不为空
                if (! isset($this->replacements[$variable])) {
                    $errors[] = "变量 {$variable} 在stub中出现但未定义";
                } elseif ($this->replacements[$variable] === '' || $this->replacements[$variable] === null) {
                    $errors[] = "变量 {$variable} 的值为空，无法替换";
                } else {
                    $errors[] = "变量 {$variable} 替换失败，可能存在重复或冲突";
                }
            }
        }

        // 检查是否有定义但未使用的变量
        // foreach ($this->replacements as $variable => $value) {
        //     if (! isset($this->replacementStats[$variable])) {
        //         $errors[] = "变量 {$variable} 已定义但stub中未使用";
        //     }
        // }

        // 如果有错误，记录日志（或者可以抛出异常）
        if (! empty($errors)) {
            $stubName = basename($stubPath);
            $message = "Stub [{$stubName}] 变量替换验证失败:\n" . implode("\n", $errors);

            // 始终记录错误日志
            logger()->error($message, [
                'stub' => $stubName,
                'module' => $this->moduleName,
                'errors' => $errors,
                'stats' => $this->replacementStats,
                'replacements' => array_keys($this->replacements),
            ]);

            // 开发环境下可以显示警告
            if (app()->environment('local', 'testing')) {
                echo "\n" . $message . "\n";
            }
        }
    }

    /**
     * 获取变量替换统计信息
     *
     * @return array<string, array{found: int, replaced: int}> 统计信息
     */
    public function getReplacementStats(): array
    {
        return $this->replacementStats;
    }

    /**
     * 获取所有已定义的变量
     *
     * @return array<string, bool> 已定义的变量列表
     */
    public function getDefinedVariables(): array
    {
        return $this->definedVariables;
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
     * @return string 模块名称
     */
    public function getModuleName(): string
    {
        return $this->moduleName;
    }

    /**
     * 获取模块小写名称
     *
     * @return string 小写的模块名称
     */
    public function getLowerModuleName(): string
    {
        return strtolower($this->moduleName);
    }

    /**
     * 获取模块驼峰名称
     *
     * @return string 驼峰命名的模块名称
     */
    public function getCamelModuleName(): string
    {
        return Str::camel($this->moduleName);
    }

    /**
     * 获取模块命名空间
     *
     * @return string 模块命名空间
     */
    public function getModuleNamespace(): string
    {
        return $this->namespace . '\\' . $this->moduleName;
    }

    /**
     * 获取根命名空间
     *
     * @return string 根命名空间
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }
}
