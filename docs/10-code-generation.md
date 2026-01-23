# 代码生成命令

本文档详细介绍模块系统提供的代码生成命令，包括控制器、模型、迁移、视图、请求类等的自动生成。

## 代码生成命令一览表

### 模块管理命令（2个）

| 命令 | 说明 | 用法 |
|------|------|------|
| `module:make` | 创建新模块 | `php artisan module:make <name> [--force]` |
| `module:delete` | 删除模块 | `php artisan module:delete <name> [--force]` |

### 代码生成命令（13个）

| 命令 | 说明 | 用法 |
|------|------|------|
| `module:make-controller` | 创建控制器 | `php artisan module:make-controller <module> <name> [--type=web] [--force] [--plain]` |
| `module:make-model` | 创建模型 | `php artisan module:make-model <module> <name> [--migration] [--factory] [--force]` |
| `module:make-migration` | 创建迁移 | `php artisan module:make-migration <module> <name> [--create=] [--update=] [--path=] [--realpath] [--fullpath]` |
| `module:make-request` | 创建请求类 | `php artisan module:make-request <module> <name> [--force]` |
| `module:make-view` | 创建视图 | `php artisan module:make-view <module> <name> [--force]` |
| `module:make-middleware` | 创建中间件 | `php artisan module:make-middleware <module> <name> [--force]` |
| `module:make-event` | 创建事件类 | `php artisan module:make-event <module> <name> [--force]` |
| `module:make-listener` | 创建监听器 | `php artisan module:make-listener <module> <name> [--event=] [--force]` |
| `module:make-provider` | 创建服务提供者 | `php artisan module:make-provider <module> <name> [--force]` |
| `module:make-seeder` | 创建数据填充器 | `php artisan module:make-seeder <module> <name> [--force]` |
| `module:make-command` | 创建命令 | `php artisan module:make-command <module> <name> [--command=] [--force]` |
| `module:make-route` | 创建路由文件 | `php artisan module:make-route <module> <name> [--type=web] [--force]` |
| `module:make-config` | 创建配置文件 | `php artisan module:make-config <module> <name> [--force]` |

**⭐ 重要说明**：
- `--type` 选项不再限制于 web/api/admin，支持任意自定义类型（如 mobile、miniapp、admin 等）
- 默认值为 `web`，但可以根据项目需求自定义

### 迁移管理命令（4个）

| 命令 | 说明 | 用法 |
|------|------|------|
| `module:migrate` | 运行迁移 | `php artisan module:migrate [module] [--force] [--path=] [--seed] [--seeder=]` |
| `module:migrate-reset` | 回滚迁移 | `php artisan module:migrate-reset [module] [--force] [--path=]` |
| `module:migrate-refresh` | 刷新迁移 | `php artisan module:migrate-refresh [module] [--force] [--seed] [--seeder=]` |
| `module:migrate-status` | 查看迁移状态 | `php artisan module:migrate-status [module] [--path=]` |

---

## 目录

1. [代码生成概述](#代码生成概述)
2. [控制器生成](#控制器生成)
3. [模型生成](#模型生成)
4. [迁移生成](#迁移生成)
5. [请求类生成](#请求类生成)
6. [视图生成](#视图生成)
7. [中间件生成](#中间件生成)
8. [事件和监听器生成](#事件和监听器生成)
9. [服务提供者生成](#服务提供者生成)
10. [数据填充器生成](#数据填充器生成)
11. [命令生成](#命令生成)
12. [路由生成](#路由生成)
13. [配置生成](#配置生成)
14. [代码生成最佳实践](#代码生成最佳实践)

---

## 代码生成概述

### 什么是代码生成

代码生成命令（也称为 Artisan 生成器）可以快速创建常用的代码文件，包括：

- 控制器（Controllers）
- 模型（Models）
- 迁移（Migrations）
- 请求类（Requests）
- 视图（Views）
- 中间件（Middleware）
- 事件（Events）
- 监听器（Listeners）
- 服务提供者（Providers）
- 数据填充器（Seeders）
- 命令（Commands）
- 路由（Routes）
- 配置（Config）

### 代码生成的好处

- ✅ **快速开发**：无需手动创建文件和编写样板代码
- ✅ **统一规范**：遵循项目约定和最佳实践
- ✅ **减少错误**：避免语法错误和命名不规范
- ✅ **提高效率**：专注于业务逻辑而非文件结构

### 模块代码生成特点

- **模块隔离**：每个模块的代码独立生成，互不干扰
- **智能识别**：自动识别操作类型（如迁移命名规范）
- **灵活配置**：支持各种选项和自定义
- **自动发现**：生成的代码自动注册到系统

---

## 控制器生成

### 命令

```bash
php artisan module:make-controller <module> <name> [--type=web] [--force]
```

### 参数

- `module`：模块名称（必需）
- `name`：控制器名称（必需）

### 选项

| 选项 | 说明 | 默认值 |
|-----|------|-------|
| `--type=<type>` | 控制器类型（可自定义，如 web、api、admin、mobile 等） | `web` |
| `--force` | 覆盖已存在的控制器 | false |
| `--plain` | 创建空控制器（无 CRUD 方法） | false |

### 示例

#### 创建 Web 控制器

```bash
php artisan module:make-controller Blog PostController
```

生成文件：`Modules/Blog/Http/Controllers/PostController.php`

#### 创建 API 控制器

```bash
php artisan module:make-controller Blog PostController --type=api
```

生成文件：`Modules/Blog/Http/Controllers/Api/PostController.php`

#### 创建 Admin 控制器

```bash
php artisan module:make-controller Blog PostController --type=admin
```

生成文件：`Modules/Blog/Http/Controllers/Admin/PostController.php`

#### 创建自定义类型控制器

```bash
php artisan module:make-controller Blog PostController --type=mobile
```

生成文件：`Modules/Blog/Http/Controllers/Mobile/PostController.php`

**支持任意自定义类型**：根据项目需求，可以创建任意类型的控制器（如 mobile、miniapp、api、admin 等）

### 生成的控制器结构

```php
<?php

namespace Modules\Blog\Http\Controllers;

use Illuminate\Http\Request;

class PostController extends Controller
{
    public function index()
    {
        // 列表逻辑
    }

    public function create()
    {
        // 创建表单
    }

    public function store(Request $request)
    {
        // 保存逻辑
    }

    public function show($id)
    {
        // 显示逻辑
    }

    public function edit($id)
    {
        // 编辑表单
    }

    public function update(Request $request, $id)
    {
        // 更新逻辑
    }

    public function destroy($id)
    {
        // 删除逻辑
    }
}
```

### 控制器类型说明

| 类型 | 目录 | 中间件 | 用途 |
|-----|------|--------|------|
| `web` | `Http/Controllers/` | `web` | Web 应用控制器 |
| `api` | `Http/Controllers/Api/` | `api` | API 控制器 |
| `admin` | `Http/Controllers/Admin/` | `web,admin` | 后台管理控制器 |

---

## 模型生成

### 命令

```bash
php artisan module:make-model <module> <name> [--table=] [--migration] [--factory] [--force]
```

### 参数

- `module`：模块名称（必需，首字母大写）
- `name`：模型名称（必需，首字母大写）

### 选项

| 选项 | 说明 | 默认值 |
|-----|------|-------|
| `--table` | 从现有数据库表生成模型，自动解析字段信息 | 无 |
| `--migration` | 创建对应的迁移文件 | false |
| `--factory` | 同时创建对应的数据工厂类 | false |
| `--force` | 覆盖已存在的模型 | false |

### 示例

#### 创建基础模型

```bash
php artisan module:make-model Blog Post
```

生成文件：`Modules/Blog/Models/Post.php`

#### 从数据库表生成模型

```bash
php artisan module:make-model Logs SystemLogs --table=system_logs
```

这将自动：
- 解析 `system_logs` 表的所有字段
- 生成完整的 PHPDoc 属性注释
- 生成 `fillable` 属性
- 生成 `casts()` 方法
- 生成 `attributes` 属性
- datetime/timestamp 字段类型使用 `\Carbon\Carbon`

#### 创建模型并生成迁移

```bash
php artisan module:make-model Blog Post --migration
```

同时生成：
- `Modules/Blog/Models/Post.php`
- `Modules/Blog/Database/Migrations/2024_01_20_120000_create_posts_table.php`

### 从数据库表生成的模型结构

```php
<?php

namespace Modules\Logs;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * SystemLogs 模型
 *
 * Logs 模块的数据模型
 * 继承 Eloquent Model，提供完整的数据库操作能力
 *
 * @property integer $id 主键 ID
 * @property integer $user_id 用户 ID
 * @property string|null $channel 请求通道
 * @property \Carbon\Carbon|null $created_at 创建时间
 * @property \Carbon\Carbon|null $updated_at 更新时间
 */
class SystemLogs extends Model
{
    use HasFactory;

    protected $table = 'system_logs';

    public $timestamps = true;

    /**
     * 可批量赋值的属性
     *
     * 这些属性可以通过 create()、update()、fill() 方法批量赋值
     * 出于安全考虑，只列出允许批量赋值的字段
     *
     * @var array<int, string>
     */
    protected $fillable = [ 'user_id', 'channel', 'ip', 'method', 'url', 'level', 'message', 'is_crawler', 'context', 'extra', 'user_agent' ];

    /**
     * 获取需要被类型转换的属性。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'user_id' => 'integer',
            'channel' => 'string',
            // ... 其他字段
            'context' => 'array',
            'extra' => 'array',
            'created_at' => 'datetime:Y-m-d H:i:s',
            'updated_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    /**
     * 默认属性值
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'user_id' => 0,
        'level' => 200,
        'is_crawler' => 0,
    ];
}
```

### 模型生成特性

#### 1. 自动字段类型映射

系统自动将数据库字段类型映射到 Laravel Eloquent 支持的转换类型：

| 数据库类型 | Laravel 类型 |
|----------|------------|
| int, integer, tinyint, smallint, mediumint, bigint | integer |
| float, double, decimal | float, decimal |
| char, varchar, text | string |
| date, datetime, timestamp | datetime |
| json, jsonb | array |
| boolean, bool | boolean |

#### 2. Carbon 集成

- 所有 `date`、`datetime`、`timestamp` 字段类型自动使用 `\Carbon\Carbon`
- 在 PHPDoc 中正确标注，IDE 智能提示

#### 3. 字段注释解析

自动从数据库表读取字段注释，并生成到模型的 PHPDoc 中：

```sql
CREATE TABLE `users` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '用户 ID',
    `name` varchar(255) NOT NULL COMMENT '用户名称',
    `email` varchar(255) NOT NULL COMMENT '用户邮箱'
)
```

生成的模型：

```php
/**
 * @property integer $id 用户 ID
 * @property string $name 用户名称
 * @property string $email 用户邮箱
 */
```

#### 4. 智能默认值

自动识别并设置字段的默认值到 `$attributes` 属性中。

#### 5. 自动排除字段

以下字段自动从 `fillable` 中排除：
- 主键（`id`）
- 时间戳字段（`created_at`, `updated_at`）
- 自动递增字段

### 生成的模型结构
     *
     * @var array
     */
    protected $fillable = [
        'title',
        'content',
        // ...
    ];

    /**
     * 隐藏的属性
     *
     * @var array
     */
    protected $hidden = [
        // ...
    ];

    /**
     * 属性转换
     *
     * @var array
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 模型关联
     */
    // public function user()
    // {
    //     return $this->belongsTo(User::class);
    // }
}
```

---

## 迁移生成

### 命令

```bash
php artisan module:make-migration <module> <name> [--create=] [--table=]
```

### 参数

- `module`：模块名称（必需）
- `name`：迁移名称（必需）

### 选项

| 选项 | 说明 | 默认值 |
|-----|------|-------|
| `--create=` | 要创建的表名（已弃用，请使用命名规范） | - |
| `--table=` | 要修改的表名（已弃用，请使用命名规范） | - |

### 命名规范

模块系统的迁移生成支持 Laravel 11+ 的智能命名规范：

| 操作类型 | 命名格式 | 示例 |
|---------|-----------|------|
| 创建表 | `create_{table}_table` | `create_users_table` |
| 删除表 | `drop_{table}_table` | `drop_users_table` |
| 重命名表 | `rename_{old}_to_{new}_table` | `rename_users_to_customers_table` |
| 添加字段 | `add_{field}_to_{table}_table` | `add_email_to_users_table` |
| 删除字段 | `drop_{field}_from_{table}_table` | `drop_email_from_users_table` |
| 修改字段 | `change_{field}_in_{table}_table` | `change_email_in_users_table` |
| 添加索引 | `add_{index}_index_on_{table}_table` | `add_email_index_on_users_table` |
| 删除索引 | `drop_{index}_index_on_{table}_table` | `drop_email_index_on_users_table` |

### 示例

#### 创建用户表

```bash
php artisan module:make-migration Blog create_users_table
```

#### 添加字段

```bash
php artisan module:make-migration Blog add_email_to_users_table
```

#### 删除字段

```bash
php artisan module:make-migration Blog drop_email_from_users_table
```

更多详情请参考：[数据库迁移](11-migrations.md)

---

## 请求类生成

### 命令

```bash
php artisan module:make-request <module> <name> [--force]
```

### 参数

- `module`：模块名称（必需）
- `name`：请求类名称（必需）

### 选项

| 选项 | 说明 | 默认值 |
|-----|------|-------|
| `--force` | 覆盖已存在的请求类 | false |

### 示例

#### 创建请求类

```bash
php artisan module:make-request Blog StorePostRequest
```

生成文件：`Modules/Blog/Http/Requests/StorePostRequest.php`

### 生成的请求类结构

```php
<?php

namespace Modules\Blog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePostRequest extends FormRequest
{
    /**
     * 验证规则
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            // ...
        ];
    }

    /**
     * 验证错误消息
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'title.required' => '标题是必需的',
            'title.max' => '标题不能超过 255 个字符',
            // ...
        ];
    }

    /**
     * 验证属性名称
     *
     * @return array
     */
    public function attributes(): array
    {
        return [
            'title' => '标题',
            'content' => '内容',
            // ...
        ];
    }
}
```

### 请求类命名约定

通常使用以下前缀：

- `Store{Model}Request`：创建请求
- `Update{Model}Request`：更新请求
- `Destroy{Model}Request`：删除请求

示例：
```bash
php artisan module:make-request Blog StorePostRequest
php artisan module:make-request Blog UpdatePostRequest
php artisan module:make-request Blog DestroyPostRequest
```

---

## 视图生成

### 命令

```bash
php artisan module:make-view <module> <name> [--type=index] [--force]
```

### 参数

- `module`：模块名称（必需）
- `name`：视图名称（必需）

### 选项

| 选项 | 说明 | 默认值 |
|-----|------|-------|
| `--type=index\|show` | 视图类型 | `index` |
| `--force` | 覆盖已存在的视图 | false |

### 示例

#### 创建列表视图

```bash
php artisan module:make-view Blog posts
```

生成文件：`Modules/Blog/Resources/views/posts/index.blade.php`

#### 创建详情视图

```bash
php artisan module:make-view Blog posts --type=show
```

生成文件：`Modules/Blog/Resources/views/posts/show.blade.php`

### 生成的视图结构

```blade
<!-- Index View -->
@extends('blog::layouts.app')

@section('content')
<div class="container">
    <h1>Posts</h1>
    
    @forelse($posts as $post)
        <div class="post">
            <h2>{{ $post->title }}</h2>
            <p>{{ $post->excerpt }}</p>
            <a href="{{ route('posts.show', $post->id) }}">查看详情</a>
        </div>
    @empty
        <p>暂无文章</p>
    @endforelse
</div>
@endsection

<!-- Show View -->
@extends('blog::layouts.app')

@section('content')
<div class="container">
    <h1>{{ $post->title }}</h1>
    
    <div class="content">
        {!! $post->content !!}
    </div>
    
    <div class="meta">
        <span>创建时间：{{ $post->created_at->format('Y-m-d H:i') }}</span>
    </div>
</div>
@endsection
```

---

## 中间件生成

### 命令

```bash
php artisan module:make-middleware <module> <name> [--force]
```

### 参数

- `module`：模块名称（必需）
- `name`：中间件名称（必需）

### 选项

| 选项 | 说明 | 默认值 |
|-----|------|-------|
| `--force` | 覆盖已存在的中间件 | false |

### 示例

#### 创建中间件

```bash
php artisan module:make-middleware Blog CheckAuth
```

生成文件：`Modules/Blog/Http/Middleware/CheckAuth.php`

### 生成的中间件结构

```php
<?php

namespace Modules\Blog\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckAuth
{
    /**
     * 处理请求
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // 中间件逻辑
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        return $next($request);
    }
}
```

---

## 事件和监听器生成

### 事件生成

#### 命令

```bash
php artisan module:make-event <module> <name> [--force]
```

#### 示例

```bash
php artisan module:make-event Blog PostCreated
```

生成文件：`Modules/Blog/Events/PostCreated.php`

#### 生成的事件结构

```php
<?php

namespace Modules\Blog\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;

class PostCreated
{
    use SerializesModels;

    public $post;

    public function __construct($post)
    {
        $this->post = $post;
    }
}
```

### 监听器生成

#### 命令

```bash
php artisan module:make-listener <module> <name> [--event=] [--force]
```

#### 参数

- `module`：模块名称（必需）
- `name`：监听器名称（必需）

#### 选项

| 选项 | 说明 | 默认值 |
|-----|------|-------|
| `--event=` | 要监听的事件类 | - |
| `--force` | 覆盖已存在的监听器 | false |

#### 示例

```bash
php artisan module:make-listener Blog SendPostNotification --event=PostCreated
```

生成文件：`Modules/Blog/Listeners/SendPostNotification.php`

#### 生成的监听器结构

```php
<?php

namespace Modules\Blog\Listeners;

use Modules\Blog\Events\PostCreated;

class SendPostNotification
{
    /**
     * 处理事件
     *
     * @param  PostCreated  $event
     * @return void
     */
    public function handle(PostCreated $event)
    {
        // 监听器逻辑
        $post = $event->post;
        
        // 发送通知
    }
}
```

---

## 服务提供者生成

### 命令

```bash
php artisan module:make-provider <module> <name> [--force]
```

### 参数

- `module`：模块名称（必需）
- `name`：服务提供者名称（必需）

### 选项

| 选项 | 说明 | 默认值 |
|-----|------|-------|
| `--force` | 覆盖已存在的服务提供者 | false |

### 示例

#### 创建服务提供者

```bash
php artisan module:make-provider Blog EventServiceProvider
```

生成文件：`Modules/Blog/Providers/EventServiceProvider.php`

#### 生成的服务提供者结构

```php
<?php

namespace Modules\Blog\Providers;

use Illuminate\Support\ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * 注册服务
     *
     * @return void
     */
    public function register(): void
    {
        // 注册服务
    }

    /**
     * 启动服务
     *
     * @return void
     */
    public function boot(): void
    {
        // 启动服务
    }
}
```

---

## 数据填充器生成

### 命令

```bash
php artisan module:make-seeder <module> <name> [--force]
```

### 参数

- `module`：模块名称（必需）
- `name`：填充器名称（必需）

### 选项

| 选项 | 说明 | 默认值 |
|-----|------|-------|
| `--force` | 覆盖已存在的填充器 | false |

### 示例

#### 创建数据填充器

```bash
php artisan module:make-seeder Blog PostSeeder
```

生成文件：`Modules/Blog/Database/Seeders/PostSeeder.php`

#### 生成的数据填充器结构

```php
<?php

namespace Modules\Blog\Database\Seeders;

use Modules\Blog\Models\Post;
use Illuminate\Database\Seeder;

class PostSeeder extends Seeder
{
    /**
     * 运行数据填充
     *
     * @return void
     */
    public function run(): void
    {
        // 清空现有数据
        Post::query()->delete();

        // 创建示例数据
        Post::factory()->count(10)->create();
    }
}
```

---

## 命令生成

### 命令

```bash
php artisan module:make-command <module> <name> [--command=] [--force]
```

### 参数

- `module`：模块名称（必需）
- `name`：命令类名称（必需）

### 选项

| 选项 | 说明 | 默认值 |
|-----|------|-------|
| `--command=` | 命令签名 | 自动生成 |
| `--force` | 覆盖已存在的命令 | false |

### 示例

#### 创建命令

```bash
php artisan module:make-command Blog SyncPosts
```

生成文件：`Modules/Blog/Console/Commands/SyncPosts.php`

#### 创建命令并指定签名

```bash
php artisan module:make-command Blog SyncPosts --command="blog:sync"
```

#### 生成的命令结构

```php
<?php

namespace Modules\Blog\Console\Commands;

use Illuminate\Console\Command;

class SyncPosts extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'blog:sync';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '同步博客文章';

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('开始同步文章...');

        // 业务逻辑

        $this->info('同步完成！');

        return Command::SUCCESS;
    }
}
```

---

## 路由生成

### 命令

```bash
php artisan module:make-route <module> <name> [--type=<type>] [--force]
```

### 参数

- `module`：模块名称（必需）
- `name`：路由文件名称（必需）

### 选项

| 选项 | 说明 | 默认值 |
|-----|------|-------|
| `--type=<type>` | 路由类型（可自定义，如 web、api、admin、mobile 等） | `web` |
| `--force` | 覆盖已存在的路由文件 | false |

### 示例

#### 创建 Web 路由文件

```bash
php artisan module:make-route Blog web
```

生成文件：`Modules/Blog/Routes/web.php`

#### 创建 API 路由文件

```bash
php artisan module:make-route Blog api --type=api
```

生成文件：`Modules/Blog/Routes/api.php`

#### 创建自定义类型路由文件

```bash
php artisan module:make-route Blog mobile --type=mobile
```

生成文件：`Modules/Blog/Routes/mobile.php`

**支持任意自定义类型**：不再限制于 web/api/admin，可以创建任意类型的路由文件

#### 生成的路由文件结构

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Blog\Http\Controllers\Mobile\PostController;

Route::middleware(['web'])
    ->prefix('blog/mobile')
    ->name('blog.mobile.')
    ->group(function () {
        Route::get('/posts', [PostController::class, 'index'])->name('posts.index');
        Route::get('/posts/{id}', [PostController::class, 'show'])->name('posts.show');
    });
```

---

## 配置生成

### 命令

```bash
php artisan module:make-config <module> <name> [--force]
```

### 参数

- `module`：模块名称（必需）
- `name`：配置文件名称（必需）

### 选项

| 选项 | 说明 | 默认值 |
|-----|------|-------|
| `--force` | 覆盖已存在的配置文件 | false |

### 示例

#### 创建配置文件

```bash
php artisan module:make-config Blog settings
```

生成文件：`Modules/Blog/Config/settings.php`

#### 生成的配置文件结构

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 模块设置
    |--------------------------------------------------------------------------
    |
    | 这里是模块的各种设置选项。
    |
    */

    'enabled' => env('BLOG_ENABLED', true),

    'pagination' => [
        'per_page' => env('BLOG_PER_PAGE', 15),
    ],

    'cache' => [
        'enabled' => env('BLOG_CACHE_ENABLED', false),
        'ttl' => env('BLOG_CACHE_TTL', 3600),
    ],
];
```

---

## 代码生成最佳实践

### 1. 命名规范

使用清晰的命名约定：

**推荐**：
```bash
php artisan module:make-controller Blog PostController
php artisan module:make-model Blog Post
php artisan module:make-request Blog StorePostRequest
```

**不推荐**：
```bash
php artisan module:make-controller Blog Controller1
php artisan module:make-model Blog Model1
php artisan module:make-request Blog Request1
```

### 2. 使用 --force 选项

`--force` 选项可以覆盖已存在的文件，但使用时需谨慎：

```bash
# 确认后再覆盖
php artisan module:make-controller Blog PostController --force
```

### 3. 分步生成

对于复杂的资源，分步生成各个部分：

**推荐**：
```bash
php artisan module:make-model Blog Post --migration
php artisan module:make-controller Blog PostController
php artisan module:make-request Blog StorePostRequest
php artisan module:make-request Blog UpdatePostRequest
```

**不推荐**：
```bash
# 一次性生成所有内容（如果命令支持）
```

### 4. 立即完善生成的代码

生成的代码只是基础框架，需要立即根据需求完善：

```php
// 生成的代码
public function rules(): array
{
    return [
        'title' => 'required|string|max:255',
        'content' => 'required|string',
    ];
}

// 立即完善
public function rules(): array
{
    return [
        'title' => 'required|string|max:255|unique:posts',
        'content' => 'required|string|min:10',
        'category_id' => 'required|exists:categories,id',
        'published_at' => 'nullable|date',
    ];
}
```

### 5. 版本控制

将生成的文件提交到版本控制系统：

```bash
git add Modules/Blog/
git commit -m "Generate Post controller and model"
```

### 6. 自定义 Stubs

如果需要自定义生成的代码模板，可以修改 stub 文件：

```
src/Commands/stubs/
├── controller.stub
├── model.stub
├── migration.stub
└── ...
```

---

## 常见问题

### Q1: 生成的代码可以直接使用吗？

**A**: 可以，但建议根据实际需求进行修改和完善。生成的代码只是一个基础框架。

### Q2: 如何批量生成多个文件？

**A**: 可以使用脚本或组合命令：

```bash
# 创建完整的 CRUD 功能
php artisan module:make-model Blog Post --migration
php artisan module:make-controller Blog PostController
php artisan module:make-request Blog StorePostRequest
php artisan module:make-request Blog UpdatePostRequest
```

### Q3: 生成的文件可以移动吗？

**A**: 可以，但需要修改命名空间和引用。建议使用生成命令在正确位置创建文件。

### Q4: 如何自定义生成的代码模板？

**A**: 修改 `src/Commands/stubs/` 目录下的对应 `.stub` 文件。

### Q5: `--force` 选项会删除原有代码吗？

**A**: 是的。`--force` 选项会完全覆盖现有文件，使用前请备份重要代码。

---

## 相关文档

- [数据库迁移](11-migrations.md) - 数据库迁移的详细说明
- [命令参考](09-commands.md) - 所有可用命令的详细说明
- [模块结构](03-module-structure.md) - 模块结构说明
