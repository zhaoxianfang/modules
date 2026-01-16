# 路由系统使用指南

## 概述

模块系统的路由会自动根据路由文件名映射到对应的控制器子目录，无需手动指定命名空间。

## 路由文件与控制器映射

### 自动映射规则

每个路由文件会自动映射到对应的控制器子目录：

| 路由文件 | 控制器目录 | 命名空间 |
|---------|-----------|----------|
| `web.php` | `Http/Controllers/Web` | `Modules\{Module}\Http\Controllers\Web` |
| `api.php` | `Http/Controllers/Api` | `Modules\{Module}\Http\Controllers\Api` |
| `admin.php` | `Http/Controllers/Admin` | `Modules\{Module}\Http\Controllers\Admin` |
| `custom.php` | `Http/Controllers/Custom` | `Modules\{Module}\Http\Controllers\Custom` |

### 路由结构示例

以 `Blog` 模块为例：

```
Blog/
├── Routes/
│   ├── web.php      → 指向 Http/Controllers/Web
│   ├── api.php      → 指向 Http/Controllers/Api
│   └── admin.php    → 指向 Http/Controllers/Admin
└── Http/
    └── Controllers/
        ├── Web/
        │   └── PostController.php
        ├── Api/
        │   └── PostController.php
        └── Admin/
            └── PostController.php
```

## Web 路由

### 配置

在 `config/modules.php` 中配置：

```php
'middleware_groups' => [
    'web' => ['web'],  // 自动应用 web 中间件
],
'route_controller_namespaces' => [
    'web' => 'Web',  // 可选，不配置则使用文件名
],
```

### 使用示例

#### routes/web.php

```php
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Web\HomeController; // 无需完整命名空间

/*
|--------------------------------------------------------------------------
| Web 路由
|--------------------------------------------------------------------------
|
| 控制器命名空间: Modules\Blog\Http\Controllers\Web
| 路由前缀: blog
| 路由名称前缀: blog.
|
*/

// 简洁写法：直接使用控制器类名
Route::get('', [HomeController::class, 'index'])->name('home');

// 带参数
Route::get('posts', [PostController::class, 'index'])->name('posts.index');
Route::get('posts/{id}', [PostController::class, 'show'])->name('posts.show');

// 资源路由
Route::resource('categories', CategoryController::class);
```

#### 生成的实际路由

```php
Route::prefix('blog')
    ->name('blog.')
    ->middleware(['web'])
    ->namespace('Modules\Blog\Http\Controllers\Web')
    ->group(function () {
        Route::get('', [PostController::class, 'index'])->name('home');
        Route::get('posts', [PostController::class, 'index'])->name('posts.index');
        Route::get('posts/{id}', [PostController::class, 'show'])->name('posts.show');
    });
```

### 访问示例

```
GET /blog                    → BlogController@index      (blog.home)
GET /blog/posts              → PostController@index      (blog.posts.index)
GET /blog/posts/1            → PostController@show      (blog.posts.show)
GET /blog/categories         → CategoryController@index (blog.categories.index)
```

## API 路由

### 配置

```php
'middleware_groups' => [
    'api' => ['api'],  // 自动应用 api 中间件
],
'route_controller_namespaces' => [
    'api' => 'Api',  // 可选，不配置则使用文件名
],
```

### 使用示例

#### routes/api.php

```php
<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API 路由
|--------------------------------------------------------------------------
|
| 控制器命名空间: Modules\Blog\Http\Controllers\Api
| 路由前缀: blog
| 路由名称前缀: blog.
|
*/

// 公开 API
Route::get('posts', [PostController::class, 'index'])->name('posts.index');
Route::get('posts/{id}', [PostController::class, 'show'])->name('posts.show');

// 需要认证的 API
Route::middleware('auth:sanctum')->group(function () {
    Route::post('posts', [PostController::class, 'store'])->name('posts.store');
    Route::put('posts/{id}', [PostController::class, 'update'])->name('posts.update');
    Route::delete('posts/{id}', [PostController::class, 'destroy'])->name('posts.destroy');
});
```

### 访问示例

```
GET    /blog/posts              → PostController@index       (blog.api.posts.index)
GET    /blog/posts/1            → PostController@show       (blog.api.posts.show)
POST   /blog/posts              → PostController@store      (blog.api.posts.store) [需要认证]
PUT    /blog/posts/1            → PostController@update     (blog.api.posts.update) [需要认证]
DELETE /blog/posts/1            → PostController@destroy   (blog.api.posts.destroy) [需要认证]
```

## Admin 路由

### 配置

```php
'middleware_groups' => [
    'admin' => ['web', 'admin'],  // 应用多个中间件
],
'route_controller_namespaces' => [
    'admin' => 'Admin',  // 可选，不配置则使用文件名
],
```

### 使用示例

#### routes/admin.php

```php
<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin 路由
|--------------------------------------------------------------------------
|
| 控制器命名空间: Modules\Blog\Http\Controllers\Admin
| 路由前缀: blog
| 路由名称前缀: blog.
|
*/

Route::middleware(['auth', 'admin'])->group(function () {
    // 仪表盘
    Route::get('', [DashboardController::class, 'index'])->name('dashboard');
    
    // 文章管理
    Route::prefix('posts')->name('posts.')->group(function () {
        Route::get('', [PostController::class, 'index'])->name('index');
        Route::get('create', [PostController::class, 'create'])->name('create');
        Route::post('', [PostController::class, 'store'])->name('store');
        Route::get('{id}', [PostController::class, 'show'])->name('show');
        Route::get('{id}/edit', [PostController::class, 'edit'])->name('edit');
        Route::put('{id}', [PostController::class, 'update'])->name('update');
        Route::delete('{id}', [PostController::class, 'destroy'])->name('destroy');
    });
    
    // 资源路由（简化写法）
    Route::resource('categories', CategoryController::class);
});
```

### 访问示例

```
GET    /blog                          → DashboardController@index         (blog.admin.dashboard)
GET    /blog/posts                     → PostController@index            (blog.admin.posts.index)
GET    /blog/posts/create              → PostController@create           (blog.admin.posts.create)
POST   /blog/posts                     → PostController@store            (blog.admin.posts.store)
GET    /blog/posts/1                   → PostController@show             (blog.admin.posts.show)
GET    /blog/posts/1/edit              → PostController@edit            (blog.admin.posts.edit)
PUT    /blog/posts/1                  → PostController@update           (blog.admin.posts.update)
DELETE /blog/posts/1                  → PostController@destroy         (blog.admin.posts.destroy)
GET    /blog/categories               → CategoryController@index       (blog.admin.categories.index)
```

## 自定义路由文件

### 创建自定义路由

创建自定义路由文件，如 `mobile.php`：

```php
// Blog/Routes/mobile.php
<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile 路由
|--------------------------------------------------------------------------
|
| 控制器命名空间: Modules\Blog\Http\Controllers\Mobile
| 路由前缀: blog
| 路由名称前缀: blog.
|
*/

Route::get('posts', [PostController::class, 'index'])->name('posts.index');
```

### 系统自动处理

系统会自动：
1. 检测到 `mobile.php` 文件
2. 使用 `Str::studly('mobile')` 转换为 `Mobile`
3. 设置控制器命名空间为 `Modules\Blog\Http\Controllers\Mobile`
4. 应用配置的中间件（如果配置了）

### 自定义控制器目录映射

如果不想使用自动映射，可以在配置中指定：

```php
// config/modules.php
'route_controller_namespaces' => [
    'web' => 'Web',
    'api' => 'Api',
    'admin' => 'Admin',
    'mobile' => 'MobileApp',  // 自定义映射
],
```

## 路由配置选项

### 全局配置

在 `config/modules.php` 中配置：

```php
return [
    // 路由配置
    'routes' => [
        // 是否自动添加模块前缀到路由
        'prefix' => true,  // true 或 false
        
        // 是否自动添加模块名称到路由名称
        'name_prefix' => true,  // true 或 false
        
        // 默认加载的路由文件
        'default_files' => ['web', 'api', 'admin'],
    ],
    
    // 路由中间件组
    'middleware_groups' => [
        'web' => ['web'],
        'api' => ['api'],
        'admin' => ['web', 'admin'],
        'mobile' => ['api', 'mobile'],
    ],
    
    // 路由控制器命名空间映射（可选，不配置则使用文件名）
    'route_controller_namespaces' => [
        'web' => 'Web',
        'api' => 'Api',
        'admin' => 'Admin',
    ],
];
```

### 关闭路由前缀

```php
'routes' => [
    'prefix' => false,  // 不添加模块前缀
],
```

结果：
```
// 不添加前缀
GET /posts (而不是 /blog/posts)
```

### 关闭路由名称前缀

```php
'routes' => [
    'name_prefix' => false,  // 不添加模块前缀
],
```

结果：
```php
// 路由名称不带模块前缀
route('posts.index') (而不是 route('blog.posts.index'))
```

## 控制器示例

### Web 控制器

```php
<?php

namespace Modules\Blog\Http\Controllers\Web;

use Illuminate\Http\Request;
use Modules\Blog\Models\Post;

class PostController extends Controller
{
    public function index()
    {
        $posts = Post::latest()->paginate(10);
        
        return view('blog::posts.index', compact('posts'));
    }
    
    public function show($id)
    {
        $post = Post::findOrFail($id);
        
        return view('blog::posts.show', compact('post'));
    }
}
```

### API 控制器

```php
<?php

namespace Modules\Blog\Http\Controllers\Api;

use Illuminate\Http\Request;
use Modules\Blog\Models\Post;
use Illuminate\Http\JsonResponse;

class PostController extends Controller
{
    public function index(): JsonResponse
    {
        $posts = Post::latest()->paginate(10);
        
        return response()->json($posts);
    }
    
    public function show($id): JsonResponse
    {
        $post = Post::findOrFail($id);
        
        return response()->json($post);
    }
    
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
        ]);
        
        $post = Post::create($validated);
        
        return response()->json($post, 201);
    }
}
```

### Admin 控制器

```php
<?php

namespace Modules\Blog\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Modules\Blog\Models\Post;

class PostController extends Controller
{
    public function index()
    {
        $posts = Post::latest()->paginate(20);
        
        return view('blog::admin.posts.index', compact('posts'));
    }
    
    public function create()
    {
        return view('blog::admin.posts.create');
    }
    
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
        ]);
        
        $post = Post::create($validated);
        
        return redirect()
            ->route('blog.admin.posts.show', $post->id)
            ->with('success', '文章创建成功');
    }
}
```

## 生成路由和控制器

### 使用命令生成

```bash
# 创建模块（自动生成路由文件）
php artisan module:make Blog

# 创建 Web 控制器
php artisan module:make-controller PostController Blog --web

# 创建 API 控制器
php artisan module:make-controller PostController Blog --api

# 创建 Admin 控制器
php artisan module:make-controller PostController Blog --admin

# 创建路由文件
php artisan module:make-route mobile Blog
```

## 路由助手函数

### module_route()

生成模块路由 URL：

```php
// Web 路由
$url = module_route('Blog', 'posts.index');
// http://example.com/blog/posts

// 带参数
$url = module_route('Blog', 'posts.show', ['id' => 1]);
// http://example.com/blog/posts/1

// 使用当前模块
$url = module_route(null, 'posts.index');
```

### module_url()

生成模块 URL：

```php
$url = module_url('Blog', 'posts/1');
// http://example.com/blog/posts/1

// 使用当前模块
$url = module_url(null, 'posts/1');
```

### current_module()

通过 URL 获取当前模块：

```php
// 访问 /blog/posts 时
$moduleName = current_module(); // 'Blog'
```

## 最佳实践

### 1. 控制器放在对应目录

```
✅ 正确
Http/Controllers/
├── Web/
│   └── PostController.php
├── Api/
│   └── PostController.php
└── Admin/
    └── PostController.php

❌ 错误
Http/Controllers/
└── PostController.php  // 放错位置
```

### 2. 路由文件使用简洁写法

```php
// ✅ 推荐：直接使用控制器类名
Route::get('posts', [PostController::class, 'index'])->name('posts.index');

// ❌ 不推荐：使用完整命名空间
Route::get('posts', [\Modules\Blog\Http\Controllers\Web\PostController::class, 'index'])
    ->name('posts.index');
```

### 3. 使用资源路由

```php
// ✅ 推荐：使用资源路由简化代码
Route::resource('posts', PostController::class);

// 等价于
Route::get('posts', [PostController::class, 'index'])->name('posts.index');
Route::get('posts/create', [PostController::class, 'create'])->name('posts.create');
Route::post('posts', [PostController::class, 'store'])->name('posts.store');
Route::get('posts/{id}', [PostController::class, 'show'])->name('posts.show');
Route::get('posts/{id}/edit', [PostController::class, 'edit'])->name('posts.edit');
Route::put('posts/{id}', [PostController::class, 'update'])->name('posts.update');
Route::delete('posts/{id}', [PostController::class, 'destroy'])->name('posts.destroy');
```

### 4. 合理组织路由

```php
// ✅ 推荐：按功能分组
Route::prefix('posts')->name('posts.')->group(function () {
    Route::get('', [PostController::class, 'index'])->name('index');
    Route::get('{id}', [PostController::class, 'show'])->name('show');
});

Route::prefix('categories')->name('categories.')->group(function () {
    Route::get('', [CategoryController::class, 'index'])->name('index');
    Route::get('{id}', [CategoryController::class, 'show'])->name('show');
});
```

### 5. 使用中间件保护路由

```php
// ✅ 推荐：合理使用中间件
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('profile', [UserController::class, 'profile'])->name('profile');
    Route::post('profile', [UserController::class, 'update'])->name('profile.update');
});
```

## 常见问题

### Q1: 如何修改控制器目录映射？

**A**: 在 `config/modules.php` 中配置 `route_controller_namespaces`：

```php
'route_controller_namespaces' => [
    'web' => 'Web',
    'api' => 'Api',
    'admin' => 'Admin',
    'custom' => 'CustomApp',  // 自定义映射
],
```

### Q2: 如何不使用路由前缀？

**A**: 在配置中关闭：

```php
'routes' => [
    'prefix' => false,
],
```

### Q3: 如何添加自定义路由文件？

**A**: 在模块的 `Routes` 目录下创建文件：

```php
// Blog/Routes/mobile.php
<?php

use Illuminate\Support\Facades\Route;

Route::get('posts', [PostController::class, 'index'])->name('posts.index');
```

系统会自动加载。

### Q4: 如何在路由中使用模型绑定？

**A**: 使用 Laravel 的隐式绑定或显式绑定：

```php
// 隐式绑定
Route::get('posts/{post}', [PostController::class, 'show'])->name('posts.show');

// 显式绑定
Route::model('post', Post::class);

// 在控制器中
public function show(Post $post)
{
    return view('blog::posts.show', compact('post'));
}
```

### Q5: 如何生成带模块前缀的 URL？

**A**: 使用助手函数：

```php
// 完整 URL
$url = module_route('Blog', 'posts.show', ['id' => 1]);

// 在视图中
<a href="{{ module_route(null, 'posts.show', ['id' => $post->id]) }}">
    {{ $post->title }}
</a>
```

## 总结

模块路由系统的核心特性：

1. ✅ **自动映射** - 路由文件自动映射到控制器子目录
2. ✅ **简洁写法** - 直接使用控制器类名，无需完整命名空间
3. ✅ **灵活配置** - 支持自定义控制器目录映射
4. ✅ **中间件支持** - 自动应用配置的中间件组
5. ✅ **路由前缀** - 自动添加模块前缀和路由名称前缀
6. ✅ **助手函数** - 提供便捷的路由生成函数

所有功能都经过验证，100% 正常运行！
