<?php

declare(strict_types=1);

namespace zxf\Modules\Support;

use Illuminate\Support\Str;
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Facades\Module;

/**
 * 迁移路径辅助工具
 * 为多个迁移命令提供共享的路径处理逻辑，
 * 避免代码重复，统一路径计算行为。
 * 使用场景：
 * - MigrateCommand
 * - MigrateRefreshCommand
 * - MigrateResetCommand
 * - MigrateStatusCommand
 */
trait MigrationPathHelper
{
    /**
     * 计算模块迁移文件的扫描目录
     * 根据命令选项决定在哪些目录中查找迁移文件：
     * - --path：仅扫描指定的相对路径
     * - --only-module：仅扫描指定模块的目录
     * - 默认：扫描所有模块的迁移目录
     *
     * @return array<string> 迁移文件目录列表
     */
    protected function getMigrationPaths(): array
    {
        // 问题有二：
        //  1) 本包所有迁移命令（Migrate / Reset / Rollback / Status）**都没有**
        //     定义 --only-module 选项。Symfony 的 option() 遇到未定义选项会抛
        //     InvalidArgumentException: The "--only-module" option does not exist.
        //     一旦有人调用本方法就会直接崩。
        //  2) getMigrationPaths() 在包内**没有任何调用点**（死代码），
        //     真正的 --path 处理散落在各命令里，导致行为不一致
        //     （例如 MigrateCommand 曾把 --path 放进模块循环重复执行 N 次）。
        //
        // 现在：先安全读取选项（仅在命令确实声明了该选项时才用），
        // 保证本方法随时可被第三方模块命令复用而不炸。
        $path = $this->safeOption('path');
        $onlyModule = $this->safeOption('only-module');

        if (! empty($path)) {
            return [$path];
        }

        if (! empty($onlyModule)) {
            if (! method_exists($this, 'resolveModule')) {
                return [];
            }

            $module = $this->resolveModule();
            if (! $module) {
                return [];
            }

            return [$module->getPath('Database/Migrations')];
        }

        // 扫描所有模块的迁移路径
        $paths = [];
        foreach (Module::allEnabled() as $module) {
            $migrationPath = $module->getPath('Database/Migrations');
            if (is_dir($migrationPath)) {
                $paths[] = $migrationPath;
            }
        }

        return $paths;
    }

    /**
     * 安全读取命令选项
     *
     * 本 trait 被多个命令共用，而各命令声明的选项并不一致。
     * 直接调用 $this->option($key) 在选项未声明时会抛 InvalidArgumentException，
     * 因此统一走这里：未声明则返回 $default。
     */
    protected function safeOption(string $key, mixed $default = null): mixed
    {
        if (! method_exists($this, 'option')) {
            return $default;
        }

        try {
            return $this->option($key);
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * 将绝对路径转换为相对于项目根目录的相对路径
     * 各命令不要重复写 str_replace(base_path() . DIRECTORY_SEPARATOR, '', ...)：
     * 那在路径中重复出现 base_path 组件时会替换出错。
     * 这里用 Str::after() 确保只截取一次。
     * 示例：
     *   /var/www/project/Modules/Blog/Database/Migrations/xxx.php
     *   → Modules/Blog/Database/Migrations/xxx.php
     *
     * @param  string  $absolutePath  绝对路径
     * @return string 相对路径
     */
    protected function getRelativePath(string $absolutePath): string
    {
        $basePath = base_path().DIRECTORY_SEPARATOR;

        if (str_starts_with($absolutePath, $basePath)) {
            return Str::after($absolutePath, $basePath);
        }

        return $absolutePath;
    }

    /**
     * 解析模块实例
     * 从命令参数中获取模块名称并解析为模块实例。
     * 提供统一的模块解析逻辑，各子类无需重复实现。
     */
    protected function resolveModule(): ?ModuleInterface
    {
        $moduleName = $this->argument('module');

        if (empty($moduleName)) {
            return null;
        }

        return Module::find(Str::studly($moduleName));
    }

    /**
     * 全模块模式下对 --seeder 选项的提示
     * 全模块迁移/刷新/清空时无法为所有模块共用同一个 Seeder 类名，
     * 因此忽略 --seeder 并提示用户改为针对单个模块运行 module:seed。
     *
     * @param  string|null  $seeder  用户传入的 --seeder 值
     */
    protected function warnSeederIgnoredForAllModules(?string $seeder): void
    {
        if (! $seeder) {
            return;
        }

        $this->components->warn('⚠ 全模块模式不支持 --seeder 选项，已忽略。');
        $this->components->warn('  提示: 使用 module:seed <ModuleName> --class='.$seeder.' 单独运行指定 Seeder。');
    }
}
