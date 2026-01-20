<?php

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

                    // 自动检测控制器命名空间
                    $controllerNamespace = self::autoDetectControllerNamespace($module, $routeFile);

                    // 构建完整控制器命名空间
                    $fullNamespace = $module->getClassNamespace() . '\\Http\\Controllers' . $controllerNamespace;

                    // 构建路由组：仅设置中间件和控制器命名空间
                    // 路由文件内部已经包含了 prefix 和 name 的路由组声明
                    $routeBuilder = Route::middleware($middleware);

                    // 设置控制器命名空间（如果检测到了）
                    if (! empty($controllerNamespace)) {
                        $routeBuilder->namespace($fullNamespace);
                    }

                    // 加载路由文件
                    $routeBuilder->group(function () use ($routePath) {
                        require $routePath;
                    });
                } catch (\Throwable $e) {
                    // 单个路由文件加载失败不影响其他路由
                    logger()->warning("加载路由文件失败: {$module->getName()}/{$routeFile}", [
                        'error' => $e->getMessage(),
                    ]);
                } catch (\Throwable) {
                    // 单个路由文件加载失败不影响其他路由
                }
            }
        } catch (\Throwable) {
            logger()->error("加载模块路由失败: {$module->getName()}");
        }
    }

    /**
     * 自动检测控制器命名空间
     *
     * 根据路由文件名自动检测对应的控制器命名空间
     * 检查对应的控制器子目录是否存在
     *
     * @param ModuleInterface $module
     * @param string $routeFile
     * @return string 控制器命名空间（如 \Web 或 ''）
     */
    protected static function autoDetectControllerNamespace(ModuleInterface $module, string $routeFile): string
    {
        try {
            // 标准化路由文件名
            $standardNames = ['web', 'api', 'admin'];

            if (in_array(strtolower($routeFile), $standardNames)) {
                // 标准路由文件名，检查对应的控制器子目录
                $subNamespace = ucfirst($routeFile);
                $controllerPath = $module->getPath('Http/Controllers/' . $subNamespace);

                if (is_dir($controllerPath)) {
                    return '\\' . $subNamespace;
                }

                // 如果子目录不存在，返回空字符串（不应用特定命名空间）
                return '';
            }

            // 非标准路由文件名，使用首字母大写的文件名
            $subNamespace = ucfirst($routeFile);
            $controllerPath = $module->getPath('Http/Controllers/' . $subNamespace);

            if (is_dir($controllerPath)) {
                return '\\' . $subNamespace;
            }

            return '';
        } catch (\Throwable) {
            return '';
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
        try {
            $namespace = self::autoDetectControllerNamespace($module, $type);

            if (empty($namespace)) {
                return null;
            }

            return $module->getClassNamespace() . '\\Http\\Controllers' . $namespace;
        } catch (\Throwable) {
            return null;
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
