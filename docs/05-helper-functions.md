# Helper 函数详解

`helper.php` 提供了 35+ 个助手函数，用于简化模块操作。大部分函数支持无参调用（在当前模块上下文中自动检测）。

> **v5.0 设计原则**：所有 Helper 函数遵循"模块名作为最末尾可选参数"的签名约定，使得在模块内部调用时无需传递模块名。

## 一、模块基本信息与检测

### module_name()

获取当前所在的模块名称（通过调用栈自动检测或路由分析）。

```php
/**
 * @param bool $toLower       是否返回小写蛇形命名
 * @param bool $requestModule 检测模式：true=路由检测, false=文件检测
 * @return string 模块名称，或 'App'/'Command'
 */
function module_name(bool $toLower = false, bool $requestModule = true): string
```

**使用示例**：

```php
// 在 Blog/Http/Controllers/PostController.php 中调用
$moduleName = module_name();       // 'Blog'（StudlyCase）
$moduleName = module_name(true);   // 'blog'（小写蛇形）
$moduleName = module_name(false, false); // 文件栈检测模式
```

**注意**：在模块外部调用返回 `'App'` 或 `'Command'`（非 `null`）。

### module()

获取模块实例或模块仓库。

```php
/**
 * @param string|null $module 模块名称（不传返回仓库，传模块名可能返回 null）
 * @return ModuleInterface|RepositoryInterface|null
 */
function module(?string $module = null): ModuleInterface|RepositoryInterface|null
```

**使用示例**：

```php
// 获取模块仓库（所有模块的管理入口）
$repository = module();
$allModules = $repository->all();

// 获取指定模块实例
$blogModule = module('Blog');
if ($blogModule) {
    echo $blogModule->getName();       // 'Blog'
    echo $blogModule->isEnabled();     // true/false
    echo $blogModule->getPriority();   // 1000
}

// 通过别名查找
$blogModule = module('blog-manager'); // 等价于 module('Blog')
```

### modules()

获取所有已注册的模块实例。

```php
/**
 * @return array<string, ModuleInterface>
 */
function modules(): array
```

```php
$allModules = modules();
foreach ($allModules as $name => $module) {
    echo $name . ': ' . ($module->isEnabled() ? '已启用' : '已禁用') . "\n";
}
```

### module_exists()

检查模块是否存在。

```php
function module_exists(string $module): bool
```

```php
if (module_exists('Blog')) {
    // Blog 模块存在
}

if (module_exists('NonExistent')) {
    // 不会执行，模块不存在
}
```

### module_enabled()

检查模块是否已启用。

```php
/**
 * @param string|null $module 模块名称（不传则检测当前模块）
 */
function module_enabled(?string $module = null): bool
```

```php
// 检查指定模块
if (module_enabled('Blog')) {
    // Blog 模块已启用
}

// 检查当前模块（自动检测）
if (module_enabled()) {
    // 当前模块已启用
}
```

---

## 二、模块路径操作

### module_path()

获取模块目录的完整绝对路径。

```php
/**
 * @param string      $path   子路径
 * @param string|null $module 模块名称（不传则自动检测）
 * @return string
 * @throws RuntimeException 当模块名无法确定时
 */
function module_path(string $path = '', ?string $module = null): string
```

```php
// 获取模块根目录
$rootPath = module_path(module: 'Blog');     // /path/to/Modules/Blog

// 获取子路径
$configPath = module_path('Config', 'Blog'); // /path/to/Modules/Blog/Config
$viewPath = module_path('Resources/views');  // 当前模块的 views 路径

// 自动检测当前模块
$modelPath = module_path('Models');          // 当前模块的 Models 路径
```

### module_config_path()

获取模块配置文件路径。

```php
function module_config_path(string $configFile = 'config.php', ?string $module = null): string
```

```php
$path = module_config_path('blog.php', 'Blog'); // .../Blog/Config/blog.php
$path = module_config_path('settings.php');      // 当前模块的配置文件
```

### module_routes_path()

获取模块路由文件路径。

```php
function module_routes_path(string $route = 'web', ?string $module = null): string
```

```php
$path = module_routes_path('web', 'Blog');  // .../Blog/Routes/web.php
$path = module_routes_path('api');           // 当前模块的 api.php
```

### module_migrations_path()

获取模块迁移目录路径。

```php
function module_migrations_path(?string $module = null): string
```

```php
$path = module_migrations_path('Blog'); // .../Blog/Database/Migrations
$path = module_migrations_path();        // 当前模块迁移目录
```

### module_models_path()

获取模块模型目录路径。

```php
function module_models_path(?string $module = null): string
```

### module_controllers_path()

获取模块控制器目录路径。

```php
/**
 * @param string      $controller 控制器类型（Web, Api, Admin 等）
 * @param string|null $module     模块名称
 */
function module_controllers_path(string $controller = 'Web', ?string $module = null): string
```

```php
$path = module_controllers_path('Web', 'Blog');   // .../Blog/Http/Controllers/Web
$path = module_controllers_path('Api');             // 当前模块的 Api 控制器
$path = module_controllers_path('Admin', 'Shop');   // .../Shop/Http/Controllers/Admin
```

### module_views_path()

获取模块视图目录路径。

```php
function module_views_path(?string $module = null): string
```

```php
$path = module_views_path('Blog'); // .../Blog/Resources/views
```

### module_trans_path()

获取模块翻译目录路径。

```php
function module_trans_path(?string $module = null): string
```

```php
$path = module_trans_path('Blog'); // .../Blog/Resources/lang
```

---

## 三、模块配置管理

### module_config()

获取模块配置值（支持多级缓存和点号分隔的键路径）。

```php
/**
 * @param string      $key     配置键（如 'options.posts_per_page'）
 * @param mixed       $default 默认值
 * @param string|null $module  模块名称（不传则自动检测）
 * @return mixed
 */
function module_config(string $key, mixed $default = null, ?string $module = null): mixed
```

**使用示例**：

```php
// 读取主配置文件（blog.php）中的 options 键
$perPage = module_config('options.posts_per_page', 15);

// 读取自定义配置文件（settings.php）
$cacheEnabled = module_config('settings.cache.enabled', false);

// 指定模块
$timeout = module_config('api.timeout', 30, 'Blog');

// 在模块内部（自动检测当前模块）
$version = module_config('options.version', '1.0.0');
```

### module_get_config()

获取模块配置文件完整数组。

```php
/**
 * @param string      $configFile 配置文件名（不含路径）
 * @param string|null $module     模块名称
 * @return array
 */
function module_get_config(string $configFile = '', ?string $module = null): array
```

```php
// 获取 Blog 模块主配置的完整数组
$config = module_get_config('blog', 'Blog');
// ['enabled' => true, 'priority' => 1000, 'options' => [...], ...]

// 获取当前模块的 settings 配置
$settings = module_get_config('settings');
// ['per_page' => 20, 'cache' => ['enabled' => true, 'ttl' => 3600]]
```

### module_set_config()

运行时设置模块配置值（仅在当前请求有效，不会持久化到文件）。

```php
/**
 * @param string      $configFile 配置文件名
 * @param string      $key        配置键
 * @param mixed       $value      配置值
 * @param string|null $module     模块名称
 */
function module_set_config(string $configFile = '', string $key = '', mixed $value = null, ?string $module = null): void
```

```php
// 运行时覆盖配置
module_set_config('settings', 'cache.enabled', false, 'Blog');
module_set_config('api', 'timeout', 60); // 当前模块
```

### module_has_config()

检查模块配置项是否存在。

```php
/**
 * @param string      $configFile 配置文件名
 * @param string      $key        配置键（为空则检查整个文件）
 * @param string|null $module     模块名称
 */
function module_has_config(string $configFile = '', string $key = '', ?string $module = null): bool
```

```php
// 检查配置项
if (module_has_config('blog', 'options.posts_per_page', 'Blog')) {
    $perPage = module_config('options.posts_per_page', 15, 'Blog');
}

// 检查配置文件是否存在
if (module_has_config('settings', module: 'Blog')) {
    $settings = module_get_config('settings', 'Blog');
}
```

### module_config_files()

获取模块的所有配置文件列表。

```php
/**
 * @param string|null $module 模块名称
 * @return array
 */
function module_config_files(?string $module = null): array
```

```php
$files = module_config_files('Blog');
// ['blog.php', 'settings.php', 'api.php']

$files = module_config_files(); // 当前模块的所有配置文件
```

---

## 四、命名空间与类名

### module_namespace()

获取模块的 PHP 命名空间。

```php
function module_namespace(?string $module = null): string
```

```php
$ns = module_namespace('Blog');  // 'Modules\Blog'
$ns = module_namespace();        // 当前模块命名空间
```

### module_class()

构建模块内类的完整类名。

```php
/**
 * @param string      $class  相对类名
 * @param string|null $module 模块名称
 */
function module_class(string $class = '', ?string $module = null): string
```

```php
$class = module_class('Http\Controllers\PostController', 'Blog');
// 'Modules\Blog\Http\Controllers\PostController'

$class = module_class('Models\Post'); // 当前模块命名空间下
// 'Modules\{CurrentModule}\Models\Post'
```

---

## 五、视图、路由与静态资源

### module_view()

返回模块视图实例。

```php
/**
 * @param string      $view   视图名称
 * @param array       $data   视图数据
 * @param string|null $module 模块名称
 * @return \Illuminate\Contracts\View\View
 */
function module_view(string $view = '', array $data = [], ?string $module = null): \Illuminate\Contracts\View\View
```

```php
// 在控制器中返回模块视图（推荐用法）
return module_view('posts.index', ['posts' => $posts]);
// 等价于 view('blog::posts.index', ['posts' => $posts])

// 指定模块的视图
return module_view('products.show', ['product' => $product], 'Shop');
```

### module_view_path()

获取模块视图命名空间路径（用于返回视图字符串）。

```php
/**
 * @param string      $view   视图名称
 * @param string|null $module 模块名称
 */
function module_view_path(string $view = '', ?string $module = null): string
```

```php
$path = module_view_path('posts.index');       // 'blog::posts.index'
$path = module_view_path('show', 'Shop');      // 'shop::show'
```

### module_has_view()

检查模块视图是否存在。

```php
/**
 * @param string      $view   视图名称
 * @param string|null $module 模块名称
 */
function module_has_view(string $view = '', ?string $module = null): bool
```

```php
if (module_has_view('posts.show')) {
    return module_view('posts.show', ['post' => $post]);
}
// 回退到默认视图
return view('errors.404');
```

### module_route()

生成模块路由 URL。

```php
/**
 * @param string      $route  路由名称
 * @param array       $params 路由参数
 * @param string|null $module 模块名称
 */
function module_route(string $route = '', array $params = [], ?string $module = null): string
```

```php
// 当前模块路由（推荐）
$url = module_route('posts.index');
$url = module_route('posts.show', ['id' => 1]);

// 指定模块路由
$url = module_route('products.index', [], 'Shop');

// 等价于
$url = route('blog.posts.index');
$url = route('blog.posts.show', ['id' => 1]);
```

### module_route_path()

获取模块路由名称前缀。

```php
/**
 * @param string      $route  路由名称
 * @param string|null $module 模块名称
 */
function module_route_path(string $route = '', ?string $module = null): string
```

```php
$prefix = module_route_path('posts.index'); // 'blog.posts.index'
$prefix = module_route_path('');             // 'blog.'
```

### module_url()

生成模块 URL。

```php
/**
 * @param string      $path   路径
 * @param string|null $module 模块名称
 */
function module_url(string $path = '', ?string $module = null): string
```

```php
$url = module_url('posts/1');           // http://example.com/blog/posts/1
$url = module_url('products', 'Shop'); // http://example.com/shop/products
```

### module_asset()

生成模块静态资源 URL。

```php
/**
 * @param string      $asset  资源路径
 * @param string|null $module 模块名称
 */
function module_asset(string $asset = '', ?string $module = null): string
```

```php
// CSS/JS 资源
$css = module_asset('css/style.css');   // /modules/blog/css/style.css
$js  = module_asset('js/app.js', 'Shop'); // /modules/shop/js/app.js

// Blade 模板中
<link rel="stylesheet" href="{{ module_asset('css/style.css') }}">
<script src="{{ module_asset('js/app.js') }}"></script>
```

### module_lang()

获取模块翻译文本。

```php
/**
 * @param string      $key     翻译键
 * @param array       $replace 占位符替换
 * @param string|null $locale  语言环境
 * @param string|null $module  模块名称
 * @return string|array
 */
function module_lang(string $key = '', array $replace = [], ?string $locale = null, ?string $module = null): string|array
```

```php
// 基础翻译
$welcome = module_lang('messages.welcome');
// 等价于 trans('blog::messages.welcome')

// 带参数替换
$greeting = module_lang('messages.hello', ['name' => '张三']);

// 指定模块和语言
$text = module_lang('messages.title', [], 'zh_CN', 'Shop');
```

---

## 六、模块状态与枚举

### module_enabled_modules()

获取所有已启用的模块。

```php
/**
 * @return array<string, ModuleInterface>
 */
function module_enabled_modules(): array
```

```php
$enabled = module_enabled_modules();
echo count($enabled) . ' 个模块已启用';
foreach ($enabled as $name => $module) {
    echo $name . ' (优先级: ' . $module->getPriority() . ")\n";
}
```

### module_disabled_modules()

获取所有已禁用的模块。

```php
/**
 * @return array<string, ModuleInterface>
 */
function module_disabled_modules(): array
```

### module_has_migration()

检查模块是否存在指定迁移文件。

```php
/**
 * @param string      $migrationName 迁移文件名
 * @param string|null $module        模块名称
 */
function module_has_migration(string $migrationName = '', ?string $module = null): bool
```

```php
if (module_has_migration('create_posts_table', 'Blog')) {
    // 迁移文件存在
}
if (module_has_migration('add_slug_to_posts')) {
    // 当前模块的迁移
}
```

### module_all_migrations()

获取模块所有迁移文件。

```php
/**
 * @param string|null $module 模块名称
 * @return array
 */
function module_all_migrations(?string $module = null): array
```

```php
$migrations = module_all_migrations('Blog');
// ['2024_01_01_000001_create_posts_table.php', ...]
```

### module_route_files()

获取模块所有路由文件。

```php
/**
 * @param string|null $module 模块名称
 * @return array
 */
function module_route_files(?string $module = null): array
```

```php
$files = module_route_files('Blog'); // ['web', 'api']
$files = module_route_files();        // 当前模块
```

---

## 七、Stub 与工具

### module_stub()

创建模块 Stub 生成器实例。

```php
function module_stub(string $module): StubGenerator
```

```php
$generator = module_stub('Blog');

$content = $generator->render('controller', [
    'CLASS_NAMESPACE' => 'Modules\Blog\Http\Controllers\Web',
    'CLASS' => 'PostController',
]);
```

---

## 八、通用辅助函数

### get_user_info()

获取当前认证用户信息。

```php
/**
 * @param string|null $field 用户字段名（null 返回完整数组）
 * @return mixed
 */
function get_user_info(?string $field = null): mixed
```

```php
$user = get_user_info();          // ['id' => 1, 'name' => '张三', ...]
$name = get_user_info('name');   // '张三'
$id   = get_user_info('id');    // 1
```

### view_share()

向所有视图共享数据。

```php
function view_share(string|array $key, mixed $value = ''): void
```

```php
// 单个变量
view_share('appName', 'My App');

// 批量设置
view_share([
    'appName' => 'My App',
    'version' => '1.0.0',
]);
```

### get_view_share()

获取已共享的视图数据。

```php
/**
 * @param string $key 变量名（为空返回全部）
 * @return mixed
 */
function get_view_share(string $key = ''): mixed
```

### view_exists()

判断视图文件是否存在。

```php
function view_exists(string $view): bool
```

### source_local_website()

判断请求来源地址是否来自本站。

```php
/**
 * @param string $returnType 返回类型: status|url|uri|prefix|all
 * @return bool|array|string|null
 */
function source_local_website(string $returnType = 'all'): bool|array|string|null
```

### after_class_calling()

在类方法调用前执行初始化方法（支持依赖注入）。

```php
/**
 * @param object $class  类实例
 * @param string $method 方法名
 * @param array  ...$args 参数
 */
function after_class_calling(object $class, string $method = 'initialize', array ...$args): void
```

---

## 九、实际应用示例

### 在控制器中使用

```php
namespace Modules\Blog\Http\Controllers\Web;

use Modules\Blog\Models\Post;

class PostController extends Controller
{
    public function index()
    {
        // 获取当前模块名称（自动检测）
        $moduleName = module_name(); // 'Blog'

        // 读取模块配置（自动检测当前模块）
        $perPage = module_config('options.posts_per_page', 15);

        // 获取数据
        $posts = Post::paginate($perPage);

        // 返回模块视图
        return module_view('posts.index', compact('posts'));
        // 等价于 return view('blog::posts.index', compact('posts'));
    }

    public function show($id)
    {
        $post = Post::findOrFail($id);

        // 检查模块是否启用
        if (! module_enabled()) {
            abort(404);
        }

        return module_view('posts.show', compact('post'));
    }

    public function create()
    {
        // 生成模块路由
        $storeUrl = module_route('posts.store');

        return module_view('posts.create', compact('storeUrl'));
    }
}
```

### 在模型中使用

```php
namespace Modules\Blog\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $fillable = ['title', 'content', 'status'];

    protected static function boot()
    {
        parent::boot();

        // 使用模块配置设置默认值
        $defaultStatus = module_config('options.default_status', 'draft');

        static::creating(function ($post) use ($defaultStatus) {
            if (empty($post->status)) {
                $post->status = $defaultStatus;
            }
        });
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

        // 检查维护模式
        $maintenance = module_config('options.maintenance_mode', false);
        if ($maintenance) {
            return response()->json(['message' => '模块维护中'], 503);
        }

        return $next($request);
    }
}
```

### 在 Artisan 命令中使用

```php
namespace Modules\Blog\Console\Commands;

use Illuminate\Console\Command;

class ClearCacheCommand extends Command
{
    protected $signature = 'blog:cache:clear';

    public function handle()
    {
        $moduleName = module_name();
        $this->info("正在清理 {$moduleName} 模块缓存...");

        // 读取缓存键配置
        $cacheKeys = module_config('options.cache_keys', []);

        foreach ($cacheKeys as $key) {
            cache()->forget($key);
            $this->line("  已清理: {$key}");
        }

        $this->info('缓存清理完成！');
        return Command::SUCCESS;
    }
}
```

### 在 Blade 视图中使用

```blade
{{-- Resources/views/layouts/app.blade.php --}}
<!DOCTYPE html>
<html>
<head>
    <title>{{ module_config('options.site_name', module_name()) }}</title>
    <link rel="stylesheet" href="{{ module_asset('css/style.css') }}">
</head>
<body>
    <header>
        <h1>{{ module_lang('messages.app_title') }}</h1>
    </header>

    <main>
        @yield('content')
    </main>

    <script src="{{ module_asset('js/app.js') }}"></script>
</body>
</html>
```

---

## 十、完整函数列表速查

| 函数名 | 说明 | 模块参数位置 |
|--------|------|-------------|
| `module_name()` | 获取当前模块名称 | 无需传入 |
| `module()` | 获取模块实例或仓库 | 第 1 参数（唯一参数） |
| `modules()` | 获取所有模块 | 无参数 |
| `module_exists()` | 检查模块是否存在 | 第 1 参数（必须） |
| `module_enabled()` | 检查模块是否启用 | 第 1 参数（可选） |
| `module_path()` | 获取模块路径 | 第 2 参数（可选） |
| `module_config_path()` | 获取配置文件路径 | 第 2 参数（可选） |
| `module_routes_path()` | 获取路由文件路径 | 第 2 参数（可选） |
| `module_migrations_path()` | 获取迁移目录路径 | 第 1 参数（可选） |
| `module_models_path()` | 获取模型目录路径 | 第 1 参数（可选） |
| `module_controllers_path()` | 获取控制器目录路径 | 第 2 参数（可选） |
| `module_views_path()` | 获取视图目录路径 | 第 1 参数（可选） |
| `module_trans_path()` | 获取翻译目录路径 | 第 1 参数（可选） |
| `module_config()` | 获取配置值 | 第 3 参数（可选） |
| `module_get_config()` | 获取完整配置数组 | 第 2 参数（可选） |
| `module_set_config()` | 设置配置值（运行时） | 第 4 参数（可选） |
| `module_has_config()` | 检查配置是否存在 | 第 3 参数（可选） |
| `module_config_files()` | 获取所有配置文件 | 第 1 参数（可选） |
| `module_namespace()` | 获取命名空间 | 第 1 参数（可选） |
| `module_class()` | 构建完整类名 | 第 2 参数（可选） |
| `module_view()` | 返回视图实例 | 第 3 参数（可选） |
| `module_view_path()` | 获取视图命名空间路径 | 第 2 参数（可选） |
| `module_has_view()` | 检查视图是否存在 | 第 2 参数（可选） |
| `module_route()` | 生成路由 URL | 第 3 参数（可选） |
| `module_route_path()` | 获取路由名称前缀 | 第 2 参数（可选） |
| `module_url()` | 生成模块 URL | 第 2 参数（可选） |
| `module_asset()` | 生成资源 URL | 第 2 参数（可选） |
| `module_lang()` | 获取翻译 | 第 4 参数（可选） |
| `module_enabled_modules()` | 获取已启用模块 | 无参数 |
| `module_disabled_modules()` | 获取已禁用模块 | 无参数 |
| `module_has_migration()` | 检查迁移文件存在 | 第 2 参数（可选） |
| `module_all_migrations()` | 获取所有迁移文件 | 第 1 参数（可选） |
| `module_route_files()` | 获取所有路由文件 | 第 1 参数（可选） |
| `module_stub()` | 创建 Stub 生成器 | 第 1 参数（必须） |
| `get_user_info()` | 获取认证用户信息 | 无 |
| `view_share()` | 向视图共享数据 | 无 |
| `get_view_share()` | 获取已共享视图数据 | 无 |
| `view_exists()` | 判断视图是否存在 | 无 |
| `source_local_website()` | 判断来源是否本站 | 无 |
| `after_class_calling()` | 类方法初始化调用 | 无 |

---

## 十一、最佳实践

### 1. 在模块内部优先使用无参调用

```php
// ✅ 推荐：当前模块自动检测
$perPage = module_config('options.posts_per_page', 15);
return module_view('posts.index', compact('posts'));

// ❌ 不推荐：硬编码模块名
$perPage = module_config('options.posts_per_page', 15, 'Blog');
return module_view('posts.index', compact('posts'), 'Blog');
```

### 2. 提供合理的默认值

```php
// ✅ 推荐
$perPage = module_config('options.posts_per_page', 15);
$cacheTtl = module_config('cache.ttl', 3600);

// ❌ 不推荐
$perPage = module_config('options.posts_per_page'); // 可能返回 null
```

### 3. 在需要跨模块调用时明确传入模块名

```php
// 在 Shop 模块中读取 Blog 模块配置
$blogPostsConfig = module_config('options.posts_per_page', 10, 'Blog');

// 跨模块视图引用
return module_view('components.product-card', $data, 'Shop');
```

### 4. 善用配置层级

```php
// ✅ 使用点号分隔的嵌套键
$enabled = module_config('settings.cache.enabled', false);

// ❌ 手动层层读取
$config = module_get_config('settings');
$enabled = $config['cache']['enabled'] ?? false;
```

---

## 相关文档

- [配置详解](04-configuration.md) - 配置文件格式与读取方式
- [模块结构](03-module-structure.md) - 模块目录结构说明
- [路由指南](07-routes.md) - 路由相关函数的使用
- [视图使用](08-views.md) - 视图相关函数的使用
