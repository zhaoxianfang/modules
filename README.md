# Laravel 模块系统 - 完整指南

一个为 Laravel 11+ 设计的现代化、工业级模块化系统，基于 PHP 8.2+ 开发。

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

### 调试与故障排除
- [命令故障排除](docs/16-command-troubleshooting.md)
- [命令测试指南](docs/17-command-testing-guide.md)

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

## 📚 模块结构

模块创建后会生成以下目录结构：

```
Modules/
└── Blog/
    ├── Config/
    │   ├── config.php           # 模块配置文件（必需）
    │   ├── common.php          # 自定义配置文件
    │   └── settings.php       # 自定义配置文件
    ├── Console/
    │   └── Commands/          # Artisan 命令
    ├── Database/
    │   ├── Migrations/         # 数据库迁移文件
    │   └── Seeders/            # 数据填充器
    ├── Http/
    │   ├── Controllers/         # 控制器
    │   │   ├── Controller.php  # 基础控制器
    │   │   ├── Web/          # Web 控制器
    │   │   ├── Api/          # API 控制器
    │   │   └── Admin/        # Admin 控制器
    │   ├── Middleware/         # 中间件
    │   └── Requests/           # 表单请求验证
    ├── Models/                  # 模型
    ├── Observers/              # 模型观察者
    ├── Providers/
    │   └── BlogServiceProvider.php  # 模块服务提供者（必需）
    ├── Resources/
    │   ├── assets/             # 静态资源
    │   ├── lang/               # 语言文件
    │   └── views/             # 视图文件
    ├── Routes/
    │   ├── web.php            # Web 路由
    │   ├── api.php            # API 路由
    │   └── admin.php          # Admin 路由
    ├── Events/                 # 事件
    ├── Listeners/              # 事件监听器
    ├── Policies/               # 策略类
    ├── Repositories/           # 仓库类
    └── Tests/                  # 测试文件
```

## 🎯 配置示例

模块系统的所有配置都在 `config/modules.php` 文件中：

```php
return [
    // 命名空间配置
    'namespace' => 'Modules',
    
    // 模块路径
    'path' => base_path('Modules'),
    
    // 中间件组配置
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
    
    // 模块启用列表
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

```php
// 模块中的命令：Modules/Blog/Console/Commands/TestCommand.php
class TestCommand extends Command
{
    protected $signature = 'blog:test';
    protected $description = '测试命令';
    
    public function handle(): int
    {
        $this->info('测试命令执行成功！');
        return Command::SUCCESS;
    }
}

// 自动注册，无需手动配置
// 可以直接运行：php artisan blog:test
```

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

## 🔄 版本更新

### v2.4.0 (2026-01-23)
- 🛡️ **安全增强**：
  - 修复 `module:migrate-status` 命令参数类型错误
  - 增强 `module:make-model` SQL 查询安全性，使用 Schema::hasTable() 检查表
  - 改进 `module:make-route` 路由类型验证和错误处理
  - 修复 `SHOW TABLES LIKE` SQL 语法错误，改用 Laravel Schema facade
- 🔧 **代码质量优化**：
  - 全面检查所有 Command 类，修复类型声明问题
  - 优化异常处理机制，增强错误提示
  - 统一代码风格和注释规范
- 🐛 **Bug 修复**：
  - 修复 `getMigrationStatus` 方法参数类型声明错误
  - 修复 `module:make-model` 命令 SQL 语法错误（MySQL 不支持 SHOW TABLES LIKE 参数绑定）
  - 修复路径遍历潜在风险，添加路径校验
- 📝 **文档更新**：
  - 更新迁移状态命令文档
  - 完善安全相关说明
  - 代码注释全面中文化

### v2.3.0 (2026-01-21)
- 📚 **多模块用户指南**：新增 `ModulesUserGuide.md` 统一用户指南
- 🚀 **模块发布命令**：新增 `module:publish` 命令发布资源
- 📖 **自动发布指南**：创建模块时自动发布用户指南
- 🗑️ **移除模块 README**：不再为每个模块创建 README.md
- 📝 **加强中文提示**：所有命令的注释和提示都使用中文
- 🎯 **用户体验优化**：更友好的命令输出和错误提示
- 🔧 **整体优化**：全面优化项目代码和文档

### v2.2.0 (2026-01-20)
- 🎯 **修复命令注册问题**：彻底解决模块命令无法执行的问题
- 🚀 **命令自动发现**：模块命令自动注册到 Laravel Console Application
- 🛠️ **全局命令缓存**：使用静态缓存优化命令注册性能
- 📝 **命令签名优化**：修复命令签名生成逻辑，使用正确的格式
- 🔧 **降级注册方案**：多层降级机制确保命令可靠注册
- 📚 **调试工具**：新增 `module:debug-commands` 命令
- 📖 **完整文档**：新增命令故障排除和测试指南

### v2.1.0
- 🎯 智能当前模块检测：`module_name()` 无需传递参数
- 📝 增强配置读取：支持 `module_config('common.name', 'default')` 格式
- 🔧 完善配置加载器：支持当前模块配置文件读取
- 🛠️ 优化路由加载：更灵活的路由和控制器处理
- 📦 新增多个助手函数：`module_has_view`、`module_config_path` 等
- 📚 完善文档：详细说明新功能和使用方法

### v2.0.0
- 🎨 全新基于 stubs 的模板系统
- 🖼️ 支持模块视图命名空间
- 🌍 增强的路由控制器命名空间映射
- 📂 支持多路径模块扫描
- 🔧 完善的配置选项
- 📝 更多助手函数
- 🚀 性能优化
- 🐛 Bug 修复

### v1.0.0
- 🎉 初始版本发布

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

- [GitHub 仓库](https://github.com/zxf/modules)
- [问题反馈](https://github.com/zxf/modules/issues)
- [功能建议](https://github.com/zxf/modules/discussions)

## ⭐ 支持

如果这个项目对你有帮助，请给它一个 star ⭐

---

**开发团队**：zxf
**版本**：2.4.0
**Laravel 版本**：11+
**PHP 版本**：8.2+
