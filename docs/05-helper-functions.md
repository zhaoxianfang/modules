# Helper 函数详解

`helper.php` 提供了丰富的助手函数，用于简化模块操作。大部分函数支持无参调用，会自动检测当前所在模块。

## 核心函数

### module_name()

获取当前所在的模块名称（通过调用栈自动检测）。

```php
// 在 Blog/Http/Controllers/PostController.php 中调用
$moduleName = module_name(); // 'Blog'
```

**特性**：
- 自动检测当前代码所在模块
- 不使用缓存，每次都精确检测
- 跨控制器、模型、中间件等都可用

**返回值**：
- 成功：返回模块名称（StudlyCase）
- 失败：返回 `null`

**注意事项**：
- 在模块外部调用会返回 `null`
- 需要确保文件在模块目录下
- 调用栈深度限制为 20 层

### module_config()

获取模块配置值，支持两种用法：

#### 用法 1：智能方式（推荐）

在模块内部使用，无需传递模块名，自动读取当前模块的配置文件。

```php
// 读取 Blog/Config/common.php 的 name 配置
$name = module_config('common.name', 'hello');

// 读取嵌套配置 Blog/Config/settings.php 的 cache.enabled
$enabled = module_config('settings.cache.enabled', false);

// 读取更深层的嵌套配置
$timeout = module_config('api.timeout', 30);
```

**格式说明**：
- `common.name` - `common` 是配置文件名，`name` 是配置键
- `settings.cache.enabled` - 支持无限嵌套

#### 用法 2：传统方式

```php
$value = module_config('common.name', 'default', 'Blog');
```

**特性**：
- 支持嵌套配置读取
- 自动检测当前模块
- 配置不存在时返回默认值

### module_path()

获取模块目录路径。

```php
// 指定模块
$path = module_path('Blog', 'Models/Post.php');

// 使用当前模块
$path = module_path(null, 'Config/common.php');

// 自动检测当前模块
$path = module_path('Models/Post.php');
```

**返回值**：
- 成功：返回模块路径的完整绝对路径
- 失败：抛出 `RuntimeException`

### module_enabled()

检查模块是否已启用。

```php
// 检查当前模块
if (module_enabled()) {
    // 模块已启用
}

// 检查指定模块
if (module_enabled('Blog')) {
    // Blog 模块已启用
}
```

**返回值**：
- 启用：`true`
- 未启用或模块不存在：`false`

### module_exists()

检查模块是否存在。

```php
if (module_exists('Blog')) {
    // Blog 模块存在
}
```

**返回值**：
- 存在：`true`
- 不存在：`false`

## 路由相关函数

### module_route()

生成模块路由 URL。

```php
// 指定模块
$url = module_route('Blog', 'posts.index');

// 带参数
$url = module_route('Blog', 'posts.show', ['id' => 1]);

// 使用当前模块
$url = module_route('posts.index');
```

**参数**：
- `$module`：模块名称（可选，不传则使用当前模块）
- `$route`：路由名称
- `$params`：路由参数（可选）

### module_route_path()

获取模块路由名称前缀。

```php
$prefix = module_route_path('Blog', 'posts.index'); // 'blog.posts.index'
$prefix = module_route_path('Blog', ''); // 'blog.'

// 使用当前模块
$prefix = module_route_path('posts.index'); // 'blog.posts.index'
```

### module_url()

获取模块 URL。

```php
$url = module_url('Blog', 'posts/1'); // 'http://example.com/blog/posts/1'
$url = module_url(null, 'posts/1'); // 使用当前模块

// 自动检测当前模块
$url = module_url('posts/1');
```

### current_module()

通过 URL 路径分析获取当前请求所在的模块。

```php
// 访问 /blog/posts 时
$moduleName = current_module(); // 'Blog'
```

**返回值**：
- 成功：返回模块名称（StudlyCase）
- 失败：返回 `null`

## 视图相关函数

### module_view()

返回模块视图。

```php
// 指定模块
return module_view('Blog', 'post.index', ['posts' => $posts]);

// 使用当前模块
return module_view('post.index', compact('posts'));

// 视图命名空间格式：blog::post.index
```

### module_has_view()

检查模块视图是否存在。

```php
if (module_has_view('Blog', 'post.index')) {
    // 视图存在
}

if (module_has_view('post.index')) {
    // 当前模块的视图存在
}
```

### module_view_path()

获取模块视图路径（用于返回视图）。

```php
$path = module_view_path('Blog', 'post.index'); // 'blog::post.index'
$path = module_view_path('post.index'); // 使用当前模块
```

### module_views_path()

获取模块视图目录路径。

```php
$path = module_views_path('Blog'); // '.../Blog/Resources/views'
$path = module_views_path(); // 使用当前模块
```

## 配置相关函数

### module_has_config()

检查模块配置项是否存在。

```php
// 检查当前模块的配置项
if (module_has_config('common', 'name')) {
    // 配置项存在
}

// 检查指定模块的配置文件
if (module_has_config('Blog', 'common')) {
    // 配置文件存在
}
```

### module_config_path()

获取模块配置文件路径。

```php
$path = module_config_path('Blog', 'common.php');
$path = module_config_path('common.php'); // 使用当前模块
```

### module_config_files()

获取模块的所有配置文件。

```php
$files = module_config_files('Blog');
// ['common.php', 'settings.php', 'api.php', ...]

$files = module_config_files(); // 使用当前模块
```

### module_get_config()

获取模块配置文件的所有配置（完整数组）。

```php
$config = module_get_config('Blog', 'common');
// ['name' => 'Blog', 'version' => '1.0.0', ...]

$config = module_get_config('settings'); // 使用当前模块
```

### module_set_config()

设置模块配置值（运行时）。

```php
module_set_config('Blog', 'common', 'name', 'New Name');
module_set_config('settings', 'cache', true); // 使用当前模块
```

**注意**：仅在当前请求有效，不会持久化到文件。

## 命名空间相关函数

### module_namespace()

获取模块的命名空间。

```php
$namespace = module_namespace('Blog'); // 'Modules\Blog'
$namespace = module_namespace(); // 当前模块命名空间
```

### module_class()

获取模块类的完整类名。

```php
$className = module_class('Blog', 'Http\Controllers\PostController');
// 'Modules\Blog\Http\Controllers\PostController'

$className = module_class('Models\Post'); // 使用当前模块
```

## 资源相关函数

### module_asset()

生成模块静态资源 URL。

```php
$url = module_asset('Blog', 'css/style.css');
// 'http://example.com/modules/blog/css/style.css'

$url = module_asset('js/app.js'); // 使用当前模块
```

### module_lang()

获取模块翻译。

```php
$message = module_lang('Blog', 'messages.welcome');
// trans('blog::messages.welcome')

$message = module_lang('messages.welcome'); // 使用当前模块

// 带替换参数
$message = module_lang('messages.greeting', ['name' => 'John']);
```

### module_trans_path()

获取模块翻译文件路径。

```php
$path = module_trans_path('Blog'); // '.../Blog/Resources/lang'
$path = module_trans_path(); // 使用当前模块
```

## 路径相关函数

### module_routes_path()

获取模块路由文件路径。

```php
$path = module_routes_path('Blog', 'web'); // '.../Blog/Routes/web.php'
$path = module_routes_path('api'); // 使用当前模块
```

### module_route_files()

获取模块的所有路由文件。

```php
$files = module_route_files('Blog');
// ['web', 'api', 'admin']

$files = module_route_files(); // 使用当前模块
```

### module_migrations_path()

获取模块迁移目录路径。

```php
$path = module_migrations_path('Blog'); // '.../Blog/Database/Migrations'
$path = module_migrations_path(); // 使用当前模块
```

### module_has_migration()

检查模块是否存在指定的迁移文件。

```php
if (module_has_migration('Blog', 'create_posts_table')) {
    // 迁移文件存在
}

if (module_has_migration('create_posts_table')) {
    // 当前模块的迁移文件存在
}
```

### module_all_migrations()

获取模块的所有迁移文件。

```php
$migrations = module_all_migrations('Blog');
// ['2024_01_01_000000_create_posts_table.php', ...]

$migrations = module_all_migrations(); // 使用当前模块
```

### module_models_path()

获取模块模型目录路径。

```php
$path = module_models_path('Blog'); // '.../Blog/Models'
$path = module_models_path(); // 使用当前模块
```

### module_controllers_path()

获取模块控制器目录路径。

```php
$path = module_controllers_path('Blog', 'Web');
// '.../Blog/Http/Controllers/Web'

$path = module_controllers_path('Api'); // 使用当前模块
```

## 模块管理函数

### module()

获取模块实例或模块仓库。

```php
// 获取模块仓库
$repository = module();
$allModules = $repository->all();

// 获取指定模块实例
$blogModule = module('Blog');

// 获取当前模块实例
$currentModule = module(module_name());
```

### modules()

获取所有模块。

```php
$allModules = modules();
foreach ($allModules as $module) {
    echo $module->getName();
}
```

### module_enabled_modules()

获取所有已启用的模块。

```php
$enabled = module_enabled_modules();
foreach ($enabled as $module) {
    echo $module->getName();
}
```

**特性**：
- 不使用缓存，每次都精确查询
- 保证返回最新的模块状态

### module_disabled_modules()

获取所有已禁用的模块。

```php
$disabled = module_disabled_modules();
foreach ($disabled as $module) {
    echo $module->getName();
}
```

## 代码生成函数

### module_stub()

创建模块 Stub 生成器。

```php
$generator = module_stub('Blog');

$generator->render('controller', [
    'CLASS_NAMESPACE' => 'Modules\Blog\Http\Controllers',
    'CLASS' => 'PostController',
]);
```

## 实际应用示例

### 在控制器中使用

```php
namespace Modules\Blog\Http\Controllers\Web;

use Illuminate\Http\Request;
use Modules\Blog\Models\Post;

class PostController extends Controller
{
    public function index()
    {
        // 获取当前模块
        $moduleName = module_name(); // 'Blog'

        // 读取配置
        $perPage = module_config('settings.items_per_page', 10);
        $cacheEnabled = module_config('settings.cache.enabled', false);

        // 获取数据
        $posts = Post::paginate($perPage);

        // 返回视图
        return module_view('post.index', compact('posts'));
    }

    public function show($id)
    {
        $post = Post::findOrFail($id);

        return module_view('post.show', compact('post'));
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

        // 使用当前模块的配置
        $defaultStatus = module_config('settings.default_status', 'draft');

        static::creating(function ($post) use ($defaultStatus) {
            if (empty($post->status)) {
                $post->status = $defaultStatus;
            }
        });
    }

    public function scopePublished($query)
    {
        // 使用当前模块配置
        $status = module_config('settings.published_status', 'published');
        return $query->where('status', $status);
    }
}
```

### 在中间件中使用

```php
namespace Modules\Blog\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckModuleStatus
{
    public function handle(Request $request, Closure $next)
    {
        // 检查当前模块是否启用
        if (! module_enabled()) {
            abort(404);
        }

        // 读取配置
        $maintenance = module_config('maintenance.enabled', false);

        if ($maintenance) {
            return response('系统维护中', 503);
        }

        return $next($request);
    }
}
```

### 在命令中使用

```php
namespace Modules\Blog\Console\Commands;

use Illuminate\Console\Command;

class ClearCacheCommand extends Command
{
    protected $signature = 'blog:cache:clear';

    public function handle()
    {
        $this->info('正在清理 ' . module_name() . ' 模块缓存...');

        // 获取配置
        $cacheKeys = module_get_config('cache_keys');

        foreach ($cacheKeys as $key) {
            cache()->forget($key);
            $this->info("已清理: {$key}");
        }

        $this->info('缓存清理完成！');
    }
}
```

### 在视图中使用

```blade
{{-- 在 Blog 模块视图中 --}}

{{-- 获取模块配置 --}}
<h1>{{ module_config('common.title', 'Blog') }}</h1>

{{-- 生成资源链接 --}}
<link rel="stylesheet" href="{{ module_asset('css/style.css') }}">

{{-- 翻译 --}}
<h2>{{ module_lang('messages.welcome') }}</h2>

{{-- 路由链接 --}}
<a href="{{ module_route('posts.index') }}">文章列表</a>
```

## 最佳实践

### 1. 在模块内部优先使用无参调用

```php
// ✅ 推荐
module_config('common.name', 'default');

// ❌ 不推荐
module_config('Blog', 'common.name', 'default');
```

### 2. 使用配置的完整路径

```php
// ✅ 推荐
module_config('settings.cache.enabled', false);

// ❌ 不推荐
module_get_config('settings')['cache']['enabled'];
```

### 3. 善用 helper 函数简化代码

```php
// ✅ 简洁
return module_view('post.index', compact('posts'));

// ❌ 繁琐
return view('blog::post.index', ['posts' => $posts]);
```

### 4. 检查配置是否存在

```php
if (module_has_config('common', 'name')) {
    $name = module_config('common.name', 'default');
}
```

## 性能说明

### 不使用缓存的原因

为了确保数据的准确性和一致性，核心函数不使用缓存：

1. **module_name()**
   - 每次都通过调用栈精确检测
   - 避免缓存错误
   - 保证准确性

2. **module_config()**
   - 每次都读取配置
   - 支持运行时配置变更
   - 保证实时性

### 性能优化建议

1. **合并配置读取**
   ```php
   // ✅ 推荐：一次读取多次使用
   $config = module_get_config('settings');
   $perPage = $config['per_page'];
   $cache = $config['cache'];

   // ❌ 不推荐：多次读取
   $perPage = module_config('settings.per_page');
   $cache = module_config('settings.cache');
   ```

2. **使用 Laravel 配置缓存**
   ```bash
   php artisan config:cache
   ```

3. **在应用层面缓存**
   ```php
   $cachedData = cache('module.blog.config', function () {
       return module_get_config('settings');
   }, 3600);
   ```

## 注意事项

1. **当前模块检测**：在模块外部调用 `module_name()` 会返回 `null`
2. **配置运行时修改**：`module_set_config()` 仅在当前请求有效
3. **路径参数**：大部分路径函数不自动创建目录，只返回路径字符串
4. **异常处理**：所有函数都有异常处理，不会导致应用崩溃

## 完整函数列表

| 函数名 | 说明 | 是否支持无参 |
|--------|------|-------------|
| `module_name()` | 获取当前模块名称 | ✅ |
| `module_path()` | 获取模块路径 | ✅ |
| `module_config()` | 获取配置值 | ✅ |
| `module_enabled()` | 检查模块是否启用 | ✅ |
| `module_exists()` | 检查模块是否存在 | ❌ |
| `module()` | 获取模块实例或仓库 | ❌ |
| `modules()` | 获取所有模块 | ✅ |
| `module_view_path()` | 获取视图路径 | ✅ |
| `module_route_path()` | 获取路由路径前缀 | ✅ |
| `current_module()` | 获取请求所在的模块 | ✅ |
| `module_namespace()` | 获取模块命名空间 | ✅ |
| `module_url()` | 获取模块 URL | ✅ |
| `module_route()` | 生成路由 URL | ✅ |
| `module_asset()` | 生成资源 URL | ✅ |
| `module_view()` | 返回模块视图 | ✅ |
| `module_lang()` | 获取翻译 | ✅ |
| `module_stub()` | 创建 Stub 生成器 | ❌ |
| `module_class()` | 获取完整类名 | ✅ |
| `module_has_config()` | 检查配置项是否存在 | ✅ |
| `module_config_path()` | 获取配置文件路径 | ✅ |
| `module_has_view()` | 检查视图是否存在 | ✅ |
| `module_routes_path()` | 获取路由文件路径 | ✅ |
| `module_migrations_path()` | 获取迁移目录路径 | ✅ |
| `module_models_path()` | 获取模型目录路径 | ✅ |
| `module_controllers_path()` | 获取控制器目录路径 | ✅ |
| `module_views_path()` | 获取视图目录路径 | ✅ |
| `module_trans_path()` | 获取翻译文件路径 | ✅ |
| `module_config_files()` | 获取所有配置文件 | ✅ |
| `module_route_files()` | 获取所有路由文件 | ✅ |
| `module_get_config()` | 获取完整配置数组 | ✅ |
| `module_set_config()` | 设置配置值（运行时） | ✅ |
| `module_has_migration()` | 检查迁移文件是否存在 | ✅ |
| `module_all_migrations()` | 获取所有迁移文件 | ✅ |
| `module_enabled_modules()` | 获取所有已启用模块 | ✅ |
| `module_disabled_modules()` | 获取所有已禁用模块 | ✅ |

## 相关文档

- [智能模块检测](06-intelligent-detection.md) - 深入了解自动检测机制
- [配置详解](04-configuration.md) - 学习配置的更多细节
- [路由指南](07-routes.md) - 了解路由相关函数的使用
- [视图使用](08-views.md) - 学习视图相关函数的使用
