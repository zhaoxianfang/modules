# Laravel 模块系统命令文档

本文档详细介绍 Laravel 模块系统提供的所有 Artisan 命令。每个命令都包含签名、描述、参数、选项和使用示例。

## 目录

1. [模块管理命令](#模块管理命令)
   - [module:list](#modulelist)
   - [module:install](#moduleinstall)
   - [module:uninstall](#moduleuninstall)
   - [module:enable](#moduleenable)
   - [module:disable](#moduledisable)
   - [module:migrate](#modulemigrate)
   - [module:publish](#modulepublish)
   - [module:check](#modulecheck)
   - [module:cache](#modulecache)
   - [module:clear-cache](#moduleclear-cache)

2. [代码生成命令](#代码生成命令)
   - [module:make](#modulemake)
   - [module:make:cast](#modulemakecast)
   - [module:make:channel](#modulemakechannel)
   - [module:make:component](#modulemakecomponent)
   - [module:make:contract](#modulemakecontract)
   - [module:make:controller](#modulemakecontroller)
   - [module:make:event](#modulemakeevent)
   - [module:make:exception](#modulemakeexception)
   - [module:make:factory](#modulemakefactory)
   - [module:make:job](#modulemakejob)
   - [module:make:listener](#modulemakelistener)
   - [module:make:mail](#modulemakemail)
   - [module:make:middleware](#modulemakemiddleware)
   - [module:make:migration](#modulemakemigration)
   - [module:make:model](#modulemakemodel)
   - [module:make:notification](#modulemakenotification)
   - [module:make:observer](#modulemakeobserver)
   - [module:make:policy](#modulemakepolicy)
   - [module:make:repository](#modulemakerepository)
   - [module:make:request](#modulemakerequest)
   - [module:make:resource](#modulemakeresource)
   - [module:make:rule](#modulemakerule)
   - [module:make:seeder](#modulemakeseeder)
   - [module:make:service](#modulemakeservice)

3. [命令使用示例](#命令使用示例)

---

## 模块管理命令

### module:list

**签名**：`module:list {--detail : 显示详细信息} {--json : 以 JSON 格式输出}`

**描述**：列出所有模块，显示基本信息或详细信息。

**参数**：无

**选项**：
- `--detail`：显示模块的详细信息，包括路径、版本、描述、依赖等
- `--json`：以 JSON 格式输出模块信息

**示例**：
```bash
# 列出所有模块（表格形式）
php artisan module:list

# 显示详细信息
php artisan module:list --detail

# 以 JSON 格式输出
php artisan module:list --json
```

### module:install

**签名**：`module:install 
                            {name : 模块名称}
                            {--migrations : 运行迁移}
                            {--seeders : 运行数据填充器}
                            {--publish : 发布资源}`

**描述**：安装模块（运行迁移、数据填充器、发布资源）。

**参数**：
- `name`：要安装的模块名称

**选项**：
- `--migrations`：运行模块的数据库迁移
- `--seeders`：运行模块的数据填充器
- `--publish`：发布模块的资源文件

**示例**：
```bash
# 安装 Blog 模块（只启用模块，不运行迁移）
php artisan module:install Blog

# 安装并运行迁移
php artisan module:install Blog --migrations

# 安装、运行迁移和数据填充器
php artisan module:install Blog --migrations --seeders

# 完整安装：启用、迁移、数据填充、发布资源
php artisan module:install Blog --migrations --seeders --publish
```

### module:uninstall

**签名**：`module:uninstall 
                            {name : 模块名称}
                            {--migrations : 回滚迁移}
                            {--force : 强制卸载（忽略依赖关系）}`

**描述**：卸载模块（回滚迁移、清理资源）。

**参数**：
- `name`：要卸载的模块名称

**选项**：
- `--migrations`：回滚模块的数据库迁移
- `--force`：强制卸载，忽略其他模块对此模块的依赖

**示例**：
```bash
# 卸载 Blog 模块（只禁用模块，不回滚迁移）
php artisan module:uninstall Blog

# 卸载并回滚迁移
php artisan module:uninstall Blog --migrations

# 强制卸载，忽略依赖关系
php artisan module:uninstall Blog --force
```

### module:enable

**签名**：`module:enable {name : 模块名称}`

**描述**：启用模块。

**参数**：
- `name`：要启用的模块名称

**选项**：无

**示例**：
```bash
# 启用 Blog 模块
php artisan module:enable Blog
```

### module:disable

**签名**：`module:disable {name : 模块名称}`

**描述**：禁用模块。

**参数**：
- `name`：要禁用的模块名称

**选项**：无

**示例**：
```bash
# 禁用 Blog 模块
php artisan module:disable Blog
```

### module:migrate

**签名**：`module:migrate 
                            {name : 模块名称}
                            {--fresh : 删除所有表并重新运行迁移}
                            {--seed : 运行迁移后运行数据填充}
                            {--force : 强制在生产环境中运行}
                            {--pretend : 显示要运行的 SQL 查询而不执行}
                            {--step : 强制迁移按步骤运行，以便可以单独回滚}`

**描述**：运行模块的数据库迁移。

**参数**：
- `name`：模块名称

**选项**：
- `--fresh`：删除所有表并重新运行迁移
- `--seed`：运行迁移后运行数据填充
- `--force`：强制在生产环境中运行
- `--pretend`：显示要运行的 SQL 查询而不执行
- `--step`：强制迁移按步骤运行，以便可以单独回滚

**示例**：
```bash
# 运行 Blog 模块的迁移
php artisan module:migrate Blog

# 运行迁移并填充数据
php artisan module:migrate Blog --seed

# 重新运行所有迁移（删除表后）
php artisan module:migrate Blog --fresh

# 显示迁移 SQL 而不执行
php artisan module:migrate Blog --pretend
```

### module:publish

**签名**：`module:publish 
                            {name : 模块名称}
                            {--tag= : 要发布的标签}
                            {--all : 发布所有资源}`

**描述**：发布模块资源（配置、视图、翻译、资源文件）。

**参数**：
- `name`：模块名称

**选项**：
- `--tag=`：指定要发布的标签（多个标签用逗号分隔）
- `--all`：发布所有资源

**示例**：
```bash
# 发布 Blog 模块的默认资源组
php artisan module:publish Blog

# 发布特定标签的资源
php artisan module:publish Blog --tag=config
php artisan module:publish Blog --tag=views,translations

# 发布所有资源
php artisan module:publish Blog --all
```

### module:check

**签名**：`module:check {name? : 模块名称（可选）}`

**描述**：检查模块健康状态和依赖关系。

**参数**：
- `name`：模块名称（可选，不指定则检查所有模块）

**选项**：无

**示例**：
```bash
# 检查所有模块的健康状态
php artisan module:check

# 检查特定模块的健康状态
php artisan module:check Blog
```

### module:cache

**签名**：`module:cache`

**描述**：缓存模块发现结果以提高性能。

**参数**：无

**选项**：无

**示例**：
```bash
# 缓存模块发现结果
php artisan module:cache
```

### module:clear-cache

**签名**：`module:clear-cache`

**描述**：清除模块发现缓存。

**参数**：无

**选项**：无

**示例**：
```bash
# 清除模块缓存
php artisan module:clear-cache
```

---

## 代码生成命令

所有代码生成命令都继承自 `ModuleGeneratorCommand`，具有以下共同特性：

- **参数**：
  - `module`：模块名称（必须存在）
  - `name`：要生成的类名称
- **选项**：
  - `--force`：覆盖已存在的文件
- **工作原理**：在指定模块的相应目录中生成类文件，使用预定义的存根模板

### module:make

**签名**：`module:make {name : 模块名称} {--force : 覆盖现有文件}`

**描述**：创建新模块，生成完整的模块目录结构和基础文件。

**生成的文件**：
- 模块类文件（如 `BlogModule.php`）
- 配置文件（`config/blog.php`）
- 路由文件（`routes/web.php`，`routes/api.php`）
- 服务提供者文件
- 完整的目录结构（Controllers、Models、Views等）

**示例**：
```bash
# 创建 Blog 模块
php artisan module:make Blog

# 强制创建（覆盖已存在的模块）
php artisan module:make Blog --force
```

### module:make:cast

**签名**：`module:make:cast {module : 模块名称} {name : 类型转换名称} {--force : 覆盖现有文件}`

**描述**：为模块创建新的类型转换（Cast）类。

**生成位置**：`模块路径/Casts/`

**示例**：
```bash
# 在 Blog 模块中创建 MoneyCast 类型转换
php artisan module:make:cast Blog MoneyCast
```

### module:make:channel

**签名**：`module:make:channel {module : 模块名称} {name : 频道名称} {--force : 覆盖现有文件}`

**描述**：为模块创建新的广播频道（Channel）类。

**生成位置**：`模块路径/Broadcasting/Channels/`

**示例**：
```bash
# 在 Blog 模块中创建 PostChannel 广播频道
php artisan module:make:channel Blog PostChannel
```

### module:make:component

**签名**：`module:make:component {module : 模块名称} {name : 组件名称} {--force : 覆盖现有文件}`

**描述**：为模块创建新的视图组件（Component）类。

**生成位置**：`模块路径/View/Components/`

**示例**：
```bash
# 在 Blog 模块中创建 PostCard 视图组件
php artisan module:make:component Blog PostCard
```

### module:make:contract

**签名**：`module:make:contract {module : 模块名称} {name : 契约名称} {--force : 覆盖现有文件}`

**描述**：为模块创建新的契约（Contract/Interface）类。

**生成位置**：`模块路径/Contracts/`

**示例**：
```bash
# 在 Blog 模块中创建 PostRepositoryInterface 契约
php artisan module:make:contract Blog PostRepositoryInterface
```

### module:make:controller

**签名**：`module:make:controller {module : 模块名称} {name : 控制器名称} {--force : 覆盖现有文件}`

**描述**：为模块创建新的控制器（Controller）类。

**生成位置**：`模块路径/Http/Controllers/`

**示例**：
```bash
# 在 Blog 模块中创建 PostController 控制器
php artisan module:make:controller Blog PostController
```

### module:make:event

**签名**：`module:make:event {module : 模块名称} {name : 事件名称} {--force : 如果文件存在则覆盖}`

**描述**：为模块创建新的事件（Event）类。

**生成位置**：`模块路径/Events/`

**示例**：
```bash
# 在 Blog 模块中创建 PostCreated 事件
php artisan module:make:event Blog PostCreated
```

### module:make:exception

**签名**：`module:make:exception {module : 模块名称} {name : 异常名称} {--force : 如果文件存在则覆盖}`

**描述**：为模块创建新的异常（Exception）类。

**生成位置**：`模块路径/Exceptions/`

**示例**：
```bash
# 在 Blog 模块中创建 PostNotFoundException 异常
php artisan module:make:exception Blog PostNotFoundException
```

### module:make:factory

**签名**：`module:make:factory {module : 模块名称} {name : 工厂名称} {--force : 如果文件存在则覆盖}`

**描述**：为模块创建新的工厂（Factory）类。

**生成位置**：`模块路径/Database/Factories/`

**示例**：
```bash
# 在 Blog 模块中创建 PostFactory 工厂
php artisan module:make:factory Blog PostFactory
```

### module:make:job

**签名**：`module:make:job {module : 模块名称} {name : 任务名称} {--force : 如果文件存在则覆盖}`

**描述**：为模块创建新的任务（Job）类。

**生成位置**：`模块路径/Jobs/`

**示例**：
```bash
# 在 Blog 模块中创建 ProcessPostJob 任务
php artisan module:make:job Blog ProcessPostJob
```

### module:make:listener

**签名**：`module:make:listener {module : 模块名称} {name : 监听器名称} {--force : 如果文件已存在，则强制覆盖}`

**描述**：为模块创建新的监听器（Listener）类。

**生成位置**：`模块路径/Listeners/`

**示例**：
```bash
# 在 Blog 模块中创建 SendPostNotificationListener 监听器
php artisan module:make:listener Blog SendPostNotificationListener
```

### module:make:mail

**签名**：`module:make:mail {module : 模块名称} {name : 邮件类名称} {--force : 如果文件已存在，则强制覆盖}`

**描述**：为模块创建新的可邮寄（Mail）类。

**生成位置**：`模块路径/Mail/`

**示例**：
```bash
# 在 Blog 模块中创建 PostPublishedMail 邮件类
php artisan module:make:mail Blog PostPublishedMail
```

### module:make:middleware

**签名**：`module:make:middleware {module : 模块名称} {name : 中间件名称} {--force : 如果文件已存在，则强制覆盖}`

**描述**：为模块创建新的中间件（Middleware）类。

**生成位置**：`模块路径/Http/Middleware/`

**示例**：
```bash
# 在 Blog 模块中创建 CheckPostOwnership 中间件
php artisan module:make:middleware Blog CheckPostOwnership
```

### module:make:migration

**签名**：`module:make:migration {module : 模块名称} {name : 迁移名称} {--table= : 表名} {--create : 创建新表} {--force : 覆盖现有文件}`

**描述**：为模块创建新的迁移（Migration）文件。

**生成位置**：`模块路径/database/migrations/`

**选项**：
- `--table=`：指定要操作的表名
- `--create`：指定创建新表（而不是修改现有表）

**示例**：
```bash
# 创建 posts 表的迁移
php artisan module:make:migration Blog create_posts_table --create=posts

# 修改现有表的迁移
php artisan module:make:migration Blog add_status_to_posts_table --table=posts
```

### module:make:model

**签名**：`module:make:model {module : 模块名称} {name : 模型名称} {--force : 覆盖现有文件}`

**描述**：为模块创建新的模型（Model）类。

**生成位置**：`模块路径/Models/`

**示例**：
```bash
# 在 Blog 模块中创建 Post 模型
php artisan module:make:model Blog Post
```

### module:make:notification

**签名**：`module:make:notification {module : 模块名称} {name : 通知名称} {--force : 如果文件存在则覆盖}`

**描述**：为模块创建新的通知（Notification）类。

**生成位置**：`模块路径/Notifications/`

**示例**：
```bash
# 在 Blog 模块中创建 PostPublishedNotification 通知
php artisan module:make:notification Blog PostPublishedNotification
```

### module:make:observer

**签名**：`module:make:observer {module : 模块名称} {name : 观察者名称} {--force : 覆盖现有文件}`

**描述**：为模块创建新的观察者（Observer）类。

**生成位置**：`模块路径/Observers/`

**示例**：
```bash
# 在 Blog 模块中创建 PostObserver 观察者
php artisan module:make:observer Blog PostObserver
```

### module:make:policy

**签名**：`module:make:policy {module : 模块名称} {name : 策略类名称} {--force : 如果文件已存在，则强制覆盖}`

**描述**：为模块创建新的策略（Policy）类。

**生成位置**：`模块路径/Policies/`

**示例**：
```bash
# 在 Blog 模块中创建 PostPolicy 策略
php artisan module:make:policy Blog PostPolicy
```

### module:make:repository

**签名**：`module:make:repository {module : 模块名称} {name : 仓库名称} {--force : 覆盖已存在的文件}`

**描述**：为模块创建新的仓库（Repository）类。

**生成位置**：`模块路径/Repositories/`

**示例**：
```bash
# 在 Blog 模块中创建 PostRepository 仓库
php artisan module:make:repository Blog PostRepository
```

### module:make:request

**签名**：`module:make:request {module : 模块名称} {name : 请求类名称} {--force : 如果文件已存在，则强制覆盖}`

**描述**：为模块创建新的表单请求（Form Request）类。

**生成位置**：`模块路径/Http/Requests/`

**示例**：
```bash
# 在 Blog 模块中创建 StorePostRequest 请求类
php artisan module:make:request Blog StorePostRequest
```

### module:make:resource

**签名**：`module:make:resource {module : 模块名称} {name : 资源类名称} {--force : 如果文件已存在，则强制覆盖}`

**描述**：为模块创建新的资源（Resource）类。

**生成位置**：`模块路径/Http/Resources/`

**示例**：
```bash
# 在 Blog 模块中创建 PostResource 资源类
php artisan module:make:resource Blog PostResource
```

### module:make:rule

**签名**：`module:make:rule {module : 模块名称} {name : 规则类名称} {--force : 如果文件已存在，则强制覆盖}`

**描述**：为模块创建新的验证规则（Rule）类。

**生成位置**：`模块路径/Rules/`

**示例**：
```bash
# 在 Blog 模块中创建 UniqueSlugRule 验证规则
php artisan module:make:rule Blog UniqueSlugRule
```

### module:make:seeder

**签名**：`module:make:seeder {module : 模块名称} {name : 数据填充器名称} {--force : 如果文件已存在，则强制覆盖}`

**描述**：为模块创建新的数据填充器（Seeder）类。

**生成位置**：`模块路径/Database/Seeders/`

**示例**：
```bash
# 在 Blog 模块中创建 PostsTableSeeder 数据填充器
php artisan module:make:seeder Blog PostsTableSeeder
```

### module:make:service

**签名**：`module:make:service {module : 模块名称} {name : 服务类名称} {--force : 如果文件已存在，则强制覆盖}`

**描述**：为模块创建新的服务（Service）类。

**生成位置**：`模块路径/Services/`

**示例**：
```bash
# 在 Blog 模块中创建 PostService 服务类
php artisan module:make:service Blog PostService
```

---

## 命令使用示例

### 创建新模块并生成完整结构

```bash
# 1. 创建 Blog 模块
php artisan module:make Blog

# 2. 在 Blog 模块中生成模型、控制器、迁移
php artisan module:make:model Blog Post
php artisan module:make:migration Blog create_posts_table --create=posts
php artisan module:make:controller Blog PostController

# 3. 安装模块（运行迁移、填充数据、发布资源）
php artisan module:install Blog --migrations --seeders --publish
```

### 开发工作流

```bash
# 1. 列出所有模块
php artisan module:list

# 2. 检查模块健康状态
php artisan module:check Blog

# 3. 为模块添加新功能（事件、监听器、策略）
php artisan module:make:event Blog PostPublished
php artisan module:make:listener Blog SendPostNotification
php artisan module:make:policy Blog PostPolicy

# 4. 运行模块迁移
php artisan module:migrate Blog

# 5. 发布模块资源
php artisan module:publish Blog --tag=views
```

### 维护工作流

```bash
# 1. 禁用模块进行维护
php artisan module:disable Blog

# 2. 运行模块健康检查
php artisan module:check Blog

# 3. 更新模块（运行新迁移）
php artisan module:migrate Blog

# 4. 重新启用模块
php artisan module:enable Blog

# 5. 清除模块缓存
php artisan module:clear-cache
```

### 高级用法

```bash
# 为模块生成多个相关类
php artisan module:make:model Blog Post
php artisan module:make:factory Blog PostFactory
php artisan module:make:seeder Blog PostSeeder
php artisan module:make:resource Blog PostResource
php artisan module:make:request Blog StorePostRequest

# 强制覆盖已存在的文件
php artisan module:make:controller Blog PostController --force

# 同时运行迁移和数据填充
php artisan module:migrate Blog --seed
```

---

## 注意事项

1. **模块存在性**：所有代码生成命令都需要指定已存在的模块名称。
2. **命名规范**：类名称建议使用大驼峰命名法（StudlyCase）。
3. **强制覆盖**：使用 `--force` 选项可以覆盖已存在的文件，但会丢失原有内容。
4. **依赖检查**：在安装或启用模块前，系统会检查模块依赖关系。
5. **缓存管理**：在生产环境中建议启用模块缓存以提高性能。

## 故障排除

### 命令未找到
确保已正确注册服务提供者，运行：
```bash
php artisan optimize
php artisan module:clear-cache
```

### 模块未加载
检查模块配置文件 `config/modules.php` 中的 `modules` 数组。

### 权限问题
确保存储目录（`storage/`、`bootstrap/cache/`）有正确的写入权限。

### 迁移问题
如果迁移失败，检查数据库连接和迁移文件语法。

---

**最后更新**：2025年1月16日  
**版本**：1.0.0