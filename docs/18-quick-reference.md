# Laravel 模块系统 - 快速参考

本文档提供了 Laravel 模块系统的快速参考，包括常用命令、函数和配置。

## 🚀 快速开始

### 安装与配置

```bash
# 1. 安装
composer require zxf/modules

# 2. 发布配置
php artisan vendor:publish --provider="zxf\\Modules\\ModulesServiceProvider"

# 3. 创建模块
php artisan module:make Blog

# 4. 运行迁移
php artisan module:migrate
```

## 📝 命令速查表

### 模块管理

| 命令 | 说明 | 示例 |
|------|------|------|
| `module:make` | 创建新模块 | `php artisan module:make Blog [--full]` |
| `module:list` | 列出所有模块 | `php artisan module:list` |
| `module:info` | 查看模块详情 | `php artisan module:info Blog` |
| `module:validate` | 验证模块完整性 | `php artisan module:validate Blog` |
| `module:delete` | 删除模块 | `php artisan module:delete Blog` |

### 代码生成

| 命令 | 说明 | 示例 |
|------|------|------|
| `module:make-controller` | 创建控制器 | `php artisan module:make-controller Blog PostController` |
| `module:make-model` | 创建模型 | `php artisan module:make-model Blog Post` |
| `module:make-request` | 创建请求验证 | `php artisan module:make-request Blog PostRequest` |
| `module:make-migration` | 创建迁移 | `php artisan module:make-migration Blog create_posts_table` |
| `module:make-seeder` | 创建数据填充器 | `php artisan module:make-seeder Blog PostSeeder` |
| `module:make-event` | 创建事件 | `php artisan module:make-event Blog PostCreated` |
| `module:make-listener` | 创建监听器 | `php artisan module:make-listener Blog PostListener` |
| `module:make-middleware` | 创建中间件 | `php artisan module:make-middleware Blog CheckStatus` |
| `module:make-provider` | 创建服务提供者 | `php artisan module:make-provider Blog CustomProvider` |
| `module:make-command` | 创建命令 | `php artisan module:make-command Blog TestCommand --command=blog:test` |
| `module:make-policy` | 创建策略 | `php artisan module:make-policy Blog PostPolicy` |
| `module:make-observer` | 创建观察者 | `php artisan module:make-observer Blog PostObserver` |
| `module:make-route` | 创建路由文件 | `php artisan module:make-route Blog web` |
| `module:make-config` | 创建配置文件 | `php artisan module:make-config Blog settings` |

### 迁移管理

| 命令 | 说明 | 示例 |
|------|------|------|
| `module:migrate` | 运行迁移 | `php artisan module:migrate Blog` |
| `module:migrate-status` | 查看迁移状态 | `php artisan module:migrate-status` |
| `module:migrate:reset` | 回滚迁移 | `php artisan module:migrate:reset Blog` |
| `module:migrate:refresh` | 刷新迁移 | `php artisan module:migrate:refresh Blog` |

### 调试工具

| 命令 | 说明 | 示例 |
|------|------|------|
| `module:debug-commands` | 调试命令注册 | `php artisan module:debug-commands --module=Blog` |

## 🔧 Helper 函数速查表

### 模块信息

```php
// 获取当前模块名称
$moduleName = module_name(); // 'Blog'

// 检查模块是否启用
if (module_enabled()) {
    // 模块已启用
}
```

### 配置读取

```php
// 读取配置（自动检测当前模块）
$name = module_config('common.name', '默认值');

// 读取嵌套配置
$enabled = module_config('settings.cache.enabled', false);

// 指定模块读取
$name = module_config('common.name', '默认值', 'Blog');
```

### 路径获取

```php
// 模块根路径
$path = module_path(); // 当前模块路径
$path = module_path('Blog', 'Models'); // 指定模块

// 配置文件路径
$configPath = module_config_path('common.php');

// 路由文件路径
$routePath = module_routes_path('web.php');

// 迁移目录路径
$migrationPath = module_migrations_path();

// 模型目录路径
$modelsPath = module_models_path();

// 控制器目录路径
$controllersPath = module_controllers_path('Web');

// 视图目录路径
$viewsPath = module_views_path();

// 资源目录路径
$assetsPath = module_resources_path('assets');

// 语言文件路径
$langPath = module_lang_path();
```

### 视图相关

```php
// 返回模块视图
return module_view('post.index', compact('posts'));

// 检查视图是否存在
if (module_has_view('post.index')) {
    // 视图存在
}
```

### 路由相关

```php
// 生成模块路由 URL
$url = module_route('posts.index');
$url = module_route('posts.show', ['id' => 1]);

// 检查路由是否存在
if (module_has_route('posts.index')) {
    // 路由存在
}
```

## 📁 模块目录结构

```
Modules/Blog/
├── Config/              # 配置文件
│   ├── config.php
│   ├── common.php
│   └── settings.php
├── Console/
│   └── Commands/      # Artisan 命令
├── Database/
│   ├── Migrations/     # 数据库迁移
│   └── Seeders/        # 数据填充器
├── Http/
│   ├── Controllers/     # 控制器
│   ├── Middleware/      # 中间件
│   └── Requests/       # 表单请求验证
├── Models/             # 模型
├── Observers/          # 观察者
├── Providers/
│   └── BlogServiceProvider.php  # 服务提供者
├── Resources/
│   ├── assets/         # 静态资源
│   ├── lang/           # 语言文件
│   └── views/         # 视图文件
├── Routes/
│   ├── web.php         # Web 路由
│   ├── api.php         # API 路由
│   └── admin.php       # Admin 路由
├── Events/             # 事件
├── Listeners/          # 监听器
├── Policies/           # 策略类
├── Repositories/       # 仓库类
└── Tests/              # 测试文件
```

## ⚙️ 配置文件参考

```php
// config/modules.php

return [
    // 命名空间
    'namespace' => 'Modules',
    
    // 模块路径
    'path' => base_path('Modules'),
    
    // 中间件组
    'middleware_groups' => [
        'web' => ['web'],
        'api' => ['api'],
        'admin' => ['web', 'admin'],
    ],
    
    // 控制器命名空间映射
    'controller_namespace_map' => [
        'web' => 'Web',
        'api' => 'Api',
        'admin' => 'Admin',
    ],
    
    // 路由配置
    'routes' => [
        'web' => [
            'prefix' => null,
            'as' => null,
        ],
        'api' => [
            'prefix' => 'api',
            'as' => 'api.',
        ],
    ],
    
    // 启用的模块
    'enabled' => [
        'Blog',
        'Admin',
    ],
    
    // 自动发现配置
    'discovery' => [
        'providers' => true,
        'configs' => true,
        'middlewares' => true,
        'routes' => true,
        'views' => true,
        'migrations' => true,
        'translations' => true,
        'commands' => true,
        'events' => true,
        'observers' => true,
        'policies' => true,
        'repositories' => true,
    ],
];
```

## 💡 常用代码片段

### 控制器示例

```php
<?php

namespace Modules\Blog\Http\Controllers\Web;

use Modules\Blog\Models\Post;

class PostController extends Controller
{
    public function index()
    {
        // 获取当前模块名
        $moduleName = module_name(); // 'Blog'
        
        // 读取模块配置
        $perPage = module_config('settings.pagination.per_page', 10);
        
        // 获取路径
        $viewPath = module_views_path();
        
        $posts = Post::paginate($perPage);
        
        // 返回模块视图
        return module_view('post.index', compact('posts'));
    }
    
    public function show($id)
    {
        $post = Post::findOrFail($id);
        
        return module_view('post.show', compact('post'));
    }
}
```

### 路由示例

```php
<?php

// Modules/Blog/Routes/web.php

use Illuminate\Support\Facades\Route;
use Modules\Blog\Http\Controllers\Web\PostController;

Route::prefix('blog')
    ->name('blog.')
    ->middleware(['web'])
    ->group(function () {
        Route::get('/', [PostController::class, 'index'])->name('index');
        Route::get('/posts/{id}', [PostController::class, 'show'])->name('show');
    });
```

### 模型示例

```php
<?php

namespace Modules\Blog\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $fillable = ['title', 'content', 'status'];
    
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }
}
```

### 命令示例

```php
<?php

namespace Modules\Blog\Console\Commands;

use Illuminate\Console\Command;
use Modules\Blog\Models\Post;

class SyncPostsCommand extends Command
{
    protected $signature = 'blog:sync-posts';
    protected $description = '同步博客文章';
    
    public function handle(): int
    {
        $this->info('开始同步文章...');
        
        $posts = Post::all();
        
        foreach ($posts as $post) {
            // 同步逻辑
            $this->line("处理文章: {$post->title}");
        }
        
        $this->info('同步完成！');
        
        return Command::SUCCESS;
    }
}
```

### 迁移示例

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 运行迁移
     */
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->string('status')->default('draft');
            $table->timestamps();
        });
    }

    /**
     * 回滚迁移
     */
    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
```

## 🎯 命名规范

### 命令命名

- 格式：`{模块名小写}:{功能}`
- 示例：
  - `blog:sync-posts`
  - `admin:clean-cache`
  - `shop:import-products`

### 路由命名

- 格式：`{模块名}.{功能}.{动作}`
- 示例：
  - `blog.posts.index`
  - `admin.users.create`
  - `shop.orders.show`

### 视图命名

- 格式：`{模块名}::{视图路径}`
- 示例：
  - `blog::post.index`
  - `admin::user.create`
  - `shop::order.show`

## 🔍 调试技巧

### 启用调试模式

```php
// config/app.php
'debug' => true,
```

### 查看命令注册

```bash
# 调试所有模块的命令
php artisan module:debug-commands

# 调试特定模块的命令
php artisan module:debug-commands --module=Blog
```

### 检查模块信息

```bash
# 列出所有模块
php artisan module:list

# 查看模块详情
php artisan module:info Blog

# 验证模块
php artisan module:validate Blog
```

### 查看日志

```bash
# 实时查看日志
tail -f storage/logs/laravel.log | grep -i "module"
```

## 📚 相关文档

- [完整文档目录](README.md#-文档目录)
- [安装指南](docs/01-installation.md)
- [快速开始](docs/02-quickstart.md)
- [配置详解](docs/04-configuration.md)
- [Helper 函数详解](docs/05-helper-functions.md)
- [路由指南](docs/07-routes.md)
- [视图使用](docs/08-views.md)
- [命令参考](docs/09-commands.md)
- [自动发现机制](docs/14-auto-discovery.md)

## 🆘 常见问题

### Q: 模块命令无法执行？

A: 使用调试命令检查：
```bash
php artisan module:debug-commands --module=YourModule
```

### Q: 配置文件读取不到？

A: 确保配置文件在 `Config/` 目录下：
```
Modules/Blog/Config/common.php
```

### Q: 视图返回 404？

A: 检查视图文件路径和命名：
```php
// 正确
return module_view('post.index', $data);

// 文件路径
Modules/Blog/Resources/views/post/index.blade.php
```

### Q: 路由无法访问？

A: 检查路由文件是否在 `Routes/` 目录下：
```
Modules/Blog/Routes/web.php
```

---

**提示**：本文档是一个快速参考，详细信息请查看相应的完整文档。
