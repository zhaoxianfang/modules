# 路由指南

## 概述

模块系统提供了灵活的路由管理功能，支持 Web、API、Admin 等多种路由类型。

## 路由文件结构

每个模块默认包含三个路由文件：

```
Modules/Blog/Routes/
├── web.php      # Web 路由
├── api.php      # API 路由
└── admin.php    # Admin 路由
```

## 路由配置

### 中间件组配置

在 `config/modules.php` 中配置中间件组：

```php
'middleware_groups' => [
    'web' => ['web'],
    'api' => ['api'],
    'admin' => ['web', 'admin'],
],
```

### 路由控制器命名空间映射

```php
'route_controller_namespaces' => [
    'web' => 'Http\Controllers\Web',
    'api' => 'Http\Controllers\Api',
    'admin' => 'Http\Controllers\Admin',
],
```

### 路由前缀配置

```php
'routes' => [
    'prefix' => true,         // 是否自动添加路由前缀
    'name_prefix' => true,   // 是否自动添加路由名称前缀
],
```

## Web 路由

### 基本用法

编辑 `Modules/Blog/Routes/web.php`：

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Blog\Http\Controllers\Web\PostController;

Route::group([
    'middleware' => ['web'],
], function () {
    Route::get('/posts', [PostController::class, 'index'])
        ->name('posts.index');

    Route::get('/posts/{id}', [PostController::class, 'show'])
        ->name('posts.show');

    Route::get('/posts/create', [PostController::class, 'create'])
        ->name('posts.create');

    Route::post('/posts', [PostController::class, 'store'])
        ->name('posts.store');

    Route::get('/posts/{id}/edit', [PostController::class, 'edit'])
        ->name('posts.edit');

    Route::put('/posts/{id}', [PostController::class, 'update'])
        ->name('posts.update');

    Route::delete('/posts/{id}', [PostController::class, 'destroy'])
        ->name('posts.destroy');
});
```

### 使用路由前缀

```php
Route::prefix('blog')->group(function () {
    Route::get('/posts', [PostController::class, 'index'])
        ->name('posts.index');
});

// 访问路径: /blog/posts
// 路由名称: blog.posts.index
```

## API 路由

### 基本用法

编辑 `Modules/Blog/Routes/api.php`：

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

### API 路由最佳实践

```php
Route::group([
    'middleware' => ['api', 'auth:sanctum'],
    'prefix' => 'v1',
], function () {
    Route::apiResource('posts', PostController::class);
});
```

## Admin 路由

### 基本用法

编辑 `Modules/Blog/Routes/admin.php`：

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Blog\Http\Controllers\Admin\PostController;

Route::group([
    'middleware' => ['web', 'auth', 'admin'],
    'prefix' => 'admin/blog',
], function () {
    Route::get('/posts', [PostController::class, 'index'])
        ->name('admin.posts.index');

    Route::get('/posts/{id}', [PostController::class, 'show'])
        ->name('admin.posts.show');

    Route::get('/posts/{id}/edit', [PostController::class, 'edit'])
        ->name('admin.posts.edit');

    Route::put('/posts/{id}', [PostController::class, 'update'])
        ->name('admin.posts.update');

    Route::delete('/posts/{id}', [PostController::class, 'destroy'])
        ->name('admin.posts.destroy');
});
```

## 路由 Helper 函数

### module_route()

生成模块路由 URL：

```php
// 指定模块
$url = module_route('Blog', 'posts.index');

// 带参数
$url = module_route('Blog', 'posts.show', ['id' => 1]);

// 使用当前模块
$url = module_route('posts.index');
```

### module_route_path()

获取模块路由名称前缀：

```php
$prefix = module_route_path('Blog', 'posts.index');
// 'blog.posts.index'
```

### current_module()

通过 URL 路径分析获取当前请求所在的模块：

```php
// 访问 /blog/posts 时
$moduleName = current_module(); // 'Blog'
```

## 自定义路由文件

### 创建自定义路由文件

```bash
php artisan module:make-route Blog mobile
```

这会在 `Modules/Blog/Routes/` 目录下创建 `mobile.php` 文件。

### 配置自定义路由中间件

在 `config/modules.php` 中添加：

```php
'middleware_groups' => [
    'web' => ['web'],
    'api' => ['api'],
    'admin' => ['web', 'admin'],
    'mobile' => ['web', 'mobile'],  // 新增
],
```

### 编辑自定义路由文件

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Blog\Http\Controllers\Mobile\PostController;

Route::group([
    'middleware' => ['web', 'mobile'],
    'prefix' => 'mobile',
], function () {
    Route::get('/posts', [PostController::class, 'index']);
});
```

## 路由命名空间

### 控制器命名空间

根据路由类型，自动映射到对应的控制器命名空间：

```php
// web.php -> Http\Controllers\Web
// api.php -> Http\Controllers\Api
// admin.php -> Http\Controllers\Admin
```

### 自定义命名空间

在路由文件中手动指定命名空间：

```php
Route::namespace('Modules\Blog\Http\Controllers\Custom')->group(function () {
    // 路由定义
});
```

## 路由缓存

### 缓存路由

```bash
php artisan route:cache
```

### 清除路由缓存

```bash
php artisan route:clear
```

### 查看路由列表

```bash
# 查看所有路由
php artisan route:list

# 查看模块路由
php artisan route:list --path=blog

# 查看特定类型的路由
php artisan route:list --path=blog/api
```

## 路由中间件

### 创建模块中间件

```bash
php artisan module:make-middleware Blog CheckAuth
```

### 在路由中使用中间件

```php
Route::middleware(['auth', 'check.author'])->group(function () {
    Route::get('/posts/create', [PostController::class, 'create']);
});
```

### 全局注册中间件

在模块服务提供者中注册：

```php
public function boot()
{
    $this->app['router']->aliasMiddleware('check.author', \Modules\Blog\Http\Middleware\CheckAuthor::class);
}
```

## 路由参数

### 必需参数

```php
Route::get('/posts/{id}', [PostController::class, 'show']);
```

### 可选参数

```php
Route::get('/posts/{id?}', [PostController::class, 'show']);
```

### 参数约束

```php
Route::get('/posts/{id}', [PostController::class, 'show'])
    ->where('id', '[0-9]+');
```

### 全局约束

在 `app/Providers/RouteServiceProvider.php` 中：

```php
public function boot()
{
    Route::pattern('id', '[0-9]+');

    parent::boot();
}
```

## 资源路由

### 基本资源路由

```php
Route::resource('posts', PostController::class);
```

生成的路由：

| 方法 | URI | 动作 | 路由名称 |
|------|-----|------|---------|
| GET | /posts | index | posts.index |
| GET | /posts/create | create | posts.create |
| POST | /posts | store | posts.store |
| GET | /posts/{id} | show | posts.show |
| GET | /posts/{id}/edit | edit | posts.edit |
| PUT/PATCH | /posts/{id} | update | posts.update |
| DELETE | /posts/{id} | destroy | posts.destroy |

### 部分资源路由

```php
Route::resource('photos', PhotoController::class)
    ->only(['index', 'show']);

Route::resource('photos', PhotoController::class)
    ->except(['create', 'store', 'update', 'destroy']);
```

### 命名资源路由

```php
Route::resource('users.photos', PhotoController::class);
```

生成的路由：`users.photos.{method}`

### 嵌套资源路由

```php
Route::resource('posts.comments', CommentController::class);
```

## 路由模型绑定

### 隐式绑定

```php
Route::get('/posts/{post}', function (Post $post) {
    return $post;
});
```

### 显式绑定

在模块服务提供者中：

```php
use Modules\Blog\Models\Post;

public function boot()
{
    Route::model('post', Post::class);
}
```

### 自定义解析

```php
Route::bind('post', function ($value) {
    return Post::where('slug', $value)->firstOrFail();
});
```

## 路由最佳实践

### 1. 使用资源路由

```php
// ✅ 推荐
Route::resource('posts', PostController::class);

// ❌ 不推荐（冗余）
Route::get('/posts', ...);
Route::get('/posts/{id}', ...);
Route::post('/posts', ...);
```

### 2. 路由分组

```php
// ✅ 推荐
Route::prefix('admin')->middleware(['auth', 'admin'])->group(function () {
    // 管理路由
});

// ❌ 不推荐（重复）
Route::middleware(['auth', 'admin'])->get('/admin/users', ...);
Route::middleware(['auth', 'admin'])->post('/admin/users', ...);
```

### 3. 命名路由

```php
// ✅ 推荐
Route::get('/posts', [PostController::class, 'index'])->name('posts.index');

// ❌ 不推荐
Route::get('/posts', [PostController::class, 'index']);
// 生成 URL 时: '/posts'（硬编码）
```

### 4. RESTful 风格

```php
// ✅ 推荐
Route::apiResource('posts', PostController::class);

// ❌ 不推荐（非标准）
Route::get('/get-all-posts', ...);
Route::post('/save-post', ...);
```

## 调试路由

### 查看所有路由

```bash
php artisan route:list
```

### 查看特定路由

```bash
php artisan route:list --name=blog
```

### 查看路由详情

```bash
php artisan route:list --path=blog/posts
```

### 检查路由是否存在

```php
if (Route::has('blog.posts.index')) {
    // 路由存在
}
```

## 相关文档

- [Helper 函数](05-helper-functions.md) - 路由相关的助手函数
- [配置详解](04-configuration.md) - 路由配置选项
- [代码生成](10-code-generation.md) - 生成路由文件和控制器
