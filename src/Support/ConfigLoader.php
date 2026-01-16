<?php

namespace zxf\Modules\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * 配置加载器类
 *
 * 负责加载和管理模块配置文件
 * 支持动态加载当前模块配置文件
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
        $modulePath = config('modules.path', base_path('Modules'));
        $configPath = $modulePath . DIRECTORY_SEPARATOR . $moduleName . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . $configFile . '.php';

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

        if (! $moduleName) {
            return [];
        }

        return self::load($moduleName, $configFile);
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
    public static function get(string $moduleName, string $configFile, string $key, $default = null)
    {
        $config = self::load($moduleName, $configFile);

        if (isset($config[$key])) {
            return $config[$key];
        }

        return $default;
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
    public static function getCurrent(string $configFile, string $key, $default = null)
    {
        $config = self::loadCurrent($configFile);

        if (isset($config[$key])) {
            return $config[$key];
        }

        return $default;
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
        $config = self::load($moduleName, $configFile);

        return isset($config[$key]);
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
        $config = self::loadCurrent($configFile);

        return isset($config[$key]);
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
     * 检测当前模块
     *
     * 通过调用栈自动检测当前代码所在的模块
     *
     * @return string|null
     */
    protected static function detectCurrentModule(): ?string
    {
        $modulePath = config('modules.path', base_path('Modules'));
        $modulePath = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $modulePath);

        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);

        foreach ($backtrace as $trace) {
            if (! isset($trace['file']) || ! is_string($trace['file'])) {
                continue;
            }

            $filePath = $trace['file'];

            if (strpos($filePath, $modulePath) !== false) {
                $pattern = '/' . preg_quote($modulePath, '/') . preg_quote(DIRECTORY_SEPARATOR, '/') . '([^' . preg_quote(DIRECTORY_SEPARATOR, '/') . ']+)/';
                if (preg_match($pattern, $filePath, $matches)) {
                    return $matches[1];
                }
            }
        }

        return null;
    }

    /**
     * 获取模块配置文件路径
     *
     * @param string $moduleName 模块名称
     * @param string $configFile 配置文件名
     * @return string|null
     */
    public static function getConfigPath(string $moduleName, string $configFile): ?string
    {
        $modulePath = config('modules.path', base_path('Modules'));
        $configPath = $modulePath . DIRECTORY_SEPARATOR . $moduleName . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . $configFile . '.php';

        return file_exists($configPath) ? $configPath : null;
    }

    /**
     * 获取模块所有配置文件
     *
     * @param string $moduleName 模块名称
     * @return array
     */
    public static function getConfigFiles(string $moduleName): array
    {
        $modulePath = config('modules.path', base_path('Modules'));
        $configDir = $modulePath . DIRECTORY_SEPARATOR . $moduleName . DIRECTORY_SEPARATOR . 'Config';

        if (! is_dir($configDir)) {
            return [];
        }

        $files = File::glob($configDir . DIRECTORY_SEPARATOR . '*.php');
        $configFiles = [];

        foreach ($files as $file) {
            $configFiles[] = pathinfo($file, PATHINFO_FILENAME);
        }

        return $configFiles;
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
        $modulePath = config('modules.path', base_path('Modules'));
        $configDir = $modulePath . DIRECTORY_SEPARATOR . $moduleName . DIRECTORY_SEPARATOR . 'Config';

        // 确保配置目录存在
        if (! is_dir($configDir)) {
            File::makeDirectory($configDir, 0755, true);
        }

        $configPath = $configDir . DIRECTORY_SEPARATOR . $configFile . '.php';

        // 生成配置文件内容
        $content = "<?php\n\nreturn " . var_export($config, true) . ";\n";

        return File::put($configPath, $content) !== false;
    }
}
