# 配置详解

本扩展包有两层配置体系：

| 层级 | 文件位置 | 作用 |
|------|----------|------|
| **全局配置** | `config/modules.php` | 控制模块系统的整体行为（命名空间、路径、自动发现等） |
| **模块配置** | `Modules/{Module}/Config/{lower_name}.php` | 控制单个模块的元数据和自定义设置（启用、优先级、别名等） |

> **v5.0 重要变更**：模块不再使用 `composer.json` 管理元数据，所有模块级配置均在 `Config/` 目录下的 PHP 配置文件中定义。

---

## 一、全局配置 (`config/modules.php`)

### 完整配置示例

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 模块命名空间
    |--------------------------------------------------------------------------
    | 定义模块的根命名空间，所有模块类都将使用此前缀
    | 例如: 'Modules' 则模块类命名空间为 Modules\Blog\Http\Controllers\Controller
    |
    */
    'namespace' => 'Modules',

    /*
    |--------------------------------------------------------------------------
    | 模块存储路径
    |--------------------------------------------------------------------------
    | 定义模块存储的基础路径。所有命令在生成文件时都会基于此路径。
    |
    */
    'path' => base_path('Modules'),

    /*
    |--------------------------------------------------------------------------
    | 额外扫描路径
    |--------------------------------------------------------------------------
    | 除了主路径（path）外，定义额外的模块扫描路径
    | 适用于多团队协作、第三方模块、微服务架构等场景
    |
    */
    'scan_paths' => [
        // base_path('vendor/my-organization'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 模块资源发布路径
    |--------------------------------------------------------------------------
    |
    */
    'assets' => public_path('modules'),

    /*
    |--------------------------------------------------------------------------
    | 文件生成路径配置
    |--------------------------------------------------------------------------
    | 定义模块内部各组件的相对路径。可通过修改这些配置自定义模块结构。
    |
    */
    'paths' => [
        'migration' => 'Database/Migrations',

        'generator' => [
            'provider' => ['path' => 'Providers', 'generate' => true],
            'config' => ['path' => 'Config', 'generate' => true],
            'route' => ['path' => 'Routes', 'generate' => true],
            'controller' => ['path' => 'Http/Controllers', 'generate' => true],
            'controller.web' => ['path' => 'Http/Controllers/Web', 'generate' => true],
            'controller.api' => ['path' => 'Http/Controllers/Api', 'generate' => true],
            'controller.admin' => ['path' => 'Http/Controllers/Admin', 'generate' => false],
            'model' => ['path' => 'Models', 'generate' => true],
            'observer' => ['path' => 'Observers', 'generate' => false],
            'policy' => ['path' => 'Policies', 'generate' => false],
            'repository' => ['path' => 'Repositories', 'generate' => false],
            'request' => ['path' => 'Http/Requests', 'generate' => false],
            'resource' => ['path' => 'Http/Resources', 'generate' => true],
            'middleware' => ['path' => 'Http/Middleware', 'generate' => false],
            'command' => ['path' => 'Console/Commands', 'generate' => false],
            'event' => ['path' => 'Events', 'generate' => false],
            'listener' => ['path' => 'Listeners', 'generate' => false],
            'migration' => ['path' => 'Database/Migrations', 'generate' => false],
            'seeder' => ['path' => 'Database/Seeders', 'generate' => true],
            'views' => ['path' => 'Resources/views', 'generate' => true],
            'lang' => ['path' => 'Resources/lang', 'generate' => false],
            'test' => ['path' => 'Tests', 'generate' => false],
            'assets' => ['path' => 'Resources/assets', 'generate' => false],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 路由中间件组
    |--------------------------------------------------------------------------
    | 定义不同路由文件自动加载的中间件组。支持任意自定义路由类型。
    |
    */
    'middleware_groups' => [
        'web' => ['web'],
        'api' => ['api'],
        'admin' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | 路由配置
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'prefix' => true,
        'name_prefix' => true,
        'default_files' => ['web', 'api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | 视图配置
    |--------------------------------------------------------------------------
    */
    'views' => [
        'enabled' => true,
        'namespace_format' => 'lower', // lower | studly | camel
    ],

    /*
    |--------------------------------------------------------------------------
    | 翻译文件配置
    |--------------------------------------------------------------------------
    */
    'translations' => [
        'enabled' => true,
        'path' => 'Resources/lang',
    ],

    /*
    |--------------------------------------------------------------------------
    | 自动发现配置
    |--------------------------------------------------------------------------
    */
    'discovery' => [
        'routes' => true,
        'providers' => true,
        'commands' => true,
        'views' => true,
        'config' => true,
        'translations' => true,
        'migrations' => true,
        'events' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | 模块缓存配置
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'enabled' => false,
        'key' => 'modules',
        'ttl' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | 模块注册配置
    |--------------------------------------------------------------------------
    */
    'register' => [
        'providers' => true,
        'provider_pattern' => '{Module}ServiceProvider',
    ],

    /*
    |--------------------------------------------------------------------------
    | 模块命令配置
    |--------------------------------------------------------------------------
    */
    'commands' => [
        'enabled' => true,
        'path' => 'Console/Commands',
    ],

    /*
    |--------------------------------------------------------------------------
    | 模块迁移配置
    |--------------------------------------------------------------------------
    */
    'migrations' => [
        'prefix' => '',
        'table_prefix' => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | 模块仓库配置
    |--------------------------------------------------------------------------
    */
    'repository' => [
        'cache' => false,
        'cache_ttl' => 3600,
    ],
];

```

## 配置选项详解

### namespace

**类型**：`string`  
**默认值**：`'Modules'`  
**说明**：定义模块的根命名空间

```php
'namespace' => 'Modules',
```

**影响**：
- 所有模块类的命名空间前缀
- 服务提供者的注册
- 配置文件的加载路径

**示例**：
```php
// 如果设置为 'App\Modules'
// 模块 Blog 的控制器命名空间为
App\Modules\Blog\Http\Controllers\Web\PostController
```

### path

**类型**：`string`  
**默认值**：`base_path('Modules')`  
**说明**：定义模块的存储路径

```php
'path' => base_path('Modules'),
```

**影响**：
- 模块的物理存储位置
- `module_name()` 函数的检测路径
- 配置文件的加载路径

**示例**：
```php
// 自定义模块路径
'path' => base_path('app/Modules'),
```

### scan_paths

**类型**：`array`  
**默认值**：`[]`  
**说明**：定义额外的模块扫描路径（主路径通过 `path` 配置）

```php
'scan_paths' => [
    base_path('vendor/my-organization'),
    base_path('CustomModules'),
],
```

**使用场景**：
- 多目录模块管理（主应用模块 + 第三方模块 + 自定义模块）
- 模块包独立存储
- 多团队协作

**注意**：主模块路径通过 `path` 配置，`scan_paths` 仅定义额外路径。所有路径下的模块会合并到同一个仓库中。

### middleware_groups

**类型**：`array`  
**说明**：为不同类型的路由定义中间件组

```php
'middleware_groups' => [
    'web' => ['web'],
    'api' => ['api'],
    'admin' => ['web', 'admin'],
],
```
- 键名对应 `Routes/` 下的路由文件名（不含 `.php` 扩展名）
- 支持任意自定义路由类型

---

## 二、模块配置文件 (`Config/{lower_name}.php`)

> **v5.0 起**：每个模块通过 `Config/` 目录下的 PHP 文件管理自身元数据和配置，**不再需要每个模块维护 `composer.json` 文件**。

### 配置文件定位规则

模块系统按以下优先级查找配置文件：

1. **`Config/{lower_name}.php`**（推荐，自动加载为模块主配置）
2. **`Config/config.php`**（回退方案，向后兼容）

以 `Blog` 模块为例，系统会依次查找：
```
Modules/Blog/Config/blog.php   ← 优先
Modules/Blog/Config/config.php ← 回退
```

### 内置元数据键

配置文件支持以下内置键，系统会自动读取并应用：

| 键 | 类型 | 默认值 | 说明 |
|---|---|---|---|
| `enabled` | `bool` | `true` | 是否启用该模块 |
| `priority` | `int` | `1000` | 加载优先级，数字越小越先加载 |
| `name` | `string` | 模块名 | 模块显示名称 |
| `version` | `string` | `1.0.0` | 版本号 |
| `description` | `string` | 空 | 模块描述 |
| `author` | `string` | 空 | 作者信息 |
| `aliases` | `array` | `[]` | 模块名称别名（可通过别名查找模块） |
| `providers` | `array` | `[]` | 额外需要注册的 Laravel 服务提供者类名列表 |
| `laravel_aliases` | `array` | `[]` | 额外需要注册的 Laravel 门面别名 |
| `options` | `array` | `[]` | 模块自定义配置项 |

### 完整配置文件示例

```php
<?php
// Modules/Blog/Config/blog.php

/**
 * Blog 模块配置文件
 *
 * 此文件是模块的入口配置，所有模块元数据和核心设置均在此定义。
 * 不需要额外的 composer.json 或其他 JSON 文件来管理模块。
 */

return [
    /*
    |--------------------------------------------------------------------------
    | 模块启用状态
    |--------------------------------------------------------------------------
    | 是否启用该模块。设为 false 可禁用模块的所有功能。
    */
    'enabled' => true,

    /*
    |--------------------------------------------------------------------------
    | 模块加载优先级
    |--------------------------------------------------------------------------
    | 数字越小，模块越先加载。相同优先级按模块名称字母序排列。
    */
    'priority' => 1000,

    /*
    |--------------------------------------------------------------------------
    | 模块显示名称
    |--------------------------------------------------------------------------
    */
    'name' => 'Blog',

    /*
    |--------------------------------------------------------------------------
    | 模块版本
    |--------------------------------------------------------------------------
    */
    'version' => '1.0.0',

    /*
    |--------------------------------------------------------------------------
    | 模块描述
    |--------------------------------------------------------------------------
    */
    'description' => '博客管理模块',

    /*
    |--------------------------------------------------------------------------
    | 模块作者
    |--------------------------------------------------------------------------
    */
    'author' => 'Your Name',

    /*
    |--------------------------------------------------------------------------
    | 模块别名
    |--------------------------------------------------------------------------
    | 模块名称的别名列表，可通过别名查找模块。
    | 例如：设置 ['blogs', 'blog-manager'] 后，
    | module('blogs') 和 module('blog-manager') 都能找到该模块。
    */
    'aliases' => [],

    /*
    |--------------------------------------------------------------------------
    | 额外 Laravel 服务提供者
    |--------------------------------------------------------------------------
    | 模块内需要额外注册的 Laravel 服务提供者类名列表。
    | 注意：主服务提供者（Providers/{Module}ServiceProvider.php）会被自动发现，
    | 此处仅列出第三方或次要的服务提供者。
    */
    'providers' => [
        // \Modules\Blog\Providers\EventServiceProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | 额外 Laravel 门面别名
    |--------------------------------------------------------------------------
    | 格式：'别名' => '完整类名'
    */
    'laravel_aliases' => [
        // 'BlogHelper' => \Modules\Blog\Facades\BlogHelper::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | 模块自定义配置
    |--------------------------------------------------------------------------
    | 使用以下方式访问：
    | - module_config('options.posts_per_page', 15)
    | - module('Blog')->config('options.posts_per_page', 15)
    */
    'options' => [
        'posts_per_page' => 15,
        'enable_comments' => true,
        'cache_ttl' => 3600,
    ],
];
```

### enabled 优先级规则

模块启用状态按以下优先级判定（高到低）：

1. 构造函数的 `enabled` 选项（最高优先级，代码显式传入）
2. 配置文件的 `enabled` 键
3. 默认启用（`true`）

```php
// 示例：构造函数禁用（即使配置文件 enabled=true）
$module = new Module('Blog', '/path', 'Modules', ['enabled' => false]);
$module->isEnabled(); // false
```

### 自定义配置文件

除了主配置文件，你可以在 `Config/` 目录下创建任意数量的自定义配置文件：

```php
// Config/settings.php
return [
    'per_page' => 20,
    'enable_comments' => true,
];

// Config/api.php
return [
    'timeout' => 30,
    'retries' => 3,
];
```

---

## 三、配置读取方式

### 方式 1：使用 module_config()（推荐）

```php
// 读取当前模块的配置
$perPage = module_config('options.posts_per_page', 15);

// 读取指定模块的配置
$perPage = module_config('options.posts_per_page', 10, 'Blog');
```

### 方式 2：使用 Laravel config() 函数

```php
// 配置发布后可通过 config() 访问
$perPage = config('blog.options.posts_per_page', 10);
```

### 方式 3：使用模块实例

```php
$module = module('Blog');
$enabled = $module->isEnabled();
$priority = $module->getPriority();
$perPage = $module->config('options.posts_per_page', 15);
```

### 方式 4：使用 module_get_config()

```php
// 获取整个配置数组
$config = module_get_config('Blog');
$config = module_get_config('settings'); // 当前模块
```

---

## 四、配置最佳实践

### 1. 模块配置集中管理

```php
// Config/blog.php - 模块唯一入口配置
return [
    'enabled' => true,
    'priority' => 1000,
    'description' => '博客管理模块',
    'options' => [
        'posts_per_page' => 15,
        'enable_comments' => true,
    ],
];
```

### 2. 提供默认值

```php
// ✅ 推荐
$perPage = module_config('options.posts_per_page', 10);

// ❌ 不推荐
$perPage = module_config('options.posts_per_page');
```

### 3. 使用环境变量

```php
// Config/blog.php
return [
    'enabled' => env('BLOG_ENABLED', true),
    'options' => [
        'debug' => env('BLOG_DEBUG', false),
        'api_key' => env('BLOG_API_KEY'),
    ],
];
```

### 4. 利用 priority 控制加载顺序

```php
// Config/blog.php - 优先加载
return [
    'priority' => 100,  // 最先加载
];

// Config/shop.php - 依赖 Blog 模块
return [
    'priority' => 200,  // 在 Blog 之后加载
];
```

### 5. 使用别名简化访问

```php
// Config/blog.php
return [
    'aliases' => ['blogs', 'blog-manager'],
];

// 在代码中
module('blogs')->isEnabled();  // ≡ module('Blog')->isEnabled()
```

---

## 五、v5.0 迁移指南

### 从旧版升级

如果你之前使用 `composer.json` 管理模块元数据，请按以下步骤迁移：

**旧方式（composer.json）**：
```json
{
    "extra": {
        "modules": {
            "priority": 100,
            "aliases": ["blog-manager"]
        },
        "laravel": {
            "providers": ["Modules\\Blog\\Providers\\EventProvider"],
            "aliases": {"BlogHelper": "Modules\\Blog\\Facades\\BlogHelper"}
        }
    }
}
```

**新方式（Config/blog.php）**：
```php
return [
    'enabled' => true,
    'priority' => 100,
    'aliases' => ['blog-manager'],
    'providers' => ['Modules\\Blog\\Providers\\EventProvider'],
    'laravel_aliases' => ['BlogHelper' => 'Modules\\Blog\\Facades\\BlogHelper'],
    'options' => [
        // 自定义配置
    ],
];
```

### 兼容性说明

- `getComposerData()` 方法仍存在但始终返回 `null`（标记为 `@deprecated`）
- `enable` 键已更名为 `enabled`（旧键不再支持）
- `config` 配置键已更名为 `options`（语义更清晰）

## 相关文档

- [Helper 函数](05-helper-functions.md) - 了解配置读取的更多方法
- [模块结构](03-module-structure.md) - 模块目录结构说明
- [最佳实践](12-best-practices.md) - 配置的最佳实践
