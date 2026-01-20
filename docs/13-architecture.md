# 模块系统架构文档

## 目录

1. [系统概述](#系统概述)
2. [生命周期](#生命周期)
3. [文件引用逻辑](#文件引用逻辑)
4. [自动发现机制](#自动发现机制)
5. [配置说明](#配置说明)
6. [最佳实践](#最佳实践)

---

## 系统概述

### 设计理念

zxf/modules 是一个基于 PHP 8.2+ 和 Laravel 11+ 的现代化、智能化的多模块化扩展包。

**核心特性：**

- ✅ **智能自动发现**：无需手动注册，自动发现配置、路由、迁移等
- ✅ **灵活配置驱动**：通过 config/modules.php 控制所有行为
- ✅ **模块启用/禁用**：通过配置文件控制，禁用时完全不加载
- ✅ **高性能**：优化的加载流程，支持缓存
- ✅ **Laravel 11+ 原生**：采用最新架构和代码风格

### 技术栈

- **PHP**: 8.2+
- **Laravel**: 11.0+
- **架构模式**: PSR-4 自动加载，PSR-12 代码规范
- **特性**: 类型声明、只读属性、match 表达式、命名参数等

---

## 生命周期

### 1. 应用启动阶段

#### 1.1 ModulesServiceProvider 注册

```
应用启动
  ↓
ModulesServiceProvider::register()
  ↓
注册 Repository 单例
  ↓
注册 ModuleLoader 单例
  ↓
合并 config/modules.php 到全局配置
```

**时机**: 在 Laravel 容器启动时，服务提供者注册阶段

**关键代码**:
```php
// src/ModulesServiceProvider.php
public function register(): void
{
    $this->registerRepository();
    $this->registerModuleLoader();
    $this->mergeConfig();
}
```

#### 1.2 模块扫描

```
ModulesServiceProvider::boot()
  ↓
ModuleLoader::loadAll()
  ↓
Repository::scan()
  ↓
扫描模块目录
  ↓
创建 Module 实例
  ↓
验证模块完整性
```

**扫描路径**（来自 config）:
```php
'modules.scan.paths' => [
    base_path('Modules'),
    base_path('CustomModules'),
],
```

**模块验证**:
- 检查模块目录是否存在
- 检查必需文件（config/, Providers/ 等）
- 验证模块命名规范

### 2. 模块加载阶段

#### 2.1 模块启用检查

```
加载模块
  ↓
检查 enabled 配置
  ↓
[启用] → 继续加载
[禁用] → 跳过该模块
```

**启用状态读取优先级**:
1. 模块配置文件 `Config/{module}.php` 中的 `enabled` 键
2. 如果配置不存在，默认启用
3. 如果配置值为 `false`，禁用模块

#### 2.2 自动发现流程

```
模块启用
  ↓
ModuleAutoDiscovery::discoverAll()
  ↓
按顺序执行发现任务：
  1. discoverProviders()    ← 最先加载，注册服务提供者
  2. discoverConfigs()      ← 配置文件
  3. discoverMiddlewares()  ← 中间件
  4. discoverRoutes()       ← 路由文件
  5. discoverViews()        ← 视图文件
  6. discoverMigrations()  ← 数据库迁移
  7. discoverTranslations()← 翻译文件
  8. discoverCommands()    ← Artisan 命令
  9. discoverEvents()       ← 事件和监听器
  10. discoverObservers()   ← 模型观察者
  11. discoverPolicies()    ← 策略类
  12. discoverRepositories()← 仓库类
```

**每个发现任务的流程**:

```
特定发现任务
  ↓
检查 discovery 配置是否启用
  ↓
扫描对应目录
  ↓
验证文件/类有效性
  ↓
注册到 Laravel
  ↓
记录到缓存
```

### 3. 模块创建阶段

#### 3.1 创建模块命令

```
执行: php artisan module:make Blog
  ↓
ModuleMakeCommand::handle()
  ↓
创建 StubGenerator 实例
  ↓
按配置创建目录
  ↓
根据配置生成文件
  ↓
完成创建
```

**目录创建顺序**:

```
1. 核心目录（必需）
   ├─ Config/
   ├─ Routes/
   └─ Providers/

2. 控制器目录
   ├─ Http/Controllers/
   ├─ Http/Controllers/Web/
   ├─ Http/Controllers/Api/
   └─ Http/Controllers/Admin/

3. 业务逻辑目录（可选）
   ├─ Models/
   ├─ Observers/
   ├─ Policies/
   └─ Repositories/

4. HTTP 相关目录（可选）
   ├─ Http/Middleware/
   ├─ Http/Requests/
   └─ Http/Resources/

5. 资源目录（可选）
   ├─ Resources/views/
   ├─ Resources/assets/
   └─ Resources/lang/

6. 数据库目录（可选）
   ├─ Database/Migrations/
   └─ Database/Seeders/

7. 其他目录（可选）
   ├─ Console/Commands/
   ├─ Events/
   ├─ Listeners/
   ├─ Tests/
   └─ README.md
```

#### 3.2 文件生成顺序

```
创建目录结构
  ↓
生成 ServiceProvider
  ↓
生成配置文件
  ↓
生成路由文件
  ↓
生成基础控制器
  ↓
生成示例控制器
  ↓
生成示例视图
  ↓
生成 README
```

### 4. 运行时阶段

#### 4.1 请求处理流程

```
HTTP 请求
  ↓
应用全局中间件
  ↓
匹配路由
  ↓
应用路由中间件
  ↓
执行控制器方法
  ↓
调用模块辅助函数
  ↓
返回响应
```

#### 4.2 配置读取流程

```
调用: module_config('key', 'default')
  ↓
helper.php::module_config()
  ↓
检测当前模块
  ↓
读取模块配置文件
  ↓
返回配置值
```

**配置读取优先级**:
1. 模块配置文件 `Config/{module}.php`
2. 模块自定义配置 `Config/custom.php`
3. Laravel 全局配置 `config('module.key')`

---

## 文件引用逻辑

### 1. 配置文件引用

#### 1.1 自动发现配置

```
Config/ 目录扫描
  ↓
发现所有 .php 文件
  ↓
按规则加载到全局配置:
  - {module}.php → config('module')
  - settings.php → config('module.settings')
  - custom.php → config('module.custom')
```

#### 1.2 配置合并逻辑

```php
// ModuleAutoDiscovery::discoverConfigs()
$configValue = require $configFile->getPathname();

// 检查是否为数组
if (is_array($configValue)) {
    // 合并到全局配置
    config([$configKey => $configValue]);
}
```

**配置文件命名规范**:
- 小写命名：`blog.php`, `settings.php`
- 使用短横线：`post-config.php` → config('blog.post_config')
- 避免特殊字符和空格

### 2. 路由文件引用

#### 2.1 路由文件加载

```
Routes/ 目录扫描
  ↓
发现所有 .php 文件
  ↓
为每个文件创建路由组:
  - 应用中间件组（从 config）
  - 设置控制器命名空间
  - 加载路由文件内容
```

**中间件组映射** (config/modules.php):

```php
'middleware_groups' => [
    'web' => ['web'],           // web.php 路由
    'api' => ['api'],          // api.php 路由
    'admin' => ['web', 'admin'], // admin.php 路由
],
```

**控制器命名空间映射**:

```php
'route_controller_namespaces' => [
    'web' => 'Web',      // Routes/web.php → Http\Controllers\Web
    'api' => 'Api',      // Routes/api.php → Http\Controllers\Api
    'admin' => 'Admin',  // Routes/admin.php → Http\Controllers\Admin
],
```

#### 2.2 路由文件内容

路由文件内部已包含路由组声明：

```php
// Routes/web.php
Route::middleware(['web'])
    ->prefix('blog')           // 来自 config('modules.routes.prefix')
    ->name('blog.')          // 来自 config('modules.routes.name_prefix')
    ->group(function () {
        // 路由定义
    });
```

### 3. 视图文件引用

#### 3.1 视图命名空间注册

```
Resources/views/ 目录检查
  ↓
注册视图命名空间:
  - 格式根据 config('modules.views.namespace_format')
  - 支持: lower, studly, camel
```

**命名空间格式示例**:
```php
// config/modules.php
'views' => [
    'namespace_format' => 'lower',  // blog
    // 'namespace_format' => 'studly',  // Blog
    // 'namespace_format' => 'camel',  // blogModule
],
```

**视图调用方式**:
```php
// 方式 1: 使用视图命名空间
return view('blog::post.index');

// 方式 2: 使用辅助函数
return module_view('post.index', compact('posts'));
```

#### 3.2 视图查找顺序

Laravel 视图解析器查找顺序:
1. 命名空间视图: `Modules/Blog/Resources/views/post/index.blade.php`
2. 应用视图: `resources/views/vendor/blog/post/index.blade.php`
3. 回退到应用视图: `resources/views/post/index.blade.php`

### 4. 迁移文件引用

#### 4.1 迁移路径注册

```
Database/Migrations/ 目录检查
  ↓
注册迁移路径到 Laravel
  ↓
migrator->path($migrationPath)
```

**迁移文件命名规范**:
```
YYYY_MM_DD_HHMMSS_create_table_name.php
2026_01_19_143022_create_posts_table.php
2026_01_19_143056_add_user_id_to_posts.php
```

**迁移执行顺序**:
1. 按时间戳顺序执行
2. 支持回滚
3. 支持事务（默认）

#### 4.2 迁移命令

```bash
# 运行所有模块迁移
php artisan module:migrate

# 运行指定模块迁移
php artisan module:migrate Blog

# 查看迁移状态
php artisan module:migrate-status

# 回滚迁移
php artisan module:migrate:reset Blog

# 刷新迁移
php artisan module:migrate:refresh Blog
```

### 5. 命令文件引用

#### 5.1 命令自动发现

```
Console/Commands/ 目录扫描
  ↓
发现所有 .php 文件
  ↓
验证命令类有效性:
  - 继承 Illuminate\Console\Command
  - 定义 $signature 和 $description
  ↓
注册到 Laravel
  ↓
console->addCommands($commands)
```

**命令类结构**:
```php
namespace Modules\Blog\Console\Commands;

use Illuminate\Console\Command;

class ProcessPosts extends Command
{
    protected $signature = 'blog:process';  // 命令签名
    protected $description = '处理博客文章';  // 命令描述

    public function handle(): int
    {
        // 命令逻辑
        return Command::SUCCESS;
    }
}
```

**命令执行**:
```bash
# 执行命令
php artisan blog:process

# 列出所有命令
php artisan list | grep module
```

### 6. 翻译文件引用

#### 6.1 翻译命名空间注册

```
Resources/lang/ 目录检查
  ↓
注册翻译命名空间:
  - 使用模块小写名称
  ↓
translator->addNamespace($moduleLowerName, $langPath)
```

**翻译文件结构**:
```
Resources/lang/
├── zh-CN/
│   ├── messages.php
│   ├── validation.php
│   └── common.php
├── en/
│   ├── messages.php
│   └── validation.php
└── zh-CN.json
```

**翻译调用方式**:
```php
// 使用键名
__('blog::messages.welcome')

// 使用嵌套键
__('blog::validation.required')

// 使用 JSON 文件
__('blog::title')
```

### 7. 事件和监听器引用

#### 7.1 事件自动发现

```
Events/ 目录扫描
  ↓
验证事件类有效性
  ↓
Laravel 自动加载
  ↓
无需手动注册
```

**事件类结构**:
```php
namespace Modules\Blog\Events;

class PostCreated
{
    public function __construct(
        public int $postId,
        public string $title
    ) {}
}
```

#### 7.2 监听器自动发现

```
Listeners/ 目录扫描
  ↓
验证监听器类有效性
  ↓
Laravel 自动加载
  ↓
无需手动注册
```

**监听器类结构**:
```php
namespace Modules\Blog\Listeners;

use Modules\Blog\Events\PostCreated;

class SendPostNotification
{
    public function handle(PostCreated $event): void
    {
        // 监听器逻辑
    }
}
```

**事件绑定**（可选，用于延迟加载）:
```php
// 模块服务提供者中
protected $listen = [
    PostCreated::class => [
        SendPostNotification::class,
    ],
];
```

---

## 自动发现机制

### ModuleAutoDiscovery 类

从 v2.3.0 开始，模块系统引入了统一的自动发现机制类 `ModuleAutoDiscovery`。

**核心特性**：
- ✅ **统一发现入口**：所有模块组件通过一个类统一发现
- ✅ **智能缓存**：支持发现结果缓存，提升性能
- ✅ **可控性**：通过配置文件控制是否发现特定组件
- ✅ **完整覆盖**：支持配置、路由、视图、迁移、命令、翻译、事件等所有组件
- ✅ **接口驱动**：基于 `ModuleInterface`，易于扩展

**使用方式**：

在模块的 `ServiceProvider` 中：

```php
use zxf\Modules\Support\ModuleAutoDiscovery;
use zxf\Modules\Contracts\ModuleInterface;

class BlogServiceProvider extends ServiceProvider
{
    protected ?ModuleAutoDiscovery $autoDiscovery = null;

    public function boot(): void
    {
        // 创建自动发现器
        $this->autoDiscovery = $this->createAutoDiscovery();

        // 执行所有自动发现任务
        $this->autoDiscovery->discoverAll();
    }

    protected function createAutoDiscovery(): ModuleAutoDiscovery
    {
        // 创建模块对象（实现 ModuleInterface）
        $module = new class implements ModuleInterface {
            public function getName(): string { return 'Blog'; }
            public function getPath(?string $path = null): string { return __DIR__ . '/..' . ($path ? '/' . $path : ''); }
            // ... 实现其他必需方法
        };

        return new ModuleAutoDiscovery($module);
    }
}
```

**自动发现顺序**：

1. **服务提供者** (`discoverProviders`)
   - 扫描 `Providers/` 目录
   - 自动注册到 Laravel 服务容器
   - 执行服务提供者的 register() 和 boot() 方法
   - 优先级：最高

2. **配置文件** (`discoverConfigs`)
   - 扫描 `Config/` 目录
   - 自动合并到全局配置
   - 格式：`config('module_name.key')`

3. **中间件** (`discoverMiddlewares`)
   - 扫描 `Http/Middleware/` 和 `Http/Filters/` 目录
   - 记录中间件类供调试使用

4. **路由文件** (`discoverRoutes`)
   - 扫描 `Routes/` 目录
   - 自动应用中间件组
   - 支持多种路由文件类型（web、api、admin）

5. **视图文件** (`discoverViews`)
   - 扫描 `Resources/views/` 目录
   - 注册视图命名空间
   - 使用方式：`view('module_name::view')`

6. **迁移文件** (`discoverMigrations`)
   - 扫描 `Database/Migrations/` 目录
   - 注册迁移路径
   - 包含在 Laravel 迁移系统中

7. **翻译文件** (`discoverTranslations`)
   - 扫描 `Resources/lang/` 目录
   - 注册翻译命名空间
   - 使用方式：`__('module_name::key')`

8. **命令文件** (`discoverCommands`)
   - 扫描 `Console/Commands/` 和 `Commands/` 目录
   - 自动注册 Artisan 命令
   - 命令类必须继承 `Illuminate\Console\Command`

9. **事件和监听器** (`discoverEvents`)
   - 扫描 `Events/` 和 `Listeners/` 目录
   - 记录事件和监听器类
   - Laravel 11+ 会自动发现这些类

10. **模型观察者** (`discoverObservers`)
    - 扫描 `Observers/` 目录
    - 自动注册观察者到对应的模型
    - 命名约定：`PostObserver` 对应 `Post` 模型

11. **策略类** (`discoverPolicies`)
    - 扫描 `Policies/` 目录
    - 自动注册策略到 Gate
    - 命名约定：`PostPolicy` 对应 `Post` 模型

12. **仓库类** (`discoverRepositories`)
    - 扫描 `Repositories/` 目录
    - 发现并记录仓库类
    - 需要手动注册到服务容器

### 发现配置控制

```php
// config/modules.php
'discovery' => [
    'config' => true,       // 自动发现配置文件
    'routes' => true,       // 自动发现路由文件
    'views' => true,        // 自动发现视图文件
    'commands' => true,      // 自动发现命令
    'migrations' => true,    // 自动发现迁移文件
    'translations' => true,   // 自动发现翻译文件
    'events' => true,        // 自动发现事件和监听器
],
```

### 发现优先级

自动发现器严格按照以下顺序执行：

1. **配置** (`discoverConfigs`)
   - 优先级：最高
   - 原因：其他组件可能依赖配置

2. **路由** (`discoverRoutes`)
   - 优先级：高
   - 原因：路由需要配置信息

3. **视图** (`discoverViews`)
   - 优先级：中
   - 原因：视图相对独立

4. **迁移** (`discoverMigrations`)
   - 优先级：中
   - 原因：数据库迁移相对独立

5. **翻译** (`discoverTranslations`)
   - 优先级：中
   - 原因：翻译相对独立

6. **命令** (`discoverCommands`)
   - 优先级：低
   - 原因：命令仅在控制台使用

7. **事件** (`discoverEvents`)
   - 优先级：最低
   - 原因：事件和监听器由 Laravel 自动加载

### 发现缓存

```php
// ModuleAutoDiscovery 类
protected array $cache = [];
protected bool $cacheEnabled = true;
```

**缓存内容**:
```php
[
    'config.blog' => true,
    'config.blog.settings' => true,
    'route.web' => true,
    'route.api' => true,
    'view' => true,
    'migration' => true,
    'translation' => true,
    'commands' => [/* 命令类列表 */],
    'events' => [/* 事件类列表 */],
]
```

---

## 配置说明

### 核心配置

```php
// config/modules.php

return [
    // 模块命名空间
    'namespace' => 'Modules',

    // 模块存储路径
    'path' => base_path('Modules'),

    // 模块静态资源发布路径
    'assets' => public_path('modules'),
];
```

### 扫描配置

```php
'scan' => [
    'enabled' => true,
    'paths' => [
        base_path('Modules'),
        // 可以添加多个路径
    ],
],
```

### 路由配置

```php
'routes' => [
    'prefix' => true,              // 是否自动添加路由前缀
    'name_prefix' => true,         // 是否自动添加路由名称前缀
    'default_files' => [          // 默认路由文件
        'web', 'api', 'admin',
    ],
],

'middleware_groups' => [
    'web' => ['web'],
    'api' => ['api'],
    'admin' => ['web', 'admin'],
],

'route_controller_namespaces' => [
    'web' => 'Web',
    'api' => 'Api',
    'admin' => 'Admin',
],
```

### 视图配置

```php
'views' => [
    'enabled' => true,
    'namespace_format' => 'lower',  // lower | studly | camel
],
```

### 生成器配置

```php
'paths.generator' => [
    // 核心组件
    'config' => ['enabled' => true, 'path' => 'Config'],
    'provider' => ['enabled' => true, 'path' => 'Providers'],
    'route' => ['enabled' => true, 'path' => 'Routes'],

    // 控制器
    'controller' => [
        'enabled' => true,
        'path' => 'Http/Controllers',
        'create_base' => true,
        'create_web' => true,
        'create_api' => true,
        'create_admin' => true,
        'create_examples' => true,
    ],

    // 业务逻辑
    'observer' => ['enabled' => false, 'path' => 'Observers'],
    'policy' => ['enabled' => false, 'path' => 'Policies'],
    'repository' => ['enabled' => false, 'path' => 'Repositories'],

    // 路由文件
    'routes' => [
        'web' => true,
        'api' => true,
        'admin' => false,
    ],

    // 视图
    'views' => [
        'enabled' => true,
        'path' => 'Resources/views',
        'create_layouts' => true,
        'create_example' => true,
    ],
],
```

---

## 最佳实践

### 1. 模块设计

**✅ 推荐做法**:
- 保持模块单一职责（一个模块 = 一个功能域）
- 模块间通过接口和服务进行交互
- 使用依赖注入，避免直接依赖其他模块

**❌ 避免做法**:
- 创建过大过复杂的模块
- 模块间直接调用（绕过接口）
- 硬编码其他模块的类名

### 2. 配置管理

**✅ 推荐做法**:
- 使用模块特定配置文件
- 配置项提供合理默认值
- 使用类型安全的配置访问

```php
// 推荐
$value = module_config('settings.cache_ttl', 3600);

// 避免
$value = config('blog.settings.cache_ttl', 3600);
```

### 3. 路由设计

**✅ 推荐做法**:
- 使用资源路由简化代码
- 添加路由参数约束
- 使用路由模型绑定
- 为路由提供清晰的命名

```php
// 推荐
Route::resource('posts', PostController::class)
    ->only(['index', 'show'])
    ->names('blog.posts');

// 避免
Route::get('/posts', [PostController::class, 'index'])->name('posts.index');
Route::get('/posts/{id}', [PostController::class, 'show'])->name('posts.show');
```

### 4. 视图组织

**✅ 推荐做法**:
- 使用组件和布局
- 按功能分组视图文件
- 使用命名空间避免冲突

```
Resources/views/
├── layouts/
│   └── app.blade.php
├── posts/
│   ├── index.blade.php
│   ├── show.blade.php
│   └── components/
│       └── card.blade.php
└── users/
    └── profile.blade.php
```

### 5. 数据库设计

**✅ 推荐做法**:
- 使用迁移管理数据库变更
- 为迁移添加注释
- 使用软删除而非硬删除
- 使用模型关联替代原始查询

```php
// 推荐迁移
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained();
    $table->string('title');
    $table->text('content')->nullable();
    $table->softDeletes();
    $table->timestamps();
});
```

### 6. 性能优化

**✅ 推荐做法**:
- 在生产环境启用缓存
- 使用路由缓存
- 避免不必要的配置读取
- 使用延迟加载

```php
// config/modules.php
'cache' => [
    'enabled' => env('APP_ENV') === 'production',
    'key' => 'modules',
    'ttl' => 3600,
],
```

---

## 故障排查

### 常见问题

**Q: 模块没有被加载？**

A: 检查以下项：
1. 模块目录是否在扫描路径中
2. 模块配置文件是否存在且 enabled 为 true
3. 服务提供者是否已注册

**Q: 路由不生效？**

A: 检查以下项：
1. 路由文件命名是否正确（web.php, api.php）
2. 中间件组配置是否正确
3. 是否运行了 `php artisan route:clear`

**Q: 视图找不到？**

A: 检查以下项：
1. 视图目录结构是否正确
2. 是否使用了正确的命名空间
3. 是否运行了 `php artisan view:clear`

**Q: 迁移不执行？**

A: 检查以下项：
1. 迁移文件命名是否符合规范
2. 迁移路径是否已注册
3. 是否运行了 `php artisan migrate:status` 检查状态

---

## 总结

本架构文档详细说明了 zxf/modules 模块系统的：

1. **完整生命周期**：从应用启动到模块加载的全流程
2. **文件引用逻辑**：各组件如何被发现和引用
3. **自动发现机制**：智能的组件自动发现和注册
4. **配置说明**：全面的配置选项和用法
5. **最佳实践**：设计模式和性能优化建议

遵循本架构文档可以更好地理解和使用模块系统，构建高质量的模块化应用。
