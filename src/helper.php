<?php

use Illuminate\Support\Str;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use zxf\Modules\Contracts\RepositoryInterface;
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Support\ConfigLoader;
use zxf\Modules\Support\StubGenerator;

/**
 * ============================================================================
 * 模块核心助手函数
 * ============================================================================
 *
 * 这些助手函数提供了简洁、高效的模块操作接口
 * 针对 Laravel 11+ 和 PHP 8.2+ 进行了深度优化
 *
 * 优化特性：
 * - 使用 PHP 8.2+ 新特性（null 合并运算符、只读属性、改进的类型声明）
 * - 请求级别缓存机制，避免重复计算
 * - 简化的异常处理（使用 \Throwable）
 * - 简洁的代码逻辑，减少不必要的判断
 *
 * 核心函数说明：
 * - module_name(): 通过文件路径精确检测当前模块，使用请求级别缓存
 * - module_config(): 智能配置读取，支持嵌套配置和当前模块自动检测
 * - module_path(): 获取模块路径，支持自动检测当前模块
 */

if (! function_exists('module_name')) {
    /**
     * 获取当前所在的模块名称
     *
     * 通过文件路径精确检测当前代码所在的模块，无需传递参数
     * PHP 8.2+ 优化：使用只读属性和改进的缓存机制
     *
     * @return string|null 返回模块名称（StudlyCase），如果在模块外则返回 null
     *
     * @example
     * // 在 Blog/Http/Controllers/PostController.php 中调用
     * $moduleName = module_name(); // 'Blog'
     */
    function module_name(): ?string
    {
        // 请求级别缓存 - 使用 null 作为未解析标记
        static $result = null;
        static $resolved = false;

        // 已解析，直接返回
        if ($resolved) {
            return $result;
        }

        // 容器缓存检查（跨请求持久化）
        $cacheKey = 'modules.current_module_name';
        if (function_exists('app') && app()->bound($cacheKey)) {
            $result = app($cacheKey);
            $resolved = true;
            return $result;
        }

        // 获取并缓存模块路径配置
        static $modulePath = null;
        $modulePath ??= strtr(config('modules.path', base_path('Modules')), ['\\' => '/']);

        // 获取调用栈（优化深度）
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12);

        foreach ($backtrace as $trace) {
            $filePath = $trace['file'] ?? null;

            // 跳过无效路径和 vendor 目录
            if (! $filePath || ! is_string($filePath) || str_contains($filePath, 'vendor/')) {
                continue;
            }

            // 标准化路径
            $filePath = strtr($filePath, ['\\' => '/']);

            // 检查是否在模块路径下
            if (! str_starts_with($filePath, $modulePath . '/')) {
                continue;
            }

            // 提取模块名（优化字符串操作）
            $relativePath = substr($filePath, strlen($modulePath) + 1);
            $moduleName = Str::studly(explode('/', $relativePath, 2)[0] ?? '');

            // 验证模块是否存在
            if ($moduleName && module_exists($moduleName)) {
                $result = $moduleName;
                $resolved = true;

                // 容器缓存（请求级别）
                if (function_exists('app')) {
                    app()->instance($cacheKey, $moduleName);
                }

                return $moduleName;
            }
        }

        $resolved = true;
        return null;
    }
}

if (! function_exists('module_path')) {
    /**
     * 获取模块目录的路径
     *
     * PHP 8.2+ 优化：简化逻辑，缓存 Repository 实例
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @param  string      $path    子路径（可选）
     * @return string
     * @throws \RuntimeException 当无法确定模块时抛出异常
     *
     * @example
     * module_path('Blog', 'Models/Post.php')
     * module_path(null, 'Config/common.php') // 使用当前模块
     */
    function module_path(?string $module = null, string $path = ''): string
    {
        // 缓存 Repository 实例
        static $repository = null;
        $repository ??= App::make(RepositoryInterface::class);

        $module ??= module_name();

        if (empty($module)) {
            throw new \RuntimeException('无法确定模块名称，请传递明确的模块名或确保在模块内部调用');
        }

        return $repository->getModulePath($module, $path);
    }
}

if (! function_exists('module_config')) {
    /**
     * 获取模块配置值
     *
     * 支持两种用法：
     * 1. 传统方式：module_config('Blog', 'key', 'default') - 获取 Blog 模块的配置
     * 2. 智能方式：module_config('common.name', 'hello') - 获取当前模块的 Config/common.php 中 name 的值，默认为 hello
     *
     * 智能方式支持嵌套配置，如：module_config('settings.cache.enabled', true)
     * PHP 8.2+ 优化：改进的缓存策略和嵌套配置读取
     *
     * @param  string  $module   模块名称或配置文件路径
     * @param  string  $key      配置键或默认值
     * @param  mixed   $default  默认值（可选）
     * @return mixed
     *
     * @example
     * // 在 Blog 模块的控制器中
     * $name = module_config('common.name', 'hello'); // 读取 Blog/Config/common.php 的 name
     * $enabled = module_config('settings.cache.enabled', false); // 读取嵌套配置
     *
     * // 传统方式
     * $value = module_config('Blog', 'common.name', 'default');
     */
    function module_config(string $module, $key, mixed $default = null): mixed
    {
        // 请求级别配置缓存
        static $configCache = [];

        try {
            // 智能模式：module_config('common.name', 'default')
            if (str_contains($module, '.') && ! str_starts_with($module, '\\')) {
                [$configFile, $configKey] = explode('.', $module, 2);
                $configKey ??= '';

                $currentModule = module_name();
                if (! $currentModule || ! module_exists($currentModule)) {
                    return $key;
                }

                $cacheKey = "{$currentModule}.{$configFile}.{$configKey}";

                // 缓存命中
                if (array_key_exists($cacheKey, $configCache)) {
                    return $configCache[$cacheKey];
                }

                // 读取配置
                $configData = config(strtolower($currentModule) . '.' . $configFile, []);

                if (! is_array($configData)) {
                    $configCache[$cacheKey] = $key;
                    return $key;
                }

                // 嵌套配置读取（优化）
                if ($configKey === '') {
                    $result = $configData;
                } elseif (str_contains($configKey, '.')) {
                    $result = $configData;
                    foreach (explode('.', $configKey) as $segment) {
                        $result = is_array($result) ? ($result[$segment] ?? $key) : $key;
                        if ($result === $key) {
                            break;
                        }
                    }
                } else {
                    $result = array_key_exists($configKey, $configData) ? $configData[$configKey] : $key;
                }

                $configCache[$cacheKey] = $result;
                return $result;
            }

            // 传统模式：module_config('Blog', 'key', 'default')
            if (! module_exists($module)) {
                throw new \RuntimeException("模块 '{$module}' 不存在");
            }

            $configKey = ConfigLoader::getConfigKey($module, $key);
            $result = config($configKey, $default);
            $configCache["{$module}.{$key}"] = $result;

            return $result;

        } catch (\Throwable $e) {
            return $default ?? $key;
        }
    }
}

if (! function_exists('module_enabled')) {
    /**
     * 检查模块是否已启用
     *
     * PHP 8.2+ 优化：简化的缓存逻辑
     *
     * @param  string|null  $module  模块名称（可选，不传则检查当前模块）
     * @return bool
     *
     * @example
     * if (module_enabled()) { }
     * if (module_enabled('Blog')) { }
     */
    function module_enabled(?string $module = null): bool
    {
        static $enabledCache = [];

        $module ??= module_name();

        if (! $module) {
            return false;
        }

        return $enabledCache[$module] ??= (function () use ($module) {
            $repository = App::make(RepositoryInterface::class);
            $moduleInstance = $repository->find($module);
            return $moduleInstance?->isEnabled() ?? false;
        })();
    }
}

if (! function_exists('module_exists')) {
    /**
     * 检查模块是否存在
     *
     * PHP 8.2+ 优化：简化逻辑
     *
     * @param  string  $module  模块名称
     * @return bool
     *
     * @example
     * if (module_exists('Blog')) { }
     */
    function module_exists(string $module): bool
    {
        static $existsCache = [];

        return $existsCache[$module] ??= (function () use ($module) {
            try {
                return App::make(RepositoryInterface::class)->has($module);
            } catch (\Throwable) {
                return false;
            }
        })();
    }
}

if (! function_exists('module')) {
    /**
     * 获取模块实例或模块仓库
     *
     * PHP 8.2+ 优化：简化逻辑
     *
     * @param  string|null  $module  模块名称（可选，不传则返回仓库）
     * @return ModuleInterface|RepositoryInterface
     *
     * @example
     * $repository = module(); // 获取模块仓库
     * $blogModule = module('Blog'); // 获取 Blog 模块实例
     * $currentModule = module(module_name()); // 获取当前模块实例
     */
    function module(?string $module = null): ModuleInterface|RepositoryInterface
    {
        $repository = App::make(RepositoryInterface::class);

        return $module ? $repository->find($module) : $repository;
    }
}

if (! function_exists('modules')) {
    /**
     * 获取所有模块
     *
     * PHP 8.2+ 优化：简化的缓存机制
     *
     * @return array<ModuleInterface>
     *
     * @example
     * $allModules = modules();
     */
    function modules(): array
    {
        static $allModules = null;

        return $allModules ??= (function () {
            try {
                return App::make(RepositoryInterface::class)->all();
            } catch (\Throwable) {
                return [];
            }
        })();
    }
}

if (! function_exists('module_view_path')) {
    /**
     * 获取模块视图路径（用于返回视图）
     *
     * PHP 8.2+ 优化：使用 null 合并运算符
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @param  string      $view    视图名称
     * @return string
     *
     * @example
     * $viewPath = module_view_path('Blog', 'post.index'); // 'blog::post.index'
     * $viewPath = module_view_path(null, 'post.index'); // 使用当前模块
     */
    function module_view_path(?string $module = null, string $view = ''): string
    {
        $module ??= module_name() ?? 'default';
        return strtolower($module) . '::' . $view;
    }
}

if (! function_exists('module_route_path')) {
    /**
     * 获取模块路由名称前缀
     *
     * PHP 8.2+ 优化：简化逻辑
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @param  string      $route   路由名称
     * @return string
     *
     * @example
     * $routePath = module_route_path('Blog', 'post.index'); // 'blog.post.index'
     * $routePath = module_route_path('Blog', ''); // 'blog.'
     */
    function module_route_path(?string $module = null, string $route = ''): string
    {
        $module ??= module_name() ?? 'default';
        $prefix = strtolower($module) . '.';
        return $route ? $prefix . $route : $prefix;
    }
}

if (! function_exists('current_module')) {
    /**
     * 获取当前请求所在的模块（通过 URL 路径分析）
     *
     * PHP 8.2+ 优化：简化的 URL 解析逻辑
     *
     * @return string|null
     *
     * @example
     * // 访问 /blog/posts 时
     * $moduleName = current_module(); // 'Blog'
     */
    function current_module(): ?string
    {
        if (! function_exists('request')) {
            return null;
        }

        try {
            $segments = explode('/', request()->path());
            $firstSegment = $segments[0] ?? null;

            if (! $firstSegment) {
                return null;
            }

            $repository = App::make(RepositoryInterface::class);

            foreach ($repository->all() as $module) {
                if ($module->getLowerName() === $firstSegment) {
                    return $module->getName();
                }
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }
}

if (! function_exists('module_namespace')) {
    /**
     * 获取模块的命名空间
     *
     * PHP 8.2+ 优化：简化逻辑和空值合并
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string
     *
     * @example
     * $namespace = module_namespace('Blog'); // 'Modules\Blog'
     * $namespace = module_namespace(); // 当前模块命名空间
     */
    function module_namespace(?string $module = null): string
    {
        $module ??= module_name();
        $defaultNamespace = config('modules.namespace', 'Modules');

        if (! $module) {
            return $defaultNamespace;
        }

        try {
            $moduleInstance = App::make(RepositoryInterface::class)->find($module);
            return $moduleInstance?->getClassNamespace() ?? $defaultNamespace . '\\' . Str::studly($module);
        } catch (\Throwable) {
            return $defaultNamespace . '\\' . Str::studly($module);
        }
    }
}

if (! function_exists('module_url')) {
    /**
     * 获取模块 URL
     *
     * PHP 8.2+ 优化：简化逻辑
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @param  string      $path    路径
     * @return string
     *
     * @example
     * $url = module_url('Blog', 'posts/1'); // 'http://example.com/blog/posts/1'
     * $url = module_url(null, 'posts/1'); // 使用当前模块
     */
    function module_url(?string $module = null, string $path = ''): string
    {
        $module ??= module_name() ?? 'default';
        return url(strtolower($module) . '/' . ltrim($path, '/'));
    }
}

if (! function_exists('module_route')) {
    /**
     * 生成模块路由 URL
     *
     * PHP 8.2+ 优化：简化逻辑
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @param  string      $route   路由名称
     * @param  array       $params  参数
     * @return string
     *
     * @example
     * $url = module_route('Blog', 'posts.index'); // route('blog.posts.index')
     * $url = module_route('Blog', 'posts.show', ['id' => 1]); // route('blog.posts.show', ['id' => 1])
     * $url = module_route(null, 'posts.index'); // 使用当前模块
     */
    function module_route(?string $module = null, string $route = '', array $params = []): string
    {
        $module ??= module_name() ?? 'default';
        return route(strtolower($module) . '.' . $route, $params);
    }
}

if (! function_exists('module_asset')) {
    /**
     * 生成模块静态资源 URL
     *
     * PHP 8.2+ 优化：简化逻辑
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @param  string      $asset   资源路径
     * @return string
     *
     * @example
     * $url = module_asset('Blog', 'css/style.css'); // 'http://example.com/modules/blog/css/style.css'
     * $url = module_asset(null, 'js/app.js'); // 使用当前模块
     */
    function module_asset(?string $module = null, string $asset = ''): string
    {
        $module ??= module_name() ?? 'default';
        return asset('modules/' . strtolower($module) . '/' . ltrim($asset, '/'));
    }
}

if (! function_exists('module_view')) {
    /**
     * 返回模块视图
     *
     * PHP 8.2+ 优化：简化逻辑
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @param  string      $view    视图名称
     * @param  array       $data    数据
     * @return \Illuminate\View\View
     *
     * @example
     * return module_view('Blog', 'post.index', ['posts' => $posts]);
     * return module_view(null, 'post.index', compact('posts')); // 使用当前模块
     */
    function module_view(?string $module = null, string $view = '', array $data = []): \Illuminate\View\View
    {
        $module ??= module_name() ?? 'default';
        return view(strtolower($module) . '::' . $view, $data);
    }
}

if (! function_exists('module_lang')) {
    /**
     * 获取模块翻译
     *
     * PHP 8.2+ 优化：简化逻辑，添加返回类型
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @param  string      $key     翻译键
     * @param  array       $replace 替换参数
     * @param  string|null  $locale  语言环境
     * @return string|array
     *
     * @example
     * $message = module_lang('Blog', 'messages.welcome'); // trans('blog::messages.welcome')
     * $message = module_lang(null, 'messages.welcome'); // 使用当前模块
     */
    function module_lang(?string $module = null, string $key = '', array $replace = [], ?string $locale = null): string|array
    {
        $module ??= module_name() ?? 'default';
        return trans(strtolower($module) . '::' . $key, $replace, $locale);
    }
}

if (! function_exists('module_stub')) {
    /**
     * 创建模块 Stub 生成器
     *
     * PHP 8.2+ 优化：简化构造器调用
     *
     * @param  string  $module  模块名称
     * @return StubGenerator
     *
     * @example
     * $generator = module_stub('Blog');
     * $generator->render('controller', [
     *     'CLASS_NAMESPACE' => 'Modules\Blog\Http\Controllers',
     *     'CLASS' => 'PostController',
     * ]);
     */
    function module_stub(string $module): StubGenerator
    {
        return new StubGenerator($module);
    }
}

if (! function_exists('module_class')) {
    /**
     * 获取模块类的完整类名
     *
     * PHP 8.2+ 优化：简化逻辑
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @param  string      $class   类名
     * @return string
     *
     * @example
     * $className = module_class('Blog', 'Http\Controllers\PostController');
     * $className = module_class(null, 'Models\Post'); // 使用当前模块
     */
    function module_class(?string $module = null, string $class = ''): string
    {
        return module_namespace($module) . '\\' . $class;
    }
}

if (! function_exists('module_has_config')) {
    /**
     * 检查模块配置项是否存在
     *
     * PHP 8.2+ 优化：简化逻辑
     *
     * @param  string|null  $module     模块名称（可选，不传则使用当前模块）
     * @param  string      $configFile  配置文件名（如 'common'）
     * @param  string      $key        配置键（如 'name'）
     * @return bool
     *
     * @example
     * if (module_has_config('Blog', 'common', 'name')) { }
     * if (module_has_config(null, 'common', 'name')) { }
     */
    function module_has_config(?string $module = null, string $configFile = '', string $key = ''): bool
    {
        try {
            $module ??= module_name();

            if (! $module) {
                return false;
            }

            $configData = config(strtolower($module) . '.' . $configFile, []);

            if (empty($key)) {
                return ! empty($configData);
            }

            return is_array($configData) && array_key_exists($key, $configData);
        } catch (\Throwable) {
            return false;
        }
    }
}

if (! function_exists('module_config_path')) {
    /**
     * 获取模块配置文件路径
     *
     * PHP 8.2+ 优化：简化逻辑
     *
     * @param  string|null  $module      模块名称（可选，不传则使用当前模块）
     * @param  string      $configFile  配置文件名
     * @return string
     * @throws \RuntimeException 当无法确定模块时抛出异常
     *
     * @example
     * $path = module_config_path('Blog', 'common.php');
     * $path = module_config_path(null, 'common.php'); // 使用当前模块
     */
    function module_config_path(?string $module = null, string $configFile = 'config.php'): string
    {
        $module ??= module_name();

        if (! $module) {
            throw new \RuntimeException('无法确定模块名称，请传递明确的模块名或确保在模块内部调用');
        }

        return module_path($module, 'Config/' . $configFile);
    }
}

if (! function_exists('module_has_view')) {
    /**
     * 检查模块视图是否存在
     *
     * PHP 8.2+ 优化：简化逻辑
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @param  string      $view    视图名称
     * @return bool
     *
     * @example
     * if (module_has_view('Blog', 'post.index')) { }
     * if (module_has_view(null, 'post.index')) { }
     */
    function module_has_view(?string $module = null, string $view = ''): bool
    {
        try {
            $module ??= module_name();

            return $module && view()->exists(strtolower($module) . '::' . $view);
        } catch (\Throwable) {
            return false;
        }
    }
}

if (! function_exists('module_routes_path')) {
    /**
     * 获取模块路由文件路径
     *
     * PHP 8.2+ 优化：简化逻辑
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @param  string      $route   路由文件名（如 'web'）
     * @return string
     * @throws \RuntimeException 当无法确定模块时抛出异常
     *
     * @example
     * $path = module_routes_path('Blog', 'web'); // '.../Blog/Routes/web.php'
     * $path = module_routes_path(null, 'api'); // 使用当前模块
     */
    function module_routes_path(?string $module = null, string $route = 'web'): string
    {
        $module ??= module_name();

        if (! $module) {
            throw new \RuntimeException('无法确定模块名称，请传递明确的模块名或确保在模块内部调用');
        }

        return module_path($module, 'Routes/' . $route . '.php');
    }
}

if (! function_exists('module_migrations_path')) {
    /**
     * 获取模块迁移目录路径
     *
     * PHP 8.2+ 优化：简化逻辑
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string
     * @throws \RuntimeException 当无法确定模块时抛出异常
     *
     * @example
     * $path = module_migrations_path('Blog'); // '.../Blog/Database/Migrations'
     * $path = module_migrations_path(); // 使用当前模块
     */
    function module_migrations_path(?string $module = null): string
    {
        $module ??= module_name();

        if (! $module) {
            throw new \RuntimeException('无法确定模块名称，请传递明确的模块名或确保在模块内部调用');
        }

        return module_path($module, 'Database/Migrations');
    }
}

if (! function_exists('module_models_path')) {
    /**
     * 获取模块模型目录路径
     *
     * PHP 8.2+ 优化：简化逻辑
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string
     * @throws \RuntimeException 当无法确定模块时抛出异常
     *
     * @example
     * $path = module_models_path('Blog'); // '.../Blog/Models'
     * $path = module_models_path(); // 使用当前模块
     */
    function module_models_path(?string $module = null): string
    {
        $module ??= module_name();

        if (! $module) {
            throw new \RuntimeException('无法确定模块名称，请传递明确的模块名或确保在模块内部调用');
        }

        return module_path($module, 'Models');
    }
}

if (! function_exists('module_controllers_path')) {
    /**
     * 获取模块控制器目录路径
     *
     * PHP 8.2+ 优化：简化逻辑
     *
     * @param  string|null  $module     模块名称（可选，不传则使用当前模块）
     * @param  string      $controller  控制器类型（web/api/admin）
     * @return string
     * @throws \RuntimeException 当无法确定模块时抛出异常
     *
     * @example
     * $path = module_controllers_path('Blog', 'Web'); // '.../Blog/Http/Controllers/Web'
     * $path = module_controllers_path(null, 'Api'); // 使用当前模块
     */
    function module_controllers_path(?string $module = null, string $controller = 'Web'): string
    {
        $module ??= module_name();

        if (! $module) {
            throw new \RuntimeException('无法确定模块名称，请传递明确的模块名或确保在模块内部调用');
        }

        return module_path($module, 'Http/Controllers/' . Str::studly($controller));
    }
}

if (! function_exists('module_views_path')) {
    /**
     * 获取模块视图目录路径
     *
     * PHP 8.2+ 优化：简化逻辑
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string
     * @throws \RuntimeException 当无法确定模块时抛出异常
     *
     * @example
     * $path = module_views_path('Blog'); // '.../Blog/Resources/views'
     * $path = module_views_path(); // 使用当前模块
     */
    function module_views_path(?string $module = null): string
    {
        $module ??= module_name();

        if (! $module) {
            throw new \RuntimeException('无法确定模块名称，请传递明确的模块名或确保在模块内部调用');
        }

        return module_path($module, 'Resources/views');
    }
}

if (! function_exists('module_trans_path')) {
    /**
     * 获取模块翻译文件路径
     *
     * PHP 8.2+ 优化：简化逻辑
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string
     * @throws \RuntimeException 当无法确定模块时抛出异常
     *
     * @example
     * $path = module_trans_path('Blog'); // '.../Blog/Resources/lang'
     * $path = module_trans_path(); // 使用当前模块
     */
    function module_trans_path(?string $module = null): string
    {
        $module ??= module_name();

        if (! $module) {
            throw new \RuntimeException('无法确定模块名称，请传递明确的模块名或确保在模块内部调用');
        }

        return module_path($module, 'Resources/lang');
    }
}

if (! function_exists('module_config_files')) {
    /**
     * 获取模块的所有配置文件
     *
     * PHP 8.2+ 优化：使用 array_map 简化逻辑
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return array
     *
     * @example
     * $files = module_config_files('Blog'); // ['common.php', 'settings.php', ...]
     * $files = module_config_files(); // 使用当前模块
     */
    function module_config_files(?string $module = null): array
    {
        try {
            $module ??= module_name();

            if (! $module) {
                return [];
            }

            $configPath = module_path($module, 'Config');

            if (! is_dir($configPath)) {
                return [];
            }

            return array_map('basename', File::glob($configPath . '/*.php'));
        } catch (\Throwable) {
            return [];
        }
    }
}

if (! function_exists('module_route_files')) {
    /**
     * 获取模块的所有路由文件
     *
     * PHP 8.2+ 优化：使用 array_map 简化逻辑
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return array
     *
     * @example
     * $files = module_route_files('Blog'); // ['web', 'api', 'admin']
     * $files = module_route_files(); // 使用当前模块
     */
    function module_route_files(?string $module = null): array
    {
        try {
            $module ??= module_name();

            if (! $module) {
                return [];
            }

            $routesPath = module_path($module, 'Routes');

            if (! is_dir($routesPath)) {
                return [];
            }

            return array_map(fn($file) => pathinfo($file, PATHINFO_FILENAME), File::glob($routesPath . '/*.php'));
        } catch (\Throwable) {
            return [];
        }
    }
}

if (! function_exists('module_get_config')) {
    /**
     * 获取模块配置文件的所有配置（完整数组）
     *
     * PHP 8.2+ 优化：简化逻辑
     *
     * @param  string|null  $module     模块名称（可选，不传则使用当前模块）
     * @param  string      $configFile 配置文件名（如 'common'）
     * @return array
     *
     * @example
     * $config = module_get_config('Blog', 'common'); // ['name' => 'Blog', ...]
     * $config = module_get_config(null, 'settings'); // 使用当前模块
     */
    function module_get_config(?string $module = null, string $configFile = ''): array
    {
        try {
            $module ??= module_name();

            if (! $module || empty($configFile)) {
                return [];
            }

            $configData = config(strtolower($module) . '.' . $configFile, []);

            return is_array($configData) ? $configData : [];
        } catch (\Throwable) {
            return [];
        }
    }
}

if (! function_exists('module_set_config')) {
    /**
     * 设置模块配置值（运行时）
     *
     * PHP 8.2+ 优化：简化逻辑，使用 mixed 类型
     *
     * @param  string|null  $module     模块名称（可选，不传则使用当前模块）
     * @param  string      $configFile 配置文件名
     * @param  string      $key        配置键
     * @param  mixed       $value      配置值
     * @return void
     *
     * @example
     * module_set_config('Blog', 'common', 'name', 'New Name');
     * module_set_config(null, 'settings', 'cache', true); // 使用当前模块
     */
    function module_set_config(?string $module = null, string $configFile = '', string $key = '', mixed $value = null): void
    {
        try {
            $module ??= module_name();

            if (! $module || empty($configFile) || empty($key)) {
                return;
            }

            config([strtolower($module) . '.' . $configFile . '.' . $key => $value]);
        } catch (\Throwable) {
            // 静默失败
        }
    }
}

if (! function_exists('module_has_migration')) {
    /**
     * 检查模块是否存在指定的迁移文件
     *
     * PHP 8.2+ 优化：简化 glob 匹配
     *
     * @param  string|null  $module       模块名称（可选，不传则使用当前模块）
     * @param  string      $migrationName 迁移文件名（不含扩展名）
     * @return bool
     *
     * @example
     * if (module_has_migration('Blog', 'create_posts_table')) { }
     */
    function module_has_migration(?string $module = null, string $migrationName = ''): bool
    {
        try {
            $module ??= module_name();

            if (! $module || empty($migrationName)) {
                return false;
            }

            return ! empty(File::glob(module_migrations_path($module) . '/*_' . $migrationName . '.php'));
        } catch (\Throwable) {
            return false;
        }
    }
}

if (! function_exists('module_all_migrations')) {
    /**
     * 获取模块的所有迁移文件
     *
     * PHP 8.2+ 优化：使用 array_map 简化逻辑
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return array
     *
     * @example
     * $migrations = module_all_migrations('Blog');
     * $migrations = module_all_migrations(); // 使用当前模块
     */
    function module_all_migrations(?string $module = null): array
    {
        try {
            $module ??= module_name();

            if (! $module) {
                return [];
            }

            $migrationsPath = module_migrations_path($module);

            if (! is_dir($migrationsPath)) {
                return [];
            }

            return array_map('basename', File::glob($migrationsPath . '/*.php'));
        } catch (\Throwable) {
            return [];
        }
    }
}

if (! function_exists('module_enabled_modules')) {
    /**
     * 获取所有已启用的模块
     *
     * PHP 8.2+ 优化：使用 array_filter 简化逻辑
     *
     * @return array<ModuleInterface>
     *
     * @example
     * $enabled = module_enabled_modules();
     */
    function module_enabled_modules(): array
    {
        static $enabledModules = null;

        return $enabledModules ??= array_filter(
            modules(),
            fn($module) => $module->isEnabled()
        );
    }
}

if (! function_exists('module_disabled_modules')) {
    /**
     * 获取所有已禁用的模块
     *
     * PHP 8.2+ 优化：使用 array_filter 简化逻辑
     *
     * @return array<ModuleInterface>
     *
     * @example
     * $disabled = module_disabled_modules();
     */
    function module_disabled_modules(): array
    {
        static $disabledModules = null;

        return $disabledModules ??= array_filter(
            modules(),
            fn($module) => ! $module->isEnabled()
        );
    }
}
