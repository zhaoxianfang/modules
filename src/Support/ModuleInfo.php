<?php

declare(strict_types=1);

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
        $config = $module->getModuleConfig();

        return [
            'name' => $module->getName(),
            'lower_name' => $module->getLowerName(),
            'camel_name' => $module->getCamelName(),
            'lower_camel_name' => $module->getLowerCamelName(),
            'path' => $module->getPath(),
            'namespace' => $module->getNamespace(),
            'enabled' => $module->isEnabled(),
            'priority' => $module->getPriority(),
            'description' => $module->getDescription(),
            'author' => $module->getAuthor(),
            'version' => $module->getVersion(),
            'aliases' => $module->getAliases(),
            'has_config' => self::hasConfig($module),
            'has_routes' => ! empty($module->getRouteFiles()),
            'has_views' => is_dir($module->getViewsPath()),
            'has_migrations' => self::hasMigrations($module),
            'has_seeders' => self::hasSeeders($module),
            'has_commands' => self::hasCommands($module),
            'service_provider' => $module->getServiceProviderClass(),
            'config_providers' => $module->getLaravelProviders(),
            'config_aliases' => $module->getLaravelAliases(),
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
     * 递归遍历模块目录计数，自动跳过 node_modules / vendor 等大目录，
     * 避免模块含前端依赖时统计极慢。
     *
     * @param ModuleInterface $module
     * @return int
     */
    public static function countFiles(ModuleInterface $module): int
    {
        $count = 0;
        foreach (self::walkModule($module) as $file) {
            $count++;
        }

        return $count;
    }

    /**
     * 获取模块大小
     *
     * 递归遍历模块目录累计文件体积，自动跳过 node_modules / vendor 等大目录，
     * 并对不可读目录做异常保护。
     *
     * @param ModuleInterface $module
     * @return string
     */
    public static function getSize(ModuleInterface $module): string
    {
        $size = 0;

        try {
            foreach (self::walkModule($module) as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (\Throwable $e) {
            // 目录不可读时降级为 0
            $size = 0;
        }

        return self::formatSize($size);
    }

    /**
     * 遍历模块目录（一次遍历，跳过体积庞大且与业务无关的依赖目录）
     *
     * 关键点：必须用 RecursiveFilterIterator 在「递归进入（descend）」前就拒绝
     * node_modules / vendor 等目录，否则 RecursiveIteratorIterator 仍会深入其
     * 内部遍历（仅在叶子节点检查无法阻止递归进入，会导致大目录被完整统计）。
     *
     * 同时承担异常保护：模块根目录不可读时返回空遍历器。
     *
     * @return \Iterator<\SplFileInfo>
     */
    protected static function walkModule(ModuleInterface $module): \Iterator
    {
        try {
            $inner = new \RecursiveDirectoryIterator($module->getPath(), \RecursiveDirectoryIterator::SKIP_DOTS);
        } catch (\Throwable $e) {
            return new \ArrayIterator();
        }

        $filter = new class($inner) extends \RecursiveFilterIterator {
            public function accept(): bool
            {
                if (! $this->current()->isDir()) {
                    return true;
                }

                // 在 descend 前拒绝大依赖目录，避免其被递归进入
                return ! in_array($this->current()->getFilename(), ['node_modules', 'vendor'], true);
            }
        };

        return new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::SELF_FIRST);
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
