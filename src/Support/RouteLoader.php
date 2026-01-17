<?php

namespace zxf\Modules\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use zxf\Modules\Contracts\ModuleInterface;

/**
 * 路由加载器类
 *
 * 负责加载和管理模块路由
 * 支持灵活的路由配置，包括中间件组、控制器命名空间映射等
 *
 * 注意：路由文件内部已经包含了路由组声明（prefix 和 name），
 * RouteLoader 仅负责设置中间件和控制器命名空间，不再重复添加前缀。
 */
class RouteLoader
{
    /**
     * 加载模块路由
     *
     * 每个路由文件会自动对应到不同的控制器子目录：
     * - web.php -> Http\Controllers\Web
     * - api.php -> Http\Controllers\Api
     * - admin.php -> Http\Controllers\Admin
     * - custom.php -> Http\Controllers\Custom
     *
     * 注意：路由文件内部已包含路由组声明（prefix 和 name），
     * RouteLoader 仅添加中间件和控制器命名空间。
     *
     * @param ModuleInterface $module
     * @return void
     */
    public static function load(ModuleInterface $module): void
    {
        $routeFiles = $module->getRouteFiles();

        if (empty($routeFiles)) {
            return;
        }

        $middlewareGroups = config('modules.middleware_groups', []);
        $controllerNamespaces = config('modules.route_controller_namespaces', []);
        $routeConfig = config('modules.routes', []);

        $defaultFiles = $routeConfig['default_files'] ?? ['web', 'api', 'admin'];

        // 加载默认路由文件和自定义路由文件
        $filesToLoad = array_unique(array_merge($defaultFiles, $routeFiles));

        foreach ($filesToLoad as $routeFile) {
            $routePath = $module->getRoutesPath() . DIRECTORY_SEPARATOR . $routeFile . '.php';

            if (! file_exists($routePath)) {
                continue;
            }

            // 获取中间件组
            $middleware = $middlewareGroups[$routeFile] ?? [];

            // 获取控制器命名空间：优先使用配置，否则使用路由文件名（首字母大写）
            $controllerNamespace = $controllerNamespaces[$routeFile] ?? Str::studly($routeFile);

            // 构建完整控制器命名空间
            $fullNamespace = $module->getClassNamespace() . '\\Http\\Controllers\\' . $controllerNamespace;

            // 构建路由组：仅设置中间件和控制器命名空间
            // 路由文件内部已经包含了 prefix 和 name 的路由组声明
            $routeBuilder = Route::middleware($middleware);

            // 设置控制器命名空间
            $routeBuilder->namespace($fullNamespace);

            // 加载路由文件
            $routeBuilder->group(function () use ($routePath) {
                require $routePath;
            });
        }
    }

    /**
     * 获取控制器完整命名空间
     *
     * @param ModuleInterface $module
     * @param string $type
     * @return string|null
     */
    public static function getControllerNamespace(ModuleInterface $module, string $type): ?string
    {
        $controllerNamespaces = config('modules.route_controller_namespaces', []);
        $namespace = $controllerNamespaces[$type] ?? null;

        if (! $namespace) {
            return null;
        }

        return $module->getClassNamespace() . '\\Http\\Controllers\\' . $namespace;
    }

    /**
     * 获取路由文件的中间件
     *
     * @param string $routeFile
     * @return array
     */
    public static function getMiddleware(string $routeFile): array
    {
        $middlewareGroups = config('modules.middleware_groups', []);

        return $middlewareGroups[$routeFile] ?? [];
    }

    /**
     * 检查路由文件是否存在
     *
     * @param ModuleInterface $module
     * @param string $routeFile
     * @return bool
     */
    public static function hasRouteFile(ModuleInterface $module, string $routeFile): bool
    {
        return $module->hasRoute($routeFile);
    }

    /**
     * 获取模块所有路由文件
     *
     * @param ModuleInterface $module
     * @return array
     */
    public static function getRouteFiles(ModuleInterface $module): array
    {
        return $module->getRouteFiles();
    }
}
