# 最佳实践

## 模块开发最佳实践

### 1. 模块设计原则

#### 单一职责原则

每个模块应该专注于单一功能：

```php
// ✅ 推荐：每个模块专注一个功能
Modules/Blog     // 博客功能
Modules/Shop     // 电商功能
Modules/Forum    // 论坛功能

// ❌ 不推荐：一个模块包含多个功能
Modules/Content  // 包含博客、论坛、商城等
```

#### 依赖最小化

尽量减少模块间的依赖：

```php
// ✅ 推荐：使用接口和抽象
interface CacheServiceInterface
{
    public function get(string $key): ?string;
    public function set(string $key, string $value, int $ttl): void;
}

// ❌ 不推荐：直接依赖其他模块
use Modules\Cache\CacheService;  // 强依赖
```

### 2. 命名规范

#### 模块命名

```php
// ✅ 推荐：使用描述性的 StudlyCase 名称
Modules/Blog
Modules/ECommerce
Modules/UserManagement

// ❌ 不推荐：使用模糊的名称
Modules/Module1
Modules/M1
Modules/Test
```

#### 类命名

```php
// ✅ 推荐：清晰的类名
PostController
PostRepository
PostPolicy

// ❌ 不推荐：不清晰的类名
Controller1
Repository2
Policy3
```

### 3. 配置管理

#### 配置分层

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

#### 提供默认值

```php
// ✅ 推荐：始终提供默认值
$perPage = module_config('settings.per_page', 10);

// ❌ 不推荐：不提供默认值
$perPage = module_config('settings.per_page');  // 可能返回 null
```

### 4. 路由设计

#### 使用资源路由

```php
// ✅ 推荐：使用 RESTful 资源路由
Route::resource('posts', PostController::class);

// ❌ 不推荐：定义多个相似路由
Route::get('/posts', ...);
Route::post('/posts', ...);
Route::put('/posts/{id}', ...);
Route::delete('/posts/{id}', ...);
```

#### 命名路由

```php
// ✅ 推荐：使用命名路由
Route::get('/posts', [PostController::class, 'index'])
    ->name('posts.index');

// ❌ 不推荐：不命名路由
Route::get('/posts', [PostController::class, 'index']);
```

### 5. 视图组织

#### 使用布局继承

```blade
{{-- ✅ 推荐：使用布局继承 --}}
@extends('layouts.app')

@section('content')
    <!-- 内容 -->
@endsection

{{-- ❌ 不推荐：重复 HTML --}}
<!DOCTYPE html>
<html>
<!-- 重复的 HTML 代码 -->
</html>
```

#### 视图组件化

```blade
{{-- ✅ 推荐：使用组件 --}}
<x-post-card :post="$post" />
<x-comment-list :comments="$comments" />

{{-- ❌ 不推荐：在一个文件中包含所有内容 --}}
<div class="post"><!-- ... --></div>
<div class="comments"><!-- ... --></div>
```

### 6. 模型设计

#### 使用 Repository 模式

```php
// ✅ 推荐：使用 Repository
class PostController extends Controller
{
    public function __construct(
        private PostRepository $repository
    ) {}

    public function index()
    {
        $posts = $this->repository->paginate(20);
        return module_view('post.index', compact('posts'));
    }
}

// ❌ 不推荐：直接在控制器中使用模型
class PostController extends Controller
{
    public function index()
    {
        $posts = Post::paginate(20);  // 紧耦合
    }
}
```

#### 使用观察者

```php
// ✅ 推荐：使用观察者处理模型事件
class PostObserver
{
    public function created(Post $post)
    {
        Cache::forget('posts');
    }
}

// ❌ 不推荐：在控制器中处理
class PostController extends Controller
{
    public function store(Request $request)
    {
        $post = Post::create($request->validated());
        Cache::forget('posts');  // 业务逻辑混在控制器中
    }
}
```

### 7. 安全性

#### 使用表单请求验证

```php
// ✅ 推荐：使用表单请求
class StorePostRequest extends FormRequest
{
    public function rules()
    {
        return [
            'title' => 'required|max:255',
            'content' => 'required',
        ];
    }
}

// ❌ 不推荐：在控制器中验证
class PostController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|max:255',
            'content' => 'required',
        ]);
    }
}
```

#### 使用策略类

```php
// ✅ 推荐：使用策略类
class PostPolicy
{
    public function update(User $user, Post $post)
    {
        return $user->id === $post->user_id;
    }
}

// ❌ 不推荐：在控制器中检查权限
class PostController extends Controller
{
    public function update(Request $request, $id)
    {
        $post = Post::findOrFail($id);
        if (auth()->id() !== $post->user_id) {
            abort(403);
        }
    }
}
```

### 8. 测试

#### 编写测试

```php
// ✅ 推荐：编写完整的测试
class PostTest extends TestCase
{
    public function test_user_can_create_post()
    {
        $response = $this->post(route('blog.posts.store'), [
            'title' => 'Test Post',
            'content' => 'Test Content',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('posts', ['title' => 'Test Post']);
    }
}

// ❌ 不推荐：不编写测试
// 直接在浏览器中测试
```

### 9. 性能优化

#### 使用缓存

```php
// ✅ 推荐：使用缓存
public function index()
{
    $posts = Cache::remember('posts', 3600, function () {
        return Post::with('comments')->latest()->get();
    });

    return module_view('post.index', compact('posts'));
}

// ❌ 不推荐：每次都查询数据库
public function index()
{
    $posts = Post::with('comments')->latest()->get();
}
```

#### 使用 Eager Loading

```php
// ✅ 推荐：使用 Eager Loading
$posts = Post::with('comments', 'author')->get();

// ❌ 不推荐：N+1 查询问题
$posts = Post::all();
foreach ($posts as $post) {
    echo $post->comments;  // 每次循环都查询
}
```

### 10. 错误处理

#### 使用异常

```php
// ✅ 推荐：使用异常处理
try {
    $post = Post::findOrFail($id);
    return module_view('post.show', compact('post'));
} catch (ModelNotFoundException $e) {
    return redirect()->route('blog.posts.index')
        ->with('error', 'Post not found');
}

// ❌ 不推荐：静默失败
$post = Post::find($id);
if (! $post) {
    return null;  // 返回 null，可能导致后续错误
}
```

## 生产环境最佳实践

### 1. 缓存优化

```bash
# 缓存配置
php artisan config:cache

# 缓存路由
php artisan route:cache

# 缓存视图
php artisan view:cache
```

### 2. 环境配置

```bash
# 生产环境变量
APP_ENV=production
APP_DEBUG=false

# 启用模块缓存
MODULES_CACHE_ENABLED=true
```

### 3. 日志配置

```php
// Config/logging.php
'channels' => [
    'daily' => [
        'driver' => 'daily',
        'path' => storage_path('logs/laravel.log'),
        'level' => 'debug',
        'days' => 14,
    ],
],
```

### 4. 权限设置

```bash
# 设置正确的文件权限
chmod -R 755 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

## 常见错误

### 1. 硬编码路径

```php
// ❌ 不推荐：硬编码路径
$path = '/var/www/html/Modules/Blog';

// ✅ 推荐：使用 helper 函数
$path = module_path('Blog');
```

### 2. 直接访问数据库

```php
// ❌ 不推荐：直接使用 DB 类
$posts = DB::table('posts')->get();

// ✅ 推荐：使用 Eloquent 模型
$posts = Post::all();
```

### 3. 忽略模块检测失败

```php
// ❌ 不推荐：不检查返回值
$moduleName = module_name();
$value = module_config('settings.key', 'default');  // 可能出错

// ✅ 推荐：检查返回值
$moduleName = module_name();
if (! $moduleName) {
    throw new \RuntimeException('无法检测到当前模块');
}
$value = module_config('settings.key', 'default');
```

## 相关文档

- [Helper 函数](05-helper-functions.md) - Helper 函数的最佳实践
- [路由指南](07-routes.md) - 路由设计最佳实践
- [性能优化](13-performance.md) - 性能优化技巧
