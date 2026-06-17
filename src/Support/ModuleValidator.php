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
        $providerClass = $module->getServiceProviderClass();
        if (! $providerClass) {
            $errors[] = '缺少主服务提供者文件';
        } elseif (! class_exists($providerClass)) {
            $errors[] = '服务提供者类不存在';
        }

        // 检查配置文件
        $configValidate = self::validateConfig($module);
        if(!$configValidate['valid']){
            // 配置文件异常
            $errors[] = $configValidate['error'];
        }

        // 检查控制器目录
        if (! is_dir($module->getControllersPath())) {
            $warnings[] = '缺少控制器目录 (Http/Controllers)';
        }

        // 检查视图目录
        if (! is_dir($module->getViewsPath())) {
            $warnings[] = '缺少视图目录 (Resources/views)';
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
                'error' => '模块配置文件不存在，请创建 Config/' . $module->getLowerName() . '.php 或 Config/config.php',
            ];
        }

        $config = require $configFile;

        if (! is_array($config)) {
            return [
                'valid' => false,
                'error' => '配置文件必须返回一个数组: ' . $file,
            ];
        }

        $error = '';

        if (! isset($config['enabled'])) {
            $error = '配置文件缺少 enabled 键';
        } elseif (! is_bool($config['enabled'])) {
            $error = 'enabled 键必须是布尔值';
        }

        return [
            'valid' => empty($error),
            'error' => $error ? $error . ': ' . $file : '',
        ];
    }

    /**
     * 验证模块路由
     *
     * @param ModuleInterface $module
     * @return array
     */
    public static function validateRoutes(ModuleInterface $module): array
    {
        $routeFiles = $module->getRouteFiles();

        if (empty($routeFiles)) {
            return [
                'valid' => false,
                'warnings' => ['没有路由文件'],
            ];
        }

        $errors = [];

        foreach ($routeFiles as $routeFile) {
            $routePath = $module->getRoutesPath() . DIRECTORY_SEPARATOR . $routeFile . '.php';

            if (! file_exists($routePath)) {
                $errors[] = "路由文件 {$routeFile}.php 不存在";
                continue;
            }

            // 检查路由文件是否可读（避免 eval 安全风险）
            $content = @file_get_contents($routePath);
            if ($content === false) {
                $errors[] = "路由文件 {$routeFile}.php 无法读取";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}
