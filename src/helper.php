<?php

use Illuminate\Support\Facades\App;
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Contracts\RepositoryInterface;
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
     *
     * @return string 返回模块名称（StudlyCase），如果在模块外则返回 'App'
     *
     * @example
     * // 在 Blog/Http/Controllers/PostController.php 中调用
     * $moduleName = module_name(); // 'Blog'
     */
    function module_name(): string
    {
        static $result = null;

        if ($result !== null) {
            return $result;
        }

        $modulePath = rtrim(str_replace('\\', '/',
                config('modules.path', base_path('Modules'))
            ), '/') . '/';

        // 优化：获取足够的调用栈深度
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);

        foreach ($backtrace as $trace) {
            $file = $trace['file'] ?? '';

            if (!is_string($file) || str_contains($file, '/vendor/')) {
                continue;
            }

            $file = str_replace('\\', '/', $file);

            if (str_starts_with($file, $modulePath)) {
                $relative = substr($file, strlen($modulePath));
                $segments = explode('/', $relative, 2);
                $moduleDir = $segments[0] ?? '';

                if ($moduleDir) {
                    $moduleName = \Illuminate\Support\Str::studly($moduleDir);

                    if ($moduleName && module_exists($moduleName)) {
                        $result = $moduleName;
                        return $moduleName;
                    }
                }
            }
        }

        return 'App';
    }
}

if (! function_exists('module_path')) {
    /**
     * 获取模块目录的路径
     *
     * PHP 8.2+ 优化：简化逻辑，缓存 Repository 实例
     *
     * @param  string      $path    子路径（可选）
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string
     * @throws RuntimeException 当无法确定模块时抛出异常
     *
     * @example
     * module_path('Models/Post.php', 'Blog')
     * module_path('Config/common.php') // 使用当前模块
     */
    function module_path(string $path = '', ?string $module = null): string
    {
        // 缓存 Repository 实例
        static $repository = null;
        $repository ??= App::make(RepositoryInterface::class);

        $module ??= module_name();

        if (empty($module)) {
            throw new RuntimeException('无法确定模块名称，请传递明确的模块名或确保在模块内部调用');
        }

        return $repository->getModulePath($module, $path);
    }
}

if (! function_exists('module_config')) {
    /**
     * 获取模块配置值（PHP 8.2+ / Laravel 11+ 无缓存简化版）
     *
     * 支持三种用法：
     * 1. 获取整个配置文件：module_config('common') - 获取当前模块的 Config/common.php 的所有配置
     * 2. 获取特定配置：module_config('common.name', 'hello') - 获取当前模块的 Config/common.php 中 name 的值
     * 3. 指定模块：module_config('common.name', 'default', 'Blog') - 获取指定模块的配置
     *
     * @param  string       $key      配置键或配置文件名
     * @param  mixed        $default  默认值
     * @param  string|null  $module   模块名称（可选）
     * @return mixed
     *
     * @throws RuntimeException 当指定模块不存在时抛出
     */
    function module_config(string $key, mixed $default = null, ?string $module = null): mixed
    {
        static $cache = [];

        $useModule = $module ?? module_name();

        // 验证模块
        if (!$useModule) {
            return $default;
        }

        // 指定模块时的验证
        if ($module !== null) {
            if (!module_exists($module)) {
                throw new RuntimeException("模块 '{$module}' 不存在");
            }
        }
        // 自动检测模块时的验证
        elseif (!module_exists($useModule)) {
            return $default;
        }

        // 构建缓存键
        $cacheKey = "config:{$useModule}:{$key}:" . ($default === null ? 'null' : md5(serialize($default)));

        // 检查缓存
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        // 获取配置
        $fullKey = strtolower($useModule) . '.' . $key;
        $configValue = config($fullKey, $default);

        // 特殊处理整个配置文件
        if (!str_contains($key, '.') && empty($configValue) && $default !== null) {
            $configValue = $default;
        }

        // 缓存结果
        $cache[$cacheKey] = $configValue;
        return $configValue;
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
     * @param  string      $view    视图名称
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string
     *
     * @example
     * $viewPath = module_view_path('post.index', 'Blog'); // 'blog::post.index'
     * $viewPath = module_view_path('post.index'); // 使用当前模块
     */
    function module_view_path(string $view = '', ?string $module = null): string
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
     * @param  string      $route   路由名称
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string
     *
     * @example
     * $routePath = module_route_path('post.index', 'Blog'); // 'blog.post.index'
     * $routePath = module_route_path('', 'Blog'); // 'blog.'
     */
    function module_route_path(string $route = '', ?string $module = null): string
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
            return $moduleInstance?->getClassNamespace() ?? $defaultNamespace . '\\' . $module;
        } catch (\Throwable) {
            return $defaultNamespace . '\\' . $module;
        }
    }
}

if (! function_exists('module_url')) {
    /**
     * 获取模块 URL
     *
     * PHP 8.2+ 优化：简化逻辑
     *
     * @param  string      $path    路径
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string
     *
     * @example
     * $url = module_url('posts/1', 'Blog'); // 'http://example.com/blog/posts/1'
     * $url = module_url('posts/1'); // 使用当前模块
     */
    function module_url(string $path = '', ?string $module = null): string
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
     * @param  string      $route   路由名称
     * @param  array       $params  参数
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string
     *
     * @example
     * $url = module_route('posts.index', [], 'Blog'); // route('blog.posts.index')
     * $url = module_route('posts.show', ['id' => 1], 'Blog'); // route('blog.posts.show', ['id' => 1])
     * $url = module_route('posts.index'); // 使用当前模块
     */
    function module_route(string $route = '', array $params = [], ?string $module = null): string
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
     * @param  string      $asset   资源路径
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string
     *
     * @example
     * $url = module_asset('css/style.css', 'Blog'); // 'http://example.com/modules/blog/css/style.css'
     * $url = module_asset('js/app.js'); // 使用当前模块
     */
    function module_asset(string $asset = '', ?string $module = null): string
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
     * @param  string      $view    视图名称
     * @param  array       $data    数据
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return \Illuminate\View\View
     *
     * @example
     * return module_view('post.index', ['posts' => $posts], 'Blog');
     * return module_view('post.index', compact('posts')); // 使用当前模块
     */
    function module_view(string $view = '', array $data = [], ?string $module = null): \Illuminate\View\View
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
     * @param  string      $key     翻译键
     * @param  array       $replace 替换参数
     * @param  string|null  $locale  语言环境
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string|array
     *
     * @example
     * $message = module_lang('messages.welcome', [], null, 'Blog'); // trans('blog::messages.welcome')
     * $message = module_lang('messages.welcome'); // 使用当前模块
     */
    function module_lang(string $key = '', array $replace = [], ?string $locale = null, ?string $module = null): string|array
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
     * @param  string      $class   类名
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string
     *
     * @example
     * $className = module_class('Http\Controllers\PostController', 'Blog');
     * $className = module_class('Models\Post'); // 使用当前模块
     */
    function module_class(string $class = '', ?string $module = null): string
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
     * @param  string      $configFile  配置文件名（如 'common'）
     * @param  string      $key        配置键（如 'name'）
     * @param  string|null  $module     模块名称（可选，不传则使用当前模块）
     * @return bool
     *
     * @example
     * if (module_has_config('common', 'name', 'Blog')) { }
     * if (module_has_config('common', 'name')) { }
     */
    function module_has_config(string $configFile = '', string $key = '', ?string $module = null): bool
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
     * @param  string      $configFile  配置文件名
     * @param  string|null  $module      模块名称（可选，不传则使用当前模块）
     * @return string
     * @throws RuntimeException 当无法确定模块时抛出异常
     *
     * @example
     * $path = module_config_path('common.php', 'Blog');
     * $path = module_config_path('common.php'); // 使用当前模块
     */
    function module_config_path(string $configFile = 'config.php', ?string $module = null): string
    {
        $module ??= module_name();

        if (! $module) {
            throw new RuntimeException('无法确定模块名称，请传递明确的模块名或确保在模块内部调用');
        }

        return module_path('Config/' . $configFile, $module);
    }
}

if (! function_exists('module_has_view')) {
    /**
     * 检查模块视图是否存在
     *
     * PHP 8.2+ 优化：简化逻辑
     *
     * @param  string      $view    视图名称
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return bool
     *
     * @example
     * if (module_has_view('post.index', 'Blog')) { }
     * if (module_has_view('post.index')) { }
     */
    function module_has_view(string $view = '', ?string $module = null): bool
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
     * @param  string      $route   路由文件名（如 'web'）
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string
     * @throws RuntimeException 当无法确定模块时抛出异常
     *
     * @example
     * $path = module_routes_path('web', 'Blog'); // '.../Blog/Routes/web.php'
     * $path = module_routes_path('api'); // 使用当前模块
     */
    function module_routes_path(string $route = 'web', ?string $module = null): string
    {
        $module ??= module_name();

        if (! $module) {
            throw new RuntimeException('无法确定模块名称，请传递明确的模块名或确保在模块内部调用');
        }

        return module_path('Routes/' . $route . '.php', $module);
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
     * @throws RuntimeException 当无法确定模块时抛出异常
     *
     * @example
     * $path = module_migrations_path('Blog'); // '.../Blog/Database/Migrations'
     * $path = module_migrations_path(); // 使用当前模块
     */
    function module_migrations_path(?string $module = null): string
    {
        $module ??= module_name();

        if (! $module) {
            throw new RuntimeException('无法确定模块名称，请传递明确的模块名或确保在模块内部调用');
        }

        return module_path('Database/Migrations', $module);
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
     * @throws RuntimeException 当无法确定模块时抛出异常
     *
     * @example
     * $path = module_models_path('Blog'); // '.../Blog/Models'
     * $path = module_models_path(); // 使用当前模块
     */
    function module_models_path(?string $module = null): string
    {
        $module ??= module_name();

        if (! $module) {
            throw new RuntimeException('无法确定模块名称，请传递明确的模块名或确保在模块内部调用');
        }

        return module_path('Models', $module);
    }
}

if (! function_exists('module_controllers_path')) {
    /**
     * 获取模块控制器目录路径
     *
     * PHP 8.2+ 优化：简化逻辑
     *
     * @param  string      $controller  控制器类型（web/api/admin）
     * @param  string|null  $module     模块名称（可选，不传则使用当前模块）
     * @return string
     * @throws RuntimeException 当无法确定模块时抛出异常
     *
     * @example
     * $path = module_controllers_path('Web', 'Blog'); // '.../Blog/Http/Controllers/Web'
     * $path = module_controllers_path('Api'); // 使用当前模块
     */
    function module_controllers_path(string $controller = 'Web', ?string $module = null): string
    {
        $module ??= module_name();

        if (! $module) {
            throw new RuntimeException('无法确定模块名称，请传递明确的模块名或确保在模块内部调用');
        }

        return module_path('Http/Controllers/' . ucfirst($controller), $module);
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
     * @throws RuntimeException 当无法确定模块时抛出异常
     *
     * @example
     * $path = module_views_path('Blog'); // '.../Blog/Resources/views'
     * $path = module_views_path(); // 使用当前模块
     */
    function module_views_path(?string $module = null): string
    {
        $module ??= module_name();

        if (! $module) {
            throw new RuntimeException('无法确定模块名称，请传递明确的模块名或确保在模块内部调用');
        }

        return module_path('Resources/views', $module);
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
     * @throws RuntimeException 当无法确定模块时抛出异常
     *
     * @example
     * $path = module_trans_path('Blog'); // '.../Blog/Resources/lang'
     * $path = module_trans_path(); // 使用当前模块
     */
    function module_trans_path(?string $module = null): string
    {
        $module ??= module_name();

        if (! $module) {
            throw new RuntimeException('无法确定模块名称，请传递明确的模块名或确保在模块内部调用');
        }

        return module_path('Resources/lang', $module);
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

            $configPath = module_path('Config', $module);

            if (! is_dir($configPath)) {
                return [];
            }

            return array_map('basename', \Illuminate\Support\Facades\File::glob($configPath . '/*.php'));
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

            $routesPath = module_path('Routes', $module);

            if (! is_dir($routesPath)) {
                return [];
            }

            return array_map(fn($file) => pathinfo($file, PATHINFO_FILENAME), \Illuminate\Support\Facades\File::glob($routesPath . '/*.php'));
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
     * @param  string      $configFile 配置文件名（如 'common'）
     * @param  string|null  $module     模块名称（可选，不传则使用当前模块）
     * @return array
     *
     * @example
     * $config = module_get_config('common', 'Blog'); // ['name' => 'Blog', ...]
     * $config = module_get_config('settings'); // 使用当前模块
     */
    function module_get_config(string $configFile = '', ?string $module = null): array
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
     * @param  string      $configFile 配置文件名
     * @param  string      $key        配置键
     * @param  mixed       $value      配置值
     * @param  string|null  $module     模块名称（可选，不传则使用当前模块）
     * @return void
     *
     * @example
     * module_set_config('common', 'name', 'New Name', 'Blog');
     * module_set_config('settings', 'cache', true); // 使用当前模块
     */
    function module_set_config(string $configFile = '', string $key = '', mixed $value = null, ?string $module = null): void
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
     * @param  string      $migrationName 迁移文件名（不含扩展名）
     * @param  string|null  $module       模块名称（可选，不传则使用当前模块）
     * @return bool
     *
     * @example
     * if (module_has_migration('create_posts_table', 'Blog')) { }
     * if (module_has_migration('create_posts_table')) { }
     */
    function module_has_migration(string $migrationName = '', ?string $module = null): bool
    {
        try {
            $module ??= module_name();

            if (! $module || empty($migrationName)) {
                return false;
            }

            return ! empty(\Illuminate\Support\Facades\File::glob(module_migrations_path($module) . '/*_' . $migrationName . '.php'));
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

            return array_map('basename', \Illuminate\Support\Facades\File::glob($migrationsPath . '/*.php'));
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
