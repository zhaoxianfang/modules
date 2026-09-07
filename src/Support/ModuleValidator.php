<?php

declare(strict_types=1);

namespace zxf\Modules\Support;

use zxf\Modules\Contracts\ModuleInterface;

/**
 * 模块验证器
 *
 * 用于验证模块的完整性和正确性
 */
class ModuleValidator
{
    /**
     * 验证模块
     *
     * @param ModuleInterface $module
     * @return array
     */
    public static function validate(ModuleInterface $module): array
    {
        $errors = [];
        $warnings = [];

        // 检查必需的目录
        if (! is_dir($module->getConfigPath())) {
            $errors[] = '缺少配置目录 (Config)';
        }

        if (! is_dir($module->getRoutesPath())) {
            $warnings[] = '缺少路由目录 (Routes)';
        }

        if (! is_dir($module->getProvidersPath())) {
            $errors[] = '缺少服务提供者目录 (Providers)';
        }

        // 检查服务提供者
        // 先以“文件是否存在”判定结构完整性（不依赖 composer 自动加载是否配置），
        // 避免因项目未配置 Modules\ 命名空间的 PSR-4 而误报“缺少主服务提供者文件”。
        $providerPath = $module->getProvidersPath();
        $hasProviderFile = is_dir($providerPath)
            && ! empty(glob($providerPath . DIRECTORY_SEPARATOR . '*ServiceProvider.php'));

        if (! $hasProviderFile) {
            $errors[] = '缺少主服务提供者文件 (Providers/*ServiceProvider.php)';
        } else {
            $providerClass = $module->getServiceProviderClass();
            if ($providerClass === null || ! class_exists($providerClass)) {
                $warnings[] = '服务提供者类无法被自动加载，请确认 composer.json 已配置 '
                    . $module->getNamespace() . '\\ 命名空间并运行 composer dump-autoload';
            }
        }

        // 检查配置文件
        $configValidate = self::validateConfig($module);
        if (! $configValidate['valid']) {
            // 配置文件异常（合并其错误明细）
            foreach ($configValidate['errors'] as $configError) {
                $errors[] = $configError;
            }
        }

        // 检查控制器目录
        if (! is_dir($module->getControllersPath())) {
            $warnings[] = '缺少控制器目录 (Http/Controllers)';
        }

        // 检查视图目录
        if (! is_dir($module->getViewsPath())) {
            $warnings[] = '缺少视图目录 (Resources/views)';
        }

        // 检查路由文件
        $routeValidation = self::validateRoutes($module);
        if (! $routeValidation['valid']) {
            foreach ($routeValidation['errors'] as $routeError) {
                $errors[] = $routeError;
            }
        }
        foreach ($routeValidation['warnings'] as $routeWarning) {
            $warnings[] = $routeWarning;
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * 验证模块配置文件
     *
     * 检查 Config/{lower_name}.php 或 Config/config.php 是否存在
     * 并验证必要的配置项
     *
     * @param ModuleInterface $module
     * @return array
     */
    public static function validateConfig(ModuleInterface $module): array
    {
        // 尝试找到配置文件
        $configPath = $module->getConfigPath();
        $file = $module->getLowerName() . '.php';
        $configFile = $configPath . DIRECTORY_SEPARATOR . $file;

        // 回退到 config.php
        if (! file_exists($configFile)) {
            $file = 'config.php';
            $configFile = $configPath . DIRECTORY_SEPARATOR . $file;
        }

        if (! file_exists($configFile)) {
            return [
                'valid' => false,
                'errors' => ['模块配置文件不存在，请创建 Config/' . $module->getLowerName() . '.php 或 Config/config.php'],
            ];
        }

        try {
            $config = require $configFile;
        } catch (\Throwable $e) {
            return [
                'valid' => false,
                'errors' => ['配置文件加载失败 (' . $configFile . '): ' . $e->getMessage()],
            ];
        }

        if (! is_array($config)) {
            return [
                'valid' => false,
                'errors' => ['配置文件必须返回一个数组: ' . $file],
            ];
        }

        $errors = [];

        if (! isset($config['enabled'])) {
            $errors[] = '配置文件缺少 enabled 键';
        } elseif (! is_bool($config['enabled'])) {
            $errors[] = 'enabled 键必须是布尔值';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * 验证模块路由文件
     *
     * 检查每个路由文件是否真实存在且可读，避免运行时 require 失败。
     *
     * @param ModuleInterface $module
     * @return array{valid: bool, errors: array<int, string>, warnings: array<int, string>}
     */
    public static function validateRoutes(ModuleInterface $module): array
    {
        $routeFiles = $module->getRouteFiles();

        $errors = [];

        if (empty($routeFiles)) {
            return [
                'valid' => true,
                'errors' => $errors,
                'warnings' => ['没有路由文件'],
            ];
        }

        foreach ($routeFiles as $routeFile) {
            $routePath = $module->getRoutesPath() . DIRECTORY_SEPARATOR . $routeFile . '.php';

            if (! file_exists($routePath)) {
                $errors[] = "路由文件 {$routeFile}.php 不存在";
                continue;
            }

            // 检查路由文件是否可读（避免运行时 require 失败）
            $content = @file_get_contents($routePath);
            if ($content === false) {
                $errors[] = "路由文件 {$routeFile}.php 无法读取";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => [],
        ];
    }
}
