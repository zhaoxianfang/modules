# Laravel 模块系统 - 功能完整指南

## 📋 功能一览表

### 1. 命令功能

| 命令 | 说明 | 参数/选项 | 示例 |
|---|---|---|---|
| `module:make` | 创建新模块 | `{name}` `{--force}` `{--full}` | `php artisan module:make Blog` |
| `module:list` | 列出所有模块 | — | `php artisan module:list` |
| `module:info` | 显示模块详情 | `{name}` | `php artisan module:info Blog` |
| `module:validate` | 验证模块完整性 | `{name?}` | `php artisan module:validate Blog` |
| `module:delete` | 删除模块 | `{name}` `{--force}` | `php artisan module:delete Blog` |
| `module:publish` | 发布指南/配置文件 | `{--guide}` `{--config}` `{--force}` | `php artisan module:publish --config` |
| `module:cache` | 重建模块缓存 | — | `php artisan module:cache` |
| `module:clear` | 清除模块缓存 | — | `php artisan module:clear` |
| `module:seed` | 运行模块数据填充 | `{module?}` `{--class=}` `{--database=}` `{--force}` | `php artisan module:seed Blog` |
| `module:make-controller` | 创建控制器 | `{module}` `{name}` `{--type=web}` `{--force}` `{--plain}` `{--attributes}` | `php artisan module:make-controller Blog Post --type=api` |
| `module:make-model` | 创建模型 | `{module}` `{name}` `{--table=}` `{--migration}` `{--force}` | `php artisan module:make-model Blog Post --migration` |
| `module:make-migration` | 创建迁移 | `{module}` `{name}` `{--create=}` `{--update=}` `{--path=}` `{--realpath}` `{--fullpath}` | `php artisan module:make-migration Blog create_posts_table --create=posts` |
| `module:make-request` | 创建表单请求 | `{module}` `{name}` `{--force}` | `php artisan module:make-request Blog StorePost` |
| `module:make-resource` | 创建 API 资源 | `{module}` `{name}` `{--collection}` `{--json-api}` `{--force}` | `php artisan module:make-resource Blog PostResource` |
| `module:make-repository` | 创建仓库类 | `{module}` `{name}` `{--force}` | `php artisan module:make-repository Blog PostRepository` |
| `module:make-observer` | 创建模型观察者 | `{module}` `{name}` `{--model=}` `{--force}` | `php artisan module:make-observer Blog PostObserver` |
| `module:make-policy` | 创建授权策略 | `{module}` `{name}` `{--model=}` `{--force}` | `php artisan module:make-policy Blog PostPolicy` |
| `module:make-provider` | 创建服务提供者 | `{module}` `{name}` `{--force}` | `php artisan module:make-provider Blog BlogServiceProvider` |
| `module:make-middleware` | 创建中间件 | `{module}` `{name}` `{--force}` | `php artisan module:make-middleware Blog CheckAuth` |
| `module:make-command` | 创建 Artisan 命令 | `{module}` `{name}` `{--command=}` `{--force}` | `php artisan module:make-command Blog SendEmail` |
| `module:make-event` | 创建事件类 | `{module}` `{name}` `{--force}` | `php artisan module:make-event Blog UserRegistered` |
| `module:make-listener` | 创建监听器 | `{module}` `{name}` `{--event=}` `{--force}` | `php artisan module:make-listener Blog SendWelcome --event=UserRegistered` |
| `module:make-job` | 创建队列任务 | `{module}` `{name}` `{--force}` | `php artisan module:make-job Blog SendEmail` |
| `module:make-seeder` | 创建数据填充器 | `{module}` `{name}` `{--force}` | `php artisan module:make-seeder Blog UserSeeder` |
| `module:make-config` | 创建配置文件 | `{module}` `{name}` `{--force}` | `php artisan module:make-config Blog settings` |
| `module:make-route` | 创建路由文件 | `{module}` `{name}` `{--type=web}` `{--force}` | `php artisan module:make-route Blog mobile --type=web` |
| `module:make-view` | 创建视图文件 | `{module}` `{name}` `{--force}` | `php artisan module:make-view Blog posts.index` |
| `module:make-test` | 创建测试类 | `{module}` `{name}` `{--feature}` `{--force}` | `php artisan module:make-test Blog PostTest --feature` |
| `module:check-lang` | 检查本地化文件 | `{name?}` `{--path=}` | `php artisan module:check-lang Blog` |
| `module:debug-commands` | 调试命令注册 | `{--module=}` | `php artisan module:debug-commands --module=Blog` |
| `module:migrate` | 运行模块迁移 | `{module?}` `{--force}` `{--path=}` `{--seed}` `{--seeder=}` | `php artisan module:migrate Blog` |
| `module:migrate-fresh` | 清空并重建迁移 | `{module?}` `{--database=}` `{--force}` `{--seed}` `{--seeder=}` `{--drop-views}` `{--drop-types}` | `php artisan module:migrate-fresh Blog` |
| `module:migrate-refresh` | 回滚并重跑迁移 | `{module?}` `{--force}` `{--seed}` `{--seeder=}` | `php artisan module:migrate-refresh Blog` |
| `module:migrate-reset` | 回滚全部迁移 | `{module?}` `{--force}` `{--path=}` | `php artisan module:migrate-reset Blog` |
| `module:migrate-rollback` | 回滚上一步迁移 | `{module?}` `{--database=}` `{--force}` `{--step=}` `{--path=}` | `php artisan module:migrate-rollback Blog` |
| `module:migrate-status` | 查看迁移状态 | `{module?}` `{--path=}` `{--pending}` `{--ran}` `{--no-stats}` | `php artisan module:migrate-status` |

> ⚠️ **命名约定**：迁移相关命令统一使用**连字符**（`module:migrate-refresh`、
> `module:migrate-reset`），而非 `module:migrate:refresh` 这类冒号写法——后者会报
> “Command not found”。此外，模块级管理命令的参数名为 `{name}`，
> 而生成器类命令使用 `{module}` + `{name}` 两个参数。

### 2. 配置功能

| 配置项                           | 说明           | 默认值                       | 示例                                   |
|-------------------------------|--------------|---------------------------|--------------------------------------|
| `namespace`                   | 模块根命名空间      | `Modules`                 | `'namespace' => 'Modules'`           |
| `path`                        | 模块存储路径       | `base_path('Modules')`    | `'path' => base_path('Modules')`     |
| `assets`                      | 资源发布路径       | `public_path('modules')`  | `'assets' => public_path('modules')` |
| `middleware_groups`           | 路由中间件组配置     | 见下方                       | 见下方                                  |
| `scan_paths`                   | 额外模块扫描路径       | `[]` | `['paths' => [base_path('CustomModules')]]`  |
| `routes.prefix`               | 是否自动添加路由前缀   | `true`                    | `'prefix' => true`                   |
| `routes.name_prefix`          | 是否自动添加路由名称前缀 | `true`                    | `'name_prefix' => true`              |
| `routes.default_files`        | 默认路由文件列表     | `['web', 'api', 'admin']` | `['web', 'api', 'admin']`            |
| `views.enabled`               | 是否启用视图命名空间   | `true`                    | `'enabled' => true`                  |
| `views.namespace_format`      | 视图命名空间格式     | `'lower'`                 | `'namespace_format' => 'lower'`      |
| `discovery.*`                 | 自动发现配置       | 见下方                       | 见下方                                  |
| `cache.enabled`               | 是否启用模块缓存     | `false`                   | `'enabled' => false`                 |
| `cache.key`                   | 缓存键名         | `'modules'`               | `'key' => 'modules'`                 |
| `cache.ttl`                   | 缓存时间         | `0`                       | `'ttl' => 0`                         |

#### 中间件组配置

```php
'middleware_groups' => [
    'web' => ['web'],
    'api' => ['api'],
    'admin' => ['web', 'admin'],
    'mobile' => ['web', 'mobile'],  // 自定义
],
```

#### 自动发现配置

```php
'discovery' => [
    'routes' => true,         // 自动发现路由
    'providers' => true,      // 自动发现服务提供者
    'commands' => true,       // 自动发现命令
    'views' => true,         // 自动发现视图
    'config' => true,        // 自动发现配置
    'translations' => true,   // 自动发现翻译
    'migrations' => true,     // 自动发现迁移
    'events' => true,        // 自动发现事件
],
```

### 3. Helper 函数

#### 核心函数

> ⚠️ 统一约定：**业务参数在前，模块名 `$module` 在最后且可选**。
> 省略 `$module` 时自动从当前请求推断（详见 [Helper 函数详解](05-helper-functions.md)）。

| 函数                                                                     | 说明       | 返回值      | 示例                                      |
|------------------------------------------------------------------------|----------|----------|-----------------------------------------|
| `module_name(bool $toLower = false, bool $requestModule = true)`        | 获取模块名称   | `string` | `module_name()` → `'Blog'`              |
| `module(?string $module = null)`                                       | 获取模块实例或仓库 | `Module/Repository/null` | `module('Blog')` |
| `modules()`                                                            | 获取所有模块   | `array`  | `modules()`                             |
| `module_exists(string $module)`                                        | 检查模块是否存在 | `bool`   | `module_exists('Blog')` → `true`        |
| `module_enabled(?string $module = null)`                               | 检查模块是否启用 | `bool`   | `module_enabled('Blog')` → `true`       |
| `module_enabled_modules()`                                             | 获取已启用模块  | `array`  | `module_enabled_modules()`              |
| `module_disabled_modules()`                                            | 获取已禁用模块  | `array`  | `module_disabled_modules()`             |
| `module_path(string $path = '', ?string $module = null)`               | 获取模块路径   | `string` | `module_path('Models', 'Blog')`         |
| `module_namespace(?string $module = null)`                             | 获取模块命名空间 | `string` | `module_namespace('Blog')`              |
| `module_class(string $class = '', ?string $module = null)`             | 解析模块类全名  | `string` | `module_class('Models\\Post', 'Blog')`  |

#### 路径函数

| 函数                                                              | 说明        | 返回值      | 示例                                      |
|-----------------------------------------------------------------|-----------|----------|-----------------------------------------|
| `module_config_path(string $configFile = 'config.php', ?string $module = null)` | 获取配置文件路径  | `string` | `module_config_path('common.php', 'Blog')` |
| `module_routes_path(string $route = 'web', ?string $module = null)`            | 获取路由文件路径  | `string` | `module_routes_path('web', 'Blog')`     |
| `module_migrations_path(?string $module = null)`                | 获取迁移目录路径  | `string` | `module_migrations_path('Blog')`        |
| `module_models_path(?string $module = null)`                    | 获取模型目录路径  | `string` | `module_models_path('Blog')`            |
| `module_controllers_path(string $controller = 'Web', ?string $module = null)`  | 获取控制器目录路径 | `string` | `module_controllers_path('Web', 'Blog')` |
| `module_views_path(?string $module = null)`                     | 获取视图目录路径  | `string` | `module_views_path('Blog')`             |
| `module_trans_path(?string $module = null)`                     | 获取语言目录路径  | `string` | `module_trans_path('Blog')`             |
| `module_resources_path(string $path = '', ?string $module = null)`             | 获取资源目录路径  | `string` | `module_resources_path('js', 'Blog')`   |

#### 路由函数

| 函数                                                            | 说明           | 返回值      | 示例                                 |
|---------------------------------------------------------------|--------------|----------|------------------------------------|
| `module_route(string $route = '', array $params = [], ?string $module = null)` | 生成模块路由 URL | `string` | `module_route('posts.index', [], 'Blog')` |
| `module_route_path(string $route = '', ?string $module = null)`       | 获取带前缀的路由名称  | `string` | `module_route_path('posts.index', 'Blog')` → `blog.posts.index` |
| `module_has_route(string $route = '', ?string $module = null)`        | 检查模块路由是否存在  | `bool`  | `module_has_route('posts.index', 'Blog')` |
| `module_route_files(?string $module = null)`                          | 获取模块路由文件列表 | `array` | `module_route_files('Blog')`       |
| `module_url(string $path = '', ?string $module = null)`               | 生成模块 URL     | `string` | `module_url('posts/1', 'Blog')`    |
| `module_asset(string $asset = '', ?string $module = null)`            | 生成模块资源 URL  | `string` | `module_asset('css/app.css', 'Blog')` |

#### 视图函数

| 函数                                                        | 说明       | 返回值    | 示例                                               |
|-----------------------------------------------------------|----------|--------|--------------------------------------------------|
| `module_view(string $view = '', array $data = [], ?string $module = null)` | 返回模块视图 | `View` | `module_view('post.index', ['posts' => $posts], 'Blog')` |
| `module_view_path(string $view = '', ?string $module = null)`             | 获取视图文件路径 | `string` | `module_view_path('post.index', 'Blog')` |
| `module_has_view(string $view = '', ?string $module = null)`              | 检查视图是否存在 | `bool` | `module_has_view('post.index', 'Blog')` |
| `module_lang(string $key = '', array $replace = [], ?string $locale = null, ?string $module = null)` | 获取模块翻译 | `string/array` | `module_lang('title', [], null, 'Blog')` |

#### 配置与迁移函数

| 函数                                                                             | 说明          | 返回值             | 示例                                    |
|--------------------------------------------------------------------------------|-------------|-----------------|---------------------------------------|
| `module_config(string $key, mixed $default = null, ?string $module = null)`      | 读取模块配置（支持点号） | `mixed`         | `module_config('common.name', 'x', 'Blog')` |
| `module_get_config(string $configFile = '', ?string $module = null)`             | 获取配置文件全部内容 | `array`         | `module_get_config('common', 'Blog')` |
| `module_has_config(string $configFile = '', string $key = '', ?string $module = null)` | 检查配置键是否存在（**key 为单层键，不支持点号嵌套**） | `bool` | `module_has_config('common', 'name', 'Blog')` |
| `module_set_config(string $configFile = '', string $key = '', mixed $value = null, ?string $module = null)` | 运行时设置配置值 | `void` | `module_set_config('common', 'name', 'x', 'Blog')` |
| `module_config_files(?string $module = null)`                                    | 获取模块所有配置文件 | `array`         | `module_config_files('Blog')`         |
| `module_has_migration(string $migrationName = '', ?string $module = null)`       | 检查迁移是否存在   | `bool`          | `module_has_migration('create_posts_table', 'Blog')` |
| `module_all_migrations(?string $module = null)`                                  | 获取模块所有迁移   | `array`         | `module_all_migrations('Blog')`       |
| `module_stub(string $module)`                                                    | 获取模块 stub 生成器 | `StubGenerator` | `module_stub('Blog')`                |

### 4. Stub 替换变量

| 变量                              | 说明               | 示例                       |
|---------------------------------|------------------|--------------------------|
| `{{NAME}}`                      | 模块名称（StudlyCase） | `Blog`                   |
| `{{NAME_FIRST_LETTER}}`         | 模块名称首字母          | `B`                      |
| `{{CAMEL_NAME}}`                | 模块名称（camelCase）  | `blog`                   |
| `{{LOWER_CAMEL_NAME}}`          | 模块名称（小驼峰）        | `blog`                   |
| `{{LOWER_NAME}}`                | 模块名称（小写）         | `blog`                   |
| `{{UPPER_NAME}}`                | 模块名称（大写）         | `BLOG`                   |
| `{{NAMESPACE}}`                 | 模块命名空间           | `Modules`                |
| `{{MODULE_NAMESPACE}}`          | 完整模块命名空间         | `Modules\Blog`           |
| `{{CONTROLLER_SUBNAMESPACE}}`   | 控制器子命名空间         | `\Web`                   |
| `{{CLASS}}`                     | 类名               | `PostController`         |
| `{{SIGNATURE}}`                 | 命令签名             | `module:blog:send-email` |
| `{{DESCRIPTION}}`               | 命令描述             | `Command description`    |
| `{{DATE}}`                      | 当前日期             | `2024-01-15`             |
| `{{YEAR}}`                      | 当前年份             | `2024`                   |
| `{{TIME}}`                      | 当前时间             | `10:30:45`               |
| `{{ROUTE_PREFIX_VALUE}}`        | 路由前缀值（动态）        | `api/blog`               |
| `{{ROUTE_NAME_PREFIX_VALUE}}`   | 路由名称前缀值（动态）      | `api.blog.`              |
| `{{ROUTE_PREFIX_COMMENT}}`      | 路由前缀注释           | `路由前缀: api/blog`         |
| `{{ROUTE_NAME_PREFIX_COMMENT}}` | 路由名称前缀注释         | `路由名称前缀: api.blog.`      |

### 5. 路由前缀规则

#### 前缀规则（prefix=true）

| 路由文件        | 前缀            | 名称前缀           | 示例 URL              | 示例路由名称                   |
|-------------|---------------|----------------|---------------------|--------------------------|
| `web.php`   | `{模块名}`       | `web.{模块名}.`   | `/blog/posts`       | `web.blog.posts.index`   |
| `api.php`   | `api/{模块名}`   | `api.{模块名}.`   | `/api/blog/posts`   | `api.blog.posts.index`   |
| `admin.php` | `{模块名}/admin` | `admin.{模块名}.` | `/blog/admin/posts` | `admin.blog.posts.index` |

#### 前缀规则（prefix=false）

| 路由文件        | 前缀      | 名称前缀 | 示例 URL        | 示例路由名称        |
|-------------|---------|------|---------------|---------------|
| `web.php`   | `{模块名}` | 空    | `/blog/posts` | `posts.index` |
| `api.php`   | `{模块名}` | 空    | `/blog/posts` | `posts.index` |
| `admin.php` | `{模块名}` | 空    | `/blog/posts` | `posts.index` |

#### 名称前缀规则（name_prefix=true）

| 路由文件        | 名称前缀格式         | 示例路由名称                   |
|-------------|----------------|--------------------------|
| `web.php`   | `web.{模块名}.`   | `web.blog.posts.index`   |
| `api.php`   | `api.{模块名}.`   | `api.blog.posts.index`   |
| `admin.php` | `admin.{模块名}.` | `admin.blog.posts.index` |

### 6. 模块启用/禁用

#### 配置方式

编辑模块配置文件 `Modules/Blog/Config/blog.php`：

```php
return [
    'enabled' => true,       // true: 启用, false: 禁用, 未配置: 默认启用
    'priority' => 1000,      // 加载优先级
    'name' => 'Blog',
    'version' => '1.0.0',
    'description' => 'Blog 模块',
    'author' => '',
    'aliases' => [],         // 模块别名
    'providers' => [],       // 额外服务提供者
    'laravel_aliases' => [], // 额外门面别名
    'options' => [],         // 自定义配置项
];
```

#### 禁用影响

- ❌ 模块路由无法访问
- ❌ 模块服务提供者不加载
- ❌ 模块视图无法使用
- ❌ 模块命令不注册
- ❌ 模块配置不加载
- ❌ 模块迁移不自动加载

#### 检查模块状态

```php
use zxf\Modules\Facades\Module;

// 方式 1：使用 Facade
if (Module::find('Blog')->isEnabled()) {
    // 模块已启用
}

// 方式 2：使用助手函数
if (module_enabled('Blog')) {
    // 模块已启用
}

// 方式 3：检查当前模块
if (module_enabled()) {
    // 当前模块已启用
}
```

### 7. 模块结构

```
Modules/
└── Blog/
    ├── Config/
    │   └── blog.php              # 模块配置文件（必需，包含 enabled）
    ├── Database/
    │   ├── Migrations/            # 数据库迁移文件
    │   └── Seeders/             # 数据填充器
    ├── Http/
    │   ├── Controllers/
    │   │   ├── Controller.php     # 基础控制器
    │   │   ├── Web/            # Web 控制器
    │   │   ├── Api/            # API 控制器
    │   │   └── Admin/          # Admin 控制器
    │   ├── Middleware/           # 中间件
    │   └── Requests/            # 表单请求验证
    ├── Models/                   # 模型
    ├── Providers/
    │   └── BlogServiceProvider.php # 服务提供者（必需）
    ├── Resources/
    │   ├── assets/              # 静态资源
    │   ├── lang/                # 语言文件
    │   └── views/               # 视图文件
    ├── Routes/
    │   ├── web.php              # Web 路由
    │   ├── api.php              # API 路由
    │   └── admin.php            # Admin 路由
    ├── Events/                   # 事件
    ├── Listeners/                # 事件监听器
    ├── Observers/               # 模型观察者
    ├── Policies/                # 策略类
    ├── Repositories/            # 仓库类
    ├── Console/
    │   └── Commands/            # 命令
    └── Tests/                   # 测试文件
```

### 8. 路由文件格式

#### Web 路由（Routes/web.php）

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Blog\Http\Controllers\Web;

/*
|--------------------------------------------------------------------------
| Blog 模块的 Web 路由
|--------------------------------------------------------------------------
|
| 在这里注册 Blog 模块的 Web 路由
| 这些路由会自动应用 web 中间件组
| 路由前缀: blog（根据 config/modules.php 配置）
| 路由名称前缀: web.blog.（根据 config/modules.php 配置）
| 控制器命名空间: Modules\Blog\Http\Controllers\Web
|
| 注意：路由文件包含路由组声明，由 模块路由加载器（ModuleAutoDiscovery） 统一管理路由前缀和名称前缀。
| 如需修改，请通过 modules.php 配置控制是否添加前缀。
*/

Route::prefix('blog')
    ->name('web.blog.')
    ->group(function () {
        Route::get('', [Web\BlogController::class, 'index'])->name('list');
        Route::get('{id}', [Web\BlogController::class, 'show'])->name('show');
        Route::post('', [Web\BlogController::class, 'store'])->name('store');
        Route::put('{id}', [Web\BlogController::class, 'update'])->name('update');
        Route::delete('{id}', [Web\BlogController::class, 'destroy'])->name('destroy');
    });
```

### 9. 配置文件格式

#### 模块配置（Config/blog.php）

> **v5.0**：模块不再使用 composer.json 管理。所有元数据均在此 PHP 配置文件中定义。

```php
<?php

return [
    'enabled' => true,
    'priority' => 1000,
    'name' => 'Blog',
    'version' => '1.0.0',
    'description' => 'Blog 模块',
    'author' => '',
    'aliases' => [],
    'providers' => [
        // 额外服务提供者
    ],
    'laravel_aliases' => [
        // 额外门面别名 => 类名
    ],
    'options' => [
        // 自定义配置项
        // module_config('options.cache_ttl', 3600)
    ],
];
```

### 10. 常见使用场景

#### 场景 1：创建完整的 CRUD 模块

```bash
# 1. 创建模块
php artisan module:make Blog

# 2. 创建模型和迁移
php artisan module:make-model Blog Post --migration

# 3. 运行迁移
php artisan module:migrate Blog

# 4. 创建控制器（默认已创建）
# 编辑 Http/Controllers/Web/PostController.php

# 5. 添加路由
# 编辑 Routes/web.php

# 6. 创建视图
php artisan module:make-view Blog post.index
```

#### 场景 2：创建 API 端点

```bash
# 1. 创建 API 控制器
php artisan module:make-controller Blog Post --type=api

# 2. 添加 API 路由
# 编辑 Routes/api.php

# 3. 创建表单请求
php artisan module:make-request Blog StorePost
php artisan module:make-request Blog UpdatePost

# 4. 测试 API
curl http://your-app.com/api/blog/posts
```

#### 场景 3：禁用模块

```php
// 1. 编辑 Modules/Blog/Config/blog.php
'enabled' => false,

// 2. 清除缓存
php artisan config:clear

// 3. 验证模块已禁用
php artisan module:list

// 4. 尝试访问路由（应该失败）
// 访问 /blog/posts 将返回 404
```

#### 场景 4：自定义路由前缀

```php
// 编辑 config/modules.php
'routes' => [
    'prefix' => false,          // 不自动添加前缀
    'name_prefix' => true,      // 仍然添加名称前缀
],
```

生成的路由文件：

```php
Route::prefix('blog')
    ->name('web.blog.')
    ->group(function () {
        // 路由定义
    });
```

### 11. 最佳实践

#### ✅ 推荐做法

1. **使用模块配置控制功能开关**
   ```php
   if (module_config('options.feature_enabled', false)) {
       // 功能代码
   }
   ```

2. **使用助手函数而非硬编码路径**
   ```php
   // ✅ 推荐
   $path = module_path('Models/Post.php');

   // ❌ 不推荐
   $path = base_path('Modules/Blog/Models/Post.php');
   ```

3. **使用视图命名空间**
   ```php
   // ✅ 推荐
   return module_view('post.index', ['posts' => $posts]);

   // ❌ 不推荐
   return view('blog::post.index', ['posts' => $posts]);
   ```

4. **使用模块检查**
   ```php
   if (module_enabled()) {
       // 模块启用时执行的代码
   }
   ```

#### ❌ 不推荐做法

1. **硬编码模块路径**
   ```php
   // ❌ 不推荐
   require_once base_path('Modules/Blog/Functions/helpers.php');
   ```

2. **直接访问未检查的模块**
   ```php
   // ❌ 不推荐
   $module = module('UnknownModule'); // 可能返回 null
   $module->isEnabled(); // 报错
   ```

3. **重复的路由配置**
   ```php
   // ❌ 不推荐（路由文件中已有前缀）
   Route::prefix('blog')->name('blog.')
       ->group(function () {
           Route::prefix('blog')... // 重复
       });
   ```

### 12. 故障排除

#### 问题 1：模块路由 404

**可能原因：**
- 模块被禁用
- 路由配置错误
- 缓存未清除

**解决方案：**
```bash
# 1. 检查模块状态
php artisan module:info Blog

# 2. 检查路由列表
php artisan route:list --path=blog

# 3. 清除缓存
php artisan config:clear
php artisan route:clear
```

#### 问题 2：视图未找到

**可能原因：**
- 视图命名空间错误
- 视图文件路径错误

**解决方案：**
```php
// 检查视图是否存在
if (module_has_view('post.index')) {
    return module_view('post.index');
}
```

#### 问题 3：配置读取失败

**可能原因：**
- 配置文件路径错误
- 配置文件格式错误

**解决方案：**
```php
// 检查配置文件是否存在
if (module_has_config('blog', 'blog', 'name')) {
    $name = module_config('blog.name', 'default');
}
```

## 📚 相关文档

- [安装指南](01-installation.md)
- [快速开始](02-quickstart.md)
- [模块结构](03-module-structure.md)
- [配置详解](04-configuration.md)
- [Helper 函数](05-helper-functions.md)
- [智能检测](06-intelligent-detection.md)
- [路由指南](07-routes.md)
- [视图使用](08-views.md)
- [命令参考](09-commands.md)
- [最佳实践](12-best-practices.md)

## 🤝 贡献

欢迎提交 Issue 和 Pull Request！

## 📄 许可证

MIT License
