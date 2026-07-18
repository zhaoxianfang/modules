<?php

declare(strict_types=1);

/**
 * ============================================================================
 * 模块系统 - 核心助手函数集
 * ============================================================================
 *
 * 本文件提供完整的模块系统操作API，设计原则：
 * - 最小化框架依赖：通过 ModuleContext 解析器抽象框架依赖
 * - 静态缓存：高频调用结果缓存，提升性能
 * - 防御性编程：所有函数包含异常捕获
 * - 类型安全：严格类型声明和返回类型
 *
 * @package   zxf\Modules
 * @version   4.0.0
 * @requires  PHP 8.3+
 */

use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Contracts\RepositoryInterface;
use zxf\Modules\Support\ModuleContext;
use zxf\Modules\Support\StubGenerator;

// ============================================================================
//  一、模块基本信息与检测
// ============================================================================

if (! function_exists('module_name')) {
    /**
     * 获取当前模块名称
     *
     * 自动检测当前请求或代码所在的模块上下文。
     *
     * @param bool $toLower        是否返回小写蛇形命名
     * @param bool $requestModule  路由检测模式（true: 路由检测, false: 文件检测）
     * @return string 模块名称，或 'App'/'Command'
     */
    function module_name(bool $toLower = false, bool $requestModule = true): string
    {
        return ModuleContext::detectModuleName($toLower, $requestModule);
    }
}

if (! function_exists('module')) {
    /**
     * 获取模块实例或模块仓库
     *
     * @param string|null $module 模块名称（不传返回仓库，传模块名可能返回 null 表示模块不存在）
     * @return ModuleInterface|RepositoryInterface|null
     */
    function module(?string $module = null): ModuleInterface|RepositoryInterface|null
    {
        /** @var RepositoryInterface $repository */
        static $repository = null;
        $repository ??= ModuleContext::getRepository();

        if ($module === null) {
            return $repository;
        }

        return $repository->find($module);
    }
}

if (! function_exists('modules')) {
    /**
     * 获取所有已注册的模块实例
     *
     * @return array<string, ModuleInterface>
     */
    function modules(): array
    {
        static $cache = null;
        return $cache ??= ModuleContext::getModules();
    }
}

if (! function_exists('module_exists')) {
    /**
     * 检查模块是否存在
     *
     * @param string $module 模块名称
     */
    function module_exists(string $module): bool
    {
        static $cache = [];
        return $cache[$module] ??= ModuleContext::getRepository()->has($module);
    }
}

if (! function_exists('module_enabled')) {
    /**
     * 检查模块是否启用
     *
     * @param string|null $module 模块名称（不传则检测当前模块）
     */
    function module_enabled(?string $module = null): bool
    {
        static $cache = [];
        $module ??= module_name(false, false);

        if (empty($module)) {
            return false;
        }

        return $cache[$module] ??= (function () use ($module): bool {
            try {
                return ModuleContext::getRepository()->find($module)?->isEnabled() ?? false;
            } catch (\Throwable) {
                return false;
            }
        })();
    }
}

// ============================================================================
//  二、模块路径操作
// ============================================================================

if (! function_exists('module_path')) {
    /**
     * 获取模块目录的完整路径
     *
     * @param string      $path   子路径
     * @param string|null $module 模块名称
     * @return string
     * @throws RuntimeException
     */
    function module_path(string $path = '', ?string $module = null): string
    {
        $module ??= module_name(false, false);

        if (empty($module)) {
            throw new RuntimeException('Cannot determine module name.');
        }

        return ModuleContext::getRepository()->getModulePath($module, $path);
    }
}

if (! function_exists('module_config_path')) {
    /**
     * 获取模块配置文件路径
     */
    function module_config_path(string $configFile = 'config.php', ?string $module = null): string
    {
        $module ??= module_name(false, false);

        if (empty($module)) {
            throw new RuntimeException('Cannot determine module name.');
        }

        return module_path('Config/' . $configFile, $module);
    }
}

if (! function_exists('module_routes_path')) {
    /**
     * 获取模块路由文件路径
     */
    function module_routes_path(string $route = 'web', ?string $module = null): string
    {
        $module ??= module_name(false, false);

        if (empty($module)) {
            throw new RuntimeException('Cannot determine module name.');
        }

        return module_path('Routes/' . $route . '.php', $module);
    }
}

if (! function_exists('module_migrations_path')) {
    /**
     * 获取模块迁移目录路径
     */
    function module_migrations_path(?string $module = null): string
    {
        $module ??= module_name(false, false);

        if (empty($module)) {
            throw new RuntimeException('Cannot determine module name.');
        }

        return module_path('Database/Migrations', $module);
    }
}

if (! function_exists('module_models_path')) {
    /**
     * 获取模块模型目录路径
     */
    function module_models_path(?string $module = null): string
    {
        $module ??= module_name(false, false);

        if (empty($module)) {
            throw new RuntimeException('Cannot determine module name.');
        }

        return module_path('Models', $module);
    }
}

if (! function_exists('module_controllers_path')) {
    /**
     * 获取模块控制器目录路径
     *
     * @param string      $controller 控制器类型（Web, Api, Admin 等）
     * @param string|null $module     模块名称
     */
    function module_controllers_path(string $controller = 'Web', ?string $module = null): string
    {
        $module ??= module_name(false, false);

        if (empty($module)) {
            throw new RuntimeException('Cannot determine module name.');
        }

        return module_path('Http/Controllers/' . ucfirst($controller), $module);
    }
}

if (! function_exists('module_views_path')) {
    /**
     * 获取模块视图目录路径
     */
    function module_views_path(?string $module = null): string
    {
        $module ??= module_name(false, false);

        if (empty($module)) {
            throw new RuntimeException('Cannot determine module name.');
        }

        return module_path('Resources/views', $module);
    }
}

if (! function_exists('module_trans_path')) {
    /**
     * 获取模块翻译目录路径
     */
    function module_trans_path(?string $module = null): string
    {
        $module ??= module_name(false, false);

        if (empty($module)) {
            throw new RuntimeException('Cannot determine module name.');
        }

        return module_path('Resources/lang', $module);
    }
}

// ============================================================================
//  三、模块配置管理
// ============================================================================

if (! function_exists('module_config')) {
    /**
     * 获取模块配置值（支持多级缓存）
     *
     * @param string      $key     配置键（如 'common' 或 'common.name'）
     * @param mixed       $default 默认值
     * @param string|null $module  模块名称
     * @return mixed
     */
    function module_config(string $key, mixed $default = null, ?string $module = null): mixed
    {
        static $cache = [];
        static $hitCount = 0;
        $useModule = $module ?? module_name(false, false);

        if (empty($useModule)) {
            return $default;
        }

        // 指定模块时严格验证
        if ($module !== null && ! module_exists($module)) {
            throw new RuntimeException("Module '{$module}' does not exist.");
        }

        $cacheKey = "{$useModule}:{$key}";

        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        // 限制缓存大小防止内存泄漏（每隔 100 次调用清理一次）
        if (++$hitCount > 100 && count($cache) > 200) {
            $cache = array_slice($cache, -50, null, true);
            $hitCount = 0;
        }

        $fullKey = strtolower($useModule) . '.' . $key;
        $value = ModuleContext::getConfig($fullKey, $default);

        return $cache[$cacheKey] = $value;
    }
}

if (! function_exists('module_get_config')) {
    /**
     * 获取模块配置文件完整数组
     *
     * @param string      $configFile 配置文件名
     * @param string|null $module     模块名称
     * @return array
     */
    function module_get_config(string $configFile = '', ?string $module = null): array
    {
        $module ??= module_name(false, false);

        if (empty($module) || empty($configFile)) {
            return [];
        }

        $data = ModuleContext::getConfig(strtolower($module) . '.' . $configFile, []);
        return is_array($data) ? $data : [];
    }
}

if (! function_exists('module_set_config')) {
    /**
     * 运行时设置模块配置值（非持久化）
     *
     * @param string      $configFile 配置文件名
     * @param string      $key        配置键
     * @param mixed       $value      配置值
     * @param string|null $module     模块名称
     */
    function module_set_config(string $configFile = '', string $key = '', mixed $value = null, ?string $module = null): void
    {
        $module ??= module_name(false, false);

        if (empty($module) || empty($configFile) || empty($key)) {
            return;
        }

        ModuleContext::setConfig(strtolower($module) . '.' . $configFile . '.' . $key, $value);
    }
}

if (! function_exists('module_has_config')) {
    /**
     * 检查模块配置项是否存在
     *
     * @param string      $configFile 配置文件名
     * @param string      $key        配置键（为空则检查整个文件）
     * @param string|null $module     模块名称
     */
    function module_has_config(string $configFile = '', string $key = '', ?string $module = null): bool
    {
        try {
            $module ??= module_name(false, false);

            if (empty($module)) {
                return false;
            }

            $data = ModuleContext::getConfig(strtolower($module) . '.' . $configFile, []);

            if (empty($key)) {
                return ! empty($data);
            }

            return is_array($data) && array_key_exists($key, $data);
        } catch (\Throwable) {
            return false;
        }
    }
}

if (! function_exists('module_config_files')) {
    /**
     * 获取模块的所有配置文件列表
     *
     * @param string|null $module 模块名称
     * @return array
     */
    function module_config_files(?string $module = null): array
    {
        try {
            $module ??= module_name(false, false);

            if (empty($module)) {
                return [];
            }

            $configPath = module_path('Config', $module);

            if (! is_dir($configPath)) {
                return [];
            }

            $files = glob($configPath . '/*.php');
            return $files === false ? [] : array_map('basename', $files);
        } catch (\Throwable) {
            return [];
        }
    }
}

// ============================================================================
//  四、命名空间与类名
// ============================================================================

if (! function_exists('module_namespace')) {
    /**
     * 获取模块 PHP 命名空间
     *
     * @param string|null $module 模块名称
     */
    function module_namespace(?string $module = null): string
    {
        $module ??= module_name(false, false);
        $defaultNs = ModuleContext::getConfig('modules.namespace', 'Modules');

        if (empty($module)) {
            return $defaultNs;
        }

        try {
            $moduleInstance = ModuleContext::getRepository()->find($module);
            return $moduleInstance?->getClassNamespace() ?? $defaultNs . '\\' . $module;
        } catch (\Throwable) {
            return $defaultNs . '\\' . $module;
        }
    }
}

if (! function_exists('module_class')) {
    /**
     * 构建模块内类的完整类名
     *
     * @param string      $class  相对类名
     * @param string|null $module 模块名称
     */
    function module_class(string $class = '', ?string $module = null): string
    {
        return module_namespace($module) . '\\' . $class;
    }
}

// ============================================================================
//  五、视图、路由与静态资源
// ============================================================================

if (! function_exists('module_view_path')) {
    /**
     * 获取模块视图命名空间路径
     *
     * @param string      $view   视图名称
     * @param string|null $module 模块名称
     */
    function module_view_path(string $view = '', ?string $module = null): string
    {
        $module ??= module_name(false, false) ?: 'default';
        return strtolower($module) . '::' . $view;
    }
}

if (! function_exists('module_view')) {
    /**
     * 返回模块视图实例
     *
     * @param string      $view   视图名称
     * @param array       $data   视图数据
     * @param string|null $module 模块名称
     * @return \Illuminate\Contracts\View\View
     */
    function module_view(string $view = '', array $data = [], ?string $module = null): \Illuminate\Contracts\View\View
    {
        $module ??= module_name(false, false) ?: 'default';
        return view(strtolower($module) . '::' . $view, $data);
    }
}

if (! function_exists('module_route_path')) {
    /**
     * 获取模块路由名称前缀
     *
     * @param string      $route  路由名称
     * @param string|null $module 模块名称
     */
    function module_route_path(string $route = '', ?string $module = null): string
    {
        $module ??= module_name(false, false) ?: 'default';
        $prefix = strtolower($module) . '.';
        return $route !== '' ? $prefix . $route : $prefix;
    }
}

if (! function_exists('module_route')) {
    /**
     * 生成模块路由 URL
     *
     * @param string      $route  路由名称
     * @param array       $params 路由参数
     * @param string|null $module 模块名称
     */
    function module_route(string $route = '', array $params = [], ?string $module = null): string
    {
        $module ??= module_name(false, false) ?: 'default';
        return route(strtolower($module) . '.' . $route, $params);
    }
}

if (! function_exists('module_url')) {
    /**
     * 生成模块 URL
     *
     * @param string      $path   路径
     * @param string|null $module 模块名称
     */
    function module_url(string $path = '', ?string $module = null): string
    {
        $module ??= module_name(false, false) ?: 'default';
        return url(strtolower($module) . '/' . ltrim($path, '/'));
    }
}

if (! function_exists('module_asset')) {
    /**
     * 生成模块静态资源 URL
     *
     * @param string      $asset  资源路径
     * @param string|null $module 模块名称
     */
    function module_asset(string $asset = '', ?string $module = null): string
    {
        $module ??= module_name(false, false) ?: 'default';
        return asset('modules/' . strtolower($module) . '/' . ltrim($asset, '/'));
    }
}

if (! function_exists('module_lang')) {
    /**
     * 获取模块翻译文本
     *
     * @param string      $key     翻译键
     * @param array       $replace 占位符替换
     * @param string|null $locale  语言环境
     * @param string|null $module  模块名称
     * @return string|array
     */
    function module_lang(string $key = '', array $replace = [], ?string $locale = null, ?string $module = null): string|array
    {
        $module ??= module_name(false, false) ?: 'default';
        return trans(strtolower($module) . '::' . $key, $replace, $locale);
    }
}

if (! function_exists('module_has_view')) {
    /**
     * 检查模块视图是否存在
     *
     * @param string      $view   视图名称
     * @param string|null $module 模块名称
     */
    function module_has_view(string $view = '', ?string $module = null): bool
    {
        try {
            $module ??= module_name(false, false);

            if (empty($module)) {
                return false;
            }

            return view()->exists(strtolower($module) . '::' . $view);
        } catch (\Throwable) {
            return false;
        }
    }
}

// ============================================================================
//  六、模块状态与枚举
// ============================================================================

if (! function_exists('module_enabled_modules')) {
    /**
     * 获取所有已启用的模块
     *
     * @return array<string, ModuleInterface>
     */
    function module_enabled_modules(): array
    {
        static $cache = null;
        return $cache ??= ModuleContext::getRepository()->allEnabled();
    }
}

if (! function_exists('module_disabled_modules')) {
    /**
     * 获取所有已禁用的模块
     *
     * @return array<string, ModuleInterface>
     */
    function module_disabled_modules(): array
    {
        static $cache = null;
        return $cache ??= ModuleContext::getRepository()->allDisabled();
    }
}

if (! function_exists('module_has_migration')) {
    /**
     * 检查模块是否存在指定迁移文件
     *
     * @param string      $migrationName 迁移文件名
     * @param string|null $module        模块名称
     */
    function module_has_migration(string $migrationName = '', ?string $module = null): bool
    {
        try {
            $module ??= module_name(false, false);

            if (empty($module) || empty($migrationName)) {
                return false;
            }

            $pattern = module_migrations_path($module) . '/*_' . $migrationName . '.php';
            $files = glob($pattern);
            return $files !== false && ! empty($files);
        } catch (\Throwable) {
            return false;
        }
    }
}

if (! function_exists('module_all_migrations')) {
    /**
     * 获取模块所有迁移文件
     *
     * @param string|null $module 模块名称
     * @return array
     */
    function module_all_migrations(?string $module = null): array
    {
        try {
            $module ??= module_name(false, false);

            if (empty($module)) {
                return [];
            }

            $path = module_migrations_path($module);

            if (! is_dir($path)) {
                return [];
            }

            $files = glob($path . '/*.php');
            return $files === false ? [] : array_map('basename', $files);
        } catch (\Throwable) {
            return [];
        }
    }
}

if (! function_exists('module_route_files')) {
    /**
     * 获取模块所有路由文件
     *
     * @param string|null $module 模块名称
     * @return array
     */
    function module_route_files(?string $module = null): array
    {
        try {
            $module ??= module_name(false, false);

            if (empty($module)) {
                return [];
            }

            $path = module_path('Routes', $module);

            if (! is_dir($path)) {
                return [];
            }

            $files = glob($path . '/*.php');
            return $files === false
                ? []
                : array_map(fn(string $f): string => pathinfo($f, PATHINFO_FILENAME), $files);
        } catch (\Throwable) {
            return [];
        }
    }
}

// ============================================================================
//  七、Stub 与工具
// ============================================================================

if (! function_exists('module_stub')) {
    /**
     * 创建模块 Stub 生成器实例
     *
     * @param string $module 模块名称
     */
    function module_stub(string $module): StubGenerator
    {
        return new StubGenerator($module);
    }
}

// ============================================================================
//  八、通用辅助函数
// ============================================================================

if (! function_exists('get_user_info')) {
    /**
     * 获取当前认证用户信息
     *
     * @param string|null $field 用户字段名（null 返回完整数组）
     * @return mixed
     */
    function get_user_info(?string $field = null): mixed
    {
        if (ModuleContext::runningInConsole()) {
            return null;
        }

        try {
            $authConfig = ModuleContext::getConfig('auth.guards', []);
            $user = null;

            foreach ($authConfig as $guard => $_) {
                if (auth($guard)->check()) {
                    $user = auth($guard)->user()?->toArray();
                    break;
                }
            }

            if (empty($user)) {
                return null;
            }

            if ($field === null) {
                return $user;
            }

            return $user[$field] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }
}

if (! function_exists('view_share')) {
    /**
     * 向所有视图共享数据
     *
     * @param string|array $key   变量名或关联数组
     * @param mixed        $value 变量值
     */
    function view_share(string|array $key, mixed $value = ''): void
    {
        try {
            if (is_array($key)) {
                foreach ($key as $k => $v) {
                    \Illuminate\Support\Facades\View::share($k, $v);
                }
            } else {
                \Illuminate\Support\Facades\View::share($key, $value);
            }
        } catch (\Throwable) {
            // 静默失败
        }
    }
}

if (! function_exists('get_view_share')) {
    /**
     * 获取已共享的视图数据
     *
     * @param string $key 变量名（为空返回全部）
     * @return mixed
     */
    function get_view_share(string $key = ''): mixed
    {
        try {
            $data = \Illuminate\Support\Facades\View::getShared();

            if ($key !== '') {
                return $data[$key] ?? null;
            }

            return $data;
        } catch (\Throwable) {
            return $key !== '' ? null : [];
        }
    }
}

if (! function_exists('view_exists')) {
    /**
     * 判断视图文件是否存在
     */
    function view_exists(string $view): bool
    {
        try {
            return \Illuminate\Support\Facades\View::exists($view);
        } catch (\Throwable) {
            return false;
        }
    }
}

if (! function_exists('source_local_website')) {
    /**
     * 判断请求来源地址是否来自本站
     *
     * @param string $returnType 返回类型: status|url|uri|prefix|all
     * @return bool|array|string|null
     */
    function source_local_website(string $returnType = 'all'): bool|array|string|null
    {
        try {
            $sessionUrl = session()->previousUrl();
            $previousUrl = url()->previous();
            $referer = ! empty($sessionUrl)
                ? $sessionUrl
                : (! empty($previousUrl) ? $previousUrl : request()->header('referer', ''));

            $isLocal = false;
            if (! empty($referer)) {
                $refererHost = parse_url($referer, PHP_URL_HOST) ?? '';
                $appHost = parse_url(ModuleContext::getConfig('app.url', ''), PHP_URL_HOST) ?? '';
                $isLocal = $refererHost === $appHost
                    || str_ends_with('.' . $refererHost, '.' . $appHost);
            }

            $uri = $uriPrefix = '';
            if ($isLocal) {
                $uri = parse_url($referer, PHP_URL_PATH) ?? '';
                $uriPrefix = explode('/', ltrim($uri, '/'))[0] ?? '';
            }

            return match ($returnType) {
                'status' => $isLocal,
                'url' => $isLocal ? $referer : '',
                'uri' => $isLocal ? $uri : '',
                'prefix' => $isLocal ? $uriPrefix : '',
                default => [
                    'local' => $isLocal,
                    'url' => $isLocal ? $referer : '',
                    'uri' => $isLocal ? $uri : '',
                    'prefix' => $isLocal ? $uriPrefix : '',
                ],
            };
        } catch (\Throwable) {
            return match ($returnType) {
                'status' => false,
                'all' => ['local' => false, 'url' => '', 'uri' => '', 'prefix' => ''],
                default => '',
            };
        }
    }
}

if (! function_exists('after_class_calling')) {
    /**
     * 在类方法调用前执行初始化方法（支持依赖注入）
     *
     * @param object $class  类实例
     * @param string $method 方法名
     * @param array  ...$args 参数
     */
    function after_class_calling(object $class, string $method = 'initialize', array ...$args): void
    {
        try {
            if (! is_object($class) || ! method_exists($class, $method)) {
                return;
            }

            $reflectionMethod = new \ReflectionMethod($class, $method);
            $paramsArgs = ! empty($args) ? reset($args) : [];
            $index = -1;

            $dependencies = array_map(function (\ReflectionParameter $parameter) use (&$index, $paramsArgs): mixed {
                $index++;
                $paramName = $parameter->getType()?->getName();

                // 类或可调用类型
                if (! empty($paramName) && (class_exists($paramName) || is_callable($paramName))) {
                    return $paramName;
                }

                $argIndex = $index + 1;

                // 使用传入的值
                if (! empty($paramsArgs[$index])) {
                    if (empty($paramName) || (function_exists('is_' . $paramName)
                        && call_user_func('is_' . $paramName, $paramsArgs[$index]))) {
                        return $paramsArgs[$index];
                    }
                    throw new \Exception("Parameter #{$argIndex} type mismatch: expected '{$paramName}'");
                }

                // 默认值
                if ($parameter->isDefaultValueAvailable()) {
                    return $parameter->getDefaultValue();
                }

                throw new \Exception("Parameter #{$argIndex} '\${$parameter->getName()}' cannot be empty");
            }, $reflectionMethod->getParameters());

            // 解析依赖注入
            $resolved = array_map(function (mixed $param): mixed {
                if (is_string($param) && class_exists($param)) {
                    return function_exists('app') ? app($param) : new $param();
                }
                return $param;
            }, $dependencies);

            $reflectionMethod->invokeArgs($class, $resolved);
        } catch (\ReflectionException) {
            // 静默失败
        } catch (\Throwable) {
            // 静默失败
        }
    }
}
