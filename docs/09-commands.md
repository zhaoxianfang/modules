# 命令参考

本文档详细介绍 Laravel 模块系统提供的所有 Artisan 命令。

## 命令概览

### 所有命令列表

| 序号 | 命令                       | 类型   | 说明                     | 示例                                                                           |
|----|--------------------------|------|------------------------|------------------------------------------------------------------------------|
| 1  | `module:make`            | 模块管理 | 创建新模块                  | `php artisan module:make Blog`                                               |
| 2  | `module:list`            | 模块管理 | 列出所有模块及其状态             | `php artisan module:list`                                                    |
| 3  | `module:info`            | 模块管理 | 显示指定模块的详细信息            | `php artisan module:info Blog`                                               |
| 4  | `module:validate`        | 模块管理 | 验证模块的完整性和正确性           | `php artisan module:validate Blog`                                           |
| 5  | `module:delete`          | 模块管理 | 删除一个模块                 | `php artisan module:delete Shop`                                             |
| 6  | `module:cache`          | 模块管理 | 重新扫描并写入模块缓存           | `php artisan module:cache`                                                  |
| 7  | `module:clear`          | 模块管理 | 清除模块缓存                 | `php artisan module:clear`                                                  |
| 8  | `module:publish`         | 模块管理 | 发布多模块系统资源              | `php artisan module:publish --config`                                        |
| 7  | `module:migrate`         | 迁移管理 | 运行所有模块或指定模块的数据库迁移      | `php artisan module:migrate Blog`                                            |
| 8  | `module:migrate-reset`   | 迁移管理 | 回滚所有模块或指定模块的全部迁移      | `php artisan module:migrate-reset Blog`                                      |
| 9  | `module:migrate-fresh`   | 迁移管理 | 清空所有表后重新运行模块迁移         | `php artisan module:migrate-fresh Blog --seed`                               |
| 10 | `module:migrate-refresh` | 迁移管理 | 重置并重新运行模块迁移             | `php artisan module:migrate-refresh Blog --seed`                             |
| 11 | `module:migrate-rollback`| 迁移管理 | 回滚指定步数的模块迁移            | `php artisan module:migrate-rollback Blog --step=2`                          |
| 12 | `module:migrate-status`  | 迁移管理 | 显示所有模块或指定模块的迁移状态       | `php artisan module:migrate-status Blog --pending`                           |
| 13 | `module:seed`            | 数据填充 | 运行指定模块或所有模块的数据填充器      | `php artisan module:seed Blog --class=PostSeeder`                            |
| 11 | `module:make-controller` | 代码生成 | 在指定模块中创建一个控制器          | `php artisan module:make-controller Blog PostController --type=web`          |
| 12 | `module:make-model`      | 代码生成 | 在指定模块中创建一个模型           | `php artisan module:make-model Blog Post --migration`                        |
| 13 | `module:make-migration`  | 代码生成 | 在指定模块中创建一个迁移文件         | `php artisan module:make-migration Blog create_posts_table --create=posts`   |
| 14 | `module:make-request`    | 代码生成 | 在指定模块中创建一个表单请求类        | `php artisan module:make-request Blog StorePostRequest`                      |
| 15 | `module:make-seeder`     | 代码生成 | 在指定模块中创建一个数据填充器        | `php artisan module:make-seeder Blog PostSeeder`                             |
| 16 | `module:make-provider`   | 代码生成 | 在指定模块中创建一个服务提供者        | `php artisan module:make-provider Blog EventServiceProvider`                 |
| 17 | `module:make-command`    | 代码生成 | 在指定模块中创建一个 Artisan 命令  | `php artisan module:make-command Blog SyncPosts`                             |
| 18 | `module:make-event`      | 代码生成 | 在指定模块中创建一个事件类          | `php artisan module:make-event Blog PostCreated`                             |
| 19 | `module:make-listener`   | 代码生成 | 在指定模块中创建一个事件监听器        | `php artisan module:make-listener Blog SendNotification --event=PostCreated` |
| 20 | `module:make-middleware` | 代码生成 | 在指定模块中创建一个中间件          | `php artisan module:make-middleware Blog CheckAuth`                          |
| 21 | `module:make-route`      | 代码生成 | 在指定模块中创建一个路由文件         | `php artisan module:make-route Blog mobile --type=api`                       |
| 22 | `module:make-config`     | 代码生成 | 在指定模块中创建一个配置文件         | `php artisan module:make-config Blog settings`                               |
| 23 | `module:check-lang`      | 调试检查 | 检查模块本地化文件差异            | `php artisan module:check-lang Blog`                                         |
| 24 | `module:debug-commands`  | 调试检查 | 调试模块命令的注册和发现情况         | `php artisan module:debug-commands --module=Blog`                            |

### 命令分类统计

| 类别     | 数量     | 命令                                                                                                                                                                                                                                                                                   |
|--------|--------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 模块管理   | 8      | `module:make`, `module:list`, `module:info`, `module:validate`, `module:delete`, `module:cache`, `module:clear`, `module:publish`                                                                                                                                                                                    |
| 迁移管理   | 6      | `module:migrate`, `module:migrate-reset`, `module:migrate-fresh`, `module:migrate-refresh`, `module:migrate-rollback`, `module:migrate-status`                                                                                                                                       |
| 数据填充   | 1      | `module:seed`                                                                                                                                                                                                                                                                       |
| 代码生成   | 12     | `module:make-controller`, `module:make-model`, `module:make-migration`, `module:make-request`, `module:make-seeder`, `module:make-provider`, `module:make-command`, `module:make-event`, `module:make-listener`, `module:make-middleware`, `module:make-route`, `module:make-config` |
| 调试检查   | 2      | `module:check-lang`, `module:debug-commands`                                                                                                                                                                                                                                         |
| **总计** | **28** | **涵盖模块开发全流程**                                                                                                                                                                                                                                                                        |

### 常用命令快速参考

| 场景     | 推荐命令                     | 示例                                                                         |
|--------|--------------------------|----------------------------------------------------------------------------|
| 创建新模块  | `module:make`            | `php artisan module:make Blog`                                             |
| 查看模块列表 | `module:list`            | `php artisan module:list`                                                  |
| 查看模块详情 | `module:info`            | `php artisan module:info Blog`                                             |
| 验证模块   | `module:validate`        | `php artisan module:validate Blog`                                         |
| 删除模块   | `module:delete`          | `php artisan module:delete Shop`                                           |
| 刷新缓存   | `module:cache`           | `php artisan module:cache`                                                 |
| 清除缓存   | `module:clear`           | `php artisan module:clear`                                                 |
| 发布资源   | `module:publish`         | `php artisan module:publish --config`                                      |
| 创建控制器  | `module:make-controller` | `php artisan module:make-controller Blog PostController --type=web`        |
| 创建模型   | `module:make-model`      | `php artisan module:make-model Blog Post --table=posts`                    |
| 创建迁移   | `module:make-migration`  | `php artisan module:make-migration Blog create_posts_table --create=posts` |
| 运行迁移   | `module:migrate`         | `php artisan module:migrate Blog`                                          |
| 查看迁移状态 | `module:migrate-status`  | `php artisan module:migrate-status Blog --pending`                         |
| 创建事件   | `module:make-event`      | `php artisan module:make-event Blog UserRegistered`                        |
| 创建监听器  | `module:make-listener`   | `php artisan module:make-listener Blog SendEmail --event=UserRegistered`   |
| 创建命令   | `module:make-command`    | `php artisan module:make-command Blog SyncData`                            |
| 创建路由文件 | `module:make-route`      | `php artisan module:make-route Blog api`                                   |
| 检查本地化  | `module:check-lang`      | `php artisan module:check-lang Blog`                                       |
| 调试命令   | `module:debug-commands`  | `php artisan module:debug-commands --module=Blog`                          |

## 模块命令自动发现

模块系统会自动发现并注册每个模块的 `Console/Commands/` 目录下的所有 Artisan 命令。

### 自动发现规则

- **触发条件**：仅在命令模式（`runningInConsole()`）下执行
- **扫描路径**：
  - `Console/Commands/`（推荐，标准路径）
  - `Commands/`（兼容路径）
- **注册要求**：
  - 类必须继承 `Illuminate\Console\Command`
  - 定义 `$signature` 和 `$description` 属性
  - 实现 `handle()` 方法

### 命令自动注册流程

```
运行 php artisan 命令
  ↓
ModulesServiceProvider::boot()
  ↓
ModuleLoader::loadAll()
  ↓
ModuleAutoDiscovery::discoverCommands()
  ↓
检查 runningInConsole() → 是
  ↓
扫描 Console/Commands/ 目录
  ↓
验证命令类有效性
  ↓
使用 Artisan Application::add() 注册
  ↓
命令立即可用
```

### 模块命令示例

创建模块命令文件 `Modules/Blog/Console/Commands/SyncPosts.php`：

```php
<?php

namespace Modules\Blog\Console\Commands;

use Illuminate\Console\Command;

class SyncPosts extends Command
{
    protected $signature = 'blog:sync';
    protected $description = '同步博客文章';

    public function handle()
    {
        $this->info('开始同步文章...');
        // 业务逻辑
        $this->info('同步完成！');
    }
}
```

### 验证命令自动发现

```bash
# 查看所有可用命令（包含模块命令）
php artisan list

# 输出会包含：
# blog
#  blog:sync              同步博客文章

# 直接执行模块命令
php artisan blog:sync
```

### 调试命令发现

如果命令没有自动注册，可以检查：

1. **模块是否启用**
   ```bash
   php artisan module:list
   ```

2. **命令文件位置**
   - 确保在 `Console/Commands/` 目录下
   - 文件扩展名为 `.php`

3. **命令类继承**
   - 确保继承 `Illuminate\Console\Command`
   - 检查 `$signature` 和 `$description` 属性

4. **启用调试日志**
   ```php
   // config/app.php
   'debug' => true,
   ```

5. **查看日志**
   ```bash
   tail -f storage/logs/laravel.log
   ```

### 性能优化

命令自动发现具有以下优化特性：

- **条件加载**：仅在命令模式下注册，Web 请求时跳过
- **反射缓存**：类验证结果缓存到内存
- **单例注册**：`ModuleLoader` 使用单例模式，避免重复扫描

## 模块管理命令

### module:make

创建一个新的模块。

**签名：**
```bash
php artisan module:make <name> [--force] [--full]
```

**参数：**
- `name`：模块名称（必需）

**选项：**
- `--force`：覆盖已存在的模块
- `--full`：强制生成所有文件，忽略配置中的 generate 设置

**示例：**
```bash
# 创建一个名为 Blog 的模块
php artisan module:make Blog

# 创建一个名为 Shop 的模块，如果已存在则覆盖
php artisan module:make Shop --force

# 创建一个完整模块（生成所有文件）
php artisan module:make Shop --full
```

> **缓存说明**：创建成功后命令会自动失效模块缓存（元数据 + 自动发现清单），
> 因此下一个请求会重新扫描磁盘并注册新模块的路由、视图、迁移等组件，
> 不会出现「新模块路由 404 / 组件未被发现」的问题。这与 `module:delete` 末尾的
> 缓存失效处理保持一致。若你是在开启 `MODULES_CACHE_ENABLED` 后**手动**（非通过
> `module:make`）往磁盘新增模块目录（如 git 部署拉取），缓存也会在下次启动时通过
> 目录 mtime 校验自动失效，无需手动执行 `module:clear`。

### module:list

列出所有模块及其状态。

**签名：**
```bash
php artisan module:list
```

**示例：**
```bash
php artisan module:list
```

**输出示例：**
```
+----+-------+---------+------------------------+-------------------+
| #  | Name  | Status  | Path                   | Namespace         |
+----+-------+---------+------------------------+-------------------+
| 1  | Blog  | Enabled | /path/to/Modules/Blog | Modules           |
| 2  | Shop  | Disabled| /path/to/Modules/Shop | Modules           |
+----+-------+---------+------------------------+-------------------+
Total: 2 module(s)
Enabled: 1 module(s)
Disabled: 1 module(s)
```

### module:publish

发布多模块系统资源。

**签名：**
```bash
php artisan module:publish [--guide] [--config]
```

**选项：**
- `--guide`：发布多模块用户指南到 Modules 目录
- `--config`：发布配置文件

**示例：**
```bash
# 发布用户指南
php artisan module:publish --guide

# 发布配置文件
php artisan module:publish --config
```

### module:info

显示指定模块的详细信息。

**签名：**
```bash
php artisan module:info <name>
```

**参数：**
- `name`：模块名称（必需）

**示例：**
```bash
php artisan module:info Blog
```

### module:validate

验证模块的完整性和正确性。

**签名：**
```bash
php artisan module:validate [name]
```

**参数：**
- `name`：模块名称（可选）

**示例：**
```bash
# 验证指定模块
php artisan module:validate Blog

# 验证所有模块
php artisan module:validate
```

### module:delete

删除一个模块。

**签名：**
```bash
php artisan module:delete <name> [--force]
```

**参数：**
- `name`：模块名称（必需）

**选项：**
- `--force`：强制删除，不提示确认

**示例：**
```bash
# 删除 Shop 模块（会提示确认）
php artisan module:delete Shop

# 强制删除 Shop 模块（不提示确认）
php artisan module:delete Shop --force
```

> **健壮性说明**：删除前会绕过模块缓存重新扫描磁盘。即便开启了
> `MODULES_CACHE_ENABLED=true` 且缓存未更新，也能正确识别并删除通过部署 /
> git 拉取等方式新增的模块；若注册表未找到该模块，还会回退到磁盘真实路径
> （同时匹配 StudlyCase / snake_case / 小写目录名）再次确认，避免「模块存在却
> 提示不存在」的问题。删除成功后自动清除模块缓存。

### module:cache

重新扫描磁盘并写入模块缓存，用于生产环境加速模块加载。

**签名：**
```bash
php artisan module:cache
```

**说明：**
- 命令会绕过可能过期的缓存，以磁盘真实状态重新扫描并写入缓存。
- 若 `modules.cache.enabled` 为 `false`，缓存不会被持久化（仅本次进程生效），
  会给出提示，需在 `.env` 中设置 `MODULES_CACHE_ENABLED=true`。

**示例：**
```bash
php artisan module:cache
```

### module:clear

清除模块缓存文件并重置内存缓存，使后续命令以磁盘真实状态重新扫描。

**签名：**
```bash
php artisan module:clear
```

**说明：**
- 当模块被手动新增 / 删除（部署、git 拉取）导致缓存过期时，执行本命令可
  立即恢复 `module:list` / `module:delete` 等命令的正确性。
- 与 `module:delete` 不同，本命令不会删除任何模块目录，仅清除缓存。

**示例：**
```bash
php artisan module:clear
```

## 迁移命令

### module:migrate

运行所有模块或指定模块的数据库迁移。

**签名：**
```bash
php artisan module:migrate [module] [--force] [--path=] [--seed] [--seeder=]
```

**参数：**
- `module`：模块名称（可选）

**选项：**
- `--force`：强制运行，不提示确认
- `--path=`：指定迁移路径
- `--seed`：运行数据填充
- `--seeder=`：指定数据填充器

**示例：**
```bash
# 运行所有模块的迁移
php artisan module:migrate

# 运行指定模块的迁移
php artisan module:migrate Blog

# 运行迁移并执行数据填充
php artisan module:migrate Blog --seed

# 指定数据填充器
php artisan module:migrate Blog --seeder=PostSeeder
```

### module:migrate-reset

回滚所有模块或指定模块的最后一次数据库迁移。

**签名：**
```bash
php artisan module:migrate-reset [module] [--force] [--path=]
```

**参数：**
- `module`：模块名称（可选）

**选项：**
- `--force`：强制运行，不提示确认
- `--path=`：指定迁移路径

**示例：**
```bash
# 回滚所有模块的最后一次迁移
php artisan module:migrate-reset

# 回滚指定模块的最后一次迁移
php artisan module:migrate-reset Blog
```

### module:migrate-refresh

重置并重新运行所有模块或指定模块的数据库迁移。

**签名：**
```bash
php artisan module:migrate-refresh [module] [--force] [--seed] [--seeder=]
```

**参数：**
- `module`：模块名称（可选）

**选项：**
- `--force`：强制运行
- `--seed`：运行数据填充
- `--seeder=`：指定数据填充器

**示例：**
```bash
# 重置并重新运行所有模块的迁移
php artisan module:migrate-refresh

# 重置并重新运行指定模块的迁移
php artisan module:migrate-refresh Blog

# 重置并运行数据填充
php artisan module:migrate-refresh Blog --seed

# 重置并运行指定填充器
php artisan module:migrate-refresh Blog --seeder=PostSeeder
```

### module:migrate-fresh

清空所有数据表后重新运行模块迁移（相当于 `migrate:fresh` 的模块版本）。

**签名：**
```bash
php artisan module:migrate-fresh [module] [--database=] [--force] [--seed] [--seeder=] [--drop-views] [--drop-types]
```

**参数：**
- `module`：模块名称（可选）

**选项：**
- `--database=`：指定数据库连接
- `--force`：强制运行，不提示确认
- `--seed`：清空重建后运行数据填充
- `--seeder=`：指定特定的数据填充器
- `--drop-views`：同时删除所有视图（MySQL）
- `--drop-types`：同时删除所有自定义类型（PostgreSQL）

**示例：**
```bash
# 清空并重建指定模块
php artisan module:migrate-fresh Blog

# 清空重建并运行数据填充
php artisan module:migrate-fresh Blog --seed

# 清空重建指定数据库连接
php artisan module:migrate-fresh Blog --database=testing

# 清空所有模块
php artisan module:migrate-fresh
```

### module:migrate-rollback

回滚指定模块或全部模块的指定步数的迁移。

**签名：**
```bash
php artisan module:migrate-rollback [module] [--database=] [--force] [--step=] [--path=]
```

**参数：**
- `module`：模块名称（可选）

**选项：**
- `--database=`：指定数据库连接
- `--force`：强制运行，不提示确认
- `--step=`：回滚的迁移步数（默认为 1）
- `--path=`：指定自定义迁移文件路径

**示例：**
```bash
# 回滚所有模块最近 1 次迁移
php artisan module:migrate-rollback

# 回滚指定模块最近 3 次迁移
php artisan module:migrate-rollback Blog --step=3

# 强制回滚指定模块
php artisan module:migrate-rollback Blog --force
```

### module:seed

运行指定模块或所有模块的数据填充器。

**签名：**
```bash
php artisan module:seed [module] [--class=] [--database=] [--force]
```

**参数：**
- `module`：模块名称（可选）

**选项：**
- `--class=`：指定特定的 Seeder 类名（仅类名，不含命名空间）
- `--database=`：指定数据库连接
- `--force`：强制运行，不提示确认

**示例：**
```bash
# 运行指定模块的所有 Seeder
php artisan module:seed Blog

# 运行指定模块的特定 Seeder
php artisan module:seed Blog --class=PostSeeder

# 运行所有模块的 Seeder
php artisan module:seed

# 使用指定的数据库连接
php artisan module:seed Blog --database=testing
```

### module:migrate-status

显示所有模块或指定模块的迁移状态，支持统计信息和状态筛选。

**签名：**
```bash
php artisan module:migrate-status [module] [--path=] [--pending] [--ran] [--no-stats]
```

**参数：**
- `module`：模块名称（可选，不指定则显示所有模块）

**选项：**
- `--path=`：指定自定义迁移文件路径
- `--pending`：仅显示待运行的迁移
- `--ran`：仅显示已运行的迁移
- `--no-stats`：不显示统计信息

**示例：**
```bash
# 查看所有模块的迁移状态
php artisan module:migrate-status

# 查看指定模块的迁移状态
php artisan module:migrate-status Blog

# 仅查看待运行的迁移
php artisan module:migrate-status --pending

# 仅查看已运行的迁移
php artisan module:migrate-status --ran
```

**输出示例：**
```
+---+--------+-------------------------+-------+----------+
| # | 模块   | 迁移文件                | 批次  | 状态     |
+---+--------+-------------------------+-------+----------+
| 1 | Blog   | 2024_01_01_000001_...   | 1     | 已运行   |
| 2 | Blog   | 2024_01_02_000002_...   | -     | 待运行   |
+---+--------+-------------------------+-------+----------+

迁移统计:
  模块总数: 1 / 3
  迁移文件总数: 2
  已运行: 1
  待运行: 1
```

**状态说明：**
- **已运行**：迁移已经执行
- **待运行**：迁移尚未执行

## 生成器命令

### module:make-controller

在指定模块中创建一个控制器。

**签名：**
```bash
php artisan module:make-controller <module> <name> [--type=web] [--force] [--plain] [--attributes]
```

**参数：**
- `module`：模块名称（必需）
- `name`：控制器名称（必需）

**选项：**
- `--type=web|api|admin|自定义`：控制器类型，默认为 `web`（支持任意自定义类型）
- `--force`：覆盖已存在的控制器
- `--plain`：创建空控制器（无 CRUD 方法）
- `--attributes`：使用 Laravel 13 属性路由/中间件风格生成控制器（见下文）

**示例：**
```bash
# 在 Blog 模块中创建一个 Web 控制器
php artisan module:make-controller Blog PostController

# 在 Blog 模块中创建一个 API 控制器
php artisan module:make-controller Blog PostController --type=api

# 在 Blog 模块中创建一个 Admin 控制器
php artisan module:make-controller Blog PostController --type=admin

# 创建空控制器
php artisan module:make-controller Blog BaseController --plain

# 使用 Laravel 13 属性路由风格（#[Get]/#[Post]/#[Middleware]）
php artisan module:make-controller Blog PostController --attributes
```

**Laravel 13 属性路由（--attributes）**

使用 `--attributes` 选项生成的控制器会使用 PHP 8 属性声明路由与中间件，无需在 `Routes/` 文件中注册：

```php
use Illuminate\Routing\Attributes\Get;
use Illuminate\Routing\Attributes\Middleware;

#[Middleware('web')]
class PostController extends Controller
{
    #[Get('/')]
    public function index() { /* ... */ }

    #[Post('store')]
    public function store(Request $request) { /* ... */ }
}
```

⚠️ 前置条件：需要 Laravel 13+ 并在宿主应用中启用属性路由（bootstrap/app.php 的 `withRouting()` 开启 attribute routing）。

### module:make-model

在指定模块中创建一个模型，支持从现有数据库表自动解析字段信息。

**签名：**
```bash
php artisan module:make-model <module> <name> [--table=] [--migration] [--factory] [--force]
```

**参数：**
- `module`：模块名称（必需，首字母大写）
- `name`：模型名称（必需，首字母大写）

**选项：**
- `--table`：从现有数据库表生成模型，自动解析所有字段信息
- `--migration`：创建对应的迁移文件
- `--factory`：同时创建对应的数据工厂类
- `--force`：覆盖已存在的模型

**示例：**
```bash
# 在 Blog 模块中创建一个 Post 模型
php artisan module:make-model Blog Post

# 在 Blog 模块中创建一个 Post 模型并生成迁移
php artisan module:make-model Blog Post --migration

# 从数据库表生成模型，自动解析字段信息
php artisan module:make-model Logs SystemLogs --table=system_logs
```

**特性：**
- ✅ 自动解析数据库表结构
- ✅ 生成完整的 PHPDoc 属性注释
- ✅ 包含数据库字段注释
- ✅ datetime/timestamp 字段使用 `\Carbon\Carbon` 类型
- ✅ 自动生成 `fillable` 属性
- ✅ 自动生成 `casts()` 方法
- ✅ 自动生成 `attributes` 默认值

### module:make-migration

在指定模块中创建一个迁移文件。

**签名：**
```bash
php artisan module:make-migration <module> <name> [--create=] [--update=] [--path=] [--realpath] [--fullpath]
```

**参数：**
- `module`：模块名称（必需）
- `name`：迁移名称（必需）

**选项：**
- `--create=`：要创建的表名
- `--update=`：要修改的表名
- `--path=`：迁移文件路径
- `--realpath`：指示提供的任何迁移文件路径都是预解析的绝对路径
- `--fullpath`：不在模块 Database/Migrations 目录中生成迁移

**示例：**
```bash
# 在 Blog 模块中创建一个迁移
php artisan module:make-migration Blog create_posts_table

# 在 Blog 模块中创建迁移并指定表名
php artisan module:make-migration Blog create_posts_table --create=posts
```

### module:make-request

在指定模块中创建一个表单请求类。

**签名：**
```bash
php artisan module:make-request <module> <name> [--force]
```

**参数：**
- `module`：模块名称（必需）
- `name`：请求类名称（必需）

**选项：**
- `--force`：覆盖已存在的请求类

**示例：**
```bash
# 在 Blog 模块中创建一个请求验证类
php artisan module:make-request Blog StorePostRequest
```

### module:make-seeder

在指定模块中创建一个数据填充器。

**签名：**
```bash
php artisan module:make-seeder <module> <name> [--force]
```

**参数：**
- `module`：模块名称（必需）
- `name`：填充器名称（必需）

**选项：**
- `--force`：覆盖已存在的填充器

**示例：**
```bash
# 在 Blog 模块中创建一个数据填充器
php artisan module:make-seeder Blog PostSeeder
```

### module:make-provider

在指定模块中创建一个服务提供者。

**签名：**
```bash
php artisan module:make-provider <module> <name> [--force]
```

**参数：**
- `module`：模块名称（必需）
- `name`：服务提供者名称（必需）

**选项：**
- `--force`：覆盖已存在的服务提供者

**示例：**
```bash
# 在 Blog 模块中创建一个服务提供者
php artisan module:make-provider Blog EventServiceProvider
```

### module:make-command

在指定模块中创建一个 Artisan 命令。

**签名：**
```bash
php artisan module:make-command <module> <name> [--command=] [--force]
```

**参数：**
- `module`：模块名称（必需）
- `name`：命令类名称（必需）

**选项：**
- `--command=`：命令签名
- `--force`：覆盖已存在的命令

**示例：**
```bash
# 在 Blog 模块中创建一个命令
php artisan module:make-command Blog SyncPosts

# 指定命令签名
php artisan module:make-command Blog SyncPosts --command="blog:sync"
```

### module:make-event

在指定模块中创建一个事件类。

**签名：**
```bash
php artisan module:make-event <module> <name> [--force]
```

**参数：**
- `module`：模块名称（必需）
- `name`：事件类名称（必需）

**选项：**
- `--force`：覆盖已存在的事件类

**示例：**
```bash
# 在 Blog 模块中创建一个事件
php artisan module:make-event Blog PostCreated
```

### module:make-listener

在指定模块中创建一个事件监听器。

**签名：**
```bash
php artisan module:make-listener <module> <name> [--event=] [--force]
```

**参数：**
- `module`：模块名称（必需）
- `name`：监听器类名称（必需）

**选项：**
- `--event=`：要监听的事件类
- `--force`：覆盖已存在的监听器

**示例：**
```bash
# 在 Blog 模块中创建一个监听器
php artisan module:make-listener Blog SendPostNotification

# 创建监听器并指定事件
php artisan module:make-listener Blog SendPostNotification --event=PostCreated
```

### module:make-middleware

在指定模块中创建一个中间件。

**签名：**
```bash
php artisan module:make-middleware <module> <name> [--force]
```

**参数：**
- `module`：模块名称（必需）
- `name`：中间件类名称（必需）

**选项：**
- `--force`：覆盖已存在的中间件

**示例：**
```bash
# 在 Blog 模块中创建一个中间件
php artisan module:make-middleware Blog CheckAuth
```

### module:make-route

在指定模块中创建一个路由文件。

**签名：**
```bash
php artisan module:make-route <module> <name> [--type=web] [--force]
```

**参数：**
- `module`：模块名称（必需）
- `name`：路由文件名称（必需）

**选项：**
- `--type=web|api|admin|mobile`：路由类型，默认为 `web`
- `--force`：覆盖已存在的路由文件

**示例：**
```bash
# 在 Blog 模块中创建一个路由文件
php artisan module:make-route Blog mobile
```

### module:make-config

在指定模块中创建一个配置文件。

**签名：**
```bash
php artisan module:make-config <module> <name> [--force]
```

**参数：**
- `module`：模块名称（必需）
- `name`：配置文件名称（必需）

**选项：**
- `--force`：覆盖已存在的配置文件

**示例：**
```bash
# 在 Blog 模块中创建一个配置文件
php artisan module:make-config Blog settings
```

## 调试检查命令

### module:check-lang

检查模块内不同语言本地化文件的配置项差异。

**签名：**
```bash
php artisan module:check-lang [name] [--path=]
```

**参数：**
- `name`：模块名称（可选，不指定则检查所有模块）

**选项：**
- `--path=`：自定义本地化路径（默认：Resources/lang）

**示例：**
```bash
# 检查所有模块
php artisan module:check-lang

# 检查指定模块
php artisan module:check-lang Blog

# 使用自定义路径
php artisan module:check-lang Blog --path=lang
```

**输出示例：**
```bash
$ php artisan module:check-lang Blog

正在检查模块 [Blog] 的本地化文件...

发现 3 种语言: en, zh-CN, es

发现配置项差异：

语言 [zh-CN] 缺失的配置项：
  文件 [messages] 缺失 2 个配置项:
    - greeting.hello
    - error.not_found
```

**详细说明：**
- 检查同一模块内所有语言文件的配置项是否一致
- 找出缺失的配置键
- 支持嵌套配置项（如 `error.not_found`）
- 支持多个翻译文件的对比

### module:debug-commands

调试模块命令的注册和发现情况。

**签名：**
```bash
php artisan module:debug-commands [--module=]
```

**选项：**
- `--module=`：指定模块名称（不指定则检查所有模块）

**示例：**
```bash
# 调试所有模块的命令
php artisan module:debug-commands

# 调试指定模块的命令
php artisan module:debug-commands --module=Blog
```

**输出示例：**
```bash
$ php artisan module:debug-commands --module=Blog

模块: Blog
状态: 已启用
路径: /path/to/Modules/Blog

Console/Commands 目录:
  ✓ 存在 (/path/to/Modules/Blog/Console/Commands)
  文件数: 2
    - SyncPosts.php
    - CleanupCommand.php

Commands 目录:
  ✗ 不存在

发现缓存:
  命令数: 2
    - Modules\Blog\Console\Commands\SyncPosts
      签名: blog:sync
    - Modules\Blog\Console\Commands\CleanupCommand
      签名: blog:cleanup

发现日志:
  [2024-01-01 12:00:00] 开始扫描模块 [Blog] 的命令
  [2024-01-01 12:00:00] 发现 2 个命令类

已注册到 Artisan 的命令:
  ✓ blog:sync - 同步博客文章
  ✓ blog:cleanup - 清理数据
```

**功能说明：**
- 显示模块命令目录结构
- 检查命令自动发现情况
- 显示已注册到 Artisan 的命令
- 输出命令签名和描述

## 命令参考表

### 模块管理（6个）
| 命令                | 说明       |
|-------------------|----------|
| `module:make`     | 创建新模块    |
| `module:list`     | 列出所有模块   |
| `module:info`     | 显示模块详细信息 |
| `module:validate` | 验证模块     |
| `module:delete`   | 删除模块     |
| `module:publish`  | 发布模块资源   |

### 迁移管理（5个）
| 命令                        | 说明     |
|---------------------------|--------|
| `module:migrate`          | 运行迁移   |
| `module:migrate-reset`    | 回滚全部迁移 |
| `module:migrate-fresh`    | 清空并重建  |
| `module:migrate-refresh`  | 重置并重跑  |
| `module:migrate-rollback` | 回滚指定步数 |
| `module:migrate-status`   | 查看迁移状态 |

### 数据填充（1个）
| 命令             | 说明       |
|----------------|----------|
| `module:seed`  | 运行数据填充器 |

### 代码生成（12个）
| 命令                       | 说明      |
|--------------------------|---------|
| `module:make-controller` | 创建控制器   |
| `module:make-model`      | 创建模型    |
| `module:make-migration`  | 创建迁移    |
| `module:make-request`    | 创建请求类   |
| `module:make-seeder`     | 创建填充器   |
| `module:make-provider`   | 创建服务提供者 |
| `module:make-command`    | 创建命令    |
| `module:make-event`      | 创建事件    |
| `module:make-listener`   | 创建监听器   |
| `module:make-middleware` | 创建中间件   |
| `module:make-route`      | 创建路由文件  |
| `module:make-config`     | 创建配置文件  |

### 调试检查（2个）
| 命令                      | 说明        |
|-------------------------|-----------|
| `module:check-lang`     | 检查本地化文件差异 |
| `module:debug-commands` | 调试命令注册和发现 |

## 相关文档

- [代码生成](10-code-generation.md) - 代码生成命令的详细使用
- [迁移管理](11-migrations.md) - 数据库迁移的详细说明
