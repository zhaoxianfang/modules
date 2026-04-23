<?php

// ============================================================================
// Laravel 框架依赖
// ============================================================================
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

// ============================================================================
// 模块系统核心接口与工具类
// ============================================================================
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Contracts\RepositoryInterface;
use zxf\Modules\Support\StubGenerator;

/**
 * ============================================================================
 * 模块核心助手函数集
 * ============================================================================
 *
 * 本文件提供了一套完整、高效的模块系统操作接口，基于 Laravel 框架构建。
 * 所有函数均针对 Laravel 11+ 和 PHP 8.2+ 环境进行了深度优化。
 *
 * 架构设计原则：
 * - 自动上下文感知：大多数函数支持自动检测当前模块，减少重复传参
 * - 静态缓存机制：频繁调用的结果通过 static 变量缓存，提升性能
 * - 防御性编程：所有函数均包含异常捕获，避免运行时错误中断流程
 * - 链式友好：返回值类型统一，便于在业务代码中链式调用
 *
 * PHP 8.2+ 特性应用：
 * - 使用 `mixed` 返回类型实现真正的泛型支持
 * - 使用 `??=` (null 合并赋值) 简化缓存逻辑
 * - 使用 `str_starts_with` / `str_contains` 替代复杂的 strpos 判断
 * - 使用 match 表达式替代冗长的 switch 语句
 * - 使用箭头函数简化 array_map / array_filter 回调
 *
 * 函数分类索引：
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ 一、模块基本信息与检测                                                    │
 * │    module_name() / module() / modules() / module_exists()                │
 * │    module_enabled() / current_module() / module_namespace()              │
 * │                                                                          │
 * │ 二、模块路径操作                                                          │
 * │    module_path() / module_config_path() / module_routes_path()           │
 * │    module_migrations_path() / module_models_path()                       │
 * │    module_controllers_path() / module_views_path() / module_trans_path() │
 * │                                                                          │
 * │ 三、模块配置管理                                                          │
 * │    module_config() / module_has_config() / module_get_config()           │
 * │    module_set_config() / module_config_files()                           │
 * │                                                                          │
 * │ 四、视图、路由与静态资源                                                    │
 * │    module_view_path() / module_view() / module_has_view()                │
 * │    module_route_path() / module_route() / module_url()                   │
 * │    module_asset() / module_lang() / module_stub() / module_class()       │
 * │                                                                          │
 * │ 五、模块状态与枚举                                                          │
 * │    module_enabled_modules() / module_disabled_modules()                  │
 * │    module_has_migration() / module_all_migrations()                      │
 * │    module_route_files()                                                  │
 * │                                                                          │
 * │ 六、通用辅助函数                                                          │
 * │    get_user_info() / view_share() / get_view_share()                     │
 * │    view_exists() / source_local_website()                                │
 * └──────────────────────────────────────────────────────────────────────────┘
 */

// ============================================================================
// 一、模块基本信息与检测
// ============================================================================

if (! function_exists('module_name')) {
    /**
     * 获取当前代码所在的模块名称
     *
     * 通过分析调用栈中的文件路径，自动识别当前执行代码所属的模块。
     * 这是整个模块系统的核心上下文感知函数，大多数其他助手函数都依赖它
     * 来自动确定操作的目标模块。
     *
     * 检测机制：
     * 1. 首先检查是否在命令行环境（Console），返回固定值 'Command'
     * 2. 获取配置的模块根目录路径（默认：base_path('Modules')）
     * 3. 遍历调用栈（回溯深度：8层），查找第一个位于模块目录中的文件
     * 4. 从文件路径提取模块目录名，转换为 StudlyCase 格式
     * 5. 验证模块是否存在（通过 module_exists() 确认）
     * 6. 如未检测到有效模块，返回 'App' 表示主应用
     *
     * @param  bool  $toLower  是否返回小写蛇形命名（如 'blog_module'）
     * @return string 模块名称（StudlyCase）或 'App'/'Command'
     *
     * @example
     * // 在 Blog/Http/Controllers/PostController.php 中调用
     * $moduleName = module_name();        // 'Blog'
     * $moduleName = module_name(true);    // 'blog'
     *
     * @see module_exists() 用于验证模块有效性的依赖函数
     */
    function module_name(bool $toLower = false): string
    {
        // 命令行环境下无法通过文件路径检测，返回固定标识
        if (app()->runningInConsole()) {
            return $toLower ? 'command' : 'Command';
        }

        // 标准化模块根目录路径（统一使用正斜杠，确保结尾有斜杠）
        $modulePath = rtrim(str_replace('\\', '/',
                config('modules.path', base_path('Modules'))
            ), '/') . '/';

        // 获取调用栈（限制深度 8 层，忽略参数以减少内存占用）
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);

        foreach ($backtrace as $trace) {
            $file = $trace['file'] ?? '';

            // 跳过非字符串路径和 vendor 目录中的文件
            if (!is_string($file) || str_contains($file, '/vendor/')) {
                continue;
            }

            $file = str_replace('\\', '/', $file);

            // 检查文件是否位于模块目录下
            if (str_starts_with($file, $modulePath)) {
                $relative = substr($file, strlen($modulePath));
                $segments = explode('/', $relative, 2);
                $moduleDir = $segments[0] ?? '';

                if ($moduleDir) {
                    // 将目录名转换为 StudlyCase（驼峰命名）
                    $moduleName = Str::studly($moduleDir);

                    // 验证模块真实存在后才返回
                    if ($moduleName && module_exists($moduleName)) {
                        if ($toLower) {
                            // 转换为蛇形命名（如 BlogModule => blog_module）
                            return strtolower(preg_replace('/(?<=[a-z])([A-Z])/', '_$1', $moduleName));
                        }
                        return $moduleName;
                    }
                }
            }
        }

        // 未检测到有效模块，返回默认应用标识
        return $toLower ? 'app' : 'App';
    }
}

// ============================================================================
// 二、模块路径操作
// ============================================================================

if (! function_exists('module_path')) {
    /**
     * 获取模块目录的完整路径
     *
     * 这是所有模块路径操作的基础函数，其他如 module_config_path()、
     * module_views_path() 等都基于它构建。
     *
     * 性能优化：
     * - 使用 static 变量缓存 Repository 实例，避免重复从容器解析
     * - 使用 null 合并赋值运算符 (??=) 简化缓存逻辑
     *
     * @param  string       $path    子路径（可选），如 'Models/Post.php'
     * @param  string|null  $module  模块名称（可选，不传则自动检测当前模块）
     * @return string 模块目录的绝对路径
     * @throws RuntimeException 当无法确定模块且未传入明确模块名时抛出
     *
     * @example
     * // 获取 Blog 模块的模型路径
     * $path = module_path('Models/Post.php', 'Blog');
     * // 返回: /var/www/app/Modules/Blog/Models/Post.php
     *
     * // 在当前模块中获取配置路径
     * $path = module_path('Config/common.php');
     *
     * @see module_name() 用于自动检测当前模块
     */
    function module_path(string $path = '', ?string $module = null): string
    {
        // 静态缓存 Repository 实例，提升多次调用的性能
        static $repository = null;
        $repository ??= App::make(RepositoryInterface::class);

        // 自动检测当前模块（如果未指定）
        $module ??= module_name();

        if (empty($module)) {
            throw new RuntimeException('无法确定模块名称，请传递明确的模块名或确保在模块内部调用');
        }

        return $repository->getModulePath($module, $path);
    }
}

// ============================================================================
// 三、模块配置管理
// ============================================================================

if (! function_exists('module_config')) {
    /**
     * 获取模块配置值（支持多级缓存）
     *
     * 这是模块系统中最常用的配置读取函数，提供了灵活的配置访问方式：
     *
     * 使用模式：
     * ┌─────────────────────────────────────────────────────────────────────┐
     * │ 模式 1: 获取整个配置文件                                              │
     * │   module_config('common')                                           │
     * │   → 返回当前模块 Config/common.php 中的所有配置项                      │
     * │                                                                     │
     * │ 模式 2: 获取特定配置项（带点符号路径）                                  │
     * │   module_config('common.name', 'default_value')                     │
     * │   → 返回当前模块 common 文件中 name 的值                              │
     * │                                                                     │
     * │ 模式 3: 读取指定模块的配置                                             │
     * │   module_config('settings.cache', false, 'Blog')                    │
     * │   → 返回 Blog 模块的 settings 文件中 cache 的值，默认 false            │
     * └─────────────────────────────────────────────────────────────────────┘
     *
     * 缓存策略：
     * - 使用静态变量缓存避免重复读取同一配置
     * - 缓存键包含模块名、配置键和默认值的哈希，确保唯一性
     *
     * @param  string       $key      配置键（如 'common' 或 'common.name'）
     * @param  mixed        $default  默认值（当配置不存在时返回）
     * @param  string|null  $module   模块名称（可选，不传则使用当前模块）
     * @return mixed 配置值，可能是标量、数组或 null
     *
     * @throws RuntimeException 当明确指定模块且该模块不存在时抛出
     *
     * @example
     * // 获取当前模块 common 配置的所有内容
     * $allConfig = module_config('common');
     *
     * // 获取嵌套配置，提供默认值
     * $debugMode = module_config('app.debug', false);
     *
     * // 获取指定模块的配置
     * $blogName = module_config('common.name', 'Unknown', 'Blog');
     */
    function module_config(string $key, mixed $default = null, ?string $module = null): mixed
    {
        // 静态缓存，按请求生命周期持久化配置值
        static $cache = [];

        $useModule = $module ?? module_name();

        // 模块名为空时直接返回默认值
        if (!$useModule) {
            return $default;
        }

        // 指定模块时：严格验证模块必须存在
        if ($module !== null) {
            if (!module_exists($module)) {
                throw new RuntimeException("模块 '{$module}' 不存在");
            }
        }
        // 自动检测模块时：宽松处理，模块不存在返回默认值
        elseif (!module_exists($useModule)) {
            return $default;
        }

        // 构建唯一缓存键（默认值序列化后取 MD5 确保一致性）
        $cacheKey = "config:{$useModule}:{$key}:" . ($default === null ? 'null' : md5(serialize($default)));

        // 检查静态缓存
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        // 构建完整配置键（模块名小写化以匹配 Laravel 配置命名规范）
        $fullKey = strtolower($useModule) . '.' . $key;
        $configValue = config($fullKey, $default);

        // 特殊处理：获取整个配置文件时若为空且提供了默认值，则使用默认值
        if (!str_contains($key, '.') && empty($configValue) && $default !== null) {
            $configValue = $default;
        }

        // 存入静态缓存并返回
        $cache[$cacheKey] = $configValue;
        return $configValue;
    }
}

if (! function_exists('module_enabled')) {
    /**
     * 检查模块是否已启用
     *
     * 通过模块仓库查询模块实例，并调用其 isEnabled() 方法判断状态。
     * 结果通过静态数组缓存，同一请求内重复查询不会触发多次文件读取。
     *
     * 注意：如果模块不存在，返回 false（而非抛出异常），便于在条件判断中安全使用。
     *
     * @param  string|null  $module  模块名称（可选，不传则检查当前模块）
     * @return bool true-已启用, false-未启用或模块不存在
     *
     * @example
     * // 检查当前模块是否启用
     * if (module_enabled()) { ... }
     *
     * // 检查指定模块
     * if (module_enabled('Blog')) { ... }
     *
     * @see module_exists() 用于确认模块是否存在
     * @see module_disabled_modules() 获取所有已禁用模块列表
     */
    function module_enabled(?string $module = null): bool
    {
        static $enabledCache = [];

        $module ??= module_name();

        if (! $module) {
            return false;
        }

        // 使用闭包立即执行 + null 合并赋值，实现缓存
        return $enabledCache[$module] ??= (function () use ($module) {
            $repository = App::make(RepositoryInterface::class);
            $moduleInstance = $repository->find($module);
            // 空安全运算符：若模块不存在返回 false
            return $moduleInstance?->isEnabled() ?? false;
        })();
    }
}

if (! function_exists('module_exists')) {
    /**
     * 检查模块是否存在
     *
     * 通过模块仓库的 has() 方法验证模块是否已注册。
     * 所有异常（如仓库未初始化、目录不可读等）都被捕获并返回 false，
     * 确保在系统未完全初始化时也能安全调用。
     *
     * @param  string  $module  模块名称（StudlyCase）
     * @return bool true-模块存在, false-模块不存在或查询异常
     *
     * @example
     * if (module_exists('Blog')) {
     *     // 安全地执行 Blog 模块相关操作
     * }
     *
     * @see module_name() 检测当前模块时内部调用此函数验证
     */
    function module_exists(string $module): bool
    {
        static $existsCache = [];

        return $existsCache[$module] ??= (function () use ($module) {
            try {
                return App::make(RepositoryInterface::class)->has($module);
            } catch (\Throwable) {
                // 系统未初始化或仓库异常时，安全返回 false
                return false;
            }
        })();
    }
}

if (! function_exists('module')) {
    /**
     * 获取模块实例或模块仓库
     *
     * 双重职责函数：根据是否传入模块名称决定返回类型。
     * 这是直接操作模块对象的入口，适合需要访问模块元数据（版本、作者、路径等）的场景。
     *
     * @param  string|null  $module  模块名称（可选）
     *                              - 传入名称：返回该模块的 ModuleInterface 实例
     *                              - 不传：返回 RepositoryInterface 仓库实例
     * @return ModuleInterface|RepositoryInterface
     *
     * @example
     * // 获取仓库，遍历所有模块
     * $repository = module();
     * foreach ($repository->all() as $module) { ... }
     *
     * // 获取指定模块实例，读取模块元数据
     * $blogModule = module('Blog');
     * echo $blogModule->getVersion();
     *
     * // 获取当前模块实例
     * $current = module(module_name());
     */
    function module(?string $module = null): ModuleInterface|RepositoryInterface
    {
        $repository = App::make(RepositoryInterface::class);

        return $module ? $repository->find($module) : $repository;
    }
}

if (! function_exists('modules')) {
    /**
     * 获取所有已注册模块的列表
     *
     * 返回模块仓库中所有已发现的模块实例数组。
     * 结果在请求生命周期内静态缓存，确保多次调用性能一致。
     *
     * 异常安全：如果模块系统尚未初始化，返回空数组而非抛出异常。
     *
     * @return array<ModuleInterface> 模块实例数组，键通常为模块名称
     *
     * @example
     * // 遍历所有模块
     * foreach (modules() as $name => $module) {
     *     echo $name . ': ' . $module->getName() . PHP_EOL;
     * }
     *
     * // 结合 array_filter 筛选
     * $activeModules = array_filter(modules(), fn($m) => $m->isEnabled());
     *
     * @see module_enabled_modules() 仅获取已启用的模块
     * @see module_disabled_modules() 仅获取已禁用的模块
     */
    function modules(): array
    {
        static $allModules = null;

        return $allModules ??= (function () {
            try {
                return App::make(RepositoryInterface::class)->all();
            } catch (\Throwable) {
                // 系统初始化前调用时安全返回空数组
                return [];
            }
        })();
    }
}

if (! function_exists('module_view_path')) {
    /**
     * 获取模块视图的 Laravel 命名空间路径
     *
     * 生成符合 Laravel 视图命名规范的 "模块名::视图名" 格式字符串，
     * 可直接用于 view() 辅助函数或 Blade 模板中。
     *
     * @param  string       $view    视图名称（如 'post.index'）
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string Laravel 视图路径标识（如 'blog::post.index'）
     *
     * @example
     * // 指定模块视图
     * $viewPath = module_view_path('post.index', 'Blog'); // 'blog::post.index'
     *
     * // 当前模块视图
     * $viewPath = module_view_path('dashboard'); // 如 'blog::dashboard'
     *
     * // 直接在 view() 中使用
     * return view(module_view_path('post.show'));
     *
     * @see module_view() 直接返回 View 实例的快捷函数
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
     * 生成符合 Laravel route() 函数命名的 "模块名.路由名" 格式字符串。
     * 模块路由通常以模块名小写作为前缀注册，本函数确保一致性。
     *
     * @param  string       $route   路由名称（如 'post.index'）
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string 路由全名（如 'blog.post.index'）或仅前缀（如 'blog.'）
     *
     * @example
     * // 生成完整路由名
     * $routeName = module_route_path('post.index', 'Blog'); // 'blog.post.index'
     *
     * // 仅获取前缀（用于动态拼接）
     * $prefix = module_route_path('', 'Blog'); // 'blog.'
     *
     * // 在 route() 中使用
     * $url = route(module_route_path('posts.show'), ['id' => 1]);
     *
     * @see module_route() 直接生成路由 URL 的快捷函数
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
     * 获取当前 HTTP 请求对应的模块名称（通过 URL 路径分析）
     *
     * 与 module_name() 不同，此函数基于当前请求的 URL 而非调用栈位置来推断模块。
     * 适用于需要在中间件、服务提供者等全局位置判断请求所属模块的场景。
     *
     * 匹配规则：取 URL 路径的第一个段（如 /blog/posts → blog），
     * 与所有已注册模块的小写名称比对，匹配成功则返回模块的标准名称。
     *
     * @return string|null 模块名称（StudlyCase），未匹配则返回 null
     *
     * @example
     * // 访问 /blog/posts 时
     * $moduleName = current_module(); // 'Blog'
     *
     * // 访问 /api/v1/users 且没有 Api 模块时
     * $moduleName = current_module(); // null
     *
     * @see module_name() 基于文件路径的模块检测（更适合在控制器/模型中使用）
     */
    function current_module(): ?string
    {
        // 确保 request() 函数可用（在 Artisan 命令中不可用）
        if (! function_exists('request')) {
            return null;
        }

        try {
            // 获取 URL 路径的第一段
            $segments = explode('/', request()->path());
            $firstSegment = $segments[0] ?? null;

            if (! $firstSegment) {
                return null;
            }

            // 遍历所有模块，比对 URL 前缀
            $repository = App::make(RepositoryInterface::class);

            foreach ($repository->all() as $module) {
                if ($module->getLowerName() === $firstSegment) {
                    return $module->getName();
                }
            }

            return null;
        } catch (\Throwable) {
            // 请求周期外或仓库异常时安全返回 null
            return null;
        }
    }
}

if (! function_exists('module_namespace')) {
    /**
     * 获取模块的 PHP 命名空间
     *
     * 用于动态构建模块内类的完整类名，配合 module_class() 使用。
     * 优先从模块实例读取实际配置的命名空间，若模块未注册则使用默认值拼接。
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string 模块命名空间（如 'Modules\Blog'）
     *
     * @example
     * // 获取指定模块命名空间
     * $namespace = module_namespace('Blog'); // 'Modules\Blog'
     *
     * // 获取当前模块命名空间
     * $namespace = module_namespace(); // 如 'Modules\Admin'
     *
     * // 构建控制器完整类名
     * $controller = module_namespace('Blog') . '\\Http\\Controllers\\PostController';
     *
     * @see module_class() 更便捷地构建完整类名
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
            // 优先使用模块实例中配置的命名空间，否则回退到默认值
            return $moduleInstance?->getClassNamespace() ?? $defaultNamespace . '\\' . $module;
        } catch (\Throwable) {
            // 异常时返回默认拼接的命名空间
            return $defaultNamespace . '\\' . $module;
        }
    }
}

// ============================================================================
// 四、视图、路由与静态资源
// ============================================================================

if (! function_exists('module_url')) {
    /**
     * 生成模块的完整 URL
     *
     * 将路径自动附加到模块 URL 前缀下，生成包含域名和 scheme 的完整地址。
     * 适用于需要生成模块链接的场景（如邮件模板、API 响应等）。
     *
     * URL 构造规则：base_url + /{module_lower}/{path}
     *
     * @param  string       $path    路径（如 'posts/1'），前导斜杠会自动去除
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string 完整 URL（如 'https://example.com/blog/posts/1'）
     *
     * @example
     * // 指定模块
     * $url = module_url('posts/1', 'Blog'); // 'https://example.com/blog/posts/1'
     *
     * // 当前模块
     * $url = module_url('dashboard'); // 如 'https://example.com/admin/dashboard'
     */
    function module_url(string $path = '', ?string $module = null): string
    {
        $module ??= module_name() ?? 'default';
        return url(strtolower($module) . '/' . ltrim($path, '/'));
    }
}

if (! function_exists('module_route')) {
    /**
     * 生成模块路由的完整 URL
     *
     * 是 route() 函数的模块包装版本，自动处理路由名称前缀拼接。
     *
     * @param  string       $route   路由名称（如 'posts.index'）
     * @param  array        $params  路由参数（如 ['id' => 1]）
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string 完整路由 URL
     *
     * @example
     * // 生成索引页 URL
     * $url = module_route('posts.index', [], 'Blog');
     * // 等价于: route('blog.posts.index')
     *
     * // 带参数的路由
     * $url = module_route('posts.show', ['id' => 1], 'Blog');
     * // 等价于: route('blog.posts.show', ['id' => 1])
     *
     * // 当前模块
     * $url = module_route('dashboard'); // route('blog.dashboard')
     *
     * @see module_route_path() 仅生成路由名称而不生成 URL
     */
    function module_route(string $route = '', array $params = [], ?string $module = null): string
    {
        $module ??= module_name() ?? 'default';
        return route(strtolower($module) . '.' . $route, $params);
    }
}

if (! function_exists('module_asset')) {
    /**
     * 生成模块静态资源的完整 URL
     *
     * 用于引用模块内的 CSS、JS、图片等静态文件。
     * 资源通常存放在 public/modules/{module}/ 目录下。
     *
     * 路径构造规则：base_url + /modules/{module_lower}/{asset}
     *
     * @param  string       $asset   资源路径（如 'css/style.css'）
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string 完整资源 URL
     *
     * @example
     * // 指定模块的样式文件
     * $cssUrl = module_asset('css/style.css', 'Blog');
     * // 返回: 'https://example.com/modules/blog/css/style.css'
     *
     * // 当前模块的脚本文件
     * $jsUrl = module_asset('js/app.js'); // '/modules/blog/js/app.js'
     *
     * // 在 Blade 模板中使用
     * <link rel="stylesheet" href="{{ module_asset('css/admin.css') }}">
     */
    function module_asset(string $asset = '', ?string $module = null): string
    {
        $module ??= module_name() ?? 'default';
        return asset('modules/' . strtolower($module) . '/' . ltrim($asset, '/'));
    }
}

if (! function_exists('module_view')) {
    /**
     * 返回模块视图实例
     *
     * 是 view() 函数的模块包装版本，自动处理视图命名空间前缀，
     * 并支持一次性传递视图数据。
     *
     * @param  string       $view    视图名称（如 'post.index'）
     * @param  array        $data    视图数据数组
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return \Illuminate\View\View Laravel 视图实例
     *
     * @example
     * // 从控制器返回模块视图
     * return module_view('post.index', ['posts' => $posts], 'Blog');
     *
     * // 使用当前模块，传递多个变量
     * return module_view('dashboard', compact('stats', 'charts', 'alerts'));
     *
     * // 无数据视图
     * return module_view('auth.login');
     *
     * @see module_view_path() 仅生成视图路径字符串
     * @see module_has_view() 检查视图是否存在
     */
    function module_view(string $view = '', array $data = [], ?string $module = null): \Illuminate\View\View
    {
        $module ??= module_name() ?? 'default';
        return view(strtolower($module) . '::' . $view, $data);
    }
}

if (! function_exists('module_lang')) {
    /**
     * 获取模块翻译文本
     *
     * 是 trans() 函数的模块包装版本，自动处理翻译文件命名空间。
     * 模块翻译文件通常存放在 Resources/lang/ 目录下。
     *
     * @param  string       $key     翻译键（如 'messages.welcome'）
     * @param  array        $replace 占位符替换数组（如 ['name' => 'Admin']）
     * @param  string|null  $locale  语言环境（如 'zh_CN'），不传则使用当前语言
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string|array 翻译后的字符串，或整个语言数组（当 key 为空时）
     *
     * @example
     * // 基本翻译
     * $message = module_lang('messages.welcome', [], null, 'Blog');
     * // 等价于: trans('blog::messages.welcome')
     *
     * // 带占位符替换
     * $greeting = module_lang('messages.hello', ['name' => 'Admin']);
     * // 语言文件中: 'hello' => '你好, :name'
     *
     * // 当前模块翻译
     * $title = module_lang('common.site_title');
     */
    function module_lang(string $key = '', array $replace = [], ?string $locale = null, ?string $module = null): string|array
    {
        $module ??= module_name() ?? 'default';
        return trans(strtolower($module) . '::' . $key, $replace, $locale);
    }
}

if (! function_exists('module_stub')) {
    /**
     * 创建模块 Stub 生成器实例
     *
     * Stub 生成器用于根据模板文件生成模块代码文件（如控制器、模型等）。
     * 支持占位符替换，常用于代码生成命令或安装脚本。
     *
     * @param  string  $module  模块名称（目标模块）
     * @return StubGenerator Stub 生成器实例
     *
     * @example
     * // 创建生成器并渲染控制器
     * $generator = module_stub('Blog');
     * $code = $generator->render('controller', [
     *     'CLASS_NAMESPACE' => 'Modules\Blog\Http\Controllers',
     *     'CLASS' => 'PostController',
     *     'MODEL' => 'Post',
     * ]);
     *
     * @see StubGenerator 模板生成器类
     */
    function module_stub(string $module): StubGenerator
    {
        return new StubGenerator($module);
    }
}

if (! function_exists('module_class')) {
    /**
     * 构建模块内类的完整类名（Fully Qualified Class Name）
     *
     * 自动拼接模块命名空间和相对类名，避免手动处理命名空间分隔符。
     *
     * @param  string       $class   相对类名（如 'Models\Post'）
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string 完整类名（如 'Modules\Blog\Models\Post'）
     *
     * @example
     * // 构建控制器完整类名
     * $class = module_class('Http\Controllers\PostController', 'Blog');
     * // 返回: 'Modules\Blog\Http\Controllers\PostController'
     *
     * // 构建模型类名（使用当前模块）
     * $model = module_class('Models\Post'); // 'Modules\Blog\Models\Post'
     *
     * // 用于依赖注入解析
     * $instance = app(module_class('Services\PaymentService'));
     *
     * @see module_namespace() 获取模块命名空间
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
     * 用于在执行依赖特定配置的逻辑前，先验证配置是否已定义。
     * 支持检查整个配置文件是否存在，或检查配置中的特定键。
     *
     * @param  string       $configFile  配置文件名（如 'common'）
     * @param  string       $key         配置键（如 'name'），为空则检查整个文件
     * @param  string|null  $module      模块名称（可选，不传则使用当前模块）
     * @return bool true-配置存在, false-配置不存在或模块无效
     *
     * @example
     * // 检查配置项是否存在
     * if (module_has_config('common', 'name', 'Blog')) {
     *     $name = module_config('common.name');
     * }
     *
     * // 仅检查配置文件是否存在
     * if (module_has_config('settings')) { ... }
     *
     * // 当前模块配置检查
     * if (module_has_config('api', 'timeout')) { ... }
     *
     * @see module_config() 读取配置值
     * @see module_get_config() 获取完整配置数组
     */
    function module_has_config(string $configFile = '', string $key = '', ?string $module = null): bool
    {
        try {
            $module ??= module_name();

            if (! $module) {
                return false;
            }

            $configData = config(strtolower($module) . '.' . $configFile, []);

            // 未指定 key 时，检查整个配置是否非空
            if (empty($key)) {
                return ! empty($configData);
            }

            // 检查特定键是否存在（使用 array_key_exists 而非 isset，允许 null 值）
            return is_array($configData) && array_key_exists($key, $configData);
        } catch (\Throwable) {
            return false;
        }
    }
}

if (! function_exists('module_config_path')) {
    /**
     * 获取模块配置文件的绝对路径
     *
     * 是 module_path() 的包装函数，自动定位到模块的 Config/ 子目录。
     *
     * @param  string       $configFile  配置文件名（如 'common.php'）
     * @param  string|null  $module      模块名称（可选，不传则使用当前模块）
     * @return string 配置文件的完整路径
     * @throws RuntimeException 当无法确定模块时抛出异常
     *
     * @example
     * // 获取指定模块的配置路径
     * $path = module_config_path('common.php', 'Blog');
     * // 返回: '/var/www/app/Modules/Blog/Config/common.php'
     *
     * // 当前模块配置路径
     * $path = module_config_path('database.php');
     *
     * // 检查文件是否存在
     * if (file_exists(module_config_path('settings.php'))) { ... }
     *
     * @see module_path() 基础路径获取函数
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
     * 使用 Laravel 的视图工厂检查视图文件是否真实存在且可加载，
     * 在条件渲染或动态视图选择时非常有用。
     *
     * @param  string       $view    视图名称（如 'post.index'）
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return bool true-视图存在, false-视图不存在或模块无效
     *
     * @example
     * // 条件渲染：若视图存在则使用，否则使用默认视图
     * $view = module_has_view('post.custom') ? 'post.custom' : 'post.default';
     * return module_view($view, compact('post'));
     *
     * // 检查指定模块视图
     * if (module_has_view('emails.welcome', 'Notification')) { ... }
     *
     * @see module_view() 返回视图实例
     * @see module_view_path() 生成视图路径字符串
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
     * 获取模块路由文件的绝对路径
     *
     * 模块通常包含多个路由文件（web.php、api.php、admin.php 等），
     * 此函数提供统一的路由文件路径获取方式。
     *
     * @param  string       $route   路由文件名（如 'web'、'api'），不含扩展名
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string 路由文件的完整路径
     * @throws RuntimeException 当无法确定模块时抛出异常
     *
     * @example
     * // 获取 Web 路由文件路径
     * $path = module_routes_path('web', 'Blog');
     * // 返回: '/var/www/app/Modules/Blog/Routes/web.php'
     *
     * // 获取 API 路由文件路径（当前模块）
     * $path = module_routes_path('api');
     *
     * // 动态加载路由文件
     * require module_routes_path('custom');
     *
     * @see module_route_files() 获取所有路由文件列表
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
     * 获取模块数据库迁移目录的绝对路径
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string 迁移目录路径
     * @throws RuntimeException 当无法确定模块时抛出异常
     *
     * @example
     * // 获取指定模块的迁移目录
     * $path = module_migrations_path('Blog');
     * // 返回: '/var/www/app/Modules/Blog/Database/Migrations'
     *
     * // 获取当前模块迁移目录
     * $path = module_migrations_path();
     *
     * @see module_has_migration() 检查特定迁移是否存在
     * @see module_all_migrations() 获取所有迁移文件列表
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
     * 获取模块模型目录的绝对路径
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string 模型目录路径
     * @throws RuntimeException 当无法确定模块时抛出异常
     *
     * @example
     * $path = module_models_path('Blog'); // '/var/www/app/Modules/Blog/Models'
     * $path = module_models_path();       // 使用当前模块
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
     * 获取模块控制器目录的绝对路径
     *
     * 支持按控制器类型（Web/Api/Admin）获取对应的子目录路径。
     *
     * @param  string       $controller  控制器类型（如 'Web'、'Api'、'Admin'）
     * @param  string|null  $module      模块名称（可选，不传则使用当前模块）
     * @return string 控制器目录路径
     * @throws RuntimeException 当无法确定模块时抛出异常
     *
     * @example
     * // Web 控制器目录
     * $path = module_controllers_path('Web', 'Blog');
     * // 返回: '/var/www/app/Modules/Blog/Http/Controllers/Web'
     *
     * // API 控制器目录（当前模块）
     * $path = module_controllers_path('Api');
     *
     * // Admin 控制器目录
     * $path = module_controllers_path('Admin', 'Backend');
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
     * 获取模块视图目录的绝对路径
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string 视图目录路径（如 '/.../Blog/Resources/views'）
     * @throws RuntimeException 当无法确定模块时抛出异常
     *
     * @example
     * $path = module_views_path('Blog');
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
     * 获取模块翻译文件目录的绝对路径
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return string 翻译目录路径（如 '/.../Blog/Resources/lang'）
     * @throws RuntimeException 当无法确定模块时抛出异常
     *
     * @example
     * $path = module_trans_path('Blog');
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
     * 获取模块的所有配置文件列表
     *
     * 扫描模块 Config/ 目录，返回所有 PHP 配置文件名的数组。
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return array 配置文件名数组（如 ['common.php', 'settings.php']）
     *
     * @example
     * // 获取指定模块的所有配置文件
     * $files = module_config_files('Blog');
     * // 返回: ['common.php', 'database.php', 'cache.php']
     *
     * // 遍历配置文件并加载
     * foreach (module_config_files() as $file) {
     *     $name = basename($file, '.php');
     *     // ...
     * }
     *
     * @see module_get_config() 获取特定配置文件内容
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

            return array_map('basename', File::glob($configPath . '/*.php'));
        } catch (\Throwable) {
            return [];
        }
    }
}

if (! function_exists('module_route_files')) {
    /**
     * 获取模块的所有路由文件列表
     *
     * 扫描模块 Routes/ 目录，返回所有路由文件名（不含扩展名）的数组。
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return array 路由文件名数组（如 ['web', 'api', 'admin']）
     *
     * @example
     * // 获取指定模块的所有路由
     * $files = module_route_files('Blog');
     * // 返回: ['web', 'api', 'admin']
     *
     * // 动态加载所有路由文件
     * foreach (module_route_files() as $routeFile) {
     *     require module_routes_path($routeFile);
     * }
     *
     * @see module_routes_path() 获取特定路由文件路径
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

            // 提取文件名（不含 .php 扩展名）
            return array_map(fn($file) => pathinfo($file, PATHINFO_FILENAME), File::glob($routesPath . '/*.php'));
        } catch (\Throwable) {
            return [];
        }
    }
}

if (! function_exists('module_get_config')) {
    /**
     * 获取模块配置文件的完整配置数组
     *
     * 与 module_config() 不同，此函数始终返回整个配置文件的内容，
     * 适用于需要批量读取配置项的场景。
     *
     * @param  string       $configFile  配置文件名（如 'common'）
     * @param  string|null  $module      模块名称（可选，不传则使用当前模块）
     * @return array 配置项关联数组，失败返回空数组
     *
     * @example
     * // 获取指定模块的完整配置
     * $config = module_get_config('common', 'Blog');
     * // 返回: ['name' => 'Blog', 'version' => '1.0', ...]
     *
     * // 遍历配置项
     * foreach (module_get_config('settings') as $key => $value) {
     *     echo "{$key}: {$value}\n";
     * }
     *
     * @see module_config() 读取单个配置值
     * @see module_set_config() 运行时设置配置值
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
     * 运行时设置模块配置值（非持久化）
     *
     * 仅在当前请求生命周期内有效，不会影响配置文件本身。
     * 适用于根据运行时条件动态调整模块行为的场景。
     *
     * 注意：此修改仅影响当前请求，请求结束后配置恢复原值。
     *
     * @param  string       $configFile  配置文件名
     * @param  string       $key         配置键
     * @param  mixed        $value       配置值（任意类型）
     * @param  string|null  $module      模块名称（可选，不传则使用当前模块）
     * @return void
     *
     * @example
     * // 运行时切换调试模式
     * module_set_config('common', 'debug', true, 'Blog');
     *
     * // 动态设置缓存时间（当前模块）
     * module_set_config('settings', 'cache_ttl', 3600);
     *
     * // 基于用户权限调整配置
     * if (auth()->user()->isVip()) {
     *     module_set_config('limits', 'max_upload', 100 * 1024 * 1024);
     * }
     *
     * @see module_config() 读取配置值
     * @see config() Laravel 原生配置函数
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
            // 静默失败，确保不影响业务流程
        }
    }
}

// ============================================================================
// 五、模块状态与枚举
// ============================================================================

if (! function_exists('module_has_migration')) {
    /**
     * 检查模块是否存在指定的迁移文件
     *
     * 通过 glob 模式匹配迁移文件名（支持部分匹配）。
     * 迁移文件名格式：{timestamp}_{migration_name}.php
     *
     * @param  string       $migrationName  迁移文件名（不含时间戳和扩展名，如 'create_posts_table'）
     * @param  string|null  $module         模块名称（可选，不传则使用当前模块）
     * @return bool true-迁移文件存在, false-不存在或模块无效
     *
     * @example
     * // 检查 posts 表迁移是否存在
     * if (module_has_migration('create_posts_table', 'Blog')) {
     *     // 安全地引用迁移
     * }
     *
     * // 检查当前模块的 users 迁移
     * if (module_has_migration('create_users_table')) { ... }
     *
     * @see module_all_migrations() 获取所有迁移文件列表
     * @see module_migrations_path() 获取迁移目录路径
     */
    function module_has_migration(string $migrationName = '', ?string $module = null): bool
    {
        try {
            $module ??= module_name();

            if (! $module || empty($migrationName)) {
                return false;
            }

            // 匹配模式：*_{migrationName}.php（任意时间戳前缀）
            return ! empty(File::glob(module_migrations_path($module) . '/*_' . $migrationName . '.php'));
        } catch (\Throwable) {
            return false;
        }
    }
}

if (! function_exists('module_all_migrations')) {
    /**
     * 获取模块的所有迁移文件列表
     *
     * 返回迁移目录中所有 PHP 文件的文件名数组（含时间戳前缀）。
     *
     * @param  string|null  $module  模块名称（可选，不传则使用当前模块）
     * @return array 迁移文件名数组（如 ['2024_01_01_000001_create_posts_table.php', ...]）
     *
     * @example
     * // 获取指定模块的所有迁移
     * $migrations = module_all_migrations('Blog');
     *
     * // 统计迁移数量
     * $count = count(module_all_migrations());
     *
     * @see module_has_migration() 检查特定迁移是否存在
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
     * 获取所有已启用的模块列表
     *
     * 从所有模块中筛选出状态为启用的模块。
     * 结果在请求生命周期内静态缓存。
     *
     * @return array<ModuleInterface> 已启用模块实例数组
     *
     * @example
     * // 遍历所有启用模块并执行初始化
     * foreach (module_enabled_modules() as $module) {
     *     // 加载启用的模块服务
     * }
     *
     * // 获取启用模块数量
     * $count = count(module_enabled_modules());
     *
     * @see module_disabled_modules() 获取已禁用模块
     * @see module_enabled() 检查单个模块是否启用
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
     * 获取所有已禁用的模块列表
     *
     * 从所有模块中筛选出状态为禁用的模块。
     * 结果在请求生命周期内静态缓存。
     *
     * @return array<ModuleInterface> 已禁用模块实例数组
     *
     * @example
     * // 获取所有禁用模块
     * $disabled = module_disabled_modules();
     *
     * // 批量清理禁用模块的缓存
     * foreach (module_disabled_modules() as $module) {
     *     Cache::forget("module:{$module->getLowerName()}");
     * }
     *
     * @see module_enabled_modules() 获取已启用模块
     * @see module_enabled() 检查单个模块是否启用
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

// ============================================================================
// 六、通用辅助函数
// ============================================================================

if (! function_exists('get_user_info')) {
    /**
     * 获取当前认证用户的信息
     *
     * 遍历所有已配置的认证 Guard，查找当前已登录的用户。
     * 适用于多 Guard（如 web、api、admin）场景下的统一用户信息获取。
     *
     * @param  string|null  $field  用户信息字段名（如 'name'、'email'）
     *                             为 null 时返回完整用户数组
     * @return mixed 用户字段值、完整用户数组，或 null（未登录）
     *
     * @example
     * // 获取完整用户信息
     * $user = get_user_info();
     * // 返回: ['id' => 1, 'name' => 'Admin', 'email' => 'admin@example.com', ...]
     *
     * // 获取特定字段
     * $userName = get_user_info('name'); // 'Admin'
     * $userId = get_user_info('id');     // 1
     *
     * // 命令行环境始终返回 null
     * if (app()->runningInConsole()) {
     *     get_user_info(); // null
     * }
     */
    function get_user_info(?string $field = null): mixed
    {
        $user = null;

        // 命令行环境下无法获取 HTTP 认证信息
        if (app()->runningInConsole()) {
            return null;
        }

        try {
            // 遍历所有配置的认证 Guard
            $authConfig = config('auth.guards');
            foreach ($authConfig as $guard => $config) {
                if (auth($guard)->check()) {
                    $user = auth($guard)->user()->toArray();
                    break;
                }
            }

            // 未登录时返回 null
            if (empty($user)) {
                return null;
            }

            // 未指定字段时返回完整用户数组
            if (empty($field)) {
                return $user;
            }

            // 返回指定字段值，不存在则返回 null
            return $user[$field] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }
}


if (! function_exists('view_share')) {
    /**
     * 向所有视图共享数据
     *
     * 支持批量共享（传入关联数组）或单个变量共享。
     * 共享的数据在所有 Blade 模板中均可直接访问。
     *
     * @param  string|array  $key    变量名，或变量名-值关联数组
     * @param  mixed         $value  变量值（当 $key 为字符串时使用）
     * @return void
     *
     * @example
     * // 共享单个变量
     * view_share('appName', 'My Application');
     *
     * // 在 Blade 中使用: {{ $appName }}
     *
     * // 批量共享多个变量
     * view_share([
     *     'siteName' => 'Blog',
     *     'version'  => '2.0',
     *     'user'     => auth()->user(),
     * ]);
     *
     * @see get_view_share() 获取已共享的数据
     * @see View::share() Laravel 视图共享底层方法
     */
    function view_share(string|array $key, mixed $value = ''): void
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                View::share($k, $v);
            }
        } else {
            View::share($key, $value);
        }
    }
}

if (! function_exists('get_view_share')) {
    /**
     * 获取已共享到视图的数据
     *
     * 注意：仅返回调用此函数之前通过 view_share() 或 View::share() 共享的数据。
     *
     * @param  string  $key  [可选] 指定变量名，为空则返回所有共享数据
     * @return mixed 指定变量值，或全部共享数据的关联数组
     *
     * @example
     * // 获取所有共享数据
     * $shared = get_view_share();
     * // 返回: ['appName' => 'My App', 'user' => User {...}]
     *
     * // 获取特定共享变量
     * $appName = get_view_share('appName'); // 'My App'
     *
     * // 检查变量是否已共享
     * if (get_view_share('user') !== null) { ... }
     *
     * @see view_share() 向视图共享数据
     */
    function get_view_share(string $key = ''): mixed
    {
        $data = View::getShared();

        if (! empty($key)) {
            return $data[$key] ?? null;
        }

        return $data;
    }
}

if (! function_exists('view_exists')) {
    /**
     * 判断视图文件是否存在
     *
     * 支持检查普通视图路径和模块命名空间视图。
     *
     * @param  string  $view  视图名称或路径（如 'post.index' 或 'blog::post.index'）
     * @return bool true-视图存在, false-视图不存在
     *
     * @example
     * // 检查普通视图
     * if (view_exists('layouts.app')) { ... }
     *
     * // 检查模块视图
     * if (view_exists('blog::post.index')) { ... }
     *
     * @see module_has_view() 专门检查模块视图
     * @see View::exists() Laravel 视图检查底层方法
     */
    function view_exists(string $view): bool
    {
        return View::exists($view);
    }
}

if (! function_exists('source_local_website')) {
    /**
     * 判断请求的来源地址是否来自本站
     *
     * 用于安全验证、来源分析或智能重定向等场景。
     * 依次从 session、URL 生成器、HTTP Referer 头中获取来源地址。
     *
     * @param  string  $returnType  返回类型：
     *                              - 'status': 返回 bool，是否来自本站
     *                              - 'url':    来自本站时返回完整 URL，否则返回 ''
     *                              - 'uri':    来自本站时返回 URI 路径，否则返回 ''
     *                              - 'prefix': 来自本站时返回 URI 第一段（如模块名），否则返回 ''
     *                              - 'all':    返回完整信息数组（默认）
     * @return bool|array|string|null 根据 $returnType 返回对应类型
     *
     * @example
     * // 检查是否来自本站
     * if (source_local_website('status')) {
     *     // 安全地执行返回操作
     * }
     *
     * // 获取来源 URL（仅本站）
     * $url = source_local_website('url'); // 如 'https://example.com/blog/posts'
     *
     * // 获取来源 URI 前缀（判断从哪个模块跳转）
     * $prefix = source_local_website('prefix'); // 如 'blog'
     *
     * // 获取完整信息
     * $info = source_local_website('all');
     * // 返回: ['local' => true, 'url' => '...', 'uri' => '/blog/posts', 'prefix' => 'blog']
     */
    function source_local_website(string $returnType = 'all'): bool|array|string|null
    {
        // 优先级：Session 记录的前一个 URL > URL 生成器 > HTTP Referer 头
        $sessionUrl = session()->previousUrl();
        $previousUrl = url()->previous();
        $referer = ! empty($sessionUrl) ? $sessionUrl : (! empty($previousUrl) ? $previousUrl : request()->header('referer', ''));

        // 判断来源是否为本站：比对域名
        $isLocal = false;
        if (! empty($referer)) {
            $refererHost = parse_url($referer, PHP_URL_HOST) ?? '';
            $appHost = parse_url(config('app.url'), PHP_URL_HOST) ?? '';
            // 安全匹配：完全相等或是子域名（避免 evil-example.com 匹配 example.com）
            $isLocal = $refererHost === $appHost
                || str_ends_with('.' . $refererHost, '.' . $appHost);
        }

        // 解析 URI 和 URI 前缀
        $uri = $uriPrefix = '';
        if ($isLocal) {
            $uri = parse_url($referer, PHP_URL_PATH) ?? '';
            // 获取 URI 路径的第一个段（通常对应模块名或路由前缀）
            $uriPrefix = explode('/', ltrim($uri, '/'))[0] ?? '';
        }

        // 根据返回类型返回对应格式
        return match ($returnType) {
            'status' => (bool) $isLocal, // 返回来源是否是本站
            'url'    => $isLocal ? $referer : '', // 当来源地址是本站时，返回来源地址，否则返回空
            'uri'    => $isLocal ? $uri : '', // 当来源地址是本站时，返回来源uri地址，否则返回空
            'prefix' => $isLocal ? $uriPrefix : '', // 当来源地址是本站时，返回来源uri地址，否则返回空
            default  => [
                'local'  => (bool) $isLocal,
                'url'    => $isLocal ? $referer : '',
                'uri'    => $isLocal ? $uri : '',
                'prefix' => $isLocal ? $uriPrefix : '',
            ],
        };
    }
}
