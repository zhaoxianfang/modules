<?php

/**
 * ============================================================================
 * zxf/modules - 模块系统全局配置
 * ============================================================================
 *
 * 本文件为整个模块系统的核心配置文件，控制所有模块的加载行为。
 *
 * 配置分为两大层级：
 *   1. 全局配置（本文件）：控制模块系统的整体行为
 *   2. 模块配置（Modules/{Module}/Config/{lower_name}.php）：控制单个模块的元数据
 *
 * v5.0 重要变更：
 *   - 模块不再使用 composer.json 管理元数据，所有模块级配置均在 Config/ 下的
 *     PHP 配置文件中定义（如 Modules/Blog/Config/blog.php）
 *   - 支持 'enabled'、'priority'、'aliases'、'providers'、'laravel_aliases'、
 *     'options' 等内置元数据键
 *
 * @package   zxf\Modules
 * @version   5.0.0
 * @requires  PHP 8.3+ / Laravel 11+ / 12+ / 13+
 */

return [
    /*
    |--------------------------------------------------------------------------
    | 模块命名空间（namespace）
    |--------------------------------------------------------------------------
    |
    | 定义所有模块的根命名空间前缀。
    |
    | 示例：
    |   - 'Modules'       →  Blog 模块的类为 Modules\Blog\Http\Controllers\...
    |   - 'App\Modules'   →  Blog 模块的类为 App\Modules\Blog\Http\Controllers\...
    |   - 'Plugins'       →  Blog 模块的类为 Plugins\Blog\Http\Controllers\...
    |
    | 注意：修改此值后需要同步更新项目根 composer.json 的 psr-4 自动加载配置：
    |
    |   "autoload": {
    |       "psr-4": {
    |           "Modules\\": "Modules/"
    |       }
    |   }
    |
    | 然后执行 composer dump-autoload。
    |
    */
    'namespace' => 'Modules',

    /*
    |--------------------------------------------------------------------------
    | 模块存储主路径（path）
    |--------------------------------------------------------------------------
    |
    | 定义模块存储的基础路径。所有模块命令（make、list、delete 等）默认
    | 以此路径为基准创建和查找模块。
    |
    | 通常指向项目根目录下的 Modules/ 目录。
    |
    | 示例：
    |   - base_path('Modules')              ← 默认，项目根/Modules
    |   - base_path('app/Modules')          ← 放在 app 目录内
    |   - base_path('vendor/my-org/modules')← 第三方模块包路径
    |
    */
    'path' => base_path('Modules'),

    /*
    |--------------------------------------------------------------------------
    | 额外扫描路径（scan_paths）
    |--------------------------------------------------------------------------
    |
    | 除了主路径（path）外，定义额外的模块扫描路径。
    |
    | 使用场景：
    |   - 多团队协作：不同团队的模块放在不同目录
    |   - 第三方模块：vendor 中的独立模块包
    |   - 微服务架构：按业务域拆分的模块目录
    |   - 私有/公共模块分离：核心模块 + 扩展模块不同路径
    |
    | 示例配置：
    |
    |   'scan_paths' => [
    |       base_path('vendor/my-organization'),  // 第三方组织模块
    |       base_path('CustomModules'),            // 本地自定义模块
    |       base_path('packages/community'),       // 社区贡献模块
    |   ],
    |
    | 注意：
    |   - 所有路径下的模块都会合并到同一个模块仓库中
    |   - 同名模块以先扫描到的为准
    |   - 路径中的目录必须存在，不存在则跳过
    |
    */
    'scan_paths' => [
        // base_path('vendor/my-organization'),
        // base_path('CustomModules'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 模块优先级排序（sort_by_priority）
    |--------------------------------------------------------------------------
    |
    | 是否按优先级（priority）排序加载模块。数字越小优先级越高，越先加载。
    |
    | 启用后，模块会按照每个模块配置文件中定义的 'priority' 值从小到大排序，
    | 优先级相同的模块按名称字母序排列。
    |
    | 配置方式（在模块 Config 文件中）：
    |
    |   // Modules/Core/Config/core.php
    |   return [
    |       'enabled' => true,
    |       'priority' => 50,    // Core 最先加载
    |   ];
    |
    |   // Modules/Blog/Config/blog.php
    |   return [
    |       'enabled' => true,
    |       'priority' => 500,   // Blog 在 Core 之后加载
    |   ];
    |
    | 典型优先级规划：
    |   - priority 1-99:   基础设施模块（Core、Auth、Log）
    |   - priority 100-499: 领域基础模块（User、Permission）
    |   - priority 500-999: 业务模块（Blog、Shop、CMS）
    |   - priority 1000+:   扩展/可选模块（默认值 1000）
    |
    | 设置为 false 则按模块名称字母序加载。
    |
    */
    'sort_by_priority' => true,

    /*
    |--------------------------------------------------------------------------
    | 模块静态资源发布路径（assets）
    |--------------------------------------------------------------------------
    |
    | 定义模块静态资源（CSS、JS、图片等）发布到的公共路径。
    |
    | 发布后可通过 module_asset() 函数生成资源 URL：
    |
    |   // 发布 blog 模块的 css/style.css 到 public/modules/blog/css/style.css
    |   $url = module_asset('css/style.css');  // /modules/blog/css/style.css
    |
    | 资源发布命令：
    |   php artisan module:publish Blog
    |
    */
    'assets' => public_path('modules'),

    /*
    |--------------------------------------------------------------------------
    | 文件生成路径配置（paths）
    |--------------------------------------------------------------------------
    |
    | 定义模块内部各类组件的生成目标路径。所有路径均相对于模块根目录。
    |
    | 每个生成器配置包含两个键：
    |   - path:     该组件在模块内的相对路径
    |   - generate: 使用 module:make 命令时是否默认生成此组件
    |
    | 可通过修改这些配置自定义模块的目录结构。
    |
    | 可用的生成器类型：
    |   provider      - 服务提供者
    |   config        - 配置文件
    |   route         - 路由文件
    |   controller    - 控制器（基类）
    |   controller.web/api/admin - 分层控制器
    |   model         - Eloquent 模型
    |   observer      - 模型观察者
    |   policy        - 授权策略
    |   repository    - 数据仓库
    |   request       - 表单验证请求
    |   resource      - API 资源转换器
    |   middleware    - HTTP 中间件
    |   command       - Artisan 控制台命令
    |   event         - 事件类
    |   listener      - 事件监听器
    |   migration     - 数据库迁移
    |   seeder        - 数据填充器
    |   factory       - 模型工厂
    |   views         - Blade 视图
    |   lang          - 语言文件
    |   test          - 测试文件
    |   assets        - 静态资源
    |
    */
    'paths' => [
        /*
        |----------------------------------------------------------------------
        | 迁移文件存储路径
        |----------------------------------------------------------------------
        | 模块迁移文件的默认存储路径（相对于模块根目录）
        */
        'migration' => 'Database/Migrations',

        /*
        |----------------------------------------------------------------------
        | 代码生成器路径配置
        |----------------------------------------------------------------------
        | 当使用 module:make-xxx 系列命令时，文件会按此配置生成到模块内。
        | 'generate' => true  表示执行 module:make Blog 时默认生成该组件，
        | 'generate' => false 表示默认不生成，需通过 --full 或单独命令创建。
        */
        'generator' => [
            // 服务提供者 —— 模块入口，必须生成
            'provider'       => ['path' => 'Providers',              'generate' => true],

            // 配置文件 —— 模块元数据入口，必须生成（v5.0 替代 composer.json）
            'config'         => ['path' => 'Config',                 'generate' => true],

            // 路由文件 —— 模块路由入口，必须生成
            'route'          => ['path' => 'Routes',                 'generate' => true],

            // 控制器基类 —— 提供模块内控制器的基础封装
            'controller'     => ['path' => 'Http/Controllers',       'generate' => true],

            // Web 控制器 —— 处理浏览器请求，返回 Blade 视图
            'controller.web'  => ['path' => 'Http/Controllers/Web',   'generate' => true],

            // API 控制器 —— 处理 API 请求，返回 JSON 数据
            'controller.api'  => ['path' => 'Http/Controllers/Api',   'generate' => true],

            // Admin 控制器 —— 处理后台管理请求（默认不生成，按需创建）
            'controller.admin' => ['path' => 'Http/Controllers/Admin', 'generate' => false],

            // Eloquent 模型 —— 数据库表映射，默认生成
            'model'          => ['path' => 'Models',                 'generate' => true],

            // 模型观察者 —— 监听模型生命周期事件，按需创建
            'observer'       => ['path' => 'Observers',              'generate' => false],

            // 授权策略 —— 控制模型访问权限，按需创建
            'policy'         => ['path' => 'Policies',               'generate' => false],

            // 数据仓库 —— 封装数据访问逻辑，按需创建
            'repository'     => ['path' => 'Repositories',           'generate' => false],

            // 表单验证请求 —— 封装请求验证逻辑，按需创建
            'request'        => ['path' => 'Http/Requests',          'generate' => false],

            // API 资源转换器 —— JSON 数据格式转换，默认生成
            'resource'       => ['path' => 'Http/Resources',         'generate' => true],

            // HTTP 中间件 —— 请求过滤/处理，按需创建
            'middleware'     => ['path' => 'Http/Middleware',        'generate' => false],

            // Artisan 控制台命令 —— 自定义命令行工具，按需创建
            'command'        => ['path' => 'Console/Commands',       'generate' => false],

            // 事件类 —— 事件广播/监听，按需创建
            'event'          => ['path' => 'Events',                 'generate' => false],

            // 事件监听器 —— 处理事件，按需创建
            'listener'       => ['path' => 'Listeners',              'generate' => false],

            // 数据库迁移 —— 示例迁移文件，按需创建
            'migration'      => ['path' => 'Database/Migrations',    'generate' => false],

            // 数据填充器 —— 测试/演示数据，默认生成
            'seeder'         => ['path' => 'Database/Seeders',       'generate' => true],

            // 模型工厂 —— 批量测试数据定义，按需创建
            'factory'        => ['path' => 'Database/Factories',     'generate' => false],

            // Blade 视图模板 —— 前端页面，默认生成
            'views'          => ['path' => 'Resources/views',        'generate' => true],

            // 语言/翻译文件 —— 多语言支持，按需创建
            'lang'           => ['path' => 'Resources/lang',         'generate' => false],

            // 测试文件 —— 单元/功能测试，按需创建
            'test'           => ['path' => 'Tests',                  'generate' => false],

            // 静态资源 —— CSS/JS/图片等，按需创建
            'assets'         => ['path' => 'Resources/assets',       'generate' => false],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 路由中间件组（middleware_groups）
    |--------------------------------------------------------------------------
    |
    | 定义不同类型路由文件自动加载的中间件组。
    |
    | 工作原理：
    |   - 键名对应 Routes/ 目录下的路由文件名（不含 .php 扩展名）
    |   - 值为该路由文件加载时自动应用的 Laravel 中间件组
    |
    | 示例映射关系：
    |   Routes/web.php   → 自动应用 ['web'] 中间件组
    |   Routes/api.php   → 自动应用 ['api'] 中间件组
    |   Routes/admin.php → 自动应用 ['web'] 中间件组
    |
    | 自定义路由类型：
    |   'mobile'  => ['api', 'auth:mobile'],    // 移动端路由
    |   'miniapp' => ['api', 'auth:miniapp'],   // 小程序路由
    |   'public'  => ['web'],                   // 公开路由
    |
    | 注意：
    |   - 中间件组必须在 app/Http/Kernel.php 的 $middlewareGroups 中定义
    |   - 支持任意自定义路由文件名和中间件组合
    |
    */
    'middleware_groups' => [
        'web'   => ['web'],       // Web 路由：应用 web 中间件组（Session、CSRF 等，Laravel 13 中对应 PreventRequestForgery）
        'api'   => ['api'],       // API 路由：应用 api 中间件组（速率限制等）
        'admin' => ['web'],       // Admin 路由：应用 web 中间件组（可按需添加 auth 等）
    ],

    /*
    |--------------------------------------------------------------------------
    | 路由配置（routes）
    |--------------------------------------------------------------------------
    |
    | 控制模块路由的前缀、命名和行为。
    |
    | prefix: 是否自动为模块路由添加 URL 前缀
    |   - true:  Blog/PostsController@index → GET /blog/posts
    |   - false: Blog/PostsController@index → GET /posts
    |
    | name_prefix: 是否自动为模块路由名称添加前缀
    |   - true:  route('posts.index') → route('blog.posts.index')
    |   - false: route('posts.index') → route('posts.index')
    |
    | default_files: 默认生成的路由文件列表
    |   - 执行 module:make Blog 时自动创建这些路由文件
    |   - 每个文件对应 middleware_groups 中的一个路由类型
    |
    | 注意：
    |   - prefix 为 true 时，请注意不要在路由文件中手动再添加模块名前缀，避免双重前缀
    |   - 路由文件命名必须与 middleware_groups 的键名一致
    |
    */
    'routes' => [
        'prefix'        => true,
        'name_prefix'   => true,
        'default_files' => ['web', 'api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | 视图配置（views）
    |--------------------------------------------------------------------------
    |
    | 控制模块 Blade 视图的加载行为。
    |
    | enabled: 是否自动注册模块视图命名空间
    |   - true:  可通过 blog::welcome 引用 Blog 模块的视图
    |   - false: 视图不会自动注册（需在服务提供者中手动注册）
    |
    | namespace_format: 视图命名空间的格式
    |   - 'lower':  Blog → blog::view.name      （推荐，统一小写）
    |   - 'studly': Blog → Blog::view.name       （保持原始大小写）
    |   - 'camel':  Blog → blogModule::view.name （驼峰命名）
    |
    | 使用示例：
    |
    |   // 控制器中返回模块视图
    |   return module_view('posts.index', ['posts' => $posts]);
    |   // 等价于 return view('blog::posts.index', ['posts' => $posts]);
    |
    |   // Blade 模板中引用其他模块视图
    |   @include('shop::components.product-card')
    |
    |   // 检查视图是否存在
    |   module_has_view('posts.show')  // true/false
    |
    */
    'views' => [
        'enabled'          => true,
        'namespace_format' => 'lower', // lower | studly | camel
    ],

    /*
    |--------------------------------------------------------------------------
    | 翻译文件配置（translations）
    |--------------------------------------------------------------------------
    |
    | 控制模块语言文件的加载行为。
    |
    | enabled: 是否自动注册模块翻译命名空间
    |   - true:  可通过 trans('blog::messages.welcome') 使用模块翻译
    |   - false: 翻译不会自动注册
    |
    | path: 模块内语言文件的存储路径（相对于模块根目录）
    |   默认结构：
    |     Resources/lang/
    |     ├── en/
    |     │   └── messages.php
    |     └── zh_CN/
    |         └── messages.php
    |
    | 使用示例：
    |
    |   // 获取模块翻译
    |   $text = module_lang('messages.welcome');
    |   // 等价于 trans('blog::messages.welcome')
    |
    |   // 带参数替换
    |   $text = module_lang('messages.hello', ['name' => '张三']);
    |
    */
    'translations' => [
        'enabled' => true,
        'path'    => 'Resources/lang',
    ],

    /*
    |--------------------------------------------------------------------------
    | 自动发现配置（discovery）
    |--------------------------------------------------------------------------
    |
    | 定义需要自动发现和加载的模块组件。
    |
    | 设置为 false 可禁用特定组件的自动加载，以优化性能或实现手动控制。
    |
    | 各组件说明：
    |   - routes:          自动加载 Routes/ 目录下的路由文件
    |   - providers:        自动扫描和注册 Providers/ 目录下的服务提供者
    |   - commands:         自动发现 Console/Commands/ 目录下的 Artisan 命令
    |   - views:            自动注册 Resources/views/ 目录的视图命名空间
    |   - config:           自动加载 Config/ 目录下的配置文件到 Laravel 配置
    |   - translations:     自动注册 Resources/lang/ 目录的翻译命名空间
    |   - migrations:       自动发现 Database/Migrations/ 目录的迁移文件
    |   - events:           自动发现 Events/ 目录的事件类
    |   - observers:        自动发现 Observers/ 目录的模型观察者
    |   - policies:         自动发现 Policies/ 目录的授权策略
    |   - repositories:     自动发现 Repositories/ 目录的数据仓库
    |   - middlewares:      自动发现 Http/Middleware/ 目录的中间件
    |
    | 性能优化建议（生产环境）：
    |   - 只启用必需的自动发现项
    |   - 配合 cache.enabled 使用模块缓存
    |   - 不需要的组件设为 false 减少扫描开销
    |
    */
    'discovery' => [
        'routes'        => true,  // 路由自动加载
        'providers'     => true,  // 服务提供者自动发现
        'commands'      => true,  // Artisan 命令自动发现
        'views'         => true,  // 视图命名空间自动注册
        'config'        => true,  // 配置文件自动加载
        'translations'  => true,  // 翻译文件自动注册
        'migrations'    => true,  // 迁移文件自动发现
        'events'        => true,  // 事件自动发现
        'observers'     => true,  // 模型观察者自动发现
        'policies'      => true,  // 授权策略自动发现
        'repositories'  => true,  // 数据仓库自动发现
        'middlewares'   => true,  // 中间件自动发现
    ],

    /*
    |--------------------------------------------------------------------------
    | 模块缓存配置（cache）
    |--------------------------------------------------------------------------
    |
    | 生产环境强烈建议启用模块缓存，可显著提升模块加载性能。
    |
    | 缓存内容：
    |   - 模块列表及路径信息
    |   - 模块启用/禁用状态
    |   - 模块优先级顺序
    |   - 各模块的元数据（名称、版本、别名等）
    |
    | enabled: 是否启用模块列表缓存
    |   - 开发环境建议设为 false：模块变更频繁，缓存可能过时
    |   - 生产环境建议设为 true：减少文件系统扫描，大幅提升性能
    |   - 推荐通过环境变量 MODULES_CACHE_ENABLED 控制
    |
    | key: 缓存在 Laravel 缓存系统中的键名
    |
    | ttl: 缓存有效期（秒）
    |   - 3600:  1 小时
    |   - 86400: 24 小时
    |   - 0:     永久有效（直到手动清除）
    |
    | path: 缓存文件存储路径（文件驱动时使用）
    |
    | 缓存管理命令：
    |   php artisan module:cache      # 重新生成模块缓存
    |   php artisan module:clear      # 清除模块缓存
    |
    */
    'cache' => [
        'enabled' => env('MODULES_CACHE_ENABLED', false),
        'key'     => 'modules',
        'ttl'     => 3600,
        'path'    => storage_path('framework/cache/modules'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stub 模板配置（stubs）
    |--------------------------------------------------------------------------
    |
    | 控制系统生成的代码模板（stubs）行为。
    |
    | 使用流程：
    |   1. 发布默认 stub 到项目中：
    |      php artisan vendor:publish --tag=modules-stubs
    |
    |   2. 发布的文件位于 resources/stubs/modules/ 目录
    |
    |   3. 修改 stub 文件以自定义生成的代码内容
    |
    |   4. 之后执行 module:make 系列命令将使用自定义模板
    |
    | override: 是否使用项目中的自定义 stub 覆盖扩展包内置 stub
    |   - true:  优先使用 resources/stubs/modules/ 中的模板
    |   - false: 始终使用扩展包内置模板
    |
    | path: 自定义 stub 文件的存储路径
    |
    | strict: 严格模式
    |   - true:  stub 中未定义的变量在替换时会抛出异常
    |   - false: 未定义的变量会被替换为空字符串
    |
    | Stub 变量参考（详见 docs/15-stub-mapping.md）：
    |   {{ MODULE }}         - 模块名称（StudlyCase）
    |   {{ MODULE_LOWER }}   - 模块名称（小写）
    |   {{ NAMESPACE }}      - 模块命名空间
    |   {{ CLASS }}          - 目标类名
    |   {{ CLASS_LOWER }}    - 目标类名首字母小写
    |   {{ PATH }}           - 目标文件路径
    |   ... 等
    |
    */
    'stubs' => [
        'override'  => true,                                // 启用自定义 stub 覆盖
        'path'      => resource_path('stubs/modules'),      // 自定义 stub 路径
        'strict'    => true,                                // 严格模式（变量未定义时报错）
    ],

    /*
    |--------------------------------------------------------------------------
    | 模块注册配置（register）
    |--------------------------------------------------------------------------
    |
    | 控制模块服务提供者的自动注册方式。
    |
    | providers: 是否自动注册模块内的服务提供者
    |   - true:  ModuleAutoDiscovery 会自动扫描和注册服务提供者
    |   - false: 需要在 app.php 或 AppServiceProvider 中手动注册
    |
    | provider_pattern: 主服务提供者的命名模式
    |   - '{Module}ServiceProvider' → Blog 模块查找 BlogServiceProvider 类
    |   - 模式中的 {Module} 会被替换为模块名称（StudlyCase）
    |
    | v5.0 增强：
    |   除自动扫描 Providers/ 目录外，还会读取模块配置文件中的 'providers'
    |   键，注册其中声明的不符合命名约定的额外服务提供者。
    |
    | 服务提供者查找优先级：
    |   1. 配置文件 'providers' 键中声明的类（最高优先级）
    |   2. Providers/{Module}ServiceProvider.php
    |   3. Providers/ModuleServiceProvider.php
    |   4. Providers/ 目录下其他 *ServiceProvider.php
    |
    */
    'register' => [
        'providers'        => true,
        'provider_pattern' => '{Module}ServiceProvider',
    ],

    /*
    |--------------------------------------------------------------------------
    | 模块命令配置（commands）
    |--------------------------------------------------------------------------
    |
    | 控制模块内 Artisan 命令的自动发现和注册。
    |
    | enabled: 是否自动注册模块命令
    |   - true:  自动扫描和注册所有模块 Console/Commands/ 目录下的命令
    |   - false: 需要在 Kernel.php 或服务提供者中手动注册模块命令
    |
    | path: 模块内命令文件的搜索路径（相对于模块根目录）
    |
    | 自动发现行为：
    |   - 扫描所有已启用模块的 Console/Commands/ 目录
    |   - 自动注册所有继承自 Illuminate\Console\Command 的类
    |   - 仅在控制台环境（runningInConsole）下执行，不影响 HTTP 请求性能
    |
    */
    'commands' => [
        'enabled' => true,
        'path'    => 'Console/Commands',
    ],

    /*
    |--------------------------------------------------------------------------
    | 调试配置（debug）
    |--------------------------------------------------------------------------
    |
    | 控制模块系统的调试模式。
    |
    | 启用后效果：
    |   - 输出模块加载的详细过程
    |   - 记录自动发现的每一步结果
    |   - 输出配置文件加载和合并信息
    |   - 显示模块扫描和注册的详细信息
    |
    | 推荐通过环境变量控制：
    |   .env 文件中设置 MODULES_DEBUG=true
    |
    | 注意：
    |   - 生产环境务必设为 false，避免敏感信息泄露
    |   - 调试信息会通过 Laravel 日志系统输出
    |
    */
    'debug' => env('MODULES_DEBUG', false),
];
