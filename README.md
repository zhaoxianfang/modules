# Laravel 模块系统

一个为 Laravel 11+ 设计的轻量级模块化系统。

## 功能特性

- **模块自动发现**：模块自动从文件系统发现
- **模块生命周期**：注册、引导、安装、卸载钩子
- **资源自动加载**：自动加载路由、迁移、视图、配置、翻译
- **中间件支持**：为 Web 和 API 路由注册中间件
- **依赖管理**：声明和检查模块依赖
- **事件系统**：ModuleEnabled 和 ModuleDisabled 事件
- **Artisan 命令**：创建、列表、启用、禁用、发布、缓存、检查、安装、卸载模块
- **缓存支持**：缓存模块发现以提升性能
- **健康检查**：验证模块依赖和兼容性
- **基于优先级的引导顺序**：控制模块引导顺序
- **配置驱动生成**：通过配置枚举可创建的模块，控制是否生成文件
- **自动发现机制**：自动发现并注册模块下的路由、命令、事件、监听器、观察者、迁移
- **模块独立操作**：支持每个模块单独创建和执行迁移、命令等，与 Laravel App 目录功能一致
- **模块状态管理**：通过模块自身的 `config/config.php` 文件中的 `enabled` 配置项控制启用/停用
- **辅助函数**：提供丰富的模块相关辅助函数
- **PHP 8.2+ 代码规范**：所有代码满足 PHP 8.2+ 的代码风格和规范

## 环境要求

- PHP 8.2+
- Laravel 11+

## 安装

通过 Composer 安装：

```bash
composer require zxf/modules
```

## 配置

发布配置文件：

```bash
php artisan vendor:publish --tag=modules-config
```

这会创建 `config/modules.php` 文件，包含以下配置选项：

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 模块路径
    |--------------------------------------------------------------------------
    |
    | 此路径用于存储模块。
    |
    */
    'path' => \base_path('modules'),

    /*
    |--------------------------------------------------------------------------
    | 模块命名空间
    |--------------------------------------------------------------------------
    |
    | 此命名空间用于模块。
    |
    */
    'namespace' => 'Modules',

    /*
    |--------------------------------------------------------------------------
    | 启用自动发现
    |--------------------------------------------------------------------------
    |
    | 如果启用，模块将自动发现并引导。
    |
    */
    'auto_discovery' => true,

    /*
    |--------------------------------------------------------------------------
    | 启用缓存
    |--------------------------------------------------------------------------
    |
    | 如果启用，模块发现将被缓存。
    |
    */
    'cache' => false,

    /*
    |--------------------------------------------------------------------------
    | 缓存键
    |--------------------------------------------------------------------------
    |
    | 用于存储模块发现的缓存键。
    |
    */
    'cache_key' => 'zxf.modules',

    /*
    |--------------------------------------------------------------------------
    | 缓存时长
    |--------------------------------------------------------------------------
    |
    | 缓存时长（秒）。默认：3600（1小时）。
    |
    */
    'cache_duration' => 3600,

    /*
    |--------------------------------------------------------------------------
    | 默认优先级
    |--------------------------------------------------------------------------
    |
    | 模块的默认优先级（数字越大优先级越高）。
    | 当模块未定义自身优先级时使用。
    |
    */
    'default_priority' => 100,

    /*
    |--------------------------------------------------------------------------
    | 依赖检查
    |--------------------------------------------------------------------------
    |
    | 是否在启用前检查模块依赖。
    |
    */
    'dependency_check' => true,

    /*
    |--------------------------------------------------------------------------
    | 自动注册服务提供者
    |--------------------------------------------------------------------------
    |
    | 是否自动注册模块服务提供者。
    | 如果禁用，您需要在 config/app.php 中手动注册提供者。
    |
    */
    'auto_register_providers' => true,

    /*
    |--------------------------------------------------------------------------
    | 模块存根路径
    |--------------------------------------------------------------------------
    |
    | 自定义模块存根路径。如果为 null，使用包的默认存根。
    |
    */
    'stubs_path' => null,

    /*
    |--------------------------------------------------------------------------
    | 模块资源 URL
    |--------------------------------------------------------------------------
    |
    | 模块资源的基础 URL（例如，http://example.com/modules）。
    | 由 module_asset() 辅助函数使用。
    |
    */
    'asset_url' => null,

    /*
    |--------------------------------------------------------------------------
    | 允许的模块名称
    |--------------------------------------------------------------------------
    |
    | 允许的模块名称的正则表达式模式。设置为 null 允许任何名称。
    |
    */
    'allowed_module_names' => '/^[a-zA-Z_][a-zA-Z0-9_]*$/',

    /*
    |--------------------------------------------------------------------------
    | 模块存储驱动
    |--------------------------------------------------------------------------
    |
    | 模块资源的存储驱动（例如 'local', 's3', 'ftp'）。
    | 用于模块发布功能。
    |
    */
    'storage_driver' => 'local',

    /*
    |--------------------------------------------------------------------------
    | 模块发布组
    |--------------------------------------------------------------------------
    |
    | 定义使用 module:publish 命令时要发布的资源。
    | 组：config, migrations, views, translations, assets, commands
    |
    */
    'publish_groups' => [
        'config',
        'migrations',
        'views',
        'translations',
        'assets',
        'commands',
    ],

    /*
    |--------------------------------------------------------------------------
    | 模块事件
    |--------------------------------------------------------------------------
    |
    | 是否分发模块事件（ModuleEnabled, ModuleDisabled 等）。
    |
    */
    'dispatch_events' => true,

    /*
    |--------------------------------------------------------------------------
    | 扫描 Composer 包
    |--------------------------------------------------------------------------
    |
    | 是否扫描 Composer 包以查找模块定义。
    | 启用后，composer.json 中包含 "extra.laravel-module" 的包将被视为模块。
    |
    */
    'scan_composer_packages' => false,

    /*
    |--------------------------------------------------------------------------
    | 模块中间件组
    |--------------------------------------------------------------------------
    |
    | 模块路由的默认中间件组。
    |
    */
    'middleware_groups' => [
        'web' => ['web'],
        'api' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | 模块别名
    |--------------------------------------------------------------------------
    |
    | 模块的别名。允许使用替代名称引用模块。
    | 格式：'alias' => 'real-module-name'
    |
    */
    'aliases' => [],

    /*
    |--------------------------------------------------------------------------
    | 模块清单路径
    |--------------------------------------------------------------------------
    |
    | 存储模块清单文件的路径（存储模块元数据、版本等）。
    | 如果为 null，默认为 storage_path('framework/modules.json')。
    |
    */
    'manifest_path' => null,

    /*
    |--------------------------------------------------------------------------
    | 模块枚举
    |--------------------------------------------------------------------------
    |
    | 定义可用的模块及其生成选项。
    | 格式：'模块名称' => ['generate' => true|false, 'path' => '自定义路径']
    | 示例：
    |   'Blog' => ['generate' => true],
    |   'Shop' => ['generate' => false], // 不生成该模块
    |   'Admin' => ['generate' => true, 'path' => base_path('custom-modules/Admin')],
    |
    */
    'modules' => [],

    /*
    |--------------------------------------------------------------------------
    | 默认生成选项
    |--------------------------------------------------------------------------
    |
    | 当模块未在 'modules' 数组中明确配置时，是否默认生成模块文件。
    |
    */
    'default_generate' => true,

    /*
    |--------------------------------------------------------------------------
    | 自动发现路由
    |--------------------------------------------------------------------------
    |
    | 是否自动发现并加载模块下的路由文件。
    |
    */
    'auto_discover_routes' => true,

    /*
    |--------------------------------------------------------------------------
    | 自动发现命令
    |--------------------------------------------------------------------------
    |
    | 是否自动发现并注册模块下的 Artisan 命令。
    |
    */
    'auto_discover_commands' => true,

    /*
    |--------------------------------------------------------------------------
    | 自动发现事件
    |--------------------------------------------------------------------------
    |
    | 是否自动发现并注册模块下的事件类。
    |
    */
    'auto_discover_events' => true,

    /*
    |--------------------------------------------------------------------------
    | 自动发现监听器
    |--------------------------------------------------------------------------
    |
    | 是否自动发现并注册模块下的监听器类。
    |
    */
    'auto_discover_listeners' => true,

    /*
    |--------------------------------------------------------------------------
    | 自动发现观察者
    |--------------------------------------------------------------------------
    |
    | 是否自动发现并注册模块下的观察者类。
    |
    */
    'auto_discover_observers' => true,

    /*
    |--------------------------------------------------------------------------
    | 自动发现迁移
    |--------------------------------------------------------------------------
    |
    | 是否自动发现并加载模块下的数据库迁移文件。
    |
    */
    'auto_discover_migrations' => true,

    /*
    |--------------------------------------------------------------------------
    | 自动发现策略
    |--------------------------------------------------------------------------
    |
    | 是否自动发现并注册模块下的策略类（Policies）。
    |
    */
    'auto_discover_policies' => true,

    /*
    |--------------------------------------------------------------------------
    | 自动发现表单请求
    |--------------------------------------------------------------------------
    |
    | 是否自动发现并注册模块下的表单请求类（Form Requests）。
    |
    */
    'auto_discover_requests' => true,

    /*
    |--------------------------------------------------------------------------
    | 自动发现资源
    |--------------------------------------------------------------------------
    |
    | 是否自动发现并注册模块下的资源类（API Resources）。
    |
    */
    'auto_discover_resources' => true,

    /*
    |--------------------------------------------------------------------------
    | 模块启用配置键
    |--------------------------------------------------------------------------
    |
    | 模块配置文件中用于判断模块是否启用的键名。
    | 例如：在模块的 config/config.php 中，'enabled' => true
    |
    */
    'module_config_enabled_key' => 'enabled',
];
```

## 使用指南

### 创建模块

使用 `module:make` 命令生成新模块：

```bash
php artisan module:make Blog
```

**配置驱动生成**：此命令会检查 `config/modules.php` 中的 `modules` 数组配置。如果模块被配置为 `'generate' => false`，则不会生成该模块。如果 `default_generate` 设置为 `false`，则默认不生成任何模块，除非在 `modules` 数组中明确配置 `'generate' => true`。

生成的模块结构与 Laravel 11+ 的 App 目录完全一致：

```
modules/
└── Blog/
    ├── config/
    │   ├── blog.php
    │   └── config.php          # 模块启用状态配置（enabled 键）
    ├── database/
    │   ├── migrations/
    │   ├── seeders/
    │   └── factories/
    ├── resources/
    │   ├── views/
    │   ├── lang/
    │   └── assets/
    ├── routes/
    │   ├── web.php
    │   ├── api.php
    │   └── console.php
    ├── src/
    │   ├── Console/
    │   │   └── Commands/       # 模块专用 Artisan 命令
    │   ├── Contracts/
    │   ├── Events/
    │   ├── Exceptions/
    │   ├── Http/
    │   │   ├── Controllers/
    │   │   ├── Middleware/
    │   │   └── Requests/
    │   ├── Jobs/
    │   ├── Listeners/
    │   ├── Mail/
    │   ├── Models/
    │   ├── Notifications/
    │   ├── Observers/
    │   ├── Providers/
    │   │   ├── BlogServiceProvider.php
    │   │   └── EventServiceProvider.php
    │   ├── Repositories/
    │   └── BlogModule.php      # 模块主类
```

### 模块类

每个模块必须有一个模块类，继承 `zxf\Modules\AbstractModule`。生成的类如下：

```php
<?php

namespace Modules\Blog;

use zxf\Modules\AbstractModule;

class BlogModule extends AbstractModule
{
    public function getName(): string
    {
        return 'blog';
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function register(): void
    {
        // 注册模块服务
    }

    public function boot(): void
    {
        // 引导模块
    }

    public function getProviders(): array
    {
        return [
            // Modules\Blog\Providers\BlogServiceProvider::class,
        ];
    }
}
```

### 模块方法

#### 核心方法
- `getName()`：返回模块名称（snake_case）
- `getPath()`：返回模块文件系统路径
- `getNamespace()`：返回模块命名空间
- `getPriority()`：引导优先级（数字越大优先级越高）
- `isEnabled()`：检查模块是否启用
- `enable()`：启用模块
- `disable()`：禁用模块
- `register()`：注册模块服务
- `boot()`：引导模块

#### 资源方法
- `getProviders()`：服务提供者类名数组
- `getMigrations()`：迁移目录路径
- `getRoutes()`：路由文件（web、api、console）
- `getViews()`：视图目录路径
- `getConfig()`：配置文件（键 => 路径）
- `getSeeders()`：数据填充器类名
- `getFactories()`：工厂类名
- `getTranslations()`：翻译目录路径

#### 高级方法
- `getDependencies()`：依赖模块名称数组
- `getMiddleware()`：中间件定义
- `checkDependencies()`：验证模块依赖
- `requiresPhp()`：检查 PHP 版本兼容性
- `requiresLaravel()`：检查 Laravel 版本兼容性
- `install()`：模块安装逻辑
- `uninstall()`：模块卸载逻辑
- `getVersion()`、`getDescription()`、`getAuthor()`、`getHomepage()`、`getLicense()`：元数据
- `toArray()`、`toJson()`：序列化

### 管理模块

#### 列出所有模块
```bash
php artisan module:list
```

显示详细信息：
```bash
php artisan module:list --detail
```

JSON 输出：
```bash
php artisan module:list --json
```

#### 启用模块
```bash
php artisan module:enable Blog
```

#### 禁用模块
```bash
php artisan module:disable Blog
```

#### 发布模块资源
发布配置、视图、翻译、资源等：
```bash
php artisan module:publish Blog
```

发布特定标签的资源：
```bash
php artisan module:publish Blog --tag=config,views
```

#### 缓存模块发现
```bash
php artisan module:cache
```

#### 清除模块缓存
```bash
php artisan module:clear-cache
```

#### 检查模块健康状态
```bash
php artisan module:check Blog
```

检查所有模块：
```bash
php artisan module:check
```

#### 安装模块
运行迁移、数据填充、发布资源：
```bash
php artisan module:install Blog
```

带选项安装：
```bash
php artisan module:install Blog --migrations --seeders --publish
```

#### 卸载模块
回滚迁移、清理资源：
```bash
php artisan module:uninstall Blog
```

强制卸载（忽略依赖）：
```bash
php artisan module:uninstall Blog --force
```

#### 模块独立迁移
运行指定模块的数据库迁移（支持所有标准 migrate 选项）：
```bash
php artisan module:migrate Blog
```

带选项运行：
```bash
php artisan module:migrate Blog --fresh --seed --force
```

### 模块资源

#### 路由
路由自动从以下位置加载：
- `modules/Blog/routes/web.php`
- `modules/Blog/routes/api.php`
- `modules/Blog/routes/console.php`

#### 迁移
迁移自动从 `modules/Blog/database/migrations` 加载。像往常一样运行迁移：

```bash
php artisan migrate
```

或者使用模块独立迁移命令：
```bash
php artisan module:migrate Blog
```

#### 视图
视图自动从 `modules/Blog/resources/views` 加载。使用方式：

```php
return view('blog::welcome');
```

#### 配置
配置文件自动合并。例如，`modules/Blog/config/blog.php` 将作为 `config('blog')` 可用。

#### 翻译
翻译自动从 `modules/Blog/resources/lang` 加载。使用方式：

```php
return __('blog::messages.welcome');
```

#### 中间件
模块可以为 Web 和 API 路由注册中间件：

```php
public function getMiddleware(): array
{
    return [
        'web' => [
            \Modules\Blog\Http\Middleware\AuthenticateBlog::class,
        ],
        'api' => [
            \Modules\Blog\Http\Middleware\ApiAuth::class,
        ],
    ];
}
```

### 自动发现机制

系统支持自动发现并注册模块下的各种类文件，通过以下配置开关控制：

- `auto_discover_routes`：自动发现路由文件
- `auto_discover_commands`：自动发现 Artisan 命令
- `auto_discover_events`：自动发现事件类
- `auto_discover_listeners`：自动发现监听器类
- `auto_discover_observers`：自动发现观察者类
- `auto_discover_migrations`：自动发现迁移文件
- `auto_discover_policies`：自动发现策略类
- `auto_discover_requests`：自动发现表单请求类
- `auto_discover_resources`：自动发现资源类

**发现流程**：
1. 系统扫描每个启用模块的对应目录
2. 发现符合条件的类文件
3. 自动注册到 Laravel 应用（例如命令注册到 Artisan，事件监听器注册到事件系统）
4. 无需手动配置，开箱即用

### 模块状态管理

模块的启用/停用状态由其自身的配置文件决定：

1. 每个模块的 `config/config.php` 文件中可以定义 `enabled` 键（键名可通过 `module_config_enabled_key` 配置）
2. 模块初始化时会读取此配置，自动设置启用状态
3. 可以通过修改配置文件动态启用/停用模块，无需重启应用

示例模块配置 `modules/Blog/config/config.php`：
```php
<?php

return [
    'enabled' => true, // 启用模块
    'other_config' => 'value',
];
```

### 辅助函数

包提供了一系列全局辅助函数（位于 `src/helper.php`），可在 Composer 自动加载后直接使用：

- `module_path(string $module, string $path = '')`：获取模块目录路径
- `module_config(string $module, string $key, $default = null)`：获取模块配置值
- `module_enabled(string $module)`：检查模块是否启用
- `module_disabled(string $module)`：检查模块是否禁用
- `module_asset(string $module, string $path)`：生成模块资源 URL
- `module_view(string $module, string $view, array $data = [])`：获取模块视图
- `module_route(string $module, string $name, array $parameters = [], bool $absolute = true)`：生成模块路由 URL
- `module_exists(string $module)`：检查模块是否存在
- `module_config_path(string $module, string $file)`：获取模块配置文件路径
- `module_migration_path(string $module, string $file = '')`：获取模块迁移文件路径
- `module_view_path(string $module, string $file = '')`：获取模块视图文件路径
- `module_asset_path(string $module, string $path = '')`：获取模块资源文件路径
- `module_namespace(string $module)`：获取模块命名空间
- `module_providers(string $module)`：获取模块服务提供者列表
- `module_version(string $module)`：获取模块版本号

### Facade

使用 `Module` Facade 与模块管理器交互：

```php
use zxf\Modules\Facades\Module;

// 获取所有模块
$modules = Module::all();

// 获取启用的模块
$enabled = Module::enabled();

// 获取禁用的模块
$disabled = Module::disabled();

// 检查模块是否存在
if (Module::exists('Blog')) {
    // 启用模块
    Module::enable('Blog');
    
    // 获取模块实例
    $module = Module::find('Blog');
}

// 获取模块路径
$path = Module::getModulesPath();

// 设置自定义模块路径
Module::setModulesPath(base_path('custom-modules'));

// 缓存模块
Module::cache();

// 清除缓存
Module::clearCache();
```

## 自定义

### 修改模块路径

可以在 `config/modules.php` 中修改模块路径，或动态设置：

```php
Module::setModulesPath(base_path('custom-modules'));
```

### 自定义存根

发布存根文件：

```bash
php artisan vendor:publish --tag=modules-stubs
```

然后在 `stubs/modules/` 中修改存根。

### 模块服务提供者

模块可以定义自己的服务提供者。这些提供者会在模块启用时自动注册。使用 `getProviders()` 方法返回提供者类名数组。

### 模块依赖

模块可以声明对其他模块的依赖：

```php
public function getDependencies(): array
{
    return ['auth', 'users'];
}
```

系统会在启用模块前检查所有依赖是否已启用。

### 模块安装/卸载

重写 `install()` 和 `uninstall()` 方法以执行自定义安装和清理逻辑：

```php
public function install(): void
{
    // 运行自定义安装逻辑
    $this->createDefaultPages();
    $this->seedDefaultData();
}

public function uninstall(): void
{
    // 清理自定义资源
    $this->removeUploadedFiles();
}
```

## 测试

运行测试套件：

```bash
composer test
```

## 许可证

MIT

## 贡献

欢迎贡献！请参阅 [CONTRIBUTING.md](CONTRIBUTING.md) 了解详情。

## 支持

如果遇到任何问题，请在 GitHub 上提交 issue。

---

**注意**：此包专为 Laravel 11+ 和 PHP 8.2+ 设计。可能适用于更早的版本，但不提供官方支持。