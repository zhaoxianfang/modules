<?php

namespace zxf\Modules\Support;

use zxf\Modules\Contracts\ModuleInterface;

/**
 * 模块信息收集器
 *
 * 用于收集和展示模块的详细信息
 */
class ModuleInfo
{
    /**
     * 获取模块信息
     *
     * @param ModuleInterface $module
     * @return array
     */
    public static function getInfo(ModuleInterface $module): array
    {
        return [
            'name' => $module->getName(),
            'lower_name' => $module->getLowerName(),
            'camel_name' => $module->getCamelName(),
            'lower_camel_name' => $module->getLowerCamelName(),
            'path' => $module->getPath(),
            'namespace' => $module->getNamespace(),
            'enabled' => $module->isEnabled(),
            'has_config' => self::hasConfig($module),
            'has_routes' => ! empty($module->getRouteFiles()),
            'has_views' => is_dir($module->getViewsPath()),
            'has_migrations' => self::hasMigrations($module),
            'has_seeders' => self::hasSeeders($module),
            'has_commands' => self::hasCommands($module),
            'service_provider' => $module->getServiceProviderClass(),
            'route_files' => $module->getRouteFiles(),
            'files_count' => self::countFiles($module),
            'size' => self::getSize($module),
        ];
    }

    /**
     * 检查模块是否有配置文件
     *
     * @param ModuleInterface $module
     * @return bool
     */
    public static function hasConfig(ModuleInterface $module): bool
    {
        $configPath = $module->getConfigPath();
        return is_dir($configPath) && ! empty(glob($configPath . DIRECTORY_SEPARATOR . '*.php'));
    }

    /**
     * 检查模块是否有迁移文件
     *
     * @param ModuleInterface $module
     * @return bool
     */
    public static function hasMigrations(ModuleInterface $module): bool
    {
        $migrationPath = $module->getMigrationsPath();
        return is_dir($migrationPath) && ! empty(glob($migrationPath . DIRECTORY_SEPARATOR . '*.php'));
    }

    /**
     * 检查模块是否有数据填充器
     *
     * @param ModuleInterface $module
     * @return bool
     */
    public static function hasSeeders(ModuleInterface $module): bool
    {
        $seederPath = $module->getPath('Database/Seeders');
        return is_dir($seederPath) && ! empty(glob($seederPath . DIRECTORY_SEPARATOR . '*.php'));
    }

    /**
     * 检查模块是否有命令
     *
     * @param ModuleInterface $module
     * @return bool
     */
    public static function hasCommands(ModuleInterface $module): bool
    {
        $commandPath = $module->getCommandsPath();
        return is_dir($commandPath) && ! empty(glob($commandPath . DIRECTORY_SEPARATOR . '*.php'));
    }

    /**
     * 统计模块文件数量
     *
     * @param ModuleInterface $module
     * @return int
     */
    public static function countFiles(ModuleInterface $module): int
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($module->getPath(), \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        return iterator_count($iterator);
    }

    /**
     * 获取模块大小
     *
     * @param ModuleInterface $module
     * @return string
     */
    public static function getSize(ModuleInterface $module): string
    {
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($module->getPath(), \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return self::formatSize($size);
    }

    /**
     * 格式化大小
     *
     * @param int $bytes
     * @return string
     */
    protected static function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        foreach ($units as $unit) {
            if ($bytes < 1024) {
                return number_format($bytes, 2) . ' ' . $unit;
            }
            $bytes /= 1024;
        }

        return number_format($bytes, 2) . ' PB';
    }
}
