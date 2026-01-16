<?php

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
        if (! file_exists($module->getConfigPath() . DIRECTORY_SEPARATOR . 'config.php')) {
            $errors[] = '缺少主配置文件 (config.php)';
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
     * 验证模块配置
     *
     * @param ModuleInterface $module
     * @return array
     */
    public static function validateConfig(ModuleInterface $module): array
    {
        $configFile = $module->getConfigPath() . DIRECTORY_SEPARATOR . 'config.php';

        if (! file_exists($configFile)) {
            return [
                'valid' => false,
                'errors' => ['配置文件不存在'],
            ];
        }

        $config = require $configFile;

        if (! is_array($config)) {
            return [
                'valid' => false,
                'errors' => ['配置文件必须返回一个数组'],
            ];
        }

        $errors = [];

        if (! isset($config['enable'])) {
            $errors[] = '配置文件缺少 enable 键';
        } elseif (! is_bool($config['enable'])) {
            $errors[] = 'enable 键必须是布尔值';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
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

            // 检查路由文件语法
            $content = file_get_contents($routePath);
            if (! @eval('?>' . $content)) {
                $errors[] = "路由文件 {$routeFile}.php 语法错误";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}
