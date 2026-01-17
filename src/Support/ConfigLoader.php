<?php

namespace zxf\Modules\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * 配置加载器类
 *
 * 负责加载和管理模块配置文件
 * 支持动态加载当前模块配置文件
 *
 * PHP 8.2+ 优化：
 * - 简化的配置加载逻辑
 * - 改进的缓存机制
 * - 更好的类型声明
 */
class ConfigLoader
{
    /**
     * 加载模块配置
     *
     * @param string $moduleName 模块名称
     * @param string $configFile 配置文件名（不带.php扩展名）
     * @return array
     */
    public static function load(string $moduleName, string $configFile): array
    {
        $configPath = self::getFilePath($moduleName, $configFile);

        if (! file_exists($configPath)) {
            return [];
        }

        $config = require $configPath;

        return is_array($config) ? $config : [];
    }

    /**
     * 加载当前模块配置
     *
     * 通过调用栈自动检测当前模块并加载其配置文件
     *
     * @param string $configFile 配置文件名（不带.php扩展名）
     * @return array
     */
    public static function loadCurrent(string $configFile): array
    {
        $moduleName = self::detectCurrentModule();

        return $moduleName ? self::load($moduleName, $configFile) : [];
    }

    /**
     * 获取模块配置值
     *
     * @param string $moduleName 模块名称
     * @param string $configFile 配置文件名
     * @param string $key 配置键
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get(string $moduleName, string $configFile, string $key, mixed $default = null): mixed
    {
        $config = self::load($moduleName, $configFile);

        return $config[$key] ?? $default;
    }

    /**
     * 获取当前模块配置值
     *
     * 通过调用栈自动检测当前模块并获取其配置值
     *
     * @param string $configFile 配置文件名
     * @param string $key 配置键
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function getCurrent(string $configFile, string $key, mixed $default = null): mixed
    {
        $config = self::loadCurrent($configFile);

        return $config[$key] ?? $default;
    }

    /**
     * 检查配置键是否存在
     *
     * @param string $moduleName 模块名称
     * @param string $configFile 配置文件名
     * @param string $key 配置键
     * @return bool
     */
    public static function has(string $moduleName, string $configFile, string $key): bool
    {
        return array_key_exists($key, self::load($moduleName, $configFile));
    }

    /**
     * 检查当前模块配置键是否存在
     *
     * @param string $configFile 配置文件名
     * @param string $key 配置键
     * @return bool
     */
    public static function hasCurrent(string $configFile, string $key): bool
    {
        return array_key_exists($key, self::loadCurrent($configFile));
    }

    /**
     * 获取配置键前缀
     *
     * @param string $moduleName 模块名称
     * @param string $configFile 配置文件名
     * @return string
     */
    public static function getConfigKey(string $moduleName, string $configFile): string
    {
        return Str::camel($moduleName) . '.' . $configFile;
    }

    /**
     * 获取模块配置文件路径
     *
     * @param string $moduleName 模块名称
     * @param string $configFile 配置文件名
     * @return string
     */
    protected static function getFilePath(string $moduleName, string $configFile): string
    {
        $modulePath = config('modules.path', base_path('Modules'));

        return $modulePath
            . DIRECTORY_SEPARATOR
            . $moduleName
            . DIRECTORY_SEPARATOR
            . 'Config'
            . DIRECTORY_SEPARATOR
            . $configFile
            . '.php';
    }

    /**
     * 检测当前模块
     *
     * 通过调用栈自动检测当前代码所在的模块
     * PHP 8.2+ 优化：简化的检测逻辑
     *
     * @return string|null
     */
    protected static function detectCurrentModule(): ?string
    {
        static $cachedResult = null;

        if ($cachedResult !== null) {
            return $cachedResult;
        }

        $modulePath = strtr(config('modules.path', base_path('Modules')), ['\\' => '/']);

        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);

        foreach ($backtrace as $trace) {
            $filePath = $trace['file'] ?? null;

            if (! $filePath || ! str_starts_with($filePath, $modulePath . '/')) {
                continue;
            }

            // 标准化路径并提取模块名
            $filePath = strtr($filePath, ['\\' => '/']);
            $relativePath = substr($filePath, strlen($modulePath) + 1);
            $moduleName = explode('/', $relativePath, 2)[0] ?? '';

            if ($moduleName) {
                $cachedResult = $moduleName;
                return $moduleName;
            }
        }

        $cachedResult = null;
        return null;
    }

    /**
     * 获取模块所有配置文件
     *
     * PHP 8.2+ 优化：使用 array_map
     *
     * @param string $moduleName 模块名称
     * @return array
     */
    public static function getConfigFiles(string $moduleName): array
    {
        $configDir = config('modules.path', base_path('Modules'))
            . DIRECTORY_SEPARATOR
            . $moduleName
            . DIRECTORY_SEPARATOR
            . 'Config';

        if (! is_dir($configDir)) {
            return [];
        }

        return array_map(
            fn($file) => pathinfo($file, PATHINFO_FILENAME),
            File::glob($configDir . DIRECTORY_SEPARATOR . '*.php')
        );
    }

    /**
     * 保存模块配置
     *
     * @param string $moduleName 模块名称
     * @param string $configFile 配置文件名
     * @param array $config 配置数据
     * @return bool
     */
    public static function save(string $moduleName, string $configFile, array $config): bool
    {
        $configDir = config('modules.path', base_path('Modules'))
            . DIRECTORY_SEPARATOR
            . $moduleName
            . DIRECTORY_SEPARATOR
            . 'Config';

        // 确保配置目录存在
        if (! is_dir($configDir)) {
            File::makeDirectory($configDir, 0755, true);
        }

        $configPath = $configDir . DIRECTORY_SEPARATOR . $configFile . '.php';
        $content = "<?php\n\nreturn " . var_export($config, true) . ";\n";

        return File::put($configPath, $content) !== false;
    }
}
