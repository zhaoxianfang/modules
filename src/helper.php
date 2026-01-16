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
 * 大部分函数支持无参调用，会自动检测当前所在模块
 * 
 * 核心函数说明：
 * - module_name(): 通过文件路径精确检测当前模块，不使用缓存
 * - module_config(): 智能配置读取，支持嵌套配置和当前模块自动检测
 */

if (! function_exists('module_name')) {
    /**
     * 获取当前所在的模块名称
     *
     * 通过文件路径精确检测当前代码所在的模块，无需传递参数
     * 不使用缓存，每次调用都进行准确的检测
     *
     * @return string|null 返回模块名称（StudlyCase），如果在模块外则返回 null
     * 
     * @example
     * // 在 Blog/Http/Controllers/PostController.php 中调用
     * $moduleName = module_name(); // 'Blog'
     */
    function module_name(): ?string
    {
        // 获取模块路径配置
        $modulePath = config('modules.path', base_path('Modules'));
        
        // 标准化路径
        $modulePath = rtrim($modulePath, DIRECTORY_SEPARATOR);
        
        // 获取调用栈
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS | DEBUG_BACKTRACE_PROVIDE_OBJECT, 20);
        
        // 遍历调用栈查找模块文件
        foreach ($backtrace as $trace) {
            // 检查文件是否存在
            if (! isset($trace['file']) || ! is_string($trace['file'])) {
                continue;
            }
            
            $filePath = $trace['file'];
            
            // 标准化文件路径
            $filePath = str_replace('\\', '/', $filePath);
            $modulePathNormalized = str_replace('\\', '/', $modulePath);
            
            // 检查文件是否在模块路径下
            if (strpos($filePath, $modulePathNormalized) === false) {
                continue;
            }
            
            // 提取模块名
            $relativePath = substr($filePath, strlen($modulePathNormalized));
            
            // 跳过路径开头的斜杠
            if (strpos($relativePath, '/') === 0) {
                $relativePath = substr($relativePath, 1);
            }
            
            // 分割路径获取第一部分（模块名）
            $segments = explode('/', $relativePath);
            
            if (! empty($segments[0])) {
                // 转换为 StudlyCase
                $moduleName = Str::studly($segments[0]);
                
                // 验证模块是否真实存在
                if (module_exists($moduleName)) {
                    return $moduleName;
                }
            }
        }
        
        return null;
    }
}

if (! function_exists('module_path')) {
    /**
     * 获取模块目录的路径
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
        try {
            $repository = App::make(RepositoryInterface::class);
            
            // 如果没有指定模块，使用当前模块
            if (is_null($module)) {
                $module = module_name();
            }
            
            if (empty($module)) {
                throw new \RuntimeException('无法确定模块名称，请传递明确的模块名或确保在模块内部调用');
            }
            
            return $repository->getModulePath($module, $path);
        } catch (\Exception $e) {
            throw new \RuntimeException('获取模块路径失败: ' . $e->getMessage(), 0, $e);
        }
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
     * 不使用缓存，每次调用都准确读取配置
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
    function module_config(string $module, $key, $default = null)
    {
        try {
            // 检查第一个参数是否包含点号（配置文件路径）
            if (str_contains($module, '.') && ! str_starts_with($module, '\\')) {
                // 解析配置文件路径，如 'common.name' 或 'settings.cache.enabled'
                $parts = explode('.', $module, 2);
                $configFile = $parts[0]; // common 或 settings
                $configKey = $parts[1] ?? ''; // name 或 cache.enabled
                
                // 获取当前模块名称
                $currentModule = module_name();
                
                if (! $currentModule) {
                    // 无法检测到当前模块，返回默认值
                    return $key;
                }
                
                // 验证模块是否存在
                if (! module_exists($currentModule)) {
                    return $key;
                }
                
                // 构建配置键
                $fullConfigKey = strtolower($currentModule) . '.' . $configFile;
                
                // 读取配置文件
                $configData = config($fullConfigKey, []);
                
                // 如果配置不是数组或为空，返回默认值
                if (! is_array($configData) || empty($configData)) {
                    return $key;
                }
                
                // 支持嵌套配置读取
                if (! empty($configKey) && str_contains($configKey, '.')) {
                    $nestedKeys = explode('.', $configKey);
                    
                    foreach ($nestedKeys as $nestedKey) {
                        if (is_array($configData) && array_key_exists($nestedKey, $configData)) {
                            $configData = $configData[$nestedKey];
                        } else {
                            return $key; // 返回默认值
                        }
                    }
                    
                    return $configData;
                }
                
                // 如果配置项存在，返回其值
                if (array_key_exists($configKey, $configData)) {
                    return $configData[$configKey];
                }
                
                // 如果配置项不存在，返回第二个参数作为默认值
                return $key;
            }
            
            // 传统用法：module_config('Blog', 'key', 'default')
            if (! module_exists($module)) {
                throw new \RuntimeException("模块 '{$module}' 不存在");
            }
            
            $configKey = ConfigLoader::getConfigKey($module, $key);
            return config($configKey, $default);
            
        } catch (\Exception $e) {
            // 出现异常时返回默认值
            return $default ?? $key;
        }
    }
}

if (! function_exists('module_enabled')) {
    /**
     * 检查模块是否已启用
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
        try {
            $repository = App::make(RepositoryInterface::class);
            
            // 如果没有指定模块，检查当前模块
            if (is_null($module)) {
                $module = module_name();
            }
            
            if (! $module) {
                return false;
            }
            
            $moduleInstance = $repository->find($module);
            
            if (! $moduleInstance) {
                return false;
            }
            
            return $moduleInstance->isEnabled();
        } catch (\Exception $e) {
            return false;
        }
    }
}

if (! function_exists('module_exists')) {
    /**
     * 检查模块是否存在
     *
     * @param  string  $module  模块名称
     * @return bool
     * 
     * @example
     * if (module_exists('Blog')) { }
     */
    function module_exists(string $module): bool
    {
        try {
            $repository = App::make(RepositoryInterface::class);
            return $repository->has($module);
        } catch (\Exception $e) {
            return false;
        }
    }
}

if (! function_exists('module')) {
    /**
     * 获取模块实例或模块仓库
     *
     * @param  string|null  $module  模块名称（可选，不传则返回仓库）
     * @return ModuleInterface|RepositoryInterface
     * 
     * @example
     * $repository = module(); // 获取模块仓库
     * $blogModule = module('Blog'); // 获取 Blog 模块实例
     * $currentModule = module(module_name()); // 获取当前模块实例
     */
    function module(?string $module = null)
    {
        try {
            $repository = App::make(RepositoryInterface::class);
            
            if ($module) {
                return $repository->find($module);
            }
            
            return $repository;
        } catch (\Exception $e) {
            throw new \RuntimeException('获取模块失败: ' . $e->getMessage(), 0, $e);
        }
    }
}

if (! function_exists('modules')) {
    /**
     * 获取所有模块
     *
     * @return array<ModuleInterface>
     * 
     * @example
     * $allModules = modules();
     */
    function modules(): array
    {
        try {
            return App::make(RepositoryInterface::class)->all();
        } catch (\Exception $e) {
            return [];
        }
    }
}

if (! function_exists('module_view_path')) {
    /**
     * 获取模块视图路径（用于返回视图）
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
        if (is_null($module)) {
            $module = module_name() ?? 'default';
        }
        return strtolower($module) . '::' . $view;
    }
}

if (! function_exists('module_route_path')) {
    /**
     * 获取模块路由名称前缀
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
        if (is_null($module)) {
            $module = module_name() ?? 'default';
        }
        $prefix = strtolower($module) . '.';
        return $route ? $prefix . $route : $prefix;
    }
}

if (! function_exists('current_module')) {
    /**
     * 获取当前请求所在的模块（通过 URL 路径分析）
     *
     * @return string|null
     * 
     * @example
     * // 访问 /blog/posts 时
     * $moduleName = current_module(); // 'Blog'
     */
    function current_module(): ?string
    {
        try {
            if (! function_exists('request')) {
                return null;
            }
            
            $path = request()->path();
            $segments = explode('/', $path);
            
            if (empty($segments) || empty($segments[0])) {
                return null;
            }
            
            $firstSegment = $segments[0];
            
            $repository = App::make(RepositoryInterface::class);
            $allModules = $repository->all();
            
            foreach ($allModules as $module) {
                if ($module->getLowerName() === $firstSegment) {
                    return $module->getName();
                }
            }
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
}

if (! function_exists('module_namespace')) {
    /**
     * 获取模块的命名空间
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
        try {
            $repository = App::make(RepositoryInterface::class);
            
            // 如果没有指定模块，使用当前模块
            if (is_null($module)) {
                $module = module_name();
            }
            
            if (! $module) {
                $namespace = config('modules.namespace', 'Modules');
                return $namespace;
            }
            
            $moduleInstance = $repository->find($module);
            
            if (! $moduleInstance) {
                $namespace = config('modules.namespace', 'Modules');
                return $namespace . '\\' . Str::studly($module);
            }
            
            return $moduleInstance->getClassNamespace();
        } catch (\Exception $e) {
            $namespace = config('modules.namespace', 'Modules');
            return $namespace . '\\' . Str::studly($module ?? 'Module');
        }
    }
}

if (! function_exists('module_url')) {
    /**
     * 获取模块 URL
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
        if (is_null($module)) {
            $module = module_name() ?? 'default';
        }
        $prefix = strtolower($module);
        return url($prefix . '/' . ltrim($path, '/'));
    }
}

if (! function_exists('module_route')) {
    /**
     * 生成模块路由 URL
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
        if (is_null($module)) {
            $module = module_name() ?? 'default';
        }
        return route(strtolower($module) . '.' . $route, $params);
    }
}

if (! function_exists('module_asset')) {
    /**
     * 生成模块静态资源 URL
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
        if (is_null($module)) {
            $module = module_name() ?? 'default';
        }
        $assetsPath = config('modules.assets', public_path('modules'));
        $moduleName = strtolower($module);
        
        return asset('modules/' . $moduleName . '/' . ltrim($asset, '/'));
    }
}

if (! function_exists('module_view')) {
    /**
     * 返回模块视图
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
    function module_view(?string $module = null, string $view = '', array $data = [])
    {
        if (is_null($module)) {
            $module = module_name() ?? 'default';
        }
        return view(strtolower($module) . '::' . $view, $data);
    }
}

if (! function_exists('module_lang')) {
    /**
     * 获取模块翻译
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
    function module_lang(?string $module = null, string $key = '', array $replace = [], ?string $locale = null)
    {
        if (is_null($module)) {
            $module = module_name() ?? 'default';
        }
        return trans(strtolower($module) . '::' . $key, $replace, $locale);
    }
}

if (! function_exists('module_stub')) {
    /**
     * 创建模块 Stub 生成器
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
        $namespace = module_namespace($module);
        return $namespace . '\\' . $class;
    }
}

if (! function_exists('module_has_config')) {
    /**
     * 检查模块配置项是否存在
     *
     * @param  string|null  $module     模块名称（可选，不传则使用当前模块）
     * @param  string  $configFile  配置文件名（如 'common'）
     * @param  string  $key        配置键（如 'name'）
     * @return bool
     * 
     * @example
     * if (module_has_config('Blog', 'common', 'name')) { }
     * if (module_has_config(null, 'common', 'name')) { }
     */
    function module_has_config(?string $module = null, string $configFile = '', string $key = ''): bool
    {
        try {
            if (is_null($module)) {
                $module = module_name();
            }
            
            if (! $module) {
                return false;
            }
            
            $fullConfigKey = strtolower($module) . '.' . $configFile;
            $configData = config($fullConfigKey, []);
            
            if (empty($key)) {
                return ! empty($configData);
            }
            
            return is_array($configData) && array_key_exists($key, $configData);
        } catch (\Exception $e) {
            return false;
        }
    }
}

if (! function_exists('module_config_path')) {
    /**
     * 获取模块配置文件路径
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
        if (is_null($module)) {
            $module = module_name();
        }
        
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
            if (is_null($module)) {
                $module = module_name();
            }
            
            if (! $module) {
                return false;
            }
            
            return view()->exists(strtolower($module) . '::' . $view);
        } catch (\Exception $e) {
            return false;
        }
    }
}

if (! function_exists('module_routes_path')) {
    /**
     * 获取模块路由文件路径
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
        if (is_null($module)) {
            $module = module_name();
        }
        
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
        if (is_null($module)) {
            $module = module_name();
        }
        
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
        if (is_null($module)) {
            $module = module_name();
        }
        
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
        if (is_null($module)) {
            $module = module_name();
        }
        
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
        if (is_null($module)) {
            $module = module_name();
        }
        
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
        if (is_null($module)) {
            $module = module_name();
        }
        
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
            if (is_null($module)) {
                $module = module_name();
            }
            
            if (! $module) {
                return [];
            }
            
            $configPath = module_path($module, 'Config');
            
            if (! is_dir($configPath)) {
                return [];
            }
            
            $files = [];
            foreach (File::glob($configPath . '/*.php') as $file) {
                $files[] = basename($file);
            }
            
            return $files;
        } catch (\Exception $e) {
            return [];
        }
    }
}

if (! function_exists('module_route_files')) {
    /**
     * 获取模块的所有路由文件
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
            if (is_null($module)) {
                $module = module_name();
            }
            
            if (! $module) {
                return [];
            }
            
            $routesPath = module_path($module, 'Routes');
            
            if (! is_dir($routesPath)) {
                return [];
            }
            
            $files = [];
            foreach (File::glob($routesPath . '/*.php') as $file) {
                $files[] = pathinfo($file, PATHINFO_FILENAME);
            }
            
            return $files;
        } catch (\Exception $e) {
            return [];
        }
    }
}

if (! function_exists('module_get_config')) {
    /**
     * 获取模块配置文件的所有配置（完整数组）
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
            if (is_null($module)) {
                $module = module_name();
            }
            
            if (! $module || empty($configFile)) {
                return [];
            }
            
            $fullConfigKey = strtolower($module) . '.' . $configFile;
            $configData = config($fullConfigKey, []);
            
            return is_array($configData) ? $configData : [];
        } catch (\Exception $e) {
            return [];
        }
    }
}

if (! function_exists('module_set_config')) {
    /**
     * 设置模块配置值（运行时）
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
    function module_set_config(?string $module = null, string $configFile = '', string $key = '', $value = null): void
    {
        try {
            if (is_null($module)) {
                $module = module_name();
            }
            
            if (! $module || empty($configFile) || empty($key)) {
                return;
            }
            
            $fullConfigKey = strtolower($module) . '.' . $configFile . '.' . $key;
            config([$fullConfigKey => $value]);
        } catch (\Exception $e) {
            // 静默失败
        }
    }
}

if (! function_exists('module_has_migration')) {
    /**
     * 检查模块是否存在指定的迁移文件
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
            if (is_null($module)) {
                $module = module_name();
            }
            
            if (! $module || empty($migrationName)) {
                return false;
            }
            
            $migrationsPath = module_migrations_path($module);
            
            foreach (File::glob($migrationsPath . '/*_' . $migrationName . '.php') as $file) {
                if (File::exists($file)) {
                    return true;
                }
            }
            
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }
}

if (! function_exists('module_all_migrations')) {
    /**
     * 获取模块的所有迁移文件
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
            if (is_null($module)) {
                $module = module_name();
            }
            
            if (! $module) {
                return [];
            }
            
            $migrationsPath = module_migrations_path($module);
            
            if (! is_dir($migrationsPath)) {
                return [];
            }
            
            $migrations = [];
            foreach (File::glob($migrationsPath . '/*.php') as $file) {
                $migrations[] = basename($file);
            }
            
            return $migrations;
        } catch (\Exception $e) {
            return [];
        }
    }
}

if (! function_exists('module_enabled_modules')) {
    /**
     * 获取所有已启用的模块
     *
     * @return array<ModuleInterface>
     * 
     * @example
     * $enabled = module_enabled_modules();
     */
    function module_enabled_modules(): array
    {
        try {
            $all = modules();
            return array_filter($all, function ($module) {
                return $module->isEnabled();
            });
        } catch (\Exception $e) {
            return [];
        }
    }
}

if (! function_exists('module_disabled_modules')) {
    /**
     * 获取所有已禁用的模块
     *
     * @return array<ModuleInterface>
     * 
     * @example
     * $disabled = module_disabled_modules();
     */
    function module_disabled_modules(): array
    {
        try {
            $all = modules();
            return array_filter($all, function ($module) {
                return ! $module->isEnabled();
            });
        } catch (\Exception $e) {
            return [];
        }
    }
}
