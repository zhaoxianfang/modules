# Laravel 模块系统 - 完整指南

一个为 Laravel 11+ 设计的现代化、工业级模块化系统，基于 PHP 8.2+ 开发。

## 📖 文档目录

### 快速开始
- [功能一览](00-overview.md) - 所有功能和配置的完整表格
- [安装指南](01-installation.md)
- [快速开始](02-quickstart.md)

### 核心功能
- [模块结构](03-module-structure.md)
- [配置详解](04-configuration.md)
- [Helper 函数](05-helper-functions.md)
- [智能模块检测](06-intelligent-detection.md)

### 路由与视图
- [路由指南](07-routes.md)
- [视图使用](08-views.md)

### 开发指南
- [命令参考](09-commands.md)
- [代码生成](10-code-generation.md)
- [迁移管理](11-migrations.md)

### 最佳实践
- [最佳实践](12-best-practices.md)
- [性能优化](13-performance.md)
- [常见问题](14-faq.md)

### 架构说明
- [架构设计](15-architecture.md)
- [核心组件](16-core-components.md)

## 🚀 特性

- **现代化架构**：专为 Laravel 11+ 和 PHP 8.2+ 设计
- **配置驱动**：通过 config 控制所有模块行为，无需 JSON 文件
- **模块启用/禁用**：通过配置文件控制模块是否启用，禁用时完全不加载模块组件
- **动态路由生成**：路由前缀和名称前缀根据配置动态生成
- **自动发现**：自动发现模块的服务提供者、路由、命令等
- **灵活配置**：支持多路由中间件组、控制器命名空间映射
- **功能完整**：支持路由、视图、配置、迁移、命令、事件等完整功能
- **信息统计**：提供详细的模块信息和验证功能
- **迁移增强**：完整的迁移管理命令，包括状态查看
- **助手函数**：40+ 个便捷助手函数，大部分支持无参调用
- **模块验证**：验证模块的完整性和正确性
- **模板系统**：基于 stubs 的代码生成模板系统
- **视图命名空间**：支持模块视图命名空间，如 `blog::list.test`
- **路由映射**：灵活的路由控制器命名空间映射
- **多路径扫描**：支持多个模块目录扫描
- **智能检测**：自动检测当前模块，支持嵌套配置读取
- **高性能**：优化的核心函数，保证生产环境高效运行

## 📦 快速安装

```bash
composer require zxf/modules
```

### 1. 发布配置文件

```bash
php artisan vendor:publish --provider="zxf\\Modules\\ModulesServiceProvider"
```

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

### 6. 运行模块迁移

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

### 7. 删除模块

```bash
php artisan module:delete Blog
```

## 💡 核心功能示例

### 智能当前模块检测

```php
// 在模块内部的任何地方
$moduleName = module_name(); // 自动返回 'Blog'

// 读取配置
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
    ├── Observers/             # 模型观察者
    ├── Policies/              # 策略类
    ├── Repositories/          # 仓库类
    └── Tests/                # 测试文件
```

## 🎯 配置示例

模块系统的所有配置都在 `config/modules.php` 文件中：

```php
return [
    'namespace' => 'Modules',
    'path' => base_path('Modules'),
    'middleware_groups' => [
        'web' => ['web'],
        'api' => ['api'],
        'admin' => ['web', 'admin'],
    ],
    // ... 更多配置
];
```

详细配置说明请参考 [配置详解](04-configuration.md)。

## 🔧 核心特性

### 1. 智能当前模块检测

系统提供 `module_name()` 函数，可以自动检测当前代码所在的模块：

```php
class PostController extends Controller
{
    public function index()
    {
        $moduleName = module_name(); // 自动返回 'Blog'
        // 无需传递任何参数
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
```

### 3. 路径助手函数

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
```

更多 Helper 函数请参考 [Helper 函数详解](05-helper-functions.md)。

## 🔄 版本更新

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

欢迎提交 Issue 和 Pull Request！请查看 [贡献指南](../CONTRIBUTING.md) 了解详情。

## 📄 许可证

MIT License

## 🔗 相关链接

- [GitHub 仓库](https://github.com/zxf/modules)
- [问题反馈](https://github.com/zxf/modules/issues)
- [更新日志](../CHANGELOG.md)
