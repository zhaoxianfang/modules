# 配置详解

模块系统的所有配置都在 `config/modules.php` 文件中。本指南将详细介绍每个配置选项。

## 完整配置示例

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 模块命名空间
    |--------------------------------------------------------------------------
    | 定义模块的根命名空间，所有模块类都将使用此前缀
    | 例如: 'Modules' 则模块类命名空间为 Modules\Blog\Http\Controllers\Controller
    |
    */
    'namespace' => 'Modules',

    /*
    |--------------------------------------------------------------------------
    | 模块存储路径
    |--------------------------------------------------------------------------
    | 定义模块存储的基础路径
    | 所有命令在生成文件时都会基于此路径
    |
    */
    'path' => base_path('Modules'),

    /*
    |--------------------------------------------------------------------------
    | 模块资源发布路径
    |--------------------------------------------------------------------------
    | 定义模块静态资源发布到的公共路径
    |
    */
    'assets' => public_path('modules'),

    /*
    |--------------------------------------------------------------------------
    | 文件生成路径配置
    |--------------------------------------------------------------------------
    | 定义模块内部各组件的相对路径
    | 可通过修改这些配置自定义模块结构
    | 所有路径相对于模块根目录
    |
    */
    'paths' => [
        /*
        |--------------------------------------------------------------------------
        | 迁移文件路径
        |--------------------------------------------------------------------------
        | 定义模块迁移文件在模块内的存储路径
        |
        */
        'migration' => 'Database/Migrations',

        /*
        |--------------------------------------------------------------------------
        | 代码生成器路径配置
        |--------------------------------------------------------------------------
        | 定义各类代码生成的目标路径: 根据 generate 决定是否创建文件或目录
        |
        */
        'generator' => [
            // 服务提供者
            'provider' => ['path' => 'Providers', 'generate' => true],
            // 配置文件
            'config' => ['path' => 'Config', 'generate' => true],
            // 路由文件
            'route' => ['path' => 'Routes', 'generate' => true],
            // 控制器
            'controller' => ['path' => 'Http/Controllers', 'generate' => true],
            // Web控制器子目录
            'controller.web' => ['path' => 'Http/Controllers/Web', 'generate' => true],
            // API控制器子目录
            'controller.api' => ['path' => 'Http/Controllers/Api', 'generate' => true],
            // Admin控制器子目录
            'controller.admin' => ['path' => 'Http/Controllers/Admin', 'generate' => false],
            // 模型
            'model' => ['path' => 'Models', 'generate' => true],
            // 模型观察者
            'observer' => ['path' => 'Observers', 'generate' => false],
            // 策略类
            'policy' => ['path' => 'Policies', 'generate' => false],
            // 仓库类
            'repository' => ['path' => 'Repositories', 'generate' => false],
            // 表单验证请求
            'request' => ['path' => 'Http/Requests', 'generate' => false],
            // API 资源转换器
            'resource' => ['path' => 'Http/Resources', 'generate' => true],
            // 中间件
            'middleware' => ['path' => 'Http/Middleware', 'generate' => false],
            // 控制台命令
            'command' => ['path' => 'Console/Commands', 'generate' => false],
            // 事件
            'event' => ['path' => 'Events', 'generate' => false],
            // 事件监听器
            'listener' => ['path' => 'Listeners', 'generate' => false],
            // 数据库迁移[注：示例迁移]
            'migration' => ['path' => 'Database/Migrations', 'generate' => false],
            // 数据填充器
            'seeder' => ['path' => 'Database/Seeders', 'generate' => true],
            // 视图文件
            'views' => ['path' => 'Resources/views', 'generate' => true],
            // 语言文件
            'lang' => ['path' => 'Resources/lang', 'generate' => false],
            // 测试文件
            'test' => ['path' => 'Tests', 'generate' => false],
            // 资源文件
            'assets' => ['path' => 'Resources/assets', 'generate' => false],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 路由中间件组
    |--------------------------------------------------------------------------
    | 定义不同路由文件自动加载的中间件组
    | 键为路由文件名（不含扩展名），值为中间件组名称数组
    |
    | 示例:
    | - 'web' => ['web'] 表示 web.php 路由将自动应用 web 中间件组
    | - 'api' => ['api'] 表示 api.php 路由将自动应用 api 中间件组
    | - 'mobile' => ['mobile'] 表示 mobile.php 路由将自动应用 mobile 中间件组
    |
    | 支持任意自定义路由类型，不再限制于 web/api/admin
    | 可以根据项目需求添加更多路由类型和对应的中间件组
    |
    */
    'middleware_groups' => [
        'web' => ['web'],
        'api' => ['api'],
        // 'admin' => ['web', 'admin'], // 需要自定义实现 admin 中间件
        'admin' => ['web'],
        // 可以添加更多自定义路由类型
        // 'mobile' => ['mobile'],
        // 'miniapp' => ['auth:miniapp'],
    ],

    /*
    |--------------------------------------------------------------------------
    | 路由配置
    |--------------------------------------------------------------------------
    | 路由加载相关配置
    |
    */
    'routes' => [
        // 是否自动添加模块前缀到路由
        'prefix' => true,
        // 是否自动添加模块名称到路由名称
        'name_prefix' => true,
        // 默认路由文件列表 eg: ['web', 'api', admin],
        'default_files' => ['web', 'api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | 视图配置
    |--------------------------------------------------------------------------
    | 视图加载和命名空间配置
    |
    */
    'views' => [
        // 是否自动注册视图命名空间
        'enabled' => true,
        // 视图命名空间格式: 模块小写名称
        // 例如: blog 模块的视图使用 blog::view.name
        'namespace_format' => 'lower',
        // 可选值: 'lower' (blog), 'studly' (Blog), 'camel' (blogModule)
    ],

    /*
    |--------------------------------------------------------------------------
    | 翻译文件配置
    |--------------------------------------------------------------------------
    | 语言文件加载配置
    |
    */
    'translations' => [
        // 是否自动注册翻译命名空间
        'enabled' => true,
        // 翻译文件路径
        'path' => 'Resources/lang',
    ],

    /*
    |--------------------------------------------------------------------------
    | 自动发现配置
    |--------------------------------------------------------------------------
    | 定义需要自动发现的模块组件
    | 设置为 false 可禁用特定组件的自动加载
    |
    */
    'discovery' => [
        'routes' => true,
        'providers' => true,
        'commands' => true,
        'views' => true,
        'config' => true,
        'translations' => true,
        'migrations' => true,
        'events' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | 模块缓存配置
    |--------------------------------------------------------------------------
    | 模块信息缓存相关配置
    |
    */
    'cache' => [
        // 是否启用模块缓存
        'enabled' => false,
        // 缓存键名
        'key' => 'modules',
        // 缓存时间（秒），0 表示永久
        'ttl' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | 模块注册配置
    |--------------------------------------------------------------------------
    | 定义模块服务提供者的自动注册方式
    |
    */
    'register' => [
        // 是否自动注册模块服务提供者
        'providers' => true,
        // 服务提供者文件名模式
        'provider_pattern' => '{Module}ServiceProvider',
    ],

    /*
    |--------------------------------------------------------------------------
    | 模块命令配置
    |--------------------------------------------------------------------------
    | 定义模块命令的自动注册方式
    |
    */
    'commands' => [
        // 是否自动注册命令
        'enabled' => true,
        // 命令文件路径
        'path' => 'Console/Commands',
    ],

    /*
    |--------------------------------------------------------------------------
    | 模块迁移配置
    |--------------------------------------------------------------------------
    | 模块迁移相关配置
    |
    */
    'migrations' => [
        // 迁移文件前缀
        'prefix' => '',
        // 迁移表前缀
        'table_prefix' => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | 模块仓库配置
    |--------------------------------------------------------------------------
    | 模块仓库行为配置
    |
    */
    'repository' => [
        // 缓存模块列表
        'cache' => false,
        // 缓存时间（秒）
        'cache_ttl' => 3600,
    ],
];

```

## 配置选项详解

### namespace

**类型**：`string`  
**默认值**：`'Modules'`  
**说明**：定义模块的根命名空间

```php
'namespace' => 'Modules',
```

**影响**：
- 所有模块类的命名空间前缀
- 服务提供者的注册
- 配置文件的加载路径

**示例**：
```php
// 如果设置为 'App\Modules'
// 模块 Blog 的控制器命名空间为
App\Modules\Blog\Http\Controllers\Web\PostController
```

### path

**类型**：`string`  
**默认值**：`base_path('Modules')`  
**说明**：定义模块的存储路径

```php
'path' => base_path('Modules'),
```

**影响**：
- 模块的物理存储位置
- `module_name()` 函数的检测路径
- 配置文件的加载路径

**示例**：
```php
// 自定义模块路径
'path' => base_path('app/Modules'),
```

### scan.paths

**类型**：`array`  
**默认值**：`[base_path('Modules')]`  
**说明**：定义需要扫描的模块目录（支持多个路径）

```php
'scan' => [
    'paths' => [
        base_path('Modules'),
        base_path('CustomModules'),
        base_path('packages/Modules'),
    ],
],
```

**影响**：
- 模块系统会扫描所有指定的目录
- 支持在不同位置存放模块
- 方便模块包管理

**使用场景**：
```php
// 开发时：主模块 + 自定义模块
'paths' => [
    base_path('Modules'),        // 主应用模块
    base_path('CustomModules'),  // 自定义模块
];

// 生产时：主模块 + 第三方模块
'paths' => [
    base_path('Modules'),           // 主应用模块
    base_path('vendor-modules'),    // 第三方模块
];
```

### assets

**类型**：`string`  
**默认值**：`public_path('modules')`  
**说明**：定义模块静态资源的发布路径

```php
'assets' => public_path('modules'),
```

**影响**：
- `module_asset()` 函数的资源 URL 生成
- 资源发布的目标目录

**示例**：
```php
// 自定义资源路径
'assets' => public_path('assets/modules'),

// 使用 helper 函数生成资源 URL
$url = module_asset('css/style.css');
// 返回: /assets/modules/blog/css/style.css
```

### middleware_groups

**类型**：`array`  
**默认值**：见下  
**说明**：为不同类型的路由定义中间件组

```php
'middleware_groups' => [
    'web' => ['web'],
    'api' => ['api'],
    'admin' => ['web', 'admin', 'auth'],
],
```

**影响**：
- 路由文件加载时应用的中间件
- 不同类型路由的安全级别
- 路由的认证和授权

**示例**：
```php
'middleware_groups' => [
    'web' => ['web', 'csrf'],
    'api' => ['api', 'throttle:60,1'],
    'admin' => ['web', 'admin', 'auth', 'verified'],
],
```

### route_controller_namespaces

**类型**：`array`  
**默认值**：见下  
**说明**：定义路由到控制器的命名空间映射

```php
'route_controller_namespaces' => [
    'web' => 'Http\Controllers\Web',
    'api' => 'Http\Controllers\Api',
    'admin' => 'Http\Controllers\Admin',
],
```

**影响**：
- 路由文件加载时的控制器命名空间
- 路由到控制器的自动映射

**示例**：
```php
// 自定义控制器命名空间
'route_controller_namespaces' => [
    'web' => 'Http\Controllers\Web',
    'api' => 'Http\Controllers\Api',
    'admin' => 'Http\Controllers\Admin',
    'mobile' => 'Http\Controllers\Mobile',
],
```

### routes

**类型**：`array`  
**默认值**：见下  
**说明**：定义路由的前缀和命名空间格式

```php
'routes' => [
    'prefix' => true,          // 是否自动添加路由前缀
    'name_prefix' => true,    // 是否自动添加路由名称前缀
],
```

**影响**：
- 路由 URL 的格式
- 路由名称的格式

**示例**：
```php
// 完全自定义路由格式
'routes' => [
    'prefix' => true,          // 启用前缀: /blog/posts
    'name_prefix' => true,    // 启用名称前缀: blog.posts.index
],
```

### views.namespace_format

**类型**：`string`  
**默认值**：`'lower'`  
**可选值**：`'lower' | 'studly' | 'camel'`  
**说明**：定义视图命名空间的格式

```php
'views' => [
    'namespace_format' => 'lower',  // lower, studly, camel
],
```

**影响**：
- 视图命名空间的格式
- `module_view()` 函数的使用

**示例**：
```php
// lower (默认)
'namespace_format' => 'lower',
// 视图: blog::post.index

// studly
'namespace_format' => 'studly',
// 视图: Blog::post.index

// camel
'namespace_format' => 'camel',
// 视图: blogModule::post.index
```

### discovery

**类型**：`array`  
**默认值**：见下  
**说明**：控制模块组件的自动发现

```php
'discovery' => [
    'providers' => true,   // 自动发现服务提供者
    'routes' => true,      // 自动发现路由文件
    'commands' => true,    // 自动发现命令
    'migrations' => true, // 自动发现迁移文件
    'views' => true,       // 自动发现视图
    'translations' => true,// 自动发现翻译文件
],
```

**影响**：
- 模块组件的自动加载
- 性能优化（禁用不需要的自动发现）

**示例**：
```php
// 性能优化：只启用需要的自动发现
'discovery' => [
    'providers' => true,   // 必需
    'routes' => true,      // 必需
    'commands' => false,   // 禁用，手动注册
    'migrations' => true,  // 必需
    'views' => true,       // 必需
    'translations' => false,// 禁用，不使用
],
```

### cache

**类型**：`array`  
**默认值**：见下  
**说明**：控制模块缓存行为

```php
'cache' => [
    'enabled' => env('MODULES_CACHE_ENABLED', false),
    'ttl' => 3600,  // 缓存时间（秒）
],
```

**影响**：
- 模块信息的缓存
- 性能提升

**示例**：
```php
// 生产环境启用缓存
'cache' => [
    'enabled' => env('MODULES_CACHE_ENABLED', env('APP_ENV') === 'production'),
    'ttl' => 3600,
],
```

### stubs

**类型**：`array`  
**默认值**：见下  
**说明**：定义代码生成模板的位置

```php
'stubs' => [
    'enabled' => false,
    'path' => base_path('stubs/modules'),
],
```

**影响**：
- 代码生成命令使用的模板
- 自定义代码生成逻辑

**示例**：
```php
// 启用自定义模板
'stubs' => [
    'enabled' => true,
    'path' => base_path('resources/stubs/modules'),
],
```

## 模块配置文件

### config.php

每个模块的 `Config/config.php` 文件控制模块的启用状态：

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 模块启用状态
    |--------------------------------------------------------------------------
    |
    | 控制模块是否启用
    | 设置为 false 可禁用模块，禁用后模块将不会加载
    |
    */
    'enable' => true,

    /*
    |--------------------------------------------------------------------------
    | 模块配置
    |--------------------------------------------------------------------------
    |
    | 模块的自定义配置项
    |
    */
    'config' => [
        'option' => 'value',
    ],
];
```

### 自定义配置文件

可以在 `Config` 目录下创建任意数量的自定义配置文件：

```php
// Config/settings.php
<?php

return [
    'per_page' => 20,
    'enable_comments' => true,
    'enable_likes' => true,
];
```

```php
// Config/api.php
<?php

return [
    'timeout' => 30,
    'retries' => 3,
];
```

## 配置读取

### 方式 1：使用 module_config()（推荐）

```php
// 读取当前模块的配置
$perPage = module_config('settings.per_page', 10);
$enableComments = module_config('settings.enable_comments', false);
```

### 方式 2：使用 module_config() 指定模块

```php
// 读取指定模块的配置
$perPage = module_config('settings.per_page', 10, 'Blog');
```

### 方式 3：使用 Laravel config() 函数

```php
// 读取配置（需要知道完整的配置键）
$perPage = config('blog.settings.per_page', 10);
```

### 方式 4：使用模块实例

```php
// 获取模块实例并读取配置
$module = module('Blog');
$perPage = $module->config('settings.per_page', 10);
```

## 配置最佳实践

### 1. 配置分层

```php
// Config/config.php - 模块基础配置
return [
    'enable' => true,
];

// Config/settings.php - 应用设置
return [
    'per_page' => 20,
];

// Config/features.php - 功能开关
return [
    'comments' => true,
    'likes' => true,
];
```

### 2. 提供默认值

```php
// ✅ 推荐
$perPage = module_config('settings.per_page', 10);

// ❌ 不推荐
$perPage = module_config('settings.per_page');
```

### 3. 使用嵌套配置

```php
// Config/api.php
return [
    'timeout' => 30,
    'cache' => [
        'enabled' => true,
        'ttl' => 3600,
    ],
];

// 读取嵌套配置
$cacheEnabled = module_config('api.cache.enabled', false);
```

### 4. 环境变量

```php
// Config/settings.php
return [
    'debug' => env('BLOG_DEBUG', false),
    'api_key' => env('BLOG_API_KEY'),
];
```

## 相关文档

- [Helper 函数](05-helper-functions.md) - 了解配置读取的更多方法
- [智能模块检测](06-intelligent-detection.md) - 学习自动检测当前模块
- [最佳实践](12-best-practices.md) - 配置的最佳实践
