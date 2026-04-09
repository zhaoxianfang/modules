# Laravel 模块系统 - 完整指南

一个为 Laravel 11+ 设计的现代化、工业级模块化系统，基于 PHP 8.2+ 开发。


## 📦 快速安装

### 通过 Composer 安装

```bash
composer require zxf/modules
```

### 1. 发布配置文件

```bash
php artisan vendor:publish --provider="zxf\\Modules\\ModulesServiceProvider"
```

配置文件会发布到：`config/modules.php`

### 2. 创建第一个模块

```bash
php artisan module:make Blog
```

### 3. 查看模块列表

```bash
php artisan module:list
```

### 4. 查看模块详细信息

```bash
php artisan module:info Blog
```

### 5. 验证模块

```bash
php artisan module:validate Blog
```

### 6. 发布多模块资源

```bash
# 发布所有资源（用户指南、配置文件）
php artisan module:publish

# 仅发布用户指南
php artisan module:publish --guide

# 仅发布配置文件
php artisan module:publish --config

# 强制覆盖已存在的文件
php artisan module:publish --force
```

发布后，多模块用户指南将位于：`Modules/ModulesUserGuide.md`

### 7. 运行模块迁移

```bash
# 运行所有模块的迁移
php artisan module:migrate

# 运行指定模块的迁移
php artisan module:migrate Blog

# 查看迁移状态
php artisan module:migrate-status

# 回滚迁移
php artisan module:migrate:reset Blog

# 刷新迁移（回滚并重新运行）
php artisan module:migrate:refresh Blog
```

### 8. 运行模块命令

```bash
# 运行模块的默认命令
php artisan blog:command

# 创建自定义命令
php artisan module:make-command Blog TestCommand --command=blog:test

# 运行自定义命令
php artisan blog:test

# 调试命令注册
php artisan module:debug-commands --module=Blog
```

### 9. 删除模块

```bash
# 删除模块（会提示确认）
php artisan module:delete Blog

# 强制删除（不提示确认）
php artisan module:delete Blog --force
```


## 扩展宏（MySQL 8.4+ 优化版）

> 弥补 Laravel 查询缺陷，专为 Laravel 11+ 和 MySQL 8.4+ 设计的高性能查询扩展
> 
> **新特性 v2.0**:
> - 8大类 100+ 宏函数
> - MySQL 8.4+ 窗口函数完整支持
> - 超大表快速分页（无需游标ID）
> - 高级 JSON 操作
> - 正则表达式查询
> - 纯 SQL 优化，无需缓存

### 功能概览

| 类别 | 功能数 | 说明 |
|------|--------|------|
| whereHas优化 | 10+ | 解决关联查询全表扫描问题 |
| 随机查询 | 2 | 高效随机数据获取 |
| 窗口函数 | 25+ | MySQL 8.4+ 窗口函数完整支持 |
| 递归查询 | 10 | 树形结构数据处理 |
| 分页优化 | 5 | 超大表快速分页 |
| JSON操作 | 20+ | 高级JSON查询和操作 |
| 正则表达式 | 15+ | 强大的文本匹配功能 |
| 主表字段 | 8 | 自动表前缀避免歧义 |

### 快速示例

```php
// 窗口函数排名
Employee::query()
    ->rowNumber('department_id', 'salary', 'desc', 'rank_in_dept')
    ->rank(null, 'score', 'desc', 'competition_rank')
    ->get();

// 超大表快速分页（第5000页）
$records = BigTable::query()->fastPaginate(30, 5000);

// 游标分页（性能最佳）
$posts = Post::query()->cursorPaginate(20, null, 'published_at', 'desc');

// JSON 高级查询
User::query()
    ->jsonPath('settings', '$.notifications.email', 'email_enabled', false)
    ->whereJsonArrayContains('tags', 'php')
    ->get();

// 正则表达式匹配
User::query()->whereRegexp('email', '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$')->get();
```

### random / groupRandom - 随机查询

```php
// 随机查询5条记录
Student::where('class_id', 101)->random(5)->get();

// 每个班级随机选择2名学生
Student::groupRandom('class_id', 2)->get();
```

### whereHasIn 系列 - 关联查询优化

```php
// 优化版关联查询（避免全表扫描）
User::query()->whereHasIn('posts', fn($q) => $q->where('status', 1))->get();

// 关联 JOIN 查询
User::query()->whereHasLeftJoin('orders')->get();

// 多态关联
Comment::query()->whereHasMorphIn('commentable', [Post::class, Video::class])->get();
```

### 窗口函数 - MySQL 8.4+ 专用

```php
// 排名函数
Employee::query()->rowNumber('dept_id', 'salary', 'desc', 'row_num')->get();
Employee::query()->rank('dept_id', 'salary', 'desc', 'rank_num')->get();
Employee::query()->denseRank('dept_id', 'salary', 'desc', 'dense_rank')->get();

// 偏移函数（环比分析）
Sales::query()->lag('amount', 1, 0, null, 'date', 'asc', 'prev_day')->get();
Sales::query()->lead('amount', 1, null, null, 'date', 'asc', 'next_day')->get();

// 聚合窗口
Sales::query()->sumOver('amount', 'region', null, 'asc', 'dept_total')->get();

// 累计统计
Sales::query()->cumulativeSum('amount', 'region', 'date', 'asc', 'running_total')->get();
Stock::query()->movingAverage('price', 5, 'code', 'date', 'asc', 'ma5')->get();
```

### 分页优化 - 超大表解决方案

```php
// 智能快速分页（自动选择最优策略）
$users = User::query()->fastPaginate(20);

// 简单分页（不计算总数，性能更好）
$posts = Post::query()->fastSimplePaginate(10);

// 游标分页（键集分页，性能最佳）
$items = Item::query()->cursorPaginate(20, null, 'created_at', 'desc');

// 寻址分页（适合深度分页）
$page100 = BigTable::query()->seekPaginate(100, $bookmarks, 100);
```

### JSON 操作 - MySQL 8.4+ JSON函数

```php
// JSON 路径提取
User::query()->jsonPath('settings', '$.email', 'user_email')->get();
User::query()->jsonExtract('data', '$.count', 'int', 'item_count', 0)->get();

// JSON 数组操作
Article::query()->whereJsonArrayContains('tags', 'php')->get();
Article::query()->whereJsonArrayContainsAny('tags', ['php', 'laravel'])->get();
Article::query()->jsonArrayLength('tags', null, 'tag_count')->get();

// JSON 存在性检查
User::query()->whereJsonPathExists('settings', '$.notifications')->get();
```

### 正则表达式 - 文本匹配

```php
// 正则匹配
User::query()->whereRegexp('email', '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$')->get();

// 正则提取
User::query()->regexpExtract('email', '@([^@]+)$', 1, 1, 'i', 'domain')->get();

// 正则替换
User::query()->regexpReplace('phone', '(\d{3})\d{4}(\d{4})', '$1****$2', 0, 'c', 'masked_phone')->get();
```

### 更多文档

详细文档请查看：[扩展宏完整文档](src/BuilderQuery/readme.md)


## 📖 文档目录

### 快速开始
- [功能一览](docs/00-overview.md) - 所有功能和配置的完整表格
- [安装指南](docs/01-installation.md)
- [快速开始](docs/02-quickstart.md)

### 核心功能
- [模块结构](docs/03-module-structure.md)
- [配置详解](docs/04-configuration.md)
- [Helper 函数](docs/05-helper-functions.md)
- [智能模块检测](docs/06-intelligent-detection.md)

### 路由与视图
- [路由指南](docs/07-routes.md)
- [视图使用](docs/08-views.md)

### 开发指南
- [命令参考](docs/09-commands.md)
- [代码生成](docs/10-code-generation.md)
- [迁移管理](docs/11-migrations.md)
- [自动发现机制](docs/14-auto-discovery.md)
- [Stub 模板映射](docs/15-stub-mapping.md)

### 最佳实践
- [最佳实践](docs/12-best-practices.md)
- [架构设计](docs/13-architecture.md)

## 🚀 特性

- **现代化架构**：专为 Laravel 11+ 和 PHP 8.2+ 设计
- **配置驱动**：通过 config 控制所有模块行为，无需 JSON 文件
- **模块启用/禁用**：通过配置文件控制模块是否启用，禁用时完全不加载模块组件
- **动态路由生成**：路由前缀和名称前缀根据配置动态生成
- **自动发现机制**：自动发现模块的服务提供者、路由、命令、事件等
- **灵活配置**：支持多路由中间件组、控制器命名空间映射
- **功能完整**：支持路由、视图、配置、迁移、命令、事件等完整功能
- **信息统计**：提供详细的模块信息和验证功能
- **迁移增强**：完整的迁移管理命令，包括状态查看和统计信息
- **助手函数**：40+ 个便捷助手函数，大部分支持无参调用
- **模块验证**：验证模块的完整性和正确性
- **模板系统**：基于 stubs 的代码生成模板系统
- **视图命名空间**：支持模块视图命名空间，如 `blog::list.test`
- **路由映射**：灵活的路由控制器命名空间映射
- **多路径扫描**：支持多个模块目录扫描
- **智能检测**：自动检测当前模块，支持嵌套配置读取
- **高性能**：优化的核心函数，保证生产环境高效运行
- **命令自动注册**：模块命令自动发现并注册到 Laravel Console Application
- **详细的中文日志**：所有操作都有详细的中文日志记录
- **智能模型生成**：支持从数据库表自动解析字段信息，生成完整的 Eloquent 模型
- **字段注释解析**：自动读取数据库字段注释并生成到模型的 PHPDoc 中
- **类型智能映射**：自动将数据库字段类型映射到 Laravel 类型转换格式
- **Carbon 集成**：datetime/timestamp 字段自动使用 Carbon 类型
- **迁移状态过滤**：支持按状态筛选迁移（已运行/待运行）
- **迁移统计信息**：显示迁移统计汇总信息
- **扩展查询宏**：支持whereHasIn、orWhereHasIn、whereHasNotIn、random、groupRandom 等宏查询

## 💡 核心功能示例

### 智能当前模块检测

系统会自动检测当前代码所在的模块，无需手动传递模块名称：

```php
// 在模块内部的任何地方
$moduleName = module_name(); // 自动返回 'Blog'
$enabled = module_enabled();  // 检查当前模块是否启用

// 读取模块配置（自动检测当前模块）
$name = module_config('common.name', 'hello');
$cache = module_config('settings.cache.enabled', false);
```

### 获取模块路径

```php
// 自动检测当前模块
$path = module_path(null, 'Models/Post.php');
$path = module_path('Config/common.php');

// 指定模块名
$path = module_path('Blog', 'Models/Post.php');

// 获取各种类型的路径
$configPath = module_config_path('common.php');
$routePath = module_routes_path('web.php');
$migrationPath = module_migrations_path();
$modelsPath = module_models_path();
$controllersPath = module_controllers_path('Web');
$viewsPath = module_views_path();
```

### 返回模块视图

```php
// 自动检测当前模块
return module_view('post.index', compact('posts'));

// 指定模块名
return module_view('Blog', 'post.index', ['posts' => $posts]);
```

### 生成路由 URL

```php
// 自动检测当前模块
$url = module_route('posts.index');
$url = module_route('posts.show', ['id' => 1]);

// 指定模块名
$url = module_route('Blog', 'posts.index');
```

## 🎯 配置示例

详细配置说明请参考 [配置详解](docs/04-configuration.md)。

## 🔧 核心特性

### 1. 智能当前模块检测

系统提供 `module_name()` 函数，可以自动检测当前代码所在的模块：

```php
class PostController extends Controller
{
    public function index()
    {
        $moduleName = module_name(); // 自动返回 'Blog'
        $path = module_path();     // 自动获取 Blog 模块的路径
        $config = module_config('common.name'); // 自动读取 Blog 模块的配置
        
        // 所有函数都无需传递任何参数
    }
}
```

### 2. 增强的配置读取

`module_config()` 函数支持两种使用方式：

**方式 1：指定模块名称（传统方式）**
```php
$value = module_config('common.name', 'default', 'Blog');
```

**方式 2：使用当前模块（智能方式）⭐ 推荐**

```php
// 读取 Config/common.php 的 name 配置
$value = module_config('common.name', 'hello');

// 读取嵌套配置
$enabled = module_config('settings.cache.enabled', false);

// 无需传递模块名，自动检测
```

### 3. 完整的路径助手函数

```php
// 模块路径
module_path('Models');              // 当前模块的 Models 目录
module_path('Blog', 'Models');     // Blog 模块的 Models 目录

// 配置文件路径
module_config_path('common.php');  // 当前模块的 Config/common.php

// 路由文件路径
module_routes_path('web');         // 当前模块的 Routes/web.php

// 迁移目录路径
module_migrations_path();          // 当前模块的 Database/Migrations

// 模型目录路径
module_models_path();              // 当前模块的 Models

// 控制器目录路径
module_controllers_path('Web');    // 当前模块的 Http/Controllers/Web

// 视图目录路径
module_views_path();              // 当前模块的 Resources/views

// 资源目录路径
module_resources_path('assets');  // 当前模块的 Resources/assets

// 语言目录路径
module_lang_path();              // 当前模块的 Resources/lang
```

### 4. 命令自动注册

模块中的命令会自动发现并注册到 Laravel Console Application：

自动注册，无需手动配置
可以直接运行：php artisan blog:command

## 📝 Helper 函数

模块系统提供了 40+ 个助手函数，大大简化模块操作。大部分函数支持无参调用，会自动检测当前所在模块。

### 核心函数

```php
// 获取当前模块名称（精确检测，不使用缓存）
$moduleName = module_name(); // 'Blog'

// 智能配置读取（推荐）
$name = module_config('common.name', 'hello');
$cache = module_config('settings.cache.enabled', false);

// 检查模块状态
if (module_enabled()) {
    // 模块已启用
}

// 获取模块路径
$path = module_path(null, 'Models/Post.php');

// 返回模块视图
return module_view(null, 'post.index', compact('posts'));

// 生成模块路由 URL
$url = module_route('posts.index', ['id' => 1]);

// 检查视图是否存在
if (module_has_view('post.index')) {
    // 视图存在
}
```

### 路径相关函数

```php
module_path();                  // 模块根路径
module_config_path();          // 配置文件路径
module_routes_path();          // 路由文件路径
module_migrations_path();      // 迁移文件路径
module_models_path();          // 模型路径
module_controllers_path();      // 控制器路径
module_views_path();           // 视图路径
module_resources_path();       // 资源路径
module_lang_path();           // 语言文件路径
```

### 视图相关函数

```php
module_view();              // 返回模块视图
module_has_view();          // 检查视图是否存在
```

### 路由相关函数

```php
module_route();            // 生成模块路由 URL
module_has_route();        // 检查路由是否存在
```

更多 Helper 函数请参考 [Helper 函数详解](docs/05-helper-functions.md)。

## 🛠️ 开发工具

### 代码生成命令

```bash
# 创建模块
php artisan module:make Blog

# 创建控制器
php artisan module:make-controller Blog PostController
php artisan module:make-controller Blog PostController --web
php artisan module:make-controller Blog PostController --api

# 创建模型
php artisan module:make-model Blog Post

# 创建请求验证
php artisan module:make-request Blog PostRequest

# 创建迁移
php artisan module:make-migration Blog create_posts_table
php artisan module:make-migration Blog create_posts_table --create=posts

# 创建事件和监听器
php artisan module:make-event Blog PostCreated
php artisan module:make-listener Blog PostCreatedListener --event=PostCreated

# 创建中间件
php artisan module:make-middleware Blog CheckPostStatus

# 创建服务提供者
php artisan module:make-provider Blog CustomProvider

# 创建命令
php artisan module:make-command Blog TestCommand --command=blog:test

# 创建数据填充器
php artisan module:make-seeder Blog PostSeeder

# 创建策略
php artisan module:make-policy Blog PostPolicy

# 创建观察者
php artisan module:make-observer Blog PostObserver
```

### 模块管理命令

```bash
# 列出所有模块
php artisan module:list

# 查看模块详细信息
php artisan module:info Blog

# 验证模块完整性
php artisan module:validate Blog

# 调试命令
php artisan module:debug-commands --module=Blog
```

### 迁移管理命令

```bash
# 运行迁移
php artisan module:migrate
php artisan module:migrate Blog

# 查看迁移状态
php artisan module:migrate-status

# 回滚迁移
php artisan module:migrate:reset Blog

# 刷新迁移
php artisan module:migrate:refresh Blog
```

## 🤝 贡献

欢迎提交 Issue 和 Pull Request。

在提交 PR 之前，请确保：

1. 代码遵循 PSR-12 编码标准
2. 所有函数都有完整的中文注释
3. 添加相应的测试用例
4. 更新相关文档

## 📄 许可证

MIT License

## 🔗 相关链接

- [GitHub 仓库](https://github.com/zhaoxianfang/modules)
- [问题反馈](https://github.com/zhaoxianfang/modules/issues)
- [功能建议](https://github.com/zhaoxianfang/modules/discussions)

## ⭐ 支持

如果这个项目对你有帮助，请给它一个 star ⭐
