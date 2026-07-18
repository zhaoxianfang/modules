<?php

declare(strict_types=1);

namespace zxf\Modules\Support;

use Illuminate\Support\Facades\Route;
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
        try {
            $routeFiles = $module->getRouteFiles();

            if (empty($routeFiles)) {
                return;
            }

            $middlewareGroups = config('modules.middleware_groups', []);
            $routeConfig = config('modules.routes', []);

            $defaultFiles = $routeConfig['default_files'] ?? ['web', 'api', 'admin'];

            // 加载默认路由文件和自定义路由文件
            $filesToLoad = array_unique(array_merge($defaultFiles, $routeFiles));

            foreach ($filesToLoad as $routeFile) {
                try {
                    $routePath = $module->getRoutesPath() . DIRECTORY_SEPARATOR . $routeFile . '.php';

                    if (! file_exists($routePath)) {
                        continue;
                    }

                    // 获取中间件组
                    $middleware = $middlewareGroups[$routeFile] ?? [];

                    // 构建路由组：仅设置中间件。
                    // 注意：Laravel 9+ 已移除路由组的隐式控制器命名空间，
                    // 模块路由文件统一使用完整类名（FQCN）引用控制器
                    // （如 [Web\BlogController::class, 'index']），因此无需再设置 namespace。
                    $routeBuilder = Route::middleware($middleware);

                    // 加载路由文件
                    $routeBuilder->group(function () use ($routePath) {
                        require $routePath;
                    });
                } catch (\Throwable $e) {
                    // 单个路由文件加载失败不影响其他路由
                    if (function_exists('logger')) {
                        logger()->warning("加载路由文件失败: {$module->getName()}/{$routeFile}", [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        } catch (\Throwable) {
            if (function_exists('logger')) {
                logger()->error("加载模块路由失败: {$module->getName()}");
            }
        }
    }

    /**
     * 获取路由文件的中间件
     *
     * @param string $routeFile
     * @return array
     */
    public static function getMiddleware(string $routeFile): array
    {
        try {
            $middlewareGroups = config('modules.middleware_groups', []);

            return $middlewareGroups[$routeFile] ?? [];
        } catch (\Throwable) {
            return [];
        }
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
        try {
            return $module->hasRoute($routeFile);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 获取模块所有路由文件
     *
     * @param ModuleInterface $module
     * @return array
     */
    public static function getRouteFiles(ModuleInterface $module): array
    {
        try {
            return $module->getRouteFiles();
        } catch (\Throwable) {
            return [];
        }
    }
}
