# 自动发现和自动加载功能详细文档

## 目录

- [概述](#概述)
- [自动发现机制](#自动发现机制)
- [自动发现顺序](#自动发现顺序)
- [支持的组件类型](#支持的组件类型)
- [配置选项](#配置选项)
- [使用方式](#使用方式)
- [自定义扩展](#自定义扩展)
- [调试和日志](#调试和日志)
- [最佳实践](#最佳实践)

---

## 概述

### 什么是自动发现？

自动发现是指模块系统自动扫描、识别和加载模块中的各种组件（如配置、路由、视图、命令等），无需开发者手动注册每个组件。

### 核心优势

1. **简化开发**：无需手动注册，创建文件即自动生效
2. **约定优于配置**：遵循约定即可自动工作
3. **减少错误**：避免因手动注册遗漏导致的错误
4. **提高效率**：快速添加新功能，无需修改配置

### 版本信息

- **当前版本**：2.4.0
- **PHP 版本要求**：8.2+
- **Laravel 版本要求**：11+

---

## 自动发现机制

### ModuleAutoDiscovery 类

自动发现功能由 `ModuleAutoDiscovery` 类实现，该类负责扫描模块目录并自动加载各种组件。

#### 核心方法

| 方法 | 说明 | 自动调用时机 |
|------|------|------------|
| `discoverAll()` | 执行所有自动发现任务 | 模块加载时 |
| `discoverConfigs()` | 发现并加载配置文件 | discoverAll() 调用 |
| `discoverMiddlewares()` | 发现并注册中间件 | discoverAll() 调用 |
| `discoverRoutes()` | 发现并加载路由文件 | discoverAll() 调用 |
| `discoverViews()` | 发现并注册视图 | discoverAll() 调用 |
| `discoverMigrations()` | 发现并注册迁移 | discoverAll() 调用 |
| `discoverTranslations()` | 发现并注册翻译 | discoverAll() 调用 |
| `discoverCommands()` | 发现并注册命令 | discoverAll() 调用 |
| `discoverEvents()` | 发现并注册事件和监听器 | discoverAll() 调用 |
| `discoverObservers()` | 发现并注册模型观察者 | discoverAll() 调用 |
| `discoverPolicies()` | 发现并注册策略类 | discoverAll() 调用 |
| `discoverRepositories()` | 发现仓库类 | discoverAll() 调用 |

#### 静态方法

```php
// 在 ServiceProvider 中使用的便捷方法
ModuleAutoDiscovery::discoverModule([
    'name' => 'Blog',
    'namespace' => 'Modules',
    'path' => __DIR__ . '/..',
]);
```

---

## 自动发现顺序

组件的加载顺序非常重要，因为某些组件可能依赖其他组件。以下是推荐的加载顺序：

1. **服务提供者**（最先加载）
   - 注册自定义服务和绑定
   - 路径：`Providers/`
   - 文件格式：`.php`
   - 类必须继承 `Illuminate\Support\ServiceProvider`

2. **配置文件**
   - 其他组件可能依赖配置
   - 路径：`Config/`
   - 文件格式：`.php`

3. **中间件**（过滤器）
   - 路由可能需要中间件
   - 路径：`Http/Middleware/` 或 `Http/Filters/`
   - 文件格式：`.php`

4. **路由文件**
   - 定义应用的路由
   - 路径：`Routes/`
   - 文件格式：`web.php`, `api.php`, `admin.php` 等

5. **视图文件**
   - 定义应用的视图模板
   - 路径：`Resources/views/`
   - 文件格式：`.blade.php`

6. **迁移文件**
   - 定义数据库结构变更
   - 路径：`Database/Migrations/`
   - 文件格式：`.php`

7. **翻译文件**
   - 定义多语言翻译
   - 路径：`Resources/lang/` 或 `Lang/`
   - 文件格式：`.php` 或 `.json`

8. **Artisan 命令**
   - 定义控制台命令
   - 路径：`Console/Commands/` 或 `Commands/`
   - 文件格式：`.php`

9. **事件和监听器**
   - 定义事件和监听器
   - 路径：`Events/` 和 `Listeners/`
   - 文件格式：`.php`

10. **模型观察者**
    - 定义模型观察者
    - 路径：`Observers/`
    - 文件格式：`.php`

11. **策略类**
    - 定义授权策略
    - 路径：`Policies/`
    - 文件格式：`.php`

12. **仓库类**
    - 定义数据仓库
    - 路径：`Repositories/`
    - 文件格式：`.php`

---

## 支持的组件类型

### 1. 服务提供者（Providers）

**目录结构**
```
Modules/Blog/
└── Providers/
    └── BlogServiceProvider.php
```

**文件示例**
```php
<?php

namespace Modules\Blog\Providers;

use Illuminate\Support\ServiceProvider;

class BlogServiceProvider extends ServiceProvider
{
    /**
     * 注册服务
     */
    public function register(): void
    {
        // 绑定服务到容器
        $this->app->singleton(BlogService::class);
    }

    /**
     * 启动服务
     */
    public function boot(): void
    {
        // 发布资源
        $this->publishes([
            __DIR__ . '/../Resources/views' => resource_path('views/vendor/blog'),
        ], 'views');

        // 注册路由
        $this->loadRoutesFrom(__DIR__ . '/../Routes/web.php');

        // 注册视图
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'blog');

        // 注册迁移
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }
}
```

**自动发现行为**
- 扫描 `Providers/` 目录
- 自动注册所有继承 `Illuminate\Support\ServiceProvider` 的类
- 执行服务提供者的 `register()` 和 `boot()` 方法
- 在所有其他组件加载之前执行，确保服务可用

**注意**
- 模块主服务提供者命名约定：`{ModuleName}ServiceProvider`
- 例如：Blog 模块的主服务提供者为 `BlogServiceProvider`
- 所有注册的服务可以在其他组件中使用依赖注入访问

---

### 2. 配置文件（Config）

**目录结构**
```
Modules/Blog/
└── Config/
    └── blog.php
```

**文件示例**
```php
<?php

return [
    'enabled' => true,
    'title' => 'Blog',
    'per_page' => 15,
];
```

**使用方式**
```php
// 在代码中使用配置值
$value = config('blog.title'); // 返回 'Blog'
$value = config('blog.per_page'); // 返回 15
```

**自动发现行为**
- 扫描 `Config/` 目录
- 自动加载所有 `.php` 文件
- 合并到全局配置
- 键名格式：`模块名.文件名`（全小写）

---

### 3. 中间件（Middleware）

**目录结构**
```
Modules/Blog/
└── Http/
    └── Middleware/
        └── BlogMiddleware.php
```

**文件示例**
```php
<?php

namespace Modules\Blog\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class BlogMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // 中间件逻辑
        return $next($request);
    }
}
```

**使用方式**
```php
// 在路由中使用
Route::middleware('blog')->group(function () {
    // 路由定义
});
```

**自动发现行为**
- 扫描 `Http/Middleware/` 和 `Http/Filters/` 目录
- 自动加载所有 `.php` 文件
- 记录到缓存供调试使用

---

### 4. 路由文件（Routes）

**目录结构**
```
Modules/Blog/
└── Routes/
    ├── web.php
    ├── api.php
    └── admin.php
```

**文件示例**
```php
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('blog::welcome');
});

Route::resource('posts', PostController::class);
```

**中间件自动应用**
路由文件会根据文件名自动应用对应的中间件组和控制器命名空间：

| 文件名 | 中间件组 | 控制器命名空间 |
|--------|---------|--------------|
| `web.php` | `web` | `Http\Controllers\Web`（自动检测） |
| `api.php` | `api` | `Http\Controllers\Api`（自动检测） |
| `admin.php` | `web, admin` | `Http\Controllers\Admin`（自动检测） |

**控制器命名空间自动识别规则**

系统会自动检测控制器子目录是否存在，并应用对应的命名空间：

1. **标准路由文件名**（web、api、admin）：
   - 如果 `Http/Controllers/Web` 目录存在，web.php 路由将自动应用 `Http\Controllers\Web` 命名空间
   - 如果 `Http/Controllers/Api` 目录存在，api.php 路由将自动应用 `Http\Controllers\Api` 命名空间
   - 如果 `Http/Controllers/Admin` 目录存在，admin.php 路由将自动应用 `Http\Controllers\Admin` 命名空间
   - 如果对应子目录不存在，则不应用特定命名空间

2. **自定义路由文件名**：
   - 例如 `custom.php`，如果 `Http/Controllers/Custom` 目录存在，将应用 `Http\Controllers\Custom` 命名空间
   - 命名空间为文件名的首字母大写形式

**自定义中间件组**
可在 `config/modules.php` 中配置：

```php
'middleware_groups' => [
    'web' => ['web'],
    'api' => ['api'],
    'admin' => ['web', 'admin'],
    'custom' => ['custom-middleware'],
],
```

---

### 5. 视图文件（Views）

**目录结构**
```
Modules/Blog/
└── Resources/
    └── views/
        ├── welcome.blade.php
        ├── posts/
        │   ├── index.blade.php
        │   └── show.blade.php
        └── layouts/
            └── app.blade.php
```

**使用方式**
```php
// 在控制器中返回视图
return view('blog::welcome');

// 使用子目录中的视图
return view('blog::posts.index');

// 继承布局模板
@extends('blog::layouts.app')
```

**视图命名空间格式**
可在 `config/modules.php` 中配置：

```php
'views' => [
    'namespace_format' => 'lower', // 可选值: 'lower', 'studly', 'camel'
],
```

- `lower`: `blog`（默认）
- `studly`: `Blog`
- `camel`: `blogModule`

---

### 6. 迁移文件（Migrations）

**目录结构**
```
Modules/Blog/
└── Database/
    └── Migrations/
        └── 2024_01_19_000000_create_posts_table.php
```

**文件示例**
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
```

**使用方式**
```bash
# 运行所有迁移
php artisan migrate

# 回滚最后一次迁移
php artisan migrate:rollback

# 刷新所有迁移
php artisan migrate:refresh

# 重置所有迁移
php artisan migrate:reset
```

**自动发现行为**
- 扫描 `Database/Migrations/` 目录
- 自动注册迁移路径
- Laravel 会自动识别并执行迁移

---

### 7. 翻译文件（Translations）

**目录结构**
```
Modules/Blog/
└── Resources/
    └── lang/
        ├── zh-CN.php
        ├── en.php
        └── zh-CN/
            └── messages.php
```

**文件示例**
```php
<?php

// zh-CN.php
return [
    'welcome' => '欢迎使用博客模块',
    'post_created' => '文章创建成功',
];
```

**使用方式**
```php
// 在代码中使用翻译
__('blog::welcome'); // 返回 '欢迎使用博客模块'
__('blog::post_created'); // 返回 '文章创建成功'
```

**自动发现行为**
- 扫描 `Resources/lang/` 或 `Lang/` 目录
- 自动注册翻译命名空间
- 支持 PHP 和 JSON 格式

---

### 8. Artisan 命令（Commands）

**目录结构**
```
Modules/Blog/
└── Console/
    └── Commands/
        └── BlogCommand.php
```

**文件示例**
```php
<?php

namespace Modules\Blog\Console\Commands;

use Illuminate\Console\Command;

class BlogCommand extends Command
{
    protected $signature = 'blog:command';
    protected $description = '博客模块命令';

    public function handle()
    {
        $this->info('执行博客模块命令');
    }
}
```

**使用方式**
```bash
# 列出所有命令（会自动显示模块命令）
php artisan list

# 执行模块命令
php artisan blog:command
```

**自动发现行为**
- 扫描 `Console/Commands/` 目录
- 自动注册所有继承 `Illuminate\Console\Command` 的类
- 验证命令的 `$signature` 和 `$description` 属性
- **仅在命令模式下（runningInConsole()）注册**
- 使用 `Artisan::resolve()` 方法注册到 Laravel

**支持路径**
- `Console/Commands/`（推荐，标准路径）
- `Commands/`（兼容路径）

**命令模式检测**
系统会自动检测当前是否在命令模式下运行：
- 是：自动发现并注册所有模块命令
- 否：跳过命令注册，提高性能

---

### 9. 事件和监听器（Events & Listeners）

**目录结构**
```
Modules/Blog/
├── Events/
│   └── PostCreated.php
└── Listeners/
    └── SendPostNotification.php
```

**事件文件示例**
```php
<?php

namespace Modules\Blog\Events;

class PostCreated
{
    public $post;

    public function __construct($post)
    {
        $this->post = $post;
    }
}
```

**监听器文件示例**
```php
<?php

namespace Modules\Blog\Listeners;

use Modules\Blog\Events\PostCreated;

class SendPostNotification
{
    public function handle(PostCreated $event)
    {
        // 处理事件
    }
}
```

**自动发现行为**
- 扫描 `Events/` 和 `Listeners/` 目录
- Laravel 11+ 会自动加载这些类
- 需要在 `EventServiceProvider` 中手动注册映射关系

---

### 10. 模型观察者（Observers）

**目录结构**
```
Modules/Blog/
└── Observers/
    └── PostObserver.php
```

**文件示例**
```php
<?php

namespace Modules\Blog\Observers;

use Modules\Blog\Models\Post;

class PostObserver
{
    public function created(Post $post)
    {
        // 创建后执行
    }

    public function updated(Post $post)
    {
        // 更新后执行
    }
}
```

**自动发现行为**
- 扫描 `Observers/` 目录
- 自动注册观察者到对应的模型
- 命名约定：`PostObserver` 对应 `Post` 模型

---

### 11. 策略类（Policies）

**目录结构**
```
Modules/Blog/
└── Policies/
    └── PostPolicy.php
```

**文件示例**
```php
<?php

namespace Modules\Blog\Policies;

use Modules\Blog\Models\Post;
use App\Models\User;

class PostPolicy
{
    public function view(User $user, Post $post)
    {
        return true;
    }

    public function update(User $user, Post $post)
    {
        return $user->id === $post->user_id;
    }
}
```

**自动发现行为**
- 扫描 `Policies/` 目录
- 自动注册策略到 Gate
- 命名约定：`PostPolicy` 对应 `Post` 模型

---

### 12. 仓库类（Repositories）

**目录结构**
```
Modules/Blog/
└── Repositories/
    ├── PostRepository.php
    └── PostRepositoryInterface.php
```

**文件示例**
```php
<?php

namespace Modules\Blog\Repositories;

use Modules\Blog\Models\Post;

class PostRepository
{
    public function all()
    {
        return Post::all();
    }

    public function find($id)
    {
        return Post::findOrFail($id);
    }
}
```

**自动发现行为**
- 扫描 `Repositories/` 目录
- 发现并记录仓库类
- **注意**：仓库类不会自动注册到服务容器，需要在 ServiceProvider 的 `register()` 方法中手动注册

---

## 配置选项

### 自动发现配置

在 `config/modules.php` 中配置自动发现选项：

```php
'discovery' => [
    'providers' => true,     // 服务提供者
    'config' => true,        // 配置文件
    'middlewares' => true,   // 中间件（过滤器）
    'routes' => true,        // 路由文件
    'views' => true,         // 视图文件
    'migrations' => true,    // 迁移文件
    'translations' => true,  // 翻译文件
    'commands' => true,      // Artisan 命令
    'events' => true,        // 事件和监听器
    'observers' => true,     // 模型观察者
    'policies' => true,      // 策略类
    'repositories' => true,   // 仓库类
],
```

**禁用特定组件的自动发现**

如果不需要自动发现某个组件，将其设置为 `false`：

```php
'discovery' => [
    'config' => true,
    'middlewares' => false,  // 禁用中间件自动发现
    'routes' => true,
    // ...
],
```

---

## 使用方式

### 在 ServiceProvider 中使用

**推荐方式（使用静态方法）**

```php
<?php

namespace Modules\Blog\Providers;

use Illuminate\Support\ServiceProvider;
use zxf\Modules\Support\ModuleAutoDiscovery;

class BlogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // 自动发现所有模块组件
        ModuleAutoDiscovery::discoverModule([
            'name' => 'Blog',
            'namespace' => 'Modules',
            'path' => __DIR__ . '/..',
        ]);

        // 自定义功能
        $this->publishResources();
    }
}
```

**传统方式（使用实例方法）**

```php
<?php

namespace Modules\Blog\Providers;

use Illuminate\Support\ServiceProvider;
use zxf\Modules\Support\ModuleAutoDiscovery;
use Modules\Blog\Entities\Module; // 假设有 Module 实体类

class BlogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // 创建模块对象
        $module = new Module('Blog', 'Modules', __DIR__ . '/..');
        
        // 创建自动发现器
        $discovery = new ModuleAutoDiscovery($module);
        
        // 执行自动发现
        $discovery->discoverAll();
    }
}
```

---

## 自定义扩展

### 自定义模块对象

如果需要自定义模块对象，可以实现 `ModuleInterface` 接口：

```php
<?php

namespace Modules\Blog\Entities;

use zxf\Modules\Contracts\ModuleInterface;

class Module implements ModuleInterface
{
    public function __construct(
        protected string $name,
        protected string $namespace,
        protected string $path
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getPath(?string $path = null): string
    {
        return $this->path . ($path ? '/' . $path : '');
    }

    // 实现所有接口方法...
}
```

### 自定义发现规则

可以通过继承 `ModuleAutoDiscovery` 类来自定义发现规则：

```php
<?php

namespace Modules\Blog\Support;

use zxf\Modules\Support\ModuleAutoDiscovery;

class CustomModuleAutoDiscovery extends ModuleAutoDiscovery
{
    protected function discoverCustomComponent(): void
    {
        // 自定义发现逻辑
    }

    public function discoverAll(): void
    {
        parent::discoverAll();
        $this->discoverCustomComponent();
    }
}
```

---

## 调试和日志

### 获取发现摘要

```php
$discovery = new ModuleAutoDiscovery($module);
$discovery->discoverAll();

// 获取发现摘要
$summary = $discovery->getDiscoverySummary();

print_r($summary);
/*
Array
(
    [module] => Blog
    [enabled] => 1
    [configs] => Array(...)
    [routes] => Array(...)
    [middlewares] => Array(...)
    [views] => registered
    [migrations] => registered
    [translations] => registered
    [commands_count] => 2
    [events_count] => 5
    [observers_count] => 3
    [policies_count] => 2
    [repositories_count] => 4
    [logs_count] => 25
)
*/
```

### 获取发现日志

```php
$discovery = new ModuleAutoDiscovery($module);
$discovery->discoverAll();

// 获取日志
$logs = $discovery->getLogs();

foreach ($logs as $time => $message) {
    echo "[{$time}] {$message}\n";
}
/*
[2024-01-19 10:30:00] 发现配置文件: blog.php
[2024-01-19 10:30:01] 发现路由文件: web.php
[2024-01-19 10:30:02] 注册观察者: PostObserver -> Post
*/
```

### 在调试模式下自动记录日志

在 `config/app.php` 中启用调试模式：

```php
'debug' => env('APP_DEBUG', false),
```

启用后，`ModuleLoader` 会自动将发现摘要记录到日志文件：

```php
// 位置: storage/logs/laravel.log
[2024-01-19 10:30:00] local.INFO: Module discovered {"module":"Blog",...}
```

---

## 最佳实践

### 1. 遵循命名约定

- 配置文件使用模块名（小写）：`blog.php`
- 控制器使用模块名 + Controller：`BlogController`
- 模型使用模块名单数形式：`Post`
- 观察者使用模型名 + Observer：`PostObserver`
- 策略使用模型名 + Policy：`PostPolicy`

### 2. 合理组织目录结构

```
Modules/Blog/
├── Config/              # 配置文件
├── Http/
│   ├── Controllers/     # 控制器
│   │   ├── Web/       # Web 控制器
│   │   ├── Api/       # API 控制器
│   │   └── Admin/     # Admin 控制器
│   ├── Middleware/     # 中间件
│   ├── Requests/       # 表单请求验证
│   └── Resources/     # API 资源转换
├── Models/             # 模型
├── Observers/         # 模型观察者
├── Policies/          # 策略类
├── Repositories/      # 仓库类
├── Database/
│   ├── Migrations/    # 迁移文件
│   └── Seeders/      # 数据填充器
├── Console/
│   └── Commands/     # 控制台命令
├── Events/           # 事件
├── Listeners/        # 监听器
├── Resources/
│   ├── views/        # 视图文件
│   └── lang/         # 翻译文件
├── Routes/           # 路由文件
└── Providers/        # 服务提供者
```

### 3. 合理使用配置

对于不需要自动发现的组件，在配置中禁用：

```php
'discovery' => [
    'config' => true,
    'routes' => true,
    'views' => true,
    'observers' => false,  // 不需要自动发现观察者
    'policies' => false,   // 不需要自动发现策略
],
```

### 4. 使用缓存提高性能

在生产环境中启用缓存：

```php
'cache' => [
    'enabled' => true,
    'key' => 'modules',
    'ttl' => 3600, // 1 小时
],
```

### 5. 编写测试

为模块的自动发现功能编写测试，确保组件能正确加载：

```php
<?php

namespace Modules\Blog\Tests;

use Tests\TestCase;
use zxf\Modules\Support\ModuleAutoDiscovery;
use Modules\Blog\Entities\Module;

class AutoDiscoveryTest extends TestCase
{
    public function test_discovery_finds_all_components()
    {
        $module = new Module('Blog', 'Modules', base_path('Modules/Blog'));
        $discovery = new ModuleAutoDiscovery($module);
        
        $discovery->discoverAll();
        $summary = $discovery->getDiscoverySummary();
        
        $this->assertGreaterThan(0, $summary['commands_count']);
        $this->assertEquals('registered', $summary['views']);
    }
}
```

---

## 常见问题

### Q: 如何禁用某个模块的自动发现？

A: 在模块配置文件中设置 `enabled => false`：

```php
// Modules/Blog/Config/blog.php
return [
    'enabled' => false,
];
```

### Q: 如何查看已发现的组件？

A: 使用 `getDiscoverySummary()` 方法：

```php
$discovery = new ModuleAutoDiscovery($module);
$discovery->discoverAll();
$summary = $discovery->getDiscoverySummary();
dd($summary);
```

### Q: 为什么我的组件没有被自动发现？

A: 检查以下几点：

1. 文件是否在正确的目录中
2. 文件名是否正确
3. 命名空间是否正确
4. 配置中的发现选项是否启用
5. 类是否正确继承或实现接口

### Q: 如何添加自定义的自动发现类型？

A: 继承 `ModuleAutoDiscovery` 类并添加自定义发现方法：

```php
class CustomModuleAutoDiscovery extends ModuleAutoDiscovery
{
    protected function discoverCustom(): void
    {
        // 自定义逻辑
    }

    public function discoverAll(): void
    {
        parent::discoverAll();
        $this->discoverCustom();
    }
}
```

---

## 总结

自动发现和自动加载功能大大简化了模块开发流程，开发者只需遵循约定，创建文件即可自动生效。通过合理的配置和最佳实践，可以构建高效、可维护的模块化应用。

如有任何问题或建议，欢迎反馈！
