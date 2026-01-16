<?php

use Illuminate\Support\Str;

if (! function_exists('module_path')) {
    /**
     * 获取模块目录的路径。
     *
     * @param  string  $module  模块名称
     * @param  string  $path    子路径（可选）
     * @return string
     */
    function module_path(string $module, string $path = ''): string
    {
        $modulesPath = config('modules.path', base_path('modules'));
        $modulePath = $modulesPath . '/' . Str::studly($module);

        return $modulePath . ($path ? '/' . ltrim($path, '/') : '');
    }
}

if (! function_exists('module_config')) {
    /**
     * 获取模块配置值。
     *
     * @param  string  $module   模块名称
     * @param  string  $key      配置键
     * @param  mixed   $default  默认值（可选）
     * @return mixed
     */
    function module_config(string $module, string $key, $default = null)
    {
        $moduleSnake = Str::snake($module);
        $configKey = "{$moduleSnake}.{$key}";

        return config($configKey, $default);
    }
}

if (! function_exists('module_enabled')) {
    /**
     * 检查模块是否启用。
     *
     * @param  string  $module  模块名称
     * @return bool
     */
    function module_enabled(string $module): bool
    {
        return app('modules')->enabled()->has($module);
    }
}

if (! function_exists('module_disabled')) {
    /**
     * 检查模块是否禁用。
     *
     * @param  string  $module  模块名称
     * @return bool
     */
    function module_disabled(string $module): bool
    {
        return ! module_enabled($module);
    }
}

if (! function_exists('module_asset')) {
    /**
     * 生成模块的资源 URL。
     *
     * @param  string  $module  模块名称
     * @param  string  $path    资源路径
     * @return string
     */
    function module_asset(string $module, string $path): string
    {
        $moduleSnake = Str::snake($module);
        $assetPath = "modules/{$moduleSnake}/" . ltrim($path, '/');
        
        // 检查是否配置了自定义资产 URL
        $assetUrl = config('modules.asset_url');
        if ($assetUrl) {
            return rtrim($assetUrl, '/') . '/' . $assetPath;
        }

        return asset($assetPath);
    }
}

if (! function_exists('module_view')) {
    /**
     * 获取模块视图。
     *
     * @param  string  $module  模块名称
     * @param  string  $view    视图名称
     * @param  array   $data    视图数据（可选）
     * @return \Illuminate\Contracts\View\View
     */
    function module_view(string $module, string $view, array $data = [])
    {
        $moduleSnake = Str::snake($module);
        $viewName = "{$moduleSnake}::{$view}";

        return view($viewName, $data);
    }
}

if (! function_exists('module_route')) {
    /**
     * 生成模块路由的 URL。
     *
     * @param  string  $module       模块名称
     * @param  string  $name         路由名称
     * @param  array   $parameters   路由参数（可选）
     * @param  bool    $absolute     是否生成绝对 URL（可选，默认为 true）
     * @return string
     */
    function module_route(string $module, string $name, array $parameters = [], bool $absolute = true): string
    {
        $moduleSnake = Str::snake($module);
        $routeName = "{$moduleSnake}.{$name}";

        return route($routeName, $parameters, $absolute);
    }
}

if (! function_exists('module_exists')) {
    /**
     * 检查模块是否存在。
     *
     * @param  string  $module  模块名称
     * @return bool
     */
    function module_exists(string $module): bool
    {
        return app('modules')->exists($module);
    }
}

if (! function_exists('module_config_path')) {
    /**
     * 获取模块配置文件的路径。
     *
     * @param  string  $module  模块名称
     * @param  string  $file    配置文件名（不含扩展名）
     * @return string
     */
    function module_config_path(string $module, string $file): string
    {
        return module_path($module, "config/{$file}.php");
    }
}

if (! function_exists('module_migration_path')) {
    /**
     * 获取模块迁移文件的路径。
     *
     * @param  string  $module  模块名称
     * @param  string  $file    迁移文件名（可选）
     * @return string
     */
    function module_migration_path(string $module, string $file = ''): string
    {
        return module_path($module, 'database/migrations' . ($file ? '/' . $file : ''));
    }
}

if (! function_exists('module_view_path')) {
    /**
     * 获取模块视图文件的路径。
     *
     * @param  string  $module  模块名称
     * @param  string  $file    视图文件路径（可选）
     * @return string
     */
    function module_view_path(string $module, string $file = ''): string
    {
        return module_path($module, 'resources/views' . ($file ? '/' . $file : ''));
    }
}

if (! function_exists('module_asset_path')) {
    /**
     * 获取模块资源文件的文件系统路径。
     *
     * @param  string  $module  模块名称
     * @param  string  $path    资源路径（可选）
     * @return string
     */
    function module_asset_path(string $module, string $path = ''): string
    {
        return module_path($module, 'resources/assets' . ($path ? '/' . $path : ''));
    }
}

if (! function_exists('module_namespace')) {
    /**
     * 获取模块的命名空间。
     *
     * @param  string  $module  模块名称
     * @return string
     */
    function module_namespace(string $module): string
    {
        $namespace = config('modules.namespace', 'Modules');
        $studlyName = Str::studly($module);
        
        return "{$namespace}\\{$studlyName}";
    }
}

if (! function_exists('module_providers')) {
    /**
     * 获取模块的服务提供者列表。
     *
     * @param  string  $module  模块名称
     * @return array<class-string>
     */
    function module_providers(string $module): array
    {
        if (! module_exists($module)) {
            return [];
        }
        
        $moduleInstance = app('modules')->find($module);
        return $moduleInstance->getProviders();
    }
}

if (! function_exists('module_version')) {
    /**
     * 获取模块的版本号。
     *
     * @param  string  $module  模块名称
     * @return string
     */
    function module_version(string $module): string
    {
        if (! module_exists($module)) {
            return '0.0.0';
        }
        
        $moduleInstance = app('modules')->find($module);
        return $moduleInstance->getVersion();
    }
}