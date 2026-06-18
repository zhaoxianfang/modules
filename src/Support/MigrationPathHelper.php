<?php

declare(strict_types=1);

namespace zxf\Modules\Support;

use Illuminate\Support\Str;

/**
 * 迁移路径辅助工具
 *
 * 为多个迁移命令提供共享的路径处理逻辑，
 * 避免代码重复，统一路径计算行为。
 *
 * 使用场景：
 * - MigrateCommand
 * - MigrateRefreshCommand
 * - MigrateResetCommand
 * - MigrateStatusCommand
 *
 * @package zxf\Modules\Support
 */
trait MigrationPathHelper
{
    /**
     * 计算模块迁移文件的扫描目录
     *
     * 根据命令选项决定在哪些目录中查找迁移文件：
     * - --path：仅扫描指定的相对路径
     * - --only-module：仅扫描指定模块的目录
     * - 默认：扫描所有模块的迁移目录
     *
     * @return array<string> 迁移文件目录列表
     */
    protected function getMigrationPaths(): array
    {
        $path = $this->option('path');
        $onlyModule = $this->option('only-module');

        if (! empty($path)) {
            return [$path];
        }

        if (! empty($onlyModule)) {
            $module = $this->resolveModule();
            if (! $module) {
                return [];
            }
            return [$module->getPath('Database/Migrations')];
        }

        // 扫描所有模块的迁移路径
        $paths = [];
        foreach (\zxf\Modules\Facades\Module::allEnabled() as $module) {
            $migrationPath = $module->getPath('Database/Migrations');
            if (is_dir($migrationPath)) {
                $paths[] = $migrationPath;
            }
        }

        return $paths;
    }

    /**
     * 将绝对路径转换为相对于项目根目录的相对路径
     *
     * 替代原先在各命令中重复的 str_replace(base_path() . DIRECTORY_SEPARATOR, '', ...) 实现，
     * 使用 Str::after() 确保只截取一次，避免路径中重复出现 base_path 组件时的替换错误。
     *
     * 示例：
     *   /var/www/project/Modules/Blog/Database/Migrations/xxx.php
     *   → Modules/Blog/Database/Migrations/xxx.php
     *
     * @param string $absolutePath 绝对路径
     * @return string 相对路径
     */
    protected function getRelativePath(string $absolutePath): string
    {
        $basePath = base_path() . DIRECTORY_SEPARATOR;

        if (str_starts_with($absolutePath, $basePath)) {
            return Str::after($absolutePath, $basePath);
        }

        return $absolutePath;
    }

    /**
     * 解析模块实例
     *
     * 从命令参数中获取模块名称并解析为模块实例。
     * 提供统一的模块解析逻辑，各子类无需重复实现。
     *
     * @return \zxf\Modules\Contracts\ModuleInterface|null
     */
    protected function resolveModule(): ?\zxf\Modules\Contracts\ModuleInterface
    {
        $moduleName = $this->argument('module');

        if (empty($moduleName)) {
            return null;
        }

        return \zxf\Modules\Facades\Module::find(\Illuminate\Support\Str::studly($moduleName));
    }
}
