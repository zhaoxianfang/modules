# 模块配置使用指南

## 概述

本指南详细说明如何在模块中使用配置系统，包括智能当前模块检测、配置读取、路径获取等功能。

## 核心功能

### 1. 智能当前模块检测

系统提供 `module_name()` 函数，可以自动检测当前代码所在的模块，无需传递参数。

#### 工作原理

通过调用栈（debug_backtrace）自动分析当前执行代码的文件路径，从中提取模块名称。

#### 使用示例

```php
// 在任何模块代码中使用
class MyController extends Controller
{
    public function index()
    {
        $moduleName = module_name(); // 自动返回 'Blog'

        // 无需传递任何参数
    }
}

// 在模型中使用
class Post extends Model
{
    protected static function boot()
    {
        $moduleName = module_name(); // 自动返回 'Blog'

        // 可以在模型中使用当前模块信息
    }
}
```

### 2. 增强的配置读取

`module_config()` 函数支持两种使用方式：

#### 方式 1：指定模块名称（传统方式）

```php
$value = module_config('Blog', 'config.key', 'default');
```

#### 方式 2：使用当前模块（智能方式）⭐ 推荐

在模块内部，可以使用配置文件路径格式，无需传递模块名：

```php
// 读取 Config/common.php 中的 name 配置
$value = module_config('common.name', 'hello');

// 读取 Config/settings.php 中的 cache 配置
$cache = module_config('settings.cache', true);

// 读取 Config/config.php 中的 enable 配置
$enabled = module_config('config.enable', false);
```

#### 参数说明

- **第一个参数**：可以是以下两种形式之一
  - 模块名称 + 配置键：`'Blog', 'key'`
  - 配置文件路径（推荐）：`'file.key'`

- **第二个参数**：
  - 使用方式 1 时：配置键名
  - 使用方式 2 时：默认值

- **第三个参数**：
  - 使用方式 1 时：默认值
  - 使用方式 2 时：不使用（或作为额外的默认值）

#### 配置文件结构示例

```
Modules/Blog/Config/
├── config.php          # 主配置文件
├── common.php          # 通用配置
├── settings.php        # 设置配置
└── database.php        # 数据库配置
```

#### 配置文件内容示例

```php
// Config/common.php
<?php

return [
    'name' => 'My Blog',
    'description' => 'A blog module',
    'version' => '1.0.0',
];

// Config/settings.php
<?php

return [
    'cache' => true,
    'debug' => false,
    'items_per_page' => 20,
    'options' => [
        'enable_comments' => true,
        'enable_likes' => true,
    ],
];

// Config/database.php
<?php

return [
    'connection' => 'mysql',
    'table_prefix' => 'blog_',
];
```

#### 使用示例

```php
class BlogController extends Controller
{
    public function index()
    {
        // 获取博客名称
        $blogName = module_config('common.name', 'Default Blog');
        // 返回: 'My Blog'

        // 获取缓存配置
        $cacheEnabled = module_config('settings.cache', false);
        // 返回: true

        // 获取每页数量
        $perPage = module_config('settings.items_per_page', 10);
        // 返回: 20

        // 获取嵌套配置
        $commentsEnabled = module_config('settings.options.enable_comments', false);
        // 返回: true

        // 获取数据库连接
        $connection = module_config('database.connection', 'default');
        // 返回: 'mysql'
    }
}
```

### 3. 路径助手函数

#### 模块路径

```php
// 自动使用当前模块
$path = module_path('Models/Post.php');
// 返回: /path/to/Modules/Blog/Models/Post.php

// 指定模块
$path = module_path('Blog', 'Models/Post.php');
// 返回: /path/to/Modules/Blog/Models/Post.php
```

#### 配置文件路径

```php
// 自动使用当前模块
$configPath = module_config_path('common.php');
// 返回: /path/to/Modules/Blog/Config/common.php

// 指定模块
$configPath = module_config_path('Blog', 'common.php');
// 返回: /path/to/Modules/Blog/Config/common.php
```

#### 其他路径助手

```php
// 路由文件路径
module_routes_path('web');          // Routes/web.php

// 迁移目录路径
module_migrations_path();           // Database/Migrations

// 模型目录路径
module_models_path();               // Models

// 控制器目录路径
module_controllers_path('Web');     // Http/Controllers/Web

// 视图目录路径
module_views_path();               // Resources/views
```

### 4. 配置检查

```php
// 检查配置项是否存在
if (module_has_config('common', 'name')) {
    // 配置项存在
}

if (module_has_config('settings', 'options.enable_comments')) {
    // 嵌套配置项存在
}
```

## 实际应用场景

### 场景 1：控制器中读取配置

```php
namespace Modules\Blog\Http\Controllers\Web;

use Illuminate\Http\Request;

class PostController extends Controller
{
    public function index()
    {
        // 获取当前模块
        $moduleName = module_name(); // 'Blog'

        // 读取每页数量配置
        $perPage = module_config('settings.items_per_page', 10);

        // 分页查询
        $posts = Post::paginate($perPage);

        return view('post.index', compact('posts'));
    }
}
```

### 场景 2：模型中使用配置

```php
namespace Modules\Blog\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $fillable = ['title', 'content', 'status'];

    protected static function boot()
    {
        parent::boot();

        // 根据配置自动设置状态
        $defaultStatus = module_config('common.default_status', 'draft');

        static::creating(function ($post) use ($defaultStatus) {
            if (empty($post->status)) {
                $post->status = $defaultStatus;
            }
        });

        // 根据配置添加事件监听
        $cacheEnabled = module_config('settings.cache', false);

        if ($cacheEnabled) {
            static::saved(function ($post) {
                // 缓存逻辑
            });
        }
    }
}
```

### 场景 3：中间件中检查配置

```php
namespace Modules\Blog\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class MaintenanceCheck
{
    public function handle(Request $request, Closure $next)
    {
        // 检查模块是否启用
        if (! module_enabled()) {
            return redirect()->route('home');
        }

        // 检查维护模式
        $maintenanceMode = module_config('config.maintenance', false);

        if ($maintenanceMode) {
            return response('系统维护中，请稍后再试', 503);
        }

        return $next($request);
    }
}
```

### 场景 4：视图中读取配置

```blade.php
{{-- 在 Blade 模板中 --}}
@php
    $blogName = module_config('common.name', 'Blog');
    $description = module_config('common.description', 'Description');
@endphp

<h1>{{ $blogName }}</h1>
<p>{{ $description }}</p>

{{-- 或者在服务提供者中全局共享 --}}
```

### 场景 5：命令中使用配置

```php
namespace Modules\Blog\Console\Commands;

use Illuminate\Console\Command;

class ProcessPosts extends Command
{
    protected $signature = 'blog:process';
    protected $description = '处理博客文章';

    public function handle()
    {
        // 获取当前模块
        $moduleName = module_name(); // 'Blog'

        $this->info("正在处理 {$moduleName} 模块的文章...");

        // 读取配置
        $batchSize = module_config('settings.batch_size', 100);

        // 处理逻辑
        // ...

        $this->info('处理完成！');
    }
}
```

## 配置最佳实践

### 1. 配置文件命名

- 使用有意义的文件名：`config.php`, `settings.php`, `common.php`
- 避免使用过于通用的名称
- 按功能分组配置文件

### 2. 配置键命名

- 使用点号分隔的键名：`cache.enabled`, `api.timeout`
- 使用有意义的名称
- 保持一致性

### 3. 默认值

- 始终为配置项提供合理的默认值
- 在 `module_config()` 调用中指定默认值
- 确保应用在没有配置时也能正常运行

### 4. 配置验证

```php
// 在服务提供者中验证配置
public function boot()
{
    // 验证必需的配置
    if (! module_has_config('common', 'name')) {
        throw new \RuntimeException('缺少必需的配置项: common.name');
    }
}
```

## 高级技巧

### 1. 动态配置

```php
// 运行时修改配置
config(['blog.settings.cache' => false]);

// 读取修改后的配置
$cache = module_config('settings.cache', true); // false
```

### 2. 配置继承

```php
// 在子模块中继承父模块配置
$parentConfig = module_config('common', []);

// 扩展配置
$extendedConfig = array_merge($parentConfig, [
    'additional_key' => 'value',
]);
```

### 3. 配置缓存

```php
// 在生产环境缓存配置
if (app()->environment('production')) {
    $cachedConfig = cache('blog.config');

    if (! $cachedConfig) {
        $cachedConfig = [
            'name' => module_config('common.name', 'Blog'),
            'cache' => module_config('settings.cache', true),
        ];
        cache()->put('blog.config', $cachedConfig, 3600);
    }
}
```

## 常见问题

### Q: module_name() 返回 null？

A: 确保代码在模块目录中执行，且 `config/modules.php` 中的路径配置正确。

### Q: module_config('common.name', 'hello') 读取不到配置？

A: 检查以下事项：
1. 确认配置文件存在于 `Config/common.php`
2. 确认配置文件返回数组
3. 确认配置键名正确

### Q: 如何在非模块代码中使用这些函数？

A: 需要传递模块名称作为参数：

```php
$moduleName = 'Blog';
$value = module_config($moduleName . '.common.name', 'default');
```

### Q: 配置修改后不生效？

A: 清除配置缓存：

```bash
php artisan config:clear
```

## 总结

本模块系统提供了强大的配置管理功能：

1. ✅ 智能当前模块检测
2. ✅ 灵活的配置读取方式
3. ✅ 丰富的路径助手函数
4. ✅ 完善的配置检查功能
5. ✅ 无需传递参数的便捷使用方式

通过合理使用这些功能，可以大大简化模块开发工作，提高代码可维护性。
