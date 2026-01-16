# 高级使用指南

本文档介绍 Laravel 模块系统的高级用法、自定义配置和扩展功能。

## 目录
- [模块配置详解](#模块配置详解)
- [自定义模块生成](#自定义模块生成)
- [事件系统集成](#事件系统集成)
- [中间件注册](#中间件注册)
- [依赖管理](#依赖管理)
- [模块安装与卸载](#模块安装与卸载)
- [自动发现机制](#自动发现机制)
- [模块健康检查](#模块健康检查)
- [缓存机制](#缓存机制)
- [扩展包开发](#扩展包开发)

## 模块配置详解

### 基本配置
在 `config/modules.php` 中，您可以配置以下选项：

```php
return [
    'path' => base_path('modules'),          // 模块存储路径
    'namespace' => 'Modules',                // 默认命名空间
    'auto_discovery' => true,                // 启用自动发现
    'cache' => false,                        // 启用缓存
    'cache_key' => 'zxf.modules',            // 缓存键
    'cache_duration' => 3600,                // 缓存时长（秒）
];
```

### 模块枚举配置
通过 `modules` 数组，您可以精确控制每个模块的生成选项：

```php
'modules' => [
    'Blog' => [
        'generate' => true,                    // 允许生成该模块
        'path' => base_path('custom/blog'),   // 自定义模块路径
        'priority' => 100,                     // 自定义优先级
    ],
    'Shop' => [
        'generate' => false,                   // 禁止生成该模块
    ],
    'Admin' => [
        'generate' => true,
        'dependencies' => ['Auth', 'Users'],  // 模块依赖
    ],
],
```

### 自动发现配置
控制各类资源的自动发现：

```php
'auto_discover_routes' => true,        // 自动发现路由
'auto_discover_commands' => true,      // 自动发现命令
'auto_discover_events' => true,        // 自动发现事件
'auto_discover_listeners' => true,     // 自动发现监听器
'auto_discover_observers' => true,     // 自动发现观察者
'auto_discover_migrations' => true,    // 自动发现迁移
```

## 自定义模块生成

### 自定义模块类
您可以为模块创建自定义的模块类，添加额外的功能：

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
        return 200; // 更高的优先级
    }

    public function register(): void
    {
        // 注册自定义服务
        $this->app->singleton('blog.manager', function ($app) {
            return new BlogManager($app);
        });
    }

    public function boot(): void
    {
        // 引导自定义功能
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Modules\Blog\Console\Commands\GeneratePostCommand::class,
            ]);
        }
    }
}
```

### 自定义模块结构
您可以通过创建自定义存根来修改生成的模块结构：

1. 发布存根文件：
   ```bash
   php artisan vendor:publish --tag=modules-stubs
   ```

2. 修改 `stubs/modules/` 目录下的存根文件。

3. 在 `config/modules.php` 中指定自定义存根路径：
   ```php
   'stubs_path' => base_path('stubs/modules'),
   ```

### 自定义模块命名空间
模块命名空间可以通过多种方式自定义：

1. **全局配置**：修改 `modules.namespace` 配置。
2. **模块级配置**：在模块枚举中指定 `namespace`：
   ```php
   'modules' => [
       'Blog' => [
           'generate' => true,
           'namespace' => 'App\\Modules\\Blog',
       ],
   ],
   ```
3. **动态设置**：在模块类中重写 `getNamespace()` 方法。

## 事件系统集成

### 模块事件
模块系统触发以下事件：

- `ModuleEnabled`：模块启用时触发
- `ModuleDisabled`：模块禁用时触发
- `ModuleInstalled`：模块安装完成时触发
- `ModuleUninstalled`：模块卸载完成时触发

### 事件监听
在您的 `EventServiceProvider` 中注册事件监听器：

```php
protected $listen = [
    \zxf\Modules\Events\ModuleEnabled::class => [
        \App\Listeners\LogModuleEnabled::class,
        \App\Listeners\ClearModuleCache::class,
    ],
    \zxf\Modules\Events\ModuleDisabled::class => [
        \App\Listeners\LogModuleDisabled::class,
    ],
];
```

### 自定义事件
模块可以定义自己的事件和监听器。系统会自动发现和注册：

1. 在模块的 `Events/` 目录中创建事件类。
2. 在模块的 `Listeners/` 目录中创建监听器类。
3. 系统自动扫描并注册这些类。

## 中间件注册

### 模块中间件
模块可以为不同的路由组注册中间件：

```php
public function getMiddleware(): array
{
    return [
        'web' => [
            \Modules\Blog\Http\Middleware\AuthenticateBlog::class,
            \Modules\Blog\Http\Middleware\CheckBlogPermission::class,
        ],
        'api' => [
            \Modules\Blog\Http\Middleware\ApiAuth::class,
            \Modules\Blog\Http\Middleware\RateLimitApi::class,
        ],
    ];
}
```

### 中间件优先级
您可以通过修改中间件注册顺序来控制优先级：

```php
public function boot(): void
{
    $router = $this->app['router'];
    
    // 为特定路由组添加高优先级中间件
    $router->pushMiddlewareToGroup('web', \Modules\Blog\Http\Middleware\ForceSsl::class);
}
```

## 依赖管理

### 模块依赖声明
模块可以声明对其他模块的依赖：

```php
public function getDependencies(): array
{
    return [
        'auth',      // 依赖 Auth 模块
        'users',     // 依赖 Users 模块
        'database',  // 依赖 Database 模块
    ];
}
```

### 依赖检查
在启用模块前，系统会检查所有依赖是否满足：

```php
// 手动检查依赖
if ($module->checkDependencies()) {
    $module->enable();
} else {
    // 处理依赖不满足的情况
    $missingDeps = array_diff($module->getDependencies(), $enabledModules);
    // 提示用户安装缺失的依赖模块
}
```

### 循环依赖检测
系统会自动检测并防止循环依赖：

```php
// 如果模块 A 依赖模块 B，模块 B 依赖模块 A
// 系统会抛出 CircularDependencyException
```

## 模块安装与卸载

### 安装过程
模块安装时执行的逻辑：

```php
public function install(): void
{
    // 1. 运行数据库迁移
    $this->runMigrations();
    
    // 2. 运行数据填充
    $this->runSeeders();
    
    // 3. 发布资源文件
    $this->publishAssets();
    
    // 4. 创建必要的数据库表
    $this->createTables();
    
    // 5. 设置默认配置
    $this->setupDefaultConfig();
}
```

### 卸载过程
模块卸载时执行的清理逻辑：

```php
public function uninstall(): void
{
    // 1. 回滚数据库迁移
    $this->rollbackMigrations();
    
    // 2. 删除数据库表（可选）
    $this->dropTables();
    
    // 3. 清理缓存文件
    $this->cleanCache();
    
    // 4. 移除上传的文件
    $this->removeUploadedFiles();
    
    // 5. 恢复配置
    $this->restoreConfig();
}
```

### 安装/卸载钩子
您可以在安装或卸载前后执行自定义逻辑：

```php
// 在模块类中添加这些方法
public function beforeInstall(): void
{
    // 安装前的准备工作
}

public function afterInstall(): void
{
    // 安装后的清理工作
}

public function beforeUninstall(): void
{
    // 卸载前的准备工作
}

public function afterUninstall(): void
{
    // 卸载后的清理工作
}
```

## 自动发现机制

### 自动发现流程
系统自动发现模块资源的完整流程：

1. **扫描模块目录**：遍历所有启用模块的特定目录。
2. **识别类文件**：通过文件命名约定识别特定类型的类。
3. **验证类结构**：检查类是否继承自特定的基类或实现特定的接口。
4. **注册到系统**：将发现的类注册到 Laravel 的相应系统中。

### 自定义发现规则
您可以通过配置自定义发现规则：

```php
// 在模块配置中添加自定义发现规则
'discovery_rules' => [
    'commands' => [
        'path' => '/Console/Commands',
        'pattern' => '*.php',
        'validator' => \zxf\Modules\Discovery\CommandValidator::class,
    ],
    'events' => [
        'path' => '/Events',
        'pattern' => '*.php',
        'validator' => \zxf\Modules\Discovery\EventValidator::class,
    ],
    // 更多规则...
],
```

### 禁用自动发现
如果您希望手动注册所有资源：

```php
'auto_discovery' => false,
```

然后您需要在模块的服务提供者中手动注册：

```php
public function register(): void
{
    // 手动注册命令
    $this->commands([
        \Modules\Blog\Console\Commands\GeneratePostCommand::class,
    ]);
    
    // 手动注册事件和监听器
    $this->app['events']->listen(
        \Modules\Blog\Events\PostCreated::class,
        \Modules\Blog\Listeners\SendPostNotification::class
    );
}
```

## 模块健康检查

### 健康检查内容
系统检查模块的以下方面：

1. **依赖满足**：检查所有依赖模块是否已启用。
2. **PHP 版本兼容**：检查 PHP 版本是否满足要求。
3. **Laravel 版本兼容**：检查 Laravel 版本是否满足要求。
4. **扩展依赖**：检查必需的 PHP 扩展是否已安装。
5. **配置完整性**：检查模块配置是否正确。

### 自定义健康检查
为模块添加自定义健康检查：

```php
public function healthCheck(): array
{
    $issues = [];
    
    // 检查数据库连接
    if (! $this->checkDatabaseConnection()) {
        $issues[] = '数据库连接失败';
    }
    
    // 检查外部 API 可用性
    if (! $this->checkExternalApi()) {
        $issues[] = '外部 API 不可用';
    }
    
    // 检查文件权限
    if (! $this->checkFilePermissions()) {
        $issues[] = '文件权限不正确';
    }
    
    return $issues;
}
```

### 健康检查命令
运行模块健康检查：

```bash
# 检查特定模块
php artisan module:check Blog

# 检查所有模块
php artisan module:check

# 显示详细检查结果
php artisan module:check Blog --verbose
```

## 缓存机制

### 模块发现缓存
启用缓存可以显著提升模块发现性能：

```php
'cache' => true,
'cache_key' => 'zxf.modules',
'cache_duration' => 3600, // 1小时
```

### 缓存管理命令
管理模块缓存：

```bash
# 缓存模块发现结果
php artisan module:cache

# 清除模块缓存
php artisan module:clear-cache

# 显示缓存信息
php artisan module:cache:info
```

### 自定义缓存驱动
您可以指定自定义缓存驱动：

```php
'cache_driver' => 'redis', // 使用 Redis 缓存
```

## 扩展包开发

### 创建模块扩展包
您可以创建独立的包来扩展模块系统：

1. **创建包结构**：
   ```
   my-module-extension/
   ├── src/
   │   ├── Contracts/
   │   ├── Providers/
   │   └── MyModuleExtension.php
   ├── composer.json
   └── README.md
   ```

2. **注册扩展服务**：
   ```php
   // 在服务提供者中注册
   public function register(): void
   {
       $this->app->extend('modules.manager', function ($manager, $app) {
           return new ExtendedModuleManager($app);
       });
   }
   ```

### 钩子系统
通过钩子扩展模块功能：

```php
// 注册模块启用钩子
Module::hook('enabled', function ($module) {
    // 在模块启用时执行自定义逻辑
});

// 注册模块禁用钩子
Module::hook('disabled', function ($module) {
    // 在模块禁用时执行自定义逻辑
});
```

### 自定义模块类型
创建自定义模块类型以支持特殊需求：

```php
// 创建自定义模块基类
abstract class CustomModule extends AbstractModule
{
    // 添加自定义功能
    abstract public function getCustomConfig(): array;
}

// 在模块中使用
class SpecialModule extends CustomModule
{
    public function getCustomConfig(): array
    {
        return ['custom_setting' => 'value'];
    }
}
```

---

## 最佳实践

### 模块设计原则
1. **单一职责**：每个模块应专注于一个特定功能领域。
2. **松耦合**：模块之间应通过定义良好的接口进行通信。
3. **可重用性**：设计模块时应考虑在不同项目中的可重用性。
4. **可配置性**：模块行为应通过配置进行控制，而不是硬编码。

### 性能优化
1. **启用缓存**：在生产环境中启用模块发现缓存。
2. **延迟加载**：仅在需要时加载模块资源。
3. **代码分割**：将大型模块拆分为子模块。
4. **资源优化**：压缩和合并静态资源。

### 安全性
1. **输入验证**：验证所有外部输入。
2. **权限检查**：实现细粒度的权限控制。
3. **数据加密**：敏感数据应加密存储。
4. **安全审计**：记录安全相关操作。

### 维护性
1. **完整文档**：为每个模块提供详细文档。
2. **单元测试**：为模块编写全面的测试用例。
3. **版本控制**：使用语义化版本控制。
4. **向后兼容**：在更新时保持向后兼容性。

---

## 故障排除

### 常见问题

#### 模块未加载
- **检查配置**：确保模块在 `config/modules.php` 的 `modules` 数组中已配置。
- **检查路径**：验证模块目录是否存在且可读。
- **检查类文件**：确认模块类文件存在且命名正确。

#### 命令未注册
- **检查自动发现**：确保 `auto_discover_commands` 为 `true`。
- **检查目录结构**：命令文件应在 `Console/Commands/` 目录中。
- **检查类定义**：命令类应继承自 `Illuminate\Console\Command`。

#### 事件未触发
- **检查事件类**：事件类应存在且正确命名。
- **检查监听器注册**：监听器应正确注册到事件。
- **检查自动发现**：确保 `auto_discover_events` 和 `auto_discover_listeners` 为 `true`。

### 调试技巧
1. **启用详细日志**：在 `.env` 中设置 `LOG_LEVEL=debug`。
2. **检查服务提供者注册**：确认模块服务提供者已注册。
3. **验证依赖**：检查所有模块依赖是否满足。
4. **查看缓存状态**：确认缓存是否正确工作。

### 获取帮助
- **查阅文档**：仔细阅读本文档和相关文档。
- **检查日志**：查看应用日志获取详细错误信息。
- **搜索问题**：在 GitHub issues 中搜索类似问题。
- **提交问题**：如果问题未解决，提交详细的 issue 报告。

---

## 更新日志

### v1.0.0 (2024-01-01)
- 初始版本发布
- 支持模块自动发现
- 提供完整的生命周期管理
- 集成 Laravel 11+ 功能

### v1.1.0 (2024-02-01)
- 增强自动发现机制
- 改进命名空间处理
- 添加高级配置选项
- 优化性能表现

---

## 贡献指南
请参阅 [CONTRIBUTING.md](CONTRIBUTING.md) 了解如何为项目做出贡献。

---

## 许可证
本项目基于 MIT 许可证开源。详情请参阅 [LICENSE](LICENSE) 文件。