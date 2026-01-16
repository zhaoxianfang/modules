# Laravel 模块系统命令文档

本文档详细介绍 Laravel 模块系统提供的所有 Artisan 命令。每个命令都包含签名、描述、参数、选项和使用示例。

---

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

**说明：**
- 创建的模块包含完整的目录结构
- 自动生成配置文件、路由文件、示例控制器和服务提供者
- 配置文件默认启用模块

---

### module:list

列出所有模块及其状态。

**签名：**
```bash
php artisan module:list
```

**参数：** 无

**选项：** 无

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

---

### module:info

显示指定模块的详细信息。

**签名：**
```bash
php artisan module:info <name>
```

**参数：**
- `name`：模块名称（必需）

**选项：** 无

**示例：**
```bash
php artisan module:info Blog
```

**说明：**
- 显示模块的基本信息
- 显示模块的功能信息
- 显示模块的统计信息
- 显示模块的路由文件列表
- 显示模块的服务提供者

---

### module:validate

验证模块的完整性和正确性。

**签名：**
```bash
php artisan module:validate [name]
```

**参数：**
- `name`：模块名称（可选）

**选项：** 无

**示例：**
```bash
# 验证指定模块
php artisan module:validate Blog

# 验证所有模块
php artisan module:validate
```

**说明：**
- 检查必需的目录是否存在
- 检查服务提供者是否存在
- 检查配置文件是否正确
- 检查路由文件是否有效
- 验证不通过会显示详细的错误信息

---

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

**说明：**
- 删除操作不可逆
- 会删除整个模块目录及其所有文件
- 建议先确认要删除的模块

---

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

# 强制运行
php artisan module:migrate --force
```

**说明：**
- 未启用的模块不会运行迁移
- 支持运行数据填充器
- 可以指定自定义迁移路径

---

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

# 强制回滚
php artisan module:migrate-reset --force
```

**说明：**
- 未启用的模块会被跳过
- 回滚操作会提示确认（除非使用 --force）

---

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

# 刷新并执行数据填充
php artisan module:migrate-refresh Blog --seed

# 指定数据填充器
php artisan module:migrate-refresh --seeder=PostSeeder
```

**说明：**
- 先回滚所有迁移
- 再重新运行所有迁移
- 支持运行数据填充

---

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

**说明：**
- 显示每个迁移文件的运行状态
- 显示迁移批次信息
- 未运行的迁移会标记为红色

**输出示例：**
```
+---+--------+-------------------------+-------+----------+
| # | 模块   | 迁移文件                | 批次  | 状态     |
+---+--------+-------------------------+-------+----------+
| 1 | Blog   | 2024_01_01_000001_...   | 1     | 已运行   |
| 2 | Blog   | 2024_01_02_000002_...   | -     | 待运行   |
+---+--------+-------------------------+-------+----------+
```

---

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

# 强制覆盖
php artisan module:make-controller Blog PostController --force
```

**说明：**
- 控制器会根据类型放置在对应的子目录：`Web/`、`Api/` 或 `Admin/`
- 自动生成标准的 RESTful 方法：index、store、show、update、destroy

---

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

# 强制覆盖
php artisan module:make-model Blog Post --force
```

**说明：**
- 模型会自动设置表名（模型的复数形式）
- 包含常用的模型属性和配置

---

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

# 在 Blog 模块中创建修改表的迁移
php artisan module:make-migration Blog add_status_to_posts_table
```

**说明：**
- 迁移文件会自动添加时间戳
- 使用 `--create` 选项会生成创建表的迁移模板

---

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

# 强制覆盖
php artisan module:make-request Blog StorePostRequest --force
```

**说明：**
- 请求类默认授权所有用户（authorize 方法返回 true）
- 需要在 rules 方法中定义验证规则

---

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

# 强制覆盖
php artisan module:make-seeder Blog PostSeeder --force
```

**说明：**
- 填充器会创建在模块的 `Database/Seeders` 目录下
- 在 run 方法中编写填充逻辑

---

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
php artisan module:make-provider Blog BlogServiceProvider

# 在 Blog 模块中创建一个额外的服务提供者
php artisan module:make-provider Blog EventServiceProvider

# 强制覆盖
php artisan module:make-provider Blog BlogServiceProvider --force
```

**说明：**
- 每个模块默认已有一个 `{ModuleName}ServiceProvider`
- 可以创建额外的服务提供者来组织不同的功能

---

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
php artisan module:make-command Blog SyncPostsCommand

# 指定命令签名
php artisan module:make-command Blog SyncPosts --command="blog:sync"

# 强制覆盖
php artisan module:make-command Blog SyncPostsCommand --force
```

**说明：**
- 不指定 `--command` 时会自动生成默认签名
- 在 handle 方法中编写命令逻辑

---

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

# 强制覆盖
php artisan module:make-event Blog PostCreated --force
```

**说明：**
- 事件类包含 Dispatchable、InteractsWithSockets、SerializesModels traits
- 在构造函数中可以传递事件相关的数据

---

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

# 强制覆盖
php artisan module:make-listener Blog SendPostNotification --force
```

**说明：**
- 使用 `--event` 选项会在 handle 方法中添加类型提示
- 需要在模块的服务提供者中注册事件和监听器

---

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
php artisan module:make-middleware Blog CheckPostPermission

# 强制覆盖
php artisan module:make-middleware Blog CheckPostPermission --force
```

**说明：**
- 中间件创建在模块的 `Http/Middleware` 目录下
- 需要在 `app/Http/Kernel.php` 中注册中间件
- 或在路由中直接使用

---

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
php artisan module:make-route Blog custom

# 创建其他路由文件
php artisan module:make-route Blog mobile
php artisan module:make-route Blog wechat

# 强制覆盖
php artisan module:make-route Blog custom --force
```

**说明：**
- 创建的路由文件会自动包含模块前缀和命名空间
- 如果是新创建的路由文件，需要在 `config/modules.php` 中配置对应的中间件组

---

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

# 创建其他配置文件
php artisan module:make-config Blog permissions
php artisan module:make-config Blog templates

# 强制覆盖
php artisan module:make-config Blog settings --force
```

**说明：**
- 配置文件创建在模块的 `Config` 目录下
- 可以通过 `config('blog.settings.key')` 访问配置

---

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

---

## 命令使用示例

### 完整的博客模块创建流程

```bash
# 1. 创建模块
php artisan module:make Blog

# 2. 查看模块信息
php artisan module:info Blog

# 3. 验证模块
php artisan module:validate Blog

# 4. 创建模型和迁移
php artisan module:make-model Blog Post --migration

# 5. 创建控制器
php artisan module:make-controller Blog PostController --type=web
php artisan module:make-controller Blog PostController --type=api

# 6. 创建请求验证类
php artisan module:make-request Blog StorePostRequest
php artisan module:make-request Blog UpdatePostRequest

# 7. 创建数据填充器
php artisan module:make-seeder Blog PostSeeder

# 8. 运行迁移
php artisan module:migrate Blog

# 9. 查看迁移状态
php artisan module:migrate-status Blog

# 10. 运行数据填充
php artisan db:seed --class=Modules\\Blog\\Database\\Seeders\\PostSeeder
```

### 电商模块创建流程

```bash
# 1. 创建模块
php artisan module:make Shop

# 2. 查看所有模块
php artisan module:list

# 3. 创建模型和迁移
php artisan module:make-model Shop Product --migration
php artisan module:make-model Shop Order --migration
php artisan module:make-model Shop Customer --migration

# 4. 创建控制器
php artisan module:make-controller Shop ProductController --type=web
php artisan module:make-controller Shop ProductController --type=admin
php artisan module:make-controller Shop ProductController --type=api

# 5. 创建管理路由文件
php artisan module:make-route Shop mobile

# 6. 创建事件和监听器
php artisan module:make-event Shop OrderCreated
php artisan module:make-listener Shop SendOrderConfirmation --event=OrderCreated

# 7. 创建命令
php artisan module:make-command Shop SyncProducts --command="shop:sync-products"

# 8. 运行所有迁移
php artisan module:migrate

# 9. 查看所有模块的迁移状态
php artisan module:migrate-status
```

---

## 常见问题

### Q: 如何批量创建多个模块？

```bash
# 使用循环命令（bash）
for module in Blog Shop Forum; do
    php artisan module:make $module
done
```

### Q: 如何查看模块的详细信息？

```bash
# 使用 info 命令
php artisan module:info Blog

# 或使用助手函数
php artisan tinker
>>> module('Blog')
>>> module('Blog')->getPath()
>>> module('Blog')->isEnabled()
```

### Q: 如何临时禁用某个模块？

```bash
# 方法：手动修改配置
# 编辑 Modules/Blog/Config/config.php
# 将 'enable' => true 改为 'enable' => false

# 然后验证模块
php artisan module:validate Blog
```

### Q: 如何删除所有模块？

```bash
# 警告：此操作不可逆
# 使用循环命令（bash）
for module in $(php artisan module:list | grep -Eo '^\s+\d+\s+\w+' | awk '{print $2}'); do
    php artisan module:delete $module --force
done
```

### Q: 如何回滚所有模块的迁移？

```bash
# 回滚所有模块的最后一次迁移
php artisan module:migrate-reset

# 回滚指定模块的迁移
php artisan module:migrate-reset Blog

# 查看迁移状态
php artisan module:migrate-status
```

### Q: 如何刷新所有迁移？

```bash
# 回滚并重新运行所有迁移
php artisan module:migrate-refresh

# 刷新并执行数据填充
php artisan module:migrate-refresh --seed
```

---

## 更多帮助

要查看任何命令的帮助信息，可以使用 `--help` 选项：

```bash
php artisan module:make --help
php artisan module:info --help
php artisan module:migrate --help
php artisan module:validate --help
```

或查看 README.md 获取完整的使用文档。
