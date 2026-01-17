# 快速开始

本指南将带你快速了解如何使用 Laravel 模块系统。

## 配置说明

模块系统的配置位于 `config/modules.php`，主要配置项包括：

- `namespace`: 模块根命名空间（默认：`Modules`）
- `path`: 模块存储基础路径（默认：`base_path('Modules')`）
- `paths`: 模块内部各组件的相对路径配置
- `routes`: 路由前缀和名称前缀配置
- `middleware_groups`: 路由中间件组配置
- `discovery`: 自动发现配置

当修改了 `namespace` 或 `path` 配置后，所有命令都会使用新的配置生成文件和命名空间。

## 模块启用/禁用 ⭐ 重要

每个模块都可以通过配置文件控制是否启用。模块的启用状态在加载前检查，如果模块未启用，则不会加载其路由、服务提供者、视图等任何组件。

### 配置模块启用状态

编辑模块配置文件 `Modules/Blog/Config/blog.php`：

```php
return [
    /*
    |--------------------------------------------------------------------------
    | 模块启用状态
    |--------------------------------------------------------------------------
    |
    | 是否启用该模块
    | - true: 启用模块，加载所有组件（路由、服务提供者、视图等）
    | - false: 禁用模块，不加载任何组件
    | - 未配置: 默认启用
    |
    */
    'enabled' => true,  // 设置为 false 可禁用此模块

    'name' => 'Blog',
    'version' => '1.0.0',
    'description' => 'Blog 模块',
    'author' => '',
    'options' => [],
];
```

### 启用/禁用的影响

**禁用模块 (`enabled => false`)：**
- 模块路由无法访问
- 模块服务提供者不加载
- 模块视图无法使用
- 模块命令不注册
- 模块配置不加载到 Laravel config
- 模块迁移文件仍然存在但不自动加载

**启用模块 (`enabled => true` 或未配置)：**
- 加载所有模块组件
- 模块路由正常访问
- 模块视图可以调用
- 模块命令可以执行

### 检查模块状态

在代码中检查模块是否启用：

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

// 检查当前模块（在模块内部调用）
if (module_enabled()) {
    // 当前模块已启用
}
```
## 创建第一个模块

### 1. 创建模块

使用以下命令创建一个名为 `Blog` 的模块：

```bash
php artisan module:make Blog
```

这会在 `Modules` 目录下创建以下结构：

```
Modules/
└── Blog/
    ├── Config/
    │   └── blog.php
    ├── Database/
    │   ├── Migrations/
    │   └── Seeders/
    ├── Http/
    │   ├── Controllers/
    │   │   ├── Controller.php
    │   │   ├── Web/
    │   │   │   └── BlogController.php
    │   │   ├── Api/
    │   │   │   └── BlogController.php
    │   │   └── Admin/
    │   │       └── BlogController.php
    │   └── Requests/
    ├── Models/
    ├── Providers/
    │   └── BlogServiceProvider.php
    ├── Resources/
    │   ├── assets/
    │   ├── lang/
    │   └── views/
    │       └── welcome.blade.php
    └── Routes/
        ├── web.php
        ├── api.php
        └── admin.php
```

**注意事项：**
- 配置文件命名为 `blog.php`（小写模块名），可通过 `config('blog.enable')` 访问
- 服务提供者位于 `Providers/BlogServiceProvider.php`
- 控制器使用命名空间 `Modules\Blog\Http\Controllers\Web`（Web控制器）、`Modules\Blog\Http\Controllers\Api`（API控制器）等

### 2. 查看模块信息

```bash
# 查看所有模块
php artisan module:list

# 查看特定模块的详细信息
php artisan module:info Blog
```

### 3. 验证模块

```bash
php artisan module:validate Blog
```

## 创建模型和迁移

### 1. 创建模型

```bash
# 创建 Post 模型
php artisan module:make-model Blog Post

# 创建模型并生成迁移文件
php artisan module:make-model Blog Post --migration
```

### 2. 创建迁移文件

```bash
# 创建迁移文件
php artisan module:make-migration Blog create_posts_table
```

迁移文件示例：

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
            $table->boolean('published')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
```

### 3. 运行迁移

```bash
# 运行所有模块的迁移
php artisan module:migrate

# 运行特定模块的迁移
php artisan module:migrate Blog

# 查看迁移状态
php artisan module:migrate-status
```

## 创建控制器

### 1. 创建 Web 控制器

```bash
php artisan module:make-controller Blog PostController --type=web
```

控制器示例：

```php
<?php

namespace Modules\Blog\Http\Controllers\Web;

use Modules\Blog\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PostController extends Controller
{
    public function index()
    {
        $posts = Post::paginate(10);

        // 使用 helper 函数返回视图
        return module_view('post.index', compact('posts'));
    }

    public function show($id)
    {
        $post = Post::findOrFail($id);

        return module_view('post.show', compact('post'));
    }

    public function create()
    {
        return module_view('post.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|max:255',
            'content' => 'required',
        ]);

        Post::create($validated);

        return redirect()->route('blog.posts.index');
    }
}
```

### 2. 创建 API 控制器

```bash
php artisan module:make-controller Blog PostController --type=api
```

### 3. 创建 Admin 控制器

```bash
php artisan module:make-controller Blog PostController --type=admin
```

## 配置路由

### 1. Web 路由

编辑 `Modules/Blog/Routes/web.php`：

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Blog\Http\Controllers\Web\PostController;

Route::prefix('blog')->group(function () {
    Route::get('/posts', [PostController::class, 'index'])->name('posts.index');
    Route::get('/posts/{id}', [PostController::class, 'show'])->name('posts.show');
    Route::get('/posts/create', [PostController::class, 'create'])->name('posts.create');
    Route::post('/posts', [PostController::class, 'store'])->name('posts.store');
});
```

### 2. API 路由

编辑 `Modules/Blog/Routes/api.php`：

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Blog\Http\Controllers\Api\PostController;

Route::prefix('blog')->group(function () {
    Route::get('/posts', [PostController::class, 'index']);
    Route::get('/posts/{id}', [PostController::class, 'show']);
    Route::post('/posts', [PostController::class, 'store']);
    Route::put('/posts/{id}', [PostController::class, 'update']);
    Route::delete('/posts/{id}', [PostController::class, 'destroy']);
});
```

## 使用配置

### 1. 创建配置文件

```bash
php artisan module:make-config Blog settings
```

### 2. 编辑配置文件

编辑 `Modules/Blog/Config/settings.php`：

```php
<?php

return [
    'per_page' => 20,
    'enable_comments' => true,
    'enable_likes' => true,
    'default_status' => 'draft',
];
```

### 3. 读取配置

```php
// 在控制器中
class PostController extends Controller
{
    public function index()
    {
        // 使用智能配置读取（推荐）
        $perPage = module_config('settings.per_page', 10);

        $posts = Post::paginate($perPage);

        return module_view('post.index', compact('posts'));
    }
}
```

## 创建视图

### 1. 创建主布局

创建 `Modules/Blog/Resources/views/layouts/app.blade.php`：

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Blog')</title>
</head>
<body>
    <header>
        <h1>{{ module_config('common.title', 'My Blog') }}</h1>
    </header>

    <main>
        @yield('content')
    </main>

    <footer>
        <p>&copy; {{ date('Y') }} My Blog</p>
    </footer>
</body>
</html>
```

### 2. 创建文章列表视图

创建 `Modules/Blog/Resources/views/post/index.blade.php`：

```blade
@extends('layouts.app')

@section('title', 'Posts')

@section('content')
    <h1>Posts</h1>

    <ul>
        @foreach($posts as $post)
            <li>
                <a href="{{ route('blog.posts.show', $post->id) }}">
                    {{ $post->title }}
                </a>
            </li>
        @endforeach
    </ul>

    {{ $posts->links() }}
@endsection
```

### 3. 创建文章详情视图

创建 `Modules/Blog/Resources/views/post/show.blade.php`：

```blade
@extends('layouts.app')

@section('title', $post->title)

@section('content')
    <article>
        <h1>{{ $post->title }}</h1>

        <div class="content">
            {{ $post->content }}
        </div>

        <p>
            Published: {{ $post->created_at->format('F j, Y') }}
        </p>
    </article>
@endsection
```

## 使用 Helper 函数

### 核心函数

```php
// 获取当前模块名称
$moduleName = module_name(); // 'Blog'

// 读取配置
$perPage = module_config('settings.per_page', 10);

// 获取模块路径
$modelPath = module_path('Models/Post.php');

// 返回模块视图
return module_view('post.index', compact('posts'));

// 生成路由 URL
$url = module_route('posts.show', ['id' => $post->id]);

// 检查模块是否启用
if (module_enabled()) {
    // 模块已启用
}
```

## 高级功能

### 1. 模块事件

```php
// 创建事件
php artisan module:make-event Blog PostCreated

// 创建监听器
php artisan module:make-listener Blog SendPostNotification --event=PostCreated
```

### 2. 模块命令

```bash
php artisan module:make-command Blog ClearCache --command="blog:clear"
```

命令示例：

```php
<?php

namespace Modules\Blog\Console\Commands;

use Illuminate\Console\Command;

class ClearCache extends Command
{
    protected $signature = 'blog:clear';
    protected $description = 'Clear blog cache';

    public function handle()
    {
        $this->info('Clearing cache for ' . module_name() . ' module...');

        // 清理缓存逻辑

        $this->info('Cache cleared!');
    }
}
```

### 3. 模块中间件

```bash
php artisan module:make-middleware Blog CheckAuth
```

中间件示例：

```php
<?php

namespace Modules\Blog\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        return $next($request);
    }
}
```

## 下一步

现在你已经了解了基本的模块创建和使用方法。继续阅读以下文档深入了解：

- [模块结构](03-module-structure.md) - 了解完整的模块目录结构
- [配置详解](04-configuration.md) - 学习配置文件的所有选项
- [Helper 函数](05-helper-functions.md) - 掌握所有助手函数的使用
- [路由指南](07-routes.md) - 学习如何配置和使用模块路由
- [视图使用](08-views.md) - 了解视图的最佳实践

## 示例项目

完整的示例项目可以在 [GitHub](https://github.com/zxf/modules) 上找到。
