<?php

namespace zxf\Modules\Support;

use Illuminate\Contracts\Foundation\Application;

/**
 * 编译模块加载器
 *
 * 生产环境使用编译缓存，将模块配置和发现结果编译为 PHP 数组
 * 避免重复的文件系统扫描和配置读取
 *
 * 优化策略：
 * 1. 编译所有模块配置为单一 PHP 文件
 * 2. 编译模块发现结果为可加载的数组
 * 3. 使用 OPcache 加速编译文件的执行
 * 4. 提供缓存预热和刷新机制
 */
class CompiledModuleLoader
{
    /**
     * 应用实例
     */
    protected Application $app;

    /**
     * 缓存目录
     */
    protected string $cachePath;

    /**
     * 编译文件名
     */
    protected const COMPILED_FILE = 'modules.php';

    /**
     * 元数据文件名
     */
    protected const META_FILE = 'modules_meta.php';

    /**
     * 创建新实例
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->cachePath = $app->bootstrapPath('cache');
    }

    /**
     * 检查编译缓存是否存在且有效
     */
    public function isCompiled(): bool
    {
        $compiledFile = $this->getCompiledFilePath();

        if (! file_exists($compiledFile)) {
            return false;
        }

        // 检查是否需要重新编译
        $metaFile = $this->getMetaFilePath();
        if (! file_exists($metaFile)) {
            return false;
        }

        try {
            $meta = require $metaFile;

            // 检查缓存版本
            if (($meta['version'] ?? null) !== $this->getVersion()) {
                return false;
            }

            // 检查配置是否变更
            if (($meta['config_hash'] ?? null) !== $this->getConfigHash()) {
                return false;
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 从编译缓存加载模块
     *
     * @return array<string, array>|null
     */
    public function load(): ?array
    {
        if (! $this->isCompiled()) {
            return null;
        }

        try {
            $compiledFile = $this->getCompiledFilePath();
            $modules = require $compiledFile;

            if (! is_array($modules)) {
                return null;
            }

            return $modules;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 编译模块配置
     *
     * @param array<string, array> $modules
     */
    public function compile(array $modules): void
    {
        if (! is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }

        $compiledFile = $this->getCompiledFilePath();
        $content = $this->generateCompiledContent($modules);

        file_put_contents($compiledFile, $content, LOCK_EX);

        // 生成元数据
        $this->writeMeta();
    }

    /**
     * 生成编译内容
     *
     * @param array<string, array> $modules
     */
    protected function generateCompiledContent(array $modules): string
    {
        $export = var_export($modules, true);
        $timestamp = date('Y-m-d H:i:s');

        return <<<PHP
<?php
/**
 * 自动生成的模块编译缓存
 * 生成时间: {$timestamp}
 * 请勿手动修改此文件
 */

return {$export};
PHP;
    }

    /**
     * 写入元数据
     */
    protected function writeMeta(): void
    {
        $meta = [
            'version' => $this->getVersion(),
            'config_hash' => $this->getConfigHash(),
            'compiled_at' => time(),
            'modules_path' => config('modules.path', base_path('Modules')),
        ];

        $content = "<?php\nreturn " . var_export($meta, true) . ";\n";
        file_put_contents($this->getMetaFilePath(), $content, LOCK_EX);
    }

    /**
     * 清除编译缓存
     */
    public function clear(): void
    {
        $files = [
            $this->getCompiledFilePath(),
            $this->getMetaFilePath(),
        ];

        foreach ($files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * 刷新编译缓存
     *
     * @param array<string, array> $modules
     */
    public function refresh(array $modules): void
    {
        $this->clear();
        $this->compile($modules);
    }

    /**
     * 获取编译文件路径
     */
    public function getCompiledFilePath(): string
    {
        return $this->cachePath . DIRECTORY_SEPARATOR . self::COMPILED_FILE;
    }

    /**
     * 获取元数据文件路径
     */
    public function getMetaFilePath(): string
    {
        return $this->cachePath . DIRECTORY_SEPARATOR . self::META_FILE;
    }

    /**
     * 获取缓存版本
     */
    public function getVersion(): string
    {
        return '2.0';
    }

    /**
     * 获取配置哈希
     */
    protected function getConfigHash(): string
    {
        $config = [
            'path' => config('modules.path', base_path('Modules')),
            'namespace' => config('modules.namespace', 'Modules'),
            'discovery' => config('modules.discovery', []),
            'middleware_groups' => config('modules.middleware_groups', []),
        ];

        return md5(serialize($config));
    }

    /**
     * 获取编译统计信息
     */
    public function getStats(): array
    {
        $compiledFile = $this->getCompiledFilePath();
        $metaFile = $this->getMetaFilePath();

        $stats = [
            'is_compiled' => $this->isCompiled(),
            'cache_path' => $this->cachePath,
            'version' => $this->getVersion(),
        ];

        if (file_exists($compiledFile)) {
            $stats['compiled_file_size'] = filesize($compiledFile);
            $stats['compiled_file_mtime'] = filemtime($compiledFile);
        }

        if (file_exists($metaFile)) {
            try {
                $meta = require $metaFile;
                $stats['compiled_at'] = $meta['compiled_at'] ?? null;
                $stats['modules_path'] = $meta['modules_path'] ?? null;
            } catch (\Throwable) {
                // 静默处理
            }
        }

        return $stats;
    }

    /**
     * 预热缓存
     * 在部署时预先生成编译缓存
     */
    public function warm(): void
    {
        if ($this->isCompiled()) {
            return;
        }

        // 触发模块扫描和编译
        $repository = app(\zxf\Modules\Contracts\RepositoryInterface::class);
        $repository->scan();

        $modules = [];
        foreach ($repository->all() as $name => $module) {
            $modules[$name] = [
                'name' => $module->getName(),
                'path' => $module->getPath(),
                'namespace' => $module->getNamespace(),
                'enabled' => $module->isEnabled(),
            ];
        }

        $this->compile($modules);
    }
}
