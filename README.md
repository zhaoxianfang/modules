# Laravel 模块系统

一个为 Laravel 11+ 设计的现代化、工业级模块化系统，基于 PHP 8.2+ 开发。

## 特性

- 🚀 **现代化架构**：专为 Laravel 11+ 和 PHP 8.2+ 设计
- 🎯 **配置驱动**：通过 config 控制所有模块行为，无需 JSON 文件
- ⚙️ **模块启用/禁用**：通过配置文件控制模块是否启用，禁用时完全不加载模块组件
- 🔧 **动态路由生成**：路由前缀和名称前缀根据配置动态生成到路由文件
- 📦 **自动发现**：自动发现模块的服务提供者、路由、命令等
- 🔌 **灵活配置**：支持多路由中间件组、控制器命名空间映射
- 🛠️ **功能完整**：支持路由、视图、配置、迁移、命令、事件等完整功能
- 📊 **信息统计**：提供详细的模块信息和验证功能
- 💪 **迁移增强**：完整的迁移管理命令，包括状态查看
- 📝 **助手函数**：40+ 个便捷助手函数，大部分支持无参调用
- 🔍 **模块验证**：验证模块的完整性和正确性
- 🎨 **模板系统**：基于 stubs 的代码生成模板系统
- 🖼️ **视图命名空间**：支持模块视图命名空间，如 `blog::list.test`
- 🌍 **路由映射**：灵活的路由控制器命名空间映射
- 📂 **多路径扫描**：支持多个模块目录扫描
- 🎯 **智能检测**：自动检测当前模块，支持嵌套配置读取
- ⚡ **高性能**：优化的核心函数，保证生产环境高效运行
- 📖 **完整文档**：详细的文档和使用示例

## 安装

```bash
composer require zxf/modules
```

## 快速开始

### 1. 发布配置文件

```bash
php artisan vendor:publish --provider="zxf\\Modules\\ModulesServiceProvider"
```

### 2. 创建第一个模块

```bash
php artisan module:make Blog
```

### 3. 查看模块列表

```bash
php artisan module:list
```

### 4. 查看模块详细信息

```bash
php artisan module:info Blog
```

### 5. 验证模块

```bash
php artisan module:validate Blog
```

### 6. 运行模块迁移

```bash
# 运行所有模块的迁移
php artisan module:migrate

# 运行指定模块的迁移
php artisan module:migrate Blog

# 查看迁移状态
php artisan module:migrate-status

# 回滚迁移
php artisan module:migrate:reset Blog

# 刷新迁移（回滚并重新运行）
php artisan module:migrate:refresh Blog
```

### 7. 删除模块

```bash
php artisan module:delete Blog
```

## 配置

模块系统的所有配置都在 `config/modules.php` 文件中：

```php
return [
    'namespace' => 'Modules',
    'path' => base_path('Modules'),
    'middleware_groups' => [
        'web' => ['web'],
        'api' => ['api'],
        'admin' => ['web', 'admin'],
    ],
    // ... 更多配置
];
```

详细配置说明请参考 [CONFIG_USAGE.md](CONFIG_USAGE.md)。

## Helper 函数

模块系统提供了 40+ 个助手函数，大大简化模块操作。大部分函数支持无参调用，会自动检测当前所在模块。

### 核心函数

```php
// 获取当前模块名称（精确检测，无缓存）
$moduleName = module_name(); // 'Blog'

// 智能配置读取（推荐，无缓存）
$name = module_config('common.name', 'hello');
$cache = module_config('settings.cache.enabled', false);

// 检查模块状态
if (module_enabled()) {
    // 模块已启用
}

// 获取模块路径
$path = module_path(null, 'Models/Post.php');

// 返回模块视图
return module_view(null, 'post.index', compact('posts'));
```

**注意**：
- `module_name()` 和 `module_config()` 不使用缓存，每次都精确检测
- 推荐在模块内部使用，显式传递模块名更稳定
- 详见 [HELPER_REFACTOR.md](HELPER_REFACTOR.md) 了解重构详情

### 路由函数

```php
// 生成路由 URL
$url = module_route(null, 'posts.index');
$url = module_route(null, 'posts.show', ['id' => 1]);

// 获取模块 URL
$url = module_url(null, 'posts/1');

// 通过 URL 分析当前模块
$moduleName = current_module();
```

### 配置函数

```php
// 获取完整配置数组
$config = module_get_config(null, 'common');

// 检查配置是否存在
if (module_has_config(null, 'common', 'name')) {
    // 配置存在
}

// 获取所有配置文件
$files = module_config_files();
```

更多 Helper 函数请参考 [HELPER_FUNCTIONS.md](HELPER_FUNCTIONS.md)。

## 模块启用/禁用

每个模块都可以通过配置文件控制是否启用。禁用模块后，该模块的所有组件（路由、服务提供者、视图等）都不会被加载。

### 配置启用状态

编辑 `Modules/Blog/Config/blog.php`：

```php
return [
    'enabled' => true,  // 设置为 false 可禁用此模块
    'name' => 'Blog',
    'version' => '1.0.0',
    'description' => 'Blog 模块',
    'author' => '',
    'options' => [],
];
```

### 检查模块状态

```php
use zxf\Modules\Facades\Module;

// 检查特定模块
if (Module::find('Blog')->isEnabled()) {
    // Blog 模块已启用
}

// 使用助手函数
if (module_enabled('Blog')) {
    // Blog 模块已启用
}

// 在模块内部检查当前模块
if (module_enabled()) {
    // 当前模块已启用
}
```

**重要说明：**
- 模块启用状态在加载前直接从配置文件读取，不依赖 Laravel config
- 禁用模块后，无法访问其路由、视图等任何组件
- 如果 `enabled` 未配置，默认为启用


## 智能当前模块检测

### 获取当前模块名称

在模块内部的任何地方，都可以通过 `module_name()` 函数自动获取当前模块名称：

```php
// 在 Blog 模块的控制器中
class PostController extends Controller
{
    public function index()
    {
        // 自动检测到当前模块为 Blog
        $currentModule = module_name(); // 返回 'Blog'

        // 无需传递参数
    }
}
```

### 读取当前模块配置

支持两种方式读取模块配置：

#### 方式 1：指定模块名称（传统方式）

```php
$value = module_config('config.key', 'default', 'Blog');
```

#### 方式 2：使用当前模块（智能方式）

在模块内部，可以直接使用配置文件路径，无需传递模块名：

```php
// 读取当前模块的 Config/common.php 文件中的 name 配置
// 如果没有该配置项，返回默认值 'hello'
$value = module_config('common.name', 'hello');

// 读取当前模块的 Config/settings.php 文件中的 options 配置
$options = module_config('settings.options', []);

// 读取当前模块的 Config/config.php 文件中的 enable 配置
$enabled = module_config('config.enable', false);
```

配置文件示例：

```php
// Modules/Blog/Config/common.php
<?php

return [
    'name' => 'My Blog',
    'description' => 'A blog module',
];

// Modules/Blog/Config/settings.php
<?php

return [
    'options' => [
        'cache' => true,
        'debug' => false,
    ],
];
```

## 助手函数（增强版）

### 模块信息获取

#### module_name() - 获取当前模块名称

```php
// 自动检测当前模块，无需传递参数
$moduleName = module_name();
// 返回当前模块名称，如 'Blog'
```

#### module_path() - 获取模块路径

```php
// 不传递模块名，自动使用当前模块
$path = module_path('Models/Post.php');

// 指定模块名
$path = module_path('Blog', 'Models/Post.php');
```

#### module_config() - 获取模块配置

```php
// 方式 1：指定模块名称
$value = module_config('config.key', 'default', 'Blog');

// 方式 2：使用当前模块（推荐）
$value = module_config('common.name', 'hello');
// 等价于读取 Config/common.php 中的 name 配置，默认值为 'hello'
```

#### module_enabled() - 检查模块是否启用

```php
// 不传递模块名，检查当前模块
if (module_enabled()) {
    // 当前模块已启用
}

// 检查指定模块
if (module_enabled('Blog')) {
    // Blog 模块已启用
}
```

### 路径相关助手

```php
// 模块路径
module_path('Models');              // 当前模块的 Models 目录
module_path('Blog', 'Models');     // Blog 模块的 Models 目录

// 配置文件路径
module_config_path('common.php');  // 当前模块的 Config/common.php

// 路由文件路径
module_routes_path('web');         // 当前模块的 Routes/web.php

// 迁移目录路径
module_migrations_path();          // 当前模块的 Database/Migrations

// 模型目录路径
module_models_path();              // 当前模块的 Models

// 控制器目录路径
module_controllers_path('Web');    // 当前模块的 Http/Controllers/Web

// 视图目录路径
module_views_path();              // 当前模块的 Resources/views
```

### 视图相关

```php
// 获取模块视图路径
$viewPath = module_view_path('post.index');  // blog::post.index

// 检查视图是否存在
if (module_has_view('post.index')) {
    // 视图存在
}

// 返回模块视图
return module_view('post.index', ['posts' => $posts]);
```

### 路由相关

```php
// 获取当前模块
$currentModule = current_module();

// 获取模块路由前缀
$routePrefix = module_route_path('index');  // blog.index

// 生成模块路由 URL
$url = module_route('posts.index', ['id' => 1]);
```

### 命名空间相关

```php
// 获取模块命名空间
$namespace = module_namespace();        // 当前模块命名空间
$namespace = module_namespace('Blog'); // Blog 模块命名空间

// 获取完整类名
$className = module_class('Http\\Controllers\\PostController');
// 返回: Modules\Blog\Http\Controllers\PostController
```

### 资源相关

```php
// 生成模块静态资源 URL
$assetUrl = module_asset('css/style.css');
// 返回: /modules/blog/css/style.css
```

### 配置相关

```php
// 检查配置是否存在
if (module_has_config('common', 'name')) {
    // 配置项存在
}
```

## 模块配置

### 模块启用/禁用

模块的启用/禁用通过配置文件控制，不再使用命令。

在模块的 `Config/config.php` 中设置：

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
    'enable' => true, // 设置为 false 禁用模块
];
```

### 创建自定义配置文件

在模块的 `Config` 目录下创建配置文件：

```php
// Modules/Blog/Config/settings.php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 模块设置
    |--------------------------------------------------------------------------
    */
    'cache' => true,
    'debug' => false,
    'items_per_page' => 20,
];
```

然后读取配置：

```php
// 在模块内部，无需传递模块名
$cache = module_config('settings.cache', false);
$debug = module_config('settings.debug', false);
$perPage = module_config('settings.items_per_page', 10);
```

## 模块结构

模块创建后会生成以下目录结构：

```
Modules/
└── Blog/
    ├── Config/
    │   ├── config.php           # 模块配置文件（必需）
    │   ├── common.php          # 自定义配置文件
    │   └── settings.php       # 自定义配置文件
    ├── Database/
    │   ├── Migrations/         # 数据库迁移文件
    │   └── Seeders/            # 数据填充器
    ├── Http/
    │   ├── Controllers/         # 控制器
    │   │   ├── Controller.php  # 基础控制器
    │   │   ├── Web/          # Web 控制器
    │   │   ├── Api/          # API 控制器
    │   │   └── Admin/        # Admin 控制器
    │   ├── Middleware/         # 中间件
    │   └── Requests/           # 表单请求验证
    ├── Models/                  # 模型
    ├── Providers/
    │   └── BlogServiceProvider.php  # 模块服务提供者（必需）
    ├── Resources/
    │   ├── assets/             # 静态资源
    │   ├── lang/               # 语言文件
    │   └── views/             # 视图文件
    ├── Routes/
    │   ├── web.php            # Web 路由
    │   ├── api.php            # API 路由
    │   └── admin.php          # Admin 路由
    ├── Events/                 # 事件
    ├── Listeners/              # 事件监听器
    ├── Observers/             # 模型观察者
    ├── Policies/              # 策略类
    ├── Repositories/          # 仓库类
    └── Tests/                # 测试文件
```

## 代码生成命令

### 创建控制器

```bash
# 创建 Web 控制器
php artisan module:make-controller Blog PostController --type=web

# 创建 API 控制器
php artisan module:make-controller Blog PostController --type=api

# 创建 Admin 控制器
php artisan module:make-controller Blog PostController --type=admin
```

### 创建模型

```bash
# 创建模型
php artisan module:make-model Blog Post

# 创建模型并生成迁移
php artisan module:make-model Blog Post --migration

# 创建模型并生成工厂类
php artisan module:make-model Blog Post --factory
```

### 其他生成命令

```bash
# 创建迁移
php artisan module:make-migration Blog create_posts_table

# 创建中间件
php artisan module:make-middleware Blog CheckAuth

# 创建表单请求
php artisan module:make-request Blog StorePostRequest

# 创建事件
php artisan module:make-event Blog PostCreated

# 创建监听器
php artisan module:make-listener Blog PostCreatedListener --event=PostCreated

# 创建数据填充器
php artisan module:make-seeder Blog PostSeeder

# 创建命令
php artisan module:make-command Blog ProcessPosts --command=blog:process

# 创建配置文件
php artisan module:make-config Blog settings

# 创建路由文件
php artisan module:make-route Blog custom --type=web
```

## 视图使用

### 模块视图命名空间

模块会自动注册视图命名空间，可以在应用中方便地使用模块视图：

```php
// 在模块内部使用（自动检测当前模块）
return view('post.index');
return view('list.test');

// 或者使用 module_view 函数
return module_view('post.index', ['posts' => $posts]);
```

### 视图路径映射

- `blog::welcome` → `Modules/Blog/Resources/views/welcome.blade.php`
- `blog::post.index` → `Modules/Blog/Resources/views/post/index.blade.php`
- `blog::list.test` → `Modules/Blog/Resources/views/list/test.blade.php`

## 实际使用示例

### 在控制器中使用

```php
namespace Modules\Blog\Http\Controllers\Web;

use Illuminate\Http\Request;

class PostController extends Controller
{
    public function index()
    {
        // 获取当前模块名称
        $moduleName = module_name(); // 'Blog'

        // 读取配置
        $itemsPerPage = module_config('settings.items_per_page', 10);

        // 获取模型
        $posts = Post::paginate($itemsPerPage);

        // 返回视图
        return module_view('post.index', compact('posts'));
    }
}
```

### 在模型中使用

```php
namespace Modules\Blog\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $fillable = ['title', 'content'];

    protected static function boot()
    {
        parent::boot();

        // 获取当前模块配置
        $cacheEnabled = module_config('settings.cache', false);

        // 根据配置添加行为
        if ($cacheEnabled) {
            // 缓存逻辑
        }
    }
}
```

### 在中间件中使用

```php
namespace Modules\Blog\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckConfig
{
    public function handle(Request $request, Closure $next)
    {
        // 检查模块是否启用
        if (! module_enabled()) {
            return redirect()->route('home');
        }

        // 检查特定配置
        $maintenance = module_config('config.maintenance', false);

        if ($maintenance) {
            return response('系统维护中', 503);
        }

        return $next($request);
    }
}
```

## 配置说明

完整配置选项说明请查看 `config/modules.php` 文件：

### 主要配置项

- **namespace**: 模块根命名空间（默认：Modules）
- **path**: 模块存储路径（默认：base_path('Modules')）
- **assets**: 模块静态资源发布路径
- **middleware_groups**: 路由中间件组配置
- **route_controller_namespaces**: 路由控制器命名空间映射
- **routes**: 路由配置（前缀、名称前缀等）
- **views**: 视图配置（命名空间格式）
- **discovery**: 自动发现配置
- **cache**: 缓存配置
- **stubs**: 代码生成模板配置

## 高级用法

### 自定义模块模板

在 `config/modules.php` 中启用自定义模板：

```php
'stubs' => [
    'enabled' => true,
    'path' => base_path('stubs/modules'),
],
```

### 多模块目录

支持配置多个模块扫描路径：

```php
'scan' => [
    'paths' => [
        base_path('Modules'),
        base_path('CustomModules'),
    ],
],
```

### 自定义视图命名空间格式

配置视图命名空间格式：

```php
'views' => [
    'namespace_format' => 'studly', // 可选: lower, studly, camel
],
```

## 测试

```bash
# 运行测试
php artisan test

# 运行模块测试
php artisan test --filter=Blog
```

## 许可证

MIT License

## 贡献

欢迎提交 Issue 和 Pull Request！

## 更新日志

### v2.1.0
- 🎯 智能当前模块检测：module_name() 无需传递参数
- 📝 增强配置读取：支持 module_config('common.name', 'default') 格式
- 🔧 完善配置加载器：支持当前模块配置文件读取
- 🛠️ 优化路由加载：更灵活的路由和控制器处理
- 📦 新增多个助手函数：module_has_view、module_config_path 等
- 📚 完善文档：详细说明新功能和使用方法

### v2.0.0
- 🎨 全新基于 stubs 的模板系统
- 🖼️ 支持模块视图命名空间
- 🌍 增强的路由控制器命名空间映射
- 📂 支持多路径模块扫描
- 🔧 完善的配置选项
- 📝 更多助手函数
- 🚀 性能优化
- 🐛 Bug 修复

### v1.0.0
- 🎉 初始版本发布
