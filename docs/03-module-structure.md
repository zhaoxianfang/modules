# 模块结构详解

## 标准目录结构

模块创建后会生成以下完整的目录结构：

```
Modules/
└── {ModuleName}/
    ├── Config/                          # 配置文件目录
    │   └── config.php                  # 模块配置文件（必需）
    │
    ├── Database/                        # 数据库相关目录
    │   ├── Migrations/                 # 数据库迁移文件
    │   │   └── 2024_01_01_000000_create_table_name.php
    │   └── Seeders/                   # 数据填充器
    │       └── ModuleNameSeeder.php
    │
    ├── Http/                           # HTTP 层目录
    │   ├── Controllers/                # 控制器目录
    │   │   ├── Controller.php         # 基础控制器
    │   │   ├── Web/                  # Web 控制器
    │   │   │   └── TestController.php
    │   │   ├── Api/                  # API 控制器
    │   │   │   └── TestController.php
    │   │   └── Admin/                # Admin 控制器
    │   │       └── TestController.php
    │   ├── Middleware/                # 中间件目录
    │   │   └── CustomMiddleware.php
    │   └── Requests/                  # 表单请求验证目录
    │       └── StoreRequest.php
    │
    ├── Models/                          # 模型目录
    │   └── Post.php
    │
    ├── Providers/                       # 服务提供者目录
    │   └── ModuleNameServiceProvider.php  # 模块服务提供者（必需）
    │
    ├── Resources/                       # 资源目录
    │   ├── assets/                    # 静态资源（JS、CSS、图片等）
    │   │   ├── js/
    │   │   ├── css/
    │   │   └── images/
    │   ├── lang/                      # 语言文件目录
    │   │   ├── en/
    │   │   │   └── messages.php
    │   │   └── zh_CN/
    │   │       └── messages.php
    │   └── views/                     # 视图文件目录
    │       ├── layouts/
    │       │   └── app.blade.php
    │       ├── index.blade.php
    │       └── post/
    │           └── index.blade.php
    │
    ├── Routes/                          # 路由目录
    │   ├── web.php                     # Web 路由文件
    │   ├── api.php                     # API 路由文件
    │   └── admin.php                   # Admin 路由文件
    │
    ├── Events/                          # 事件目录
    │   └── PostCreated.php
    │
    ├── Listeners/                       # 事件监听器目录
    │   └── SendPostNotification.php
    │
    ├── Observers/                       # 模型观察者目录
    │   └── PostObserver.php
    │
    ├── Policies/                        # 策略类目录
    │   └── PostPolicy.php
    │
    ├── Repositories/                    # 仓库类目录
    │   └── PostRepository.php
    │
    ├── Console/                         # 命令目录
    │   └── Commands/
    │       └── CustomCommand.php
    │
    ├── Tests/                           # 测试目录
    │   ├── Feature/
    │   │   └── PostTest.php
    │   └── Unit/
    │       └── PostTest.php
    │
    └── README.md                        # 模块说明文档（可选）
```

## 核心文件说明

### 1. Config/config.php

模块配置文件，控制模块的启用状态和自定义配置：

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

**重要说明**：
- `enable` 字段控制模块的启用/禁用
- 禁用模块后，所有自动加载组件都不会被加载
- 可以添加任何自定义配置项

### 2. Providers/ModuleNameServiceProvider.php

模块服务提供者，负责注册模块服务：

```php
<?php

namespace Modules\Blog\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class BlogServiceProvider extends ServiceProvider
{
    /**
     * 注册模块服务
     */
    public function register(): void
    {
        // 注册服务
    }

    /**
     * 启动模块服务
     */
    public function boot(): void
    {
        // 加载路由
        $this->loadRoutes();

        // 加载视图
        $this->loadViews();

        // 加载迁移
        $this->loadMigrations();

        // 加载配置
        $this->loadConfig();

        // 加载语言文件
        $this->loadTranslations();

        // 发布资源
        $this->publishAssets();
    }

    /**
     * 加载模块路由
     */
    protected function loadRoutes(): void
    {
        Route::group([
            'middleware' => config('modules.middleware_groups.web', ['web']),
            'namespace' => 'Modules\Blog\Http\Controllers\Web',
            'prefix' => 'blog',
        ], function () {
            $this->loadRoutesFrom(module_path(null, 'Routes/web.php'));
        });
    }

    /**
     * 加载模块视图
     */
    protected function loadViews(): void
    {
        $this->loadViewsFrom(module_path(null, 'Resources/views'), 'blog');
    }

    /**
     * 加载模块迁移
     */
    protected function loadMigrations(): void
    {
        $this->loadMigrationsFrom(module_path(null, 'Database/Migrations'));
    }

    /**
     * 加载模块配置
     */
    protected function loadConfig(): void
    {
        // 模块配置会自动加载
    }

    /**
     * 加载语言文件
     */
    protected function loadTranslations(): void
    {
        $this->loadTranslationsFrom(module_path(null, 'Resources/lang'), 'blog');
    }

    /**
     * 发布资源文件
     */
    protected function publishAssets(): void
    {
        $this->publishes([
            module_path(null, 'Resources/assets') => public_path('modules/blog'),
        ], ['modules-assets', 'blog-assets']);
    }
}
```

### 3. Routes/*.php

路由文件，定义模块的路由：

#### Web 路由 (Routes/web.php)

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Blog\Http\Controllers\Web\PostController;

Route::group([
    'middleware' => ['web'],
], function () {
    Route::get('/posts', [PostController::class, 'index'])->name('posts.index');
    Route::get('/posts/{id}', [PostController::class, 'show'])->name('posts.show');
    Route::get('/posts/create', [PostController::class, 'create'])->name('posts.create');
    Route::post('/posts', [PostController::class, 'store'])->name('posts.store');
});
```

#### API 路由 (Routes/api.php)

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Blog\Http\Controllers\Api\PostController;

Route::group([
    'middleware' => ['api'],
], function () {
    Route::get('/posts', [PostController::class, 'index']);
    Route::get('/posts/{id}', [PostController::class, 'show']);
    Route::post('/posts', [PostController::class, 'store']);
    Route::put('/posts/{id}', [PostController::class, 'update']);
    Route::delete('/posts/{id}', [PostController::class, 'destroy']);
});
```

#### Admin 路由 (Routes/admin.php)

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Blog\Http\Controllers\Admin\PostController;

Route::group([
    'middleware' => ['web', 'admin'],
], function () {
    Route::get('/posts', [PostController::class, 'index'])->name('admin.posts.index');
    Route::get('/posts/{id}', [PostController::class, 'show'])->name('admin.posts.show');
    Route::get('/posts/{id}/edit', [PostController::class, 'edit'])->name('admin.posts.edit');
    Route::put('/posts/{id}', [PostController::class, 'update'])->name('admin.posts.update');
    Route::delete('/posts/{id}', [PostController::class, 'destroy'])->name('admin.posts.destroy');
});
```

## 命名空间规则

### 模块根命名空间

所有模块类都遵循以下命名空间规则：

```
Modules\{ModuleName}
```

### 各组件命名空间

| 组件类型 | 命名空间 | 示例 |
|---------|---------|------|
| 模型 | `Modules\{ModuleName}\Models` | `Modules\Blog\Models\Post` |
| Web 控制器 | `Modules\{ModuleName}\Http\Controllers\Web` | `Modules\Blog\Http\Controllers\Web\PostController` |
| API 控制器 | `Modules\{ModuleName}\Http\Controllers\Api` | `Modules\Blog\Http\Controllers\Api\PostController` |
| Admin 控制器 | `Modules\{ModuleName}\Http\Controllers\Admin` | `Modules\Blog\Http\Controllers\Admin\PostController` |
| 中间件 | `Modules\{ModuleName}\Http\Middleware` | `Modules\Blog\Http\Middleware\AuthMiddleware` |
| 表单请求 | `Modules\{ModuleName}\Http\Requests` | `Modules\Blog\Http\Requests\StorePostRequest` |
| 事件 | `Modules\{ModuleName}\Events` | `Modules\Blog\Events\PostCreated` |
| 监听器 | `Modules\{ModuleName}\Listeners` | `Modules\Blog\Listeners\SendPostNotification` |
| 观察者 | `Modules\{ModuleName}\Observers` | `Modules\Blog\Observers\PostObserver` |
| 策略类 | `Modules\{ModuleName}\Policies` | `Modules\Blog\Policies\PostPolicy` |
| 仓库类 | `Modules\{ModuleName}\Repositories` | `Modules\Blog\Repositories\PostRepository` |
| 命令 | `Modules\{ModuleName}\Console\Commands` | `Modules\Blog\Console\Commands\ClearCache` |
| 服务提供者 | `Modules\{ModuleName}\Providers` | `Modules\Blog\Providers\BlogServiceProvider` |

## 视图命名空间

模块视图会自动注册命名空间，命名格式取决于配置：

| 格式 | 配置值 | 示例 |
|------|--------|------|
| 小写（默认） | `lower` | `blog::view.name` |
| 首字母大写 | `studly` | `Blog::view.name` |
| 驼峰式 | `camel` | `blogModule::view.name` |

在 `config/modules.php` 中配置：

```php
'views' => [
    'namespace_format' => 'lower', // 可选: lower, studly, camel
],
```

## 路由命名规则

### 路由前缀

所有路由会自动添加模块前缀（可配置）：

```
/blog/*      # Web 路由
/api/blog/*  # API 路由
/admin/blog/* # Admin 路由
```

### 路由名称前缀

所有路由名称会自动添加模块前缀（可配置）：

```
blog.index
blog.posts.show
```

## 配置读取规则

模块配置通过以下方式读取：

```php
// 方式 1：使用 helper 函数（推荐）
$value = module_config('Blog', 'config.key', 'default');

// 方式 2：使用智能配置读取
$value = module_config('common.name', 'hello');

// 方式 3：使用 config 函数
$value = config('blog.config.key', 'default');

// 方式 4：使用模块实例
$module = module('Blog');
$value = $module->config('config.key', 'default');
```

## 自动加载

系统会自动加载以下模块组件：

1. **配置文件**：`Config/*.php`
2. **路由文件**：`Routes/*.php`
3. **服务提供者**：`Providers/*ServiceProvider.php`
4. **命令**：`Console/Commands/*.php`
5. **视图**：`Resources/views/*.blade.php`
6. **翻译文件**：`Resources/lang/*.php`
7. **迁移文件**：`Database/Migrations/*.php`
8. **事件**：`Events/*.php`
9. **监听器**：`Listeners/*.php`

## 模块启用/禁用

通过修改 `Config/config.php` 中的 `enable` 选项：

```php
'enable' => true,  // 启用模块
'enable' => false, // 禁用模块
```

禁用后，模块的所有自动加载组件将不会被加载。

## 最佳实践

### 1. 目录组织

- 按功能组织文件，而非按类型
- 使用子目录分组相关文件
- 保持目录结构清晰

### 2. 命名规范

- 使用 StudlyCase 命名类
- 使用 snake_case 命名文件
- 使用描述性的名称

### 3. 依赖管理

- 尽量减少模块间依赖
- 使用接口解耦
- 通过配置控制行为

### 4. 配置管理

- 将配置集中管理
- 提供合理的默认值
- 文档化所有配置选项

## 示例

### 完整的博客模块结构示例

```
Modules/Blog/
├── Config/
│   ├── config.php
│   └── settings.php
├── Database/
│   ├── Migrations/
│   │   ├── 2024_01_01_000000_create_posts_table.php
│   │   └── 2024_01_02_000000_create_comments_table.php
│   └── Seeders/
│       └── BlogSeeder.php
├── Http/
│   ├── Controllers/
│   │   ├── Web/
│   │   │   ├── PostController.php
│   │   │   └── CommentController.php
│   │   ├── Api/
│   │   │   ├── PostController.php
│   │   │   └── CommentController.php
│   │   └── Admin/
│   │       ├── PostController.php
│   │       └── CommentController.php
│   ├── Middleware/
│   │   └── CheckAuthor.php
│   └── Requests/
│       ├── StorePostRequest.php
│       └── UpdatePostRequest.php
├── Models/
│   ├── Post.php
│   └── Comment.php
├── Providers/
│   └── BlogServiceProvider.php
├── Resources/
│   ├── assets/
│   │   ├── css/
│   │   │   └── blog.css
│   │   └── js/
│   │       └── blog.js
│   ├── lang/
│   │   ├── en/
│   │   │   └── messages.php
│   │   └── zh_CN/
│   │       └── messages.php
│   └── views/
│       ├── layouts/
│       │   └── app.blade.php
│       ├── post/
│       │   ├── index.blade.php
│       │   ├── show.blade.php
│       │   ├── create.blade.php
│       │   └── edit.blade.php
│       └── comment/
│           ├── index.blade.php
│           └── form.blade.php
├── Routes/
│   ├── web.php
│   ├── api.php
│   └── admin.php
├── Events/
│   └── PostCreated.php
├── Listeners/
│   └── SendPostNotification.php
├── Observers/
│   └── PostObserver.php
├── Policies/
│   └── PostPolicy.php
├── Repositories/
│   └── PostRepository.php
└── README.md
```

## 相关文档

- [配置详解](04-configuration.md) - 了解配置文件的所有选项
- [Helper 函数](05-helper-functions.md) - 掌握所有助手函数的使用
- [路由指南](07-routes.md) - 学习如何配置和使用模块路由
- [视图使用](08-views.md) - 了解视图的最佳实践
