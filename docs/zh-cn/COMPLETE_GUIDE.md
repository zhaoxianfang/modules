# 🤖 zxf/modules 多模块扩展包 · 完整中文使用教程

> **版本**: 5.0.0 | **兼容**: Laravel 11+ / 12+ / 13+ | **PHP**: 8.2+  
> **仓库**: https://github.com/zhaoxianfang/modules

---

## 目录

- [一、快速上手](#一快速上手)
  - [1.1 安装](#11-安装)
  - [1.2 发布配置](#12-发布配置)
  - [1.3 创建第一个模块](#13-创建第一个模块)
- [二、模块结构](#二模块结构)
  - [2.1 目录结构](#21-目录结构)
  - [2.2 composer.json 配置](#22-composerjson-配置)
  - [2.3 服务提供者](#23-服务提供者)
- [三、配置详解](#三配置详解)
  - [3.1 核心配置](#31-核心配置)
  - [3.2 路由配置](#32-路由配置)
  - [3.3 视图配置](#33-视图配置)
  - [3.4 缓存配置](#34-缓存配置)
  - [3.5 自动发现配置](#35-自动发现配置)
- [四、Helper 函数 API](#四helper-函数-api)
  - [4.1 模块基本信息与检测](#41-模块基本信息与检测)
  - [4.2 模块路径操作](#42-模块路径操作)
  - [4.3 模块配置管理](#43-模块配置管理)
  - [4.4 命名空间与类名](#44-命名空间与类名)
  - [4.5 视图、路由与静态资源](#45-视图路由与静态资源)
  - [4.6 模块状态与枚举](#46-模块状态与枚举)
  - [4.7 Stub 与工具](#47-stub-与工具)
  - [4.8 通用辅助函数](#48-通用辅助函数)
- [五、Artisan 命令大全](#五artisan-命令大全)
  - [5.1 模块管理命令](#51-模块管理命令)
  - [5.2 代码生成命令](#52-代码生成命令)
  - [5.3 模块迁移命令](#53-模块迁移命令)
- [六、高级特性](#六高级特性)
  - [6.1 模块优先级](#61-模块优先级)
  - [6.2 模块别名](#62-模块别名)
  - [6.3 多路径扫描](#63-多路径扫描)
  - [6.4 模块缓存](#64-模块缓存)
  - [6.5 事件钩子](#65-事件钩子)
  - [6.6 自定义 Stub](#66-自定义-stub)
  - [6.7 控制器生命周期](#67-控制器生命周期)
  - [6.8 Eloquent 查询宏](#68-eloquent-查询宏)
- [七、最佳实践](#七最佳实践)
  - [7.1 模块拆分原则](#71-模块拆分原则)
  - [7.2 性能优化](#72-性能优化)
  - [7.3 安全建议](#73-安全建议)
- [八、常见问题 FAQ](#八常见问题-faq)

---

## 一、快速上手

### 1.1 安装

```bash
composer require zxf/modules
```

> **注意**: 本扩展包精简了对 Laravel 框架的直接依赖，改为依赖 `illuminate/*` 分包组件，
> 以最大程度避免 Laravel 大版本升级导致的不兼容问题。支持 Laravel 11 / 12 / 13 三个大版本。

安装完成后，Laravel 的包自动发现机制会自动注册 `ModulesServiceProvider` 和 `Module` Facade。

如果你需要手动注册（极少情况），请在 `bootstrap/providers.php` 中添加：

```php
return [
    // ...
    zxf\Modules\ModulesServiceProvider::class,
];
```

### 1.2 发布配置

```bash
# 发布配置文件
php artisan vendor:publish --tag=modules-config

# 发布 stub 模板文件（用于自定义代码生成模板）
php artisan vendor:publish --tag=modules-stubs
```

发布后的配置文件位于 `config/modules.php`，stub 模板位于 `resources/stubs/modules/`。

### 1.3 创建第一个模块

```bash
# 创建一个名为 Blog 的模块
php artisan module:make Blog

# 强制覆盖已存在的模块
php artisan module:make Blog --force
```

执行后在 `Modules/Blog/` 目录下会生成完整的模块结构。

---

## 二、模块结构

### 2.1 目录结构

```
Modules/
└── Blog/                          # 模块根目录
    ├── Config/
    │   └── blog.php               # 模块配置文件（v5.0 起替代 composer.json）
    ├── Console/
    │   └── Commands/              # Artisan 命令
    ├── Database/
    │   ├── Factories/             # 模型工厂
    │   ├── Migrations/            # 数据库迁移
    │   └── Seeders/               # 数据填充
    ├── Events/                    # 事件类
    ├── Http/
    │   ├── Controllers/           # 控制器
    │   │   ├── Web/               # Web 控制器
    │   │   └── Api/               # API 控制器
    │   ├── Middleware/            # 中间件
    │   ├── Requests/              # 表单请求
    │   └── Resources/             # API 资源
    ├── Listeners/                 # 事件监听器
    ├── Models/                    # Eloquent 模型
    ├── Observers/                 # 模型观察者
    ├── Policies/                  # 授权策略
    ├── Providers/                 # 服务提供者
    │   └── BlogServiceProvider.php
    ├── Repositories/              # 数据仓库
    ├── Resources/
    │   ├── assets/                # 静态资源
    │   ├── lang/                  # 语言文件
    │   └── views/                 # 视图模板
    ├── Routes/
    │   ├── web.php                # Web 路由
    │   └── api.php                # API 路由
    └── Tests/                     # 测试文件
```

### 2.2 配置文件 (Config/blog.php)

> **v5.0 起**：不再需要每个模块维护 `composer.json` 管理元数据。所有模块元数据均在 `Config/{lower_name}.php` 中定义。

模块配置文件是模块的**唯一入口配置**和**元数据中心**：

```php
<?php
// Modules/Blog/Config/blog.php

return [
    /*
    |--------------------------------------------------------------------------
    | 模块启用状态
    |--------------------------------------------------------------------------
    */
    'enabled' => true,

    /*
    |--------------------------------------------------------------------------
    | 模块加载优先级（数字越小越先加载）
    |--------------------------------------------------------------------------
    */
    'priority' => 1000,

    /*
    |--------------------------------------------------------------------------
    | 模块显示名称
    |--------------------------------------------------------------------------
    */
    'name' => 'Blog',

    /*
    |--------------------------------------------------------------------------
    | 模块版本号
    |--------------------------------------------------------------------------
    */
    'version' => '1.0.0',

    /*
    |--------------------------------------------------------------------------
    | 模块描述
    |--------------------------------------------------------------------------
    */
    'description' => '博客管理模块',

    /*
    |--------------------------------------------------------------------------
    | 模块作者
    |--------------------------------------------------------------------------
    */
    'author' => 'Your Name',

    /*
    |--------------------------------------------------------------------------
    | 模块别名（可通过别名查找模块）
    |--------------------------------------------------------------------------
    */
    'aliases' => ['blog-manager'],

    /*
    |--------------------------------------------------------------------------
    | 额外需要注册的 Laravel 服务提供者
    |--------------------------------------------------------------------------
    */
    'providers' => [
        // \Modules\Blog\Providers\CustomServiceProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | 额外需要注册的 Laravel 门面别名
    |--------------------------------------------------------------------------
    */
    'laravel_aliases' => [
        // 'BlogHelper' => \Modules\Blog\Facades\BlogHelper::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | 模块自定义配置项
    |--------------------------------------------------------------------------
    */
    'options' => [
        'posts_per_page' => 15,
        'enable_comments' => true,
    ],
];
```

| 键 | 类型 | 说明 |
|---|------|------|
| `enabled` | bool | 是否启用该模块 |
| `priority` | int | 模块加载优先级（数字越小越先加载，默认 1000） |
| `name` | string | 显示名称 |
| `version` | string | 版本号 |
| `description` | string | 模块描述 |
| `author` | string | 作者信息 |
| `aliases` | array | 模块别名列表 |
| `providers` | array | 额外需要注册的服务提供者 |
| `laravel_aliases` | array | 额外需要注册的门面别名 |
| `options` | array | 自定义配置项 |

### 2.3 服务提供者

模块的服务提供者会被自动发现和注册。命名规则（按优先级）：

```
1. {ModuleName}ServiceProvider  (如 BlogServiceProvider)
2. ModuleServiceProvider        (通用命名)
3. Providers/ 目录下任意 *ServiceProvider.php
```

```php
<?php

declare(strict_types=1);

namespace Modules\Blog\Providers;

use Illuminate\Support\ServiceProvider;

class BlogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 注册模块专属服务
    }

    public function boot(): void
    {
        // 引导模块
    }
}
```

---

## 三、配置详解

完整配置文件路径: `config/modules.php`

### 3.1 核心配置

```php
return [
    // 模块根命名空间
    'namespace' => 'Modules',

    // 模块根路径
    'path' => base_path('Modules'),

    // 额外扫描路径（多团队/微服务）
    'scan_paths' => [
        // base_path('vendor/my-organization'),
    ],

    // 是否按优先级排序加载
    'sort_by_priority' => true,

    // 静态资源发布路径
    'assets' => public_path('modules'),
];
```

### 3.2 路由配置

```php
'routes' => [
    'prefix'        => true,         // 是否自动添加路由前缀
    'name_prefix'   => true,         // 是否自动添加路由名称前缀
    'default_files' => ['web', 'api'], // 默认加载的路由文件
],

// 路由中间件组映射
'middleware_groups' => [
    'web'   => ['web'],
    'api'   => ['api'],
    'admin' => ['web'],   // admin 路由默认使用 web 中间件
],
```

**路由自动发现规则**：
- `web.php` → 自动查找 `Http/Controllers/Web/` 下的控制器
- `api.php` → 自动查找 `Http/Controllers/Api/` 下的控制器
- `admin.php` → 自动查找 `Http/Controllers/Admin/` 下的控制器

### 3.3 视图配置

```php
'views' => [
    'enabled'          => true,
    'namespace_format' => 'lower', // lower | studly | camel
],
```

视图命名空间：
- `lower`: `blog::` （推荐）
- `studly`: `Blog::`
- `camel`: `blog::`

```blade
{{-- 使用模块视图 --}}
@include('blog::partials.header')

{{-- 扩展模块布局 --}}
@extends('blog::layouts.app')
```

### 3.4 缓存配置

```php
'cache' => [
    'enabled' => env('MODULES_CACHE_ENABLED', false), // 生产环境强烈建议开启
    'key'     => 'modules',
    'ttl'     => 3600,  // 缓存有效期（秒），0 表示永久
    'path'    => storage_path('framework/cache/modules'),
],
```

**生产环境配置**：

```bash
# .env
MODULES_CACHE_ENABLED=true
```

开启后模块列表将被缓存到文件，避免每次请求扫描目录，显著提升性能。

### 3.5 自动发现配置

```php
'discovery' => [
    'routes'        => true,   // 路由自动加载
    'providers'     => true,   // 服务提供者自动注册
    'commands'      => true,   // Artisan 命令自动注册
    'views'         => true,   // 视图命名空间自动注册
    'config'        => true,   // 配置自动加载
    'translations'  => true,   // 翻译文件自动加载
    'migrations'    => true,   // 迁移路径自动注册
    'events'        => true,   // 事件类自动发现
    'observers'     => true,   // 模型观察者自动注册
    'policies'      => true,   // 策略类自动注册
    'repositories'  => true,   // 仓库类自动发现
    'middlewares'   => true,   // 中间件自动发现
],
```

---

## 四、Helper 函数 API

所有辅助函数均在 `src/helper.php` 中定义，遵循 `declare(strict_types=1)` 严格类型声明。

### 4.1 模块基本信息与检测

#### `module_name()`

获取当前请求/代码所在模块的名称。

```php
/**
 * @param bool $toLower       是否返回小写蛇形命名
 * @param bool $requestModule 路由检测模式（true: 路由检测, false: 文件检测）
 * @return string 模块名称，或 'App'/'Command'
 */
function module_name(bool $toLower = false, bool $requestModule = true): string
```

```php
// 当前在 Blog 模块的控制器中
echo module_name();         // "Blog"
echo module_name(true);     // "blog"

// 文件检测模式
echo module_name(false, false); // "Blog"（通过调用栈检测）
```

#### `module()`

获取模块实例或模块仓库。

```php
/**
 * @param string|null $module 模块名称（不传返回仓库实例）
 * @return ModuleInterface|RepositoryInterface|null
 */
function module(?string $module = null): ModuleInterface|RepositoryInterface|null
```

```php
// 获取模块仓库
$repository = module();

// 获取指定模块实例
$blog = module('Blog');
if ($blog) {
    echo $blog->getDescription(); // "博客管理模块"
    echo $blog->getPriority();    // 100
}

// 链接调用
echo module('Blog')?->getPath(); // "/var/www/html/Modules/Blog"
```

#### `modules()`

获取所有已注册的模块。

```php
/**
 * @return array<string, ModuleInterface>
 */
function modules(): array
```

```php
foreach (modules() as $name => $module) {
    echo "{$name}: {$module->getDescription()}\n";
    echo "  Enabled: " . ($module->isEnabled() ? 'Yes' : 'No') . "\n";
    echo "  Priority: {$module->getPriority()}\n";
}
```

#### `module_exists()`

检查模块是否存在。

```php
/**
 * @param string $module 模块名称
 * @return bool
 */
function module_exists(string $module): bool
```

```php
if (module_exists('Blog')) {
    // 模块存在，安全加载
}
```

#### `module_enabled()`

检查模块是否已启用。

```php
/**
 * @param string|null $module 模块名称（不传则检测当前模块）
 * @return bool
 */
function module_enabled(?string $module = null): bool
```

```php
if (module_enabled('Blog')) {
    // Blog 模块已启用
}

// 检测当前模块是否启用
if (module_enabled()) {
    // ...
}
```

### 4.2 模块路径操作

#### `module_path()`

获取模块目录的完整路径。

```php
function module_path(string $path = '', ?string $module = null): string
```

```php
// 模块根目录
$root = module_path(module: 'Blog'); // "/var/www/Modules/Blog"

// 模块子目录
$configs = module_path('Config', 'Blog'); // "/var/www/Modules/Blog/Config"
$models = module_path('Models', 'Blog');  // "/var/www/Modules/Blog/Models"
```

#### 专用路径函数

```php
module_config_path('config.php', 'Blog');     // Config/config.php
module_routes_path('web', 'Blog');            // Routes/web.php
module_migrations_path('Blog');               // Database/Migrations
module_models_path('Blog');                   // Models
module_controllers_path('Web', 'Blog');       // Http/Controllers/Web
module_views_path('Blog');                    // Resources/views
module_trans_path('Blog');                    // Resources/lang
```

### 4.3 模块配置管理

#### `module_config()`

获取模块配置值（支持多级缓存）。

```php
function module_config(string $key, mixed $default = null, ?string $module = null): mixed
```

```php
// 获取 Blog 模块配置中 'common.per_page' 的值
$perPage = module_config('common.per_page', 20, 'Blog');

// 不指定模块则自动检测当前模块
$perPage = module_config('common.per_page', 20);

// 配置不存在时返回默认值
$timeout = module_config('api.timeout', 30);
```

#### `module_get_config()`

获取模块配置文件完整数组。

```php
function module_get_config(string $configFile = '', ?string $module = null): array
```

```php
// 获取 Blog 模块 common.php 配置文件全部内容
$allConfigs = module_get_config('common', 'Blog');
```

#### `module_set_config()`

运行时设置模块配置值（非持久化，仅当前请求有效）。

```php
function module_set_config(
    string $configFile = '',
    string $key = '',
    mixed $value = null,
    ?string $module = null
): void
```

```php
module_set_config('common', 'debug', true, 'Blog');
```

#### `module_has_config()`

检查模块配置项是否存在。

```php
function module_has_config(string $configFile = '', string $key = '', ?string $module = null): bool
```

```php
if (module_has_config('common', 'api_key', 'Blog')) {
    $apiKey = module_config('common.api_key', module: 'Blog');
}
```

#### `module_config_files()`

获取模块的所有配置文件列表。

```php
function module_config_files(?string $module = null): array
```

```php
$files = module_config_files('Blog');
// ["common.php", "database.php", "services.php"]
```

### 4.4 命名空间与类名

#### `module_namespace()`

获取模块 PHP 命名空间。

```php
function module_namespace(?string $module = null): string
```

```php
echo module_namespace('Blog'); // "Modules\Blog"
```

#### `module_class()`

构建模块内类的完整类名。

```php
function module_class(string $class = '', ?string $module = null): string
```

```php
echo module_class('Models\Post', 'Blog'); // "Modules\Blog\Models\Post"
echo module_class('Http\Controllers\Web\PostController', 'Blog');
```

### 4.5 视图、路由与静态资源

#### 视图函数

```php
// 获取视图命名空间路径
module_view_path('index', 'Blog'); // "blog::index"

// 返回视图实例
module_view('index', ['posts' => $posts], 'Blog');

// 检查视图是否存在
module_has_view('partials.sidebar', 'Blog');

// 翻译文本
module_lang('messages.welcome', [], 'zh-CN', 'Blog');

// 静态资源
module_asset('js/app.js', 'Blog');
// 输出: "/modules/blog/js/app.js"
```

#### 路由函数

```php
// 路由名称前缀
module_route_path('post.show', 'Blog'); // "blog.post.show"

// 生成路由 URL
module_route('post.show', ['id' => 1], 'Blog');

// 生成模块 URL
module_url('posts/1', 'Blog'); // "/blog/posts/1"
```

**模块路由文件示例**：

```php
<?php
// Modules/Blog/Routes/web.php

use Illuminate\Support\Facades\Route;

Route::group([
    'prefix' => 'blog',
    'as' => 'blog.',
], function () {
    Route::get('/', [PostController::class, 'index'])->name('post.index');
    Route::get('/{id}', [PostController::class, 'show'])->name('post.show');
});
```

### 4.6 模块状态与枚举

```php
// 获取所有已启用的模块
$enabled = module_enabled_modules();

// 获取所有已禁用的模块
$disabled = module_disabled_modules();

// 检查模块是否有指定迁移文件
module_has_migration('create_posts_table', 'Blog');

// 获取模块所有迁移文件
$migrations = module_all_migrations('Blog');

// 获取模块所有路由文件
$routes = module_route_files('Blog'); // ['web', 'api']
```

### 4.7 Stub 与工具

#### `module_stub()`

创建模块 Stub 生成器实例。

```php
function module_stub(string $module): StubGenerator
```

```php
$stub = module_stub('Blog');

// 替换变量
$stub->addReplacement('{{MODEL}}', 'Post');
$stub->addReplacement('{{TABLE}}', 'posts');

// 生成文件
$stub->generate('model.stub', 'Models/Post.php');
```

### 4.8 通用辅助函数

#### `get_user_info()`

获取当前认证用户信息。

```php
function get_user_info(?string $field = null): mixed
```

```php
// 获取当前用户完整信息
$user = get_user_info();

// 获取特定字段
$userId = get_user_info('id');
$userName = get_user_info('name');
```

#### `source_local_website()`

判断请求来源是否来自本站。

```php
function source_local_website(string $returnType = 'all'): bool|array|string|null
```

```php
$isLocal = source_local_website('status'); // true/false
$referer = source_local_website('url');    // 来源 URL
$uri = source_local_website('uri');        // 来源 URI
```

#### `after_class_calling()`

在类方法调用前执行初始化方法（支持依赖注入）。

```php
function after_class_calling(object $class, string $method = 'initialize', array ...$args): void
```

```php
after_class_calling($this, 'initialize');
after_class_calling($this, 'before', [$request]);
```

---

## 五、Artisan 命令大全

### 5.1 模块管理命令

| 命令 | 说明 | 示例 |
|------|------|------|
| `module:make` | 创建新模块 | `php artisan module:make Blog` |
| `module:list` | 列出所有模块 | `php artisan module:list` |
| `module:delete` | 删除模块 | `php artisan module:delete Blog` |
| `module:info` | 显示模块详情 | `php artisan module:info Blog` |
| `module:validate` | 验证模块完整性 | `php artisan module:validate Blog` |
| `module:publish` | 发布模块资源 | `php artisan module:publish Blog` |

```bash
# 创建模块时强制覆盖
php artisan module:make Blog --force

# 创建模块时生成所有文件（无视配置的 generate 设置）
php artisan module:make Blog --full

# 查看所有模块
php artisan module:list

# 查看模块详细信息
php artisan module:info Blog

# 验证模块
php artisan module:validate Blog

# 调试命令
php artisan module:debug-commands
```

### 5.2 代码生成命令

| 命令 | 说明 | 示例 |
|------|------|------|
| `module:make-controller` | 生成控制器 | `php artisan module:make-controller PostController Blog` |
| `module:make-model` | 生成模型 | `php artisan module:make-model Post Blog` |
| `module:make-migration` | 生成迁移 | `php artisan module:make-migration create_posts_table Blog` |
| `module:make-request` | 生成表单请求 | `php artisan module:make-request StorePostRequest Blog` |
| `module:make-seeder` | 生成数据填充 | `php artisan module:make-seeder PostSeeder Blog` |
| `module:make-provider` | 生成服务提供者 | `php artisan module:make-provider BlogServiceProvider Blog` |
| `module:make-command` | 生成命令 | `php artisan module:make-command SyncPosts Blog` |
| `module:make-event` | 生成事件 | `php artisan module:make-event PostCreated Blog` |
| `module:make-listener` | 生成监听器 | `php artisan module:make-listener SendNotification Blog` |
| `module:make-middleware` | 生成中间件 | `php artisan module:make-middleware CheckRole Blog` |
| `module:make-route` | 生成路由 | `php artisan module:make-route api Blog` |
| `module:make-config` | 生成配置 | `php artisan module:make-config services Blog` |

### 5.3 模块迁移命令

| 命令 | 说明 | 示例 |
|------|------|------|
| `module:migrate` | 运行模块迁移 | `php artisan module:migrate Blog` |
| `module:migrate-reset` | 回滚模块迁移 | `php artisan module:migrate-reset Blog` |
| `module:migrate-refresh` | 刷新模块迁移 | `php artisan module:migrate-refresh Blog` |
| `module:migrate-status` | 查看迁移状态 | `php artisan module:migrate-status Blog` |

```bash
# 运行所有模块的迁移
php artisan module:migrate

# 运行指定模块的迁移（含填充）
php artisan module:migrate Blog --seed

# 运行迁移（生产环境强制）
php artisan module:migrate Blog --force

# 查看迁移状态
php artisan module:migrate-status Blog
```

---

## 六、高级特性

### 6.1 模块优先级

模块可以通过 `Config/{lower_name}.php` 配置文件的 `priority` 键设置加载优先级，
**数字越小优先级越高，越先加载**。默认为 `1000`。

```php
// Modules/Core/Config/core.php
return [
    'enabled' => true,
    'priority' => 50,  // 最先加载
];

// Modules/Blog/Config/blog.php
return [
    'enabled' => true,
    'priority' => 500,
];
```

优先级排序在模块加载时自动执行。如果两个模块优先级相同，则按名称的字母顺序排序。

**典型场景**：
- `Core` 模块：`priority = 50` （最先加载，提供基础服务）
- `Auth` 模块：`priority = 100` 
- `Blog` 模块：`priority = 500`
- `Shop` 模块：`priority = 800`（依赖 Blog 模块）

### 6.2 模块别名

模块可以在配置文件中定义多个别名，通过这些别名也能查找模块。

```php
// Modules/Blog/Config/blog.php
return [
    'enabled' => true,
    'aliases' => ['blog-module', 'blog-system', 'content'],
];
```

```php
// 通过别名查找
$module = module('blog-module'); // 等价于 module('Blog')
$module = module('content');     // 也指向 Blog 模块
```

### 6.3 多路径扫描

支持从多个路径扫描模块，适用于多团队或微服务架构：

```php
// config/modules.php
'scan_paths' => [
    base_path('vendor/my-organization/modules'),
    base_path('packages/third-party'),
],
```

```php
// 运行时添加扫描路径
module()->addPath('/custom/modules/path');
module()->addPath(base_path('external-modules'));
module()->rescan(); // 重新扫描
```

### 6.4 模块缓存

生产环境建议开启文件缓存，避免每次请求扫描目录。

```bash
# .env
MODULES_CACHE_ENABLED=true
```

缓存文件存储在 `storage/framework/cache/modules/modules.php`，
默认 3600 秒过期，可通过配置修改。

```php
// 编程方式管理缓存
module()->clearCache();  // 清除所有缓存
module()->rescan();      // 强制重新扫描并更新缓存
```

### 6.5 事件钩子

模块系统支持生命周期事件钩子，可在模块加载前后执行自定义逻辑：

```php
use zxf\Modules\Support\ModuleAutoDiscovery;

// 模块发现前
ModuleAutoDiscovery::hook('before_discover', function ($module, $context) {
    logger()->info("Discovering module: {$module->getName()}");
});

// 模块发现后
ModuleAutoDiscovery::hook('after_discover', function ($module, $context) {
    logger()->info("Discovered module: {$module->getName()}", $context);
});
```

### 6.6 自定义 Stub

发布默认 stub 后自定义：

```bash
php artisan vendor:publish --tag=modules-stubs
```

自定义 stub 路径：`resources/stubs/modules/`

系统中支持的 stub 变量：

| 变量 | 说明 | 示例 |
|------|------|------|
| `{{NAME}}` | StudlyCase 名称 | Blog |
| `{{LOWER_NAME}}` | 全小写名称 | blog |
| `{{CAMEL_NAME}}` | 驼峰命名 | blog |
| `{{SNAKE_NAME}}` | 蛇形命名 | blog |
| `{{SLUG_NAME}}` | 虚线命名 | blog-module |
| `{{NAMESPACE}}` | 根命名空间 | Modules |
| `{{MODULE_NAMESPACE}}` | 模块命名空间 | Modules\Blog |
| `{{MODULE_PATH}}` | 模块绝对路径 | /var/www/Modules/Blog |
| `{{CLASS}}` | 类名（默认同模块名） | Blog |
| `{{DATE}}` | 当前日期 | 2026-06-17 |
| `{{YEAR}}` | 当前年份 | 2026 |
| `{{TIME}}` | 当前时间 | 16:30:00 |
| `{{DATETIME}}` | 日期时间 | 2026-06-17 16:30:00 |

### 6.7 控制器生命周期

扩展包提供了增强版的 `BaseController`，支持 `initialize` 和 `before` 生命周期方法：

```php
<?php

declare(strict_types=1);

namespace Modules\Blog\Http\Controllers\Web;

use zxf\Modules\Controller\BaseController;
use Illuminate\Http\Request;

class PostController extends BaseController
{
    /**
     * 在控制器方法执行前调用，支持依赖注入
     */
    public function initialize(PostService $postService): void
    {
        $this->postService = $postService;
    }

    /**
     * 在 initialize 之前调用
     */
    public function before(Request $request): void
    {
        // 全局权限检查
        $this->authorize('access-blog');
    }

    public function index()
    {
        $posts = $this->postService->getAll();
        return view('blog::post.index', compact('posts'));
    }
}
```

`ControllerTrait` 提供的方法：

| 方法 | 说明 |
|------|------|
| `$this->success($message, $jumpUrl)` | 返回成功响应（自动判断 AJAX/普通请求） |
| `$this->error($message, $jumpUrl)` | 返回错误响应 |
| `$this->json($data, $status, $jumpUrl)` | 返回 JSON 响应 |
| `$this->api_json($data, $code, $message)` | API JSON 标准格式 |
| `$this->dataTables($list, $total)` | DataTables 插件格式 |
| `$this->backWithError($errors)` | 返回上一页并携带错误 |
| `$this->backWithSuccess($info)` | 返回上一页并携带提示 |

### 6.8 Eloquent 查询宏

本扩展包提供了 **15 大类** Eloquent Builder 增强宏，注册到 `Illuminate\Database\Eloquent\Builder` 上：

#### 1. whereHas 优化系列

解决关联查询全表扫描问题，将 EXISTS 子查询改写为 IN/JOIN 子查询：

```php
// 替代 whereHas，大幅提升性能
Post::whereHasIn('comments', fn($q) => $q->where('approved', true))->get();

// NOT IN 版本
Post::whereHasNotIn('comments', fn($q) => $q->where('spam', true))->get();

// JOIN 方式
Post::whereHasJoin('tags', fn($q) => $q->where('name', 'laravel'))->get();
Post::whereHasLeftJoin('categories', fn($q) => $q->where('active', true))->get();

// 多态关联
Comment::whereHasMorphIn('commentable', [Post::class, Video::class])->get();
```

#### 2. 主表字段自动前缀

```php
// 避免关联查询时的字段歧义
Post::mainWhere('status', 'published')
    ->mainWhereIn('category_id', [1, 2, 3])
    ->mainOrderBy('created_at', 'desc')
    ->mainSelect(['id', 'title', 'content'])
    ->get();

// 等价于 WHERE posts.status = 'published' AND posts.category_id IN (1,2,3)
```

#### 3. 窗口函数

MySQL 8.0+ 窗口函数完整支持：

```php
// 排名
Post::rowNumber('category_id', 'views', 'desc', 'row_num')
    ->rank('category_id', 'views', 'desc', 'rank_num')
    ->denseRank('category_id', 'views', 'desc', 'dense_rank')
    ->get();

// 偏移（环比/同比分析）
Order::lag('amount', 1, 0, null, 'created_at', 'asc', 'prev_amount')
    ->lead('amount', 1, 0, null, 'created_at', 'asc', 'next_amount')
    ->get();

// 聚合窗口
Order::sumOver('amount', 'user_id', null, null, null, 'total_spent')
    ->avgOver('amount', 'user_id', null, null, null, 'avg_spent')
    ->get();

// 运行总计
Order::cumulativeSum('amount', 'user_id', 'created_at', 'asc', 'running_total')
    ->get();

// 移动平均（前后3天均价）
Stock::movingAverage('price', 3, 'stock_code', 'trade_date', 'asc', 'ma3')
    ->get();
```

#### 4. 快速分页

解决超大表深度分页性能问题：

```php
// 智能快速分页（自动选择延迟关联或窗口函数策略）
Post::fastPaginate(20);
Post::fastSimplePaginate(20);   // 不计算总数，性能更佳
Post::cursorPaginate(20);       // 游标分页，最佳性能
Post::seekPaginate(100);        // 寻址分页，适合极端深度分页
```

#### 5. JSON 高级操作

```php
// 提取 JSON 字段
User::jsonExtract('profile', '$.age', 'int', 'user_age')
    ->jsonPath('settings', '$.theme', 'theme')
    ->get();

// JSON 数组操作
Post::whereJsonArrayContains('tags', 'laravel')
    ->whereJsonArrayContainsAny('tags', ['php', 'go'])
    ->whereJsonArrayContainsAll('tags', ['laravel', 'vue'])
    ->get();

// JSON 搜索
Product::jsonSearch('metadata', 'keyword', 'all', '$.name')
    ->whereJsonLike('metadata', '%search%')
    ->get();
```

#### 6. 递归查询

树形结构数据处理（分类、组织架构等）：

```php
// 查找所有后代
Category::withAllChildren($parentId)->get();

// 查找所有祖先
Category::withAllParents($childId)->get();

// 面包屑路径
Category::withBreadcrumbs($categoryId)->get();

// 构建完整树
Category::withTree($rootId)->get();

// 查找叶子节点
Category::withLeafNodes()->get();

// 获取子孙数量
Category::withDescendantsCount($categoryId)->get();

// 自定义递归查询
Category::recursiveQuery(
    fn($q) => $q->whereNull('parent_id'),      // 根节点
    fn($q) => $q->join('categories as c', ...), // 递归部分
    ['*'], 100, 'depth'
)->get();
```

#### 其他查询宏

```php
// 随机抽样
Post::random(10);                      // 随机10条
Post::groupRandom('category_id', 3);   // 每组随机3条

// 集合操作 (MySQL 8.0.31+)
ActiveUser::query()->intersect(VipUser::query())->get();
ActiveUser::query()->except(BlacklistUser::query())->get();

// 行列转换 (PIVOT)
Sales::pivot('month', ['Jan', 'Feb', 'Mar'], 'amount', 'SUM', 'product_id')->get();

// 数据抽样
User::sample(10.0);                    // 10% 随机抽样
User::stratifiedSample('city', 100);   // 按城市分层抽样

// 正则表达式
Post::whereRegexp('title', '^Laravel', 'i')->get();
Post::regexpExtract('content', '/(\w+@\w+\.\w+)/', 1, 1, 'i', 'email')->get();

// QUALIFY 窗口函数过滤
Post::rowNumber('category_id', 'views', 'desc', 'rn')
    ->qualify('rn', '<=', 3)  // 每组前3名
    ->get();
```

---

## 七、最佳实践

### 7.1 模块拆分原则

```
✅ 推荐：
├── Core/          # 核心基础服务（用户认证、权限、配置）
├── Blog/          # 博客内容管理
├── Shop/          # 商城模块（依赖 Core）
└── Admin/         # 后台管理（依赖 Core、Blog、Shop）

❌ 避免：
├── UserLogin/     # 粒度过细，应放入 Core
├── Everything/    # 粒度过粗，难以维护
└── A/             # 无意义的命名
```

**命名规范**：
- 模块名使用 `StudlyCase`（如 `Blog`, `UserManage`, `ECommerce`）
- 每个模块职责单一明确
- 模块间通过 API/事件/服务容器通信，避免直接依赖

### 7.2 性能优化

| 优化项 | 方法 | 效果 |
|--------|------|------|
| 模块缓存 | `.env` 中设置 `MODULES_CACHE_ENABLED=true` | 减少目录扫描 I/O |
| whereHasIn | 使用 `whereHasIn()` 替代 `whereHas()` | EXISTS → IN 子查询 |
| 深度分页 | 使用 `fastPaginate()` 或 `cursorPaginate()` | 避免大 OFFSET |
| 视图预编译 | `php artisan view:cache` | 加速视图渲染 |
| 配置缓存 | `php artisan config:cache` | 加速配置读取 |
| OpCache | 生产环境开启 PHP OpCache | 加速 PHP 执行 |

### 7.3 安全建议

1. **配置敏感信息**：敏感配置（API 密钥等）通过 `.env` 管理，不要硬编码在模块配置文件中
2. **模块权限控制**：使用 Laravel 的 Gate/Policy 控制各模块访问权限
3. **迁移安全**：生产环境执行 `module:migrate` 时务必加 `--force` 前确认
4. **依赖安全**：定期运行 `composer audit` 检查模块依赖包安全漏洞
5. **输入验证**：模块内使用 `FormRequest` 统一验证输入

---

## 八、常见问题 FAQ

### Q: 模块创建后没有加载？

**A**: 检查以下几点：
1. 模块目录是否在配置的 `path` 或 `scan_paths` 下
2. 模块是否被禁用（检查 `Config/config.php` 中的 `enabled` 键）
3. 运行 `php artisan config:clear` 清除配置缓存

### Q: 路由 404？

**A**: 
1. 确认路由文件存在于 `Modules/{ModuleName}/Routes/` 下
2. 检查 `routes.default_files` 配置是否包含你的路由文件
3. 确认路由文件中定义了正确的 `prefix` 和 `name`

### Q: 模块视图找不到？

**A**: 
1. 确保视图文件在 `Modules/{ModuleName}/Resources/views/` 下
2. 使用 `{lower_module_name}::` 命名空间引用（如 `blog::index`）
3. 检查 `views.namespace_format` 配置

### Q: 如何禁用某个模块？

**A**: 三种方式：
1. 在模块 `Config/config.php` 中设置 `'enabled' => false`
2. 在 `config/modules.php` 的 `discovery` 中关闭相关组件的自动发现
3. 直接移除模块目录

### Q: 如何检查命令冲突？

**A**: 运行 `php artisan module:debug-commands` 查看所有已注册的模块命令

### Q: 模块缓存过期了怎么办？

**A**: 
- 使用 `module()->clearCache()` 清除缓存
- 或直接删除 `storage/framework/cache/modules/modules.php`
- 设置 `cache.ttl` 为 `0` 可永久缓存

### Q: 如何在模块间共享数据？

**A**: 
1. 通过 Laravel 服务容器绑定共享服务
2. 通过事件系统通信
3. 通过模块优先级确保依赖模块先加载
4. 通过模块配置文件中的 `providers` 键注册共享的服务提供者

---

> 更多技术细节请参考：
> - [GitHub 仓库](https://github.com/zhaoxianfang/modules)
> - `docs/` 目录下的其他文档
> - Laravel 官方文档: https://laravel.com/docs
