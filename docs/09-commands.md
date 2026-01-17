# 命令参考

本文档详细介绍 Laravel 模块系统提供的所有 Artisan 命令。

## 模块管理命令

### module:make

创建一个新的模块。

**签名：**
```bash
php artisan module:make <name> [--force]
```

**参数：**
- `name`：模块名称（必需）

**选项：**
- `--force`：覆盖已存在的模块

**示例：**
```bash
# 创建一个名为 Blog 的模块
php artisan module:make Blog

# 创建一个名为 Shop 的模块，如果已存在则覆盖
php artisan module:make Shop --force
```

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
```

### module:migrate-status

显示所有模块或指定模块的迁移状态。

**签名：**
```bash
php artisan module:migrate-status [module] [--path=]
```

**参数：**
- `module`：模块名称（可选）

**选项：**
- `--path=`：指定迁移路径

**示例：**
```bash
# 查看所有模块的迁移状态
php artisan module:migrate-status

# 查看指定模块的迁移状态
php artisan module:migrate-status Blog
```

**输出示例：**
```
+---+--------+-------------------------+-------+----------+
| # | 模块   | 迁移文件                | 批次  | 状态     |
+---+--------+-------------------------+-------+----------+
| 1 | Blog   | 2024_01_01_000001_...   | 1     | 已运行   |
| 2 | Blog   | 2024_01_02_000002_...   | -     | 待运行   |
+---+--------+-------------------------+-------+----------+
```

## 生成器命令

### module:make-controller

在指定模块中创建一个控制器。

**签名：**
```bash
php artisan module:make-controller <module> <name> [--type=web] [--force]
```

**参数：**
- `module`：模块名称（必需）
- `name`：控制器名称（必需）

**选项：**
- `--type=web|api|admin`：控制器类型，默认为 `web`
- `--force`：覆盖已存在的控制器

**示例：**
```bash
# 在 Blog 模块中创建一个 Web 控制器
php artisan module:make-controller Blog PostController

# 在 Blog 模块中创建一个 API 控制器
php artisan module:make-controller Blog PostController --type=api

# 在 Blog 模块中创建一个 Admin 控制器
php artisan module:make-controller Blog PostController --type=admin
```

### module:make-model

在指定模块中创建一个模型。

**签名：**
```bash
php artisan module:make-model <module> <name> [--migration] [--force]
```

**参数：**
- `module`：模块名称（必需）
- `name`：模型名称（必需）

**选项：**
- `--migration`：创建对应的迁移文件
- `--force`：覆盖已存在的模型

**示例：**
```bash
# 在 Blog 模块中创建一个 Post 模型
php artisan module:make-model Blog Post

# 在 Blog 模块中创建一个 Post 模型并生成迁移
php artisan module:make-model Blog Post --migration
```

### module:make-migration

在指定模块中创建一个迁移文件。

**签名：**
```bash
php artisan module:make-migration <module> <name> [--create=]
```

**参数：**
- `module`：模块名称（必需）
- `name`：迁移名称（必需）

**选项：**
- `--create=`：要创建的表名

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
php artisan module:make-route <module> <name> [--force]
```

**参数：**
- `module`：模块名称（必需）
- `name`：路由文件名称（必需）

**选项：**
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

## 命令参考表

### 模块管理（5个）
| 命令 | 说明 |
|------|------|
| `module:make` | 创建新模块 |
| `module:list` | 列出所有模块 |
| `module:info` | 显示模块详细信息 |
| `module:validate` | 验证模块 |
| `module:delete` | 删除模块 |

### 迁移管理（4个）
| 命令 | 说明 |
|------|------|
| `module:migrate` | 运行迁移 |
| `module:migrate-reset` | 回滚迁移 |
| `module:migrate-refresh` | 刷新迁移 |
| `module:migrate-status` | 查看迁移状态 |

### 代码生成（12个）
| 命令 | 说明 |
|------|------|
| `module:make-controller` | 创建控制器 |
| `module:make-model` | 创建模型 |
| `module:make-migration` | 创建迁移 |
| `module:make-request` | 创建请求类 |
| `module:make-seeder` | 创建填充器 |
| `module:make-provider` | 创建服务提供者 |
| `module:make-command` | 创建命令 |
| `module:make-event` | 创建事件 |
| `module:make-listener` | 创建监听器 |
| `module:make-middleware` | 创建中间件 |
| `module:make-route` | 创建路由文件 |
| `module:make-config` | 创建配置文件 |

## 相关文档

- [代码生成](10-code-generation.md) - 代码生成命令的详细使用
- [迁移管理](11-migrations.md) - 数据库迁移的详细说明
