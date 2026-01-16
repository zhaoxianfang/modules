# 模块结构详解

## 目录结构

```
Modules/
└── {ModuleName}/
    ├── Config/                          # 配置文件目录
    │   └── config.php                  # 模块配置文件（必需）
    │
    ├── Database/                        # 数据库相关目录
    │   ├── Migrations/                 # 数据库迁移文件
    │   │   └── 2024_01_01_000000_create_table_name.php
    │   └── Seeders/                   # 数据填充器
    │       └── ModuleNameSeeder.php
    │
    ├── Http/                           # HTTP 层目录
    │   ├── Controllers/                # 控制器目录
    │   │   ├── Controller.php         # 基础控制器
    │   │   ├── Web/                  # Web 控制器
    │   │   │   └── TestController.php
    │   │   ├── Api/                  # API 控制器
    │   │   │   └── TestController.php
    │   │   └── Admin/                # Admin 控制器
    │   │       └── TestController.php
    │   ├── Middleware/                # 中间件目录
    │   │   └── CustomMiddleware.php
    │   └── Requests/                  # 表单请求验证目录
    │       └── StoreRequest.php
    │
    ├── Models/                          # 模型目录
    │   └── Post.php
    │
    ├── Providers/                       # 服务提供者目录
    │   └── ModuleNameServiceProvider.php  # 模块服务提供者（必需）
    │
    ├── Resources/                       # 资源目录
    │   ├── assets/                    # 静态资源（JS、CSS、图片等）
    │   │   ├── js/
    │   │   ├── css/
    │   │   └── images/
    │   ├── lang/                      # 语言文件目录
    │   │   ├── en/
    │   │   │   └── messages.php
    │   │   └── zh_CN/
    │   │       └── messages.php
    │   └── views/                     # 视图文件目录
    │       ├── layouts/
    │       │   └── app.blade.php
    │       ├── index.blade.php
    │       └── post/
    │           └── index.blade.php
    │
    ├── Routes/                          # 路由目录
    │   ├── web.php                     # Web 路由文件
    │   ├── api.php                     # API 路由文件
    │   └── admin.php                   # Admin 路由文件
    │
    ├── Events/                          # 事件目录
    │   └── PostCreated.php
    │
    ├── Listeners/                       # 事件监听器目录
    │   └── SendPostNotification.php
    │
    ├── Observers/                       # 模型观察者目录
    │   └── PostObserver.php
    │
    ├── Policies/                        # 策略类目录
    │   └── PostPolicy.php
    │
    ├── Repositories/                    # 仓库类目录
    │   └── PostRepository.php
    │
    ├── Tests/                           # 测试目录
    │   ├── Feature/
    │   │   └── PostTest.php
    │   └── Unit/
    │       └── PostTest.php
    │
    └── README.md                        # 模块说明文档
```

## 核心文件说明

### 1. Config/config.php

模块配置文件，控制模块的启用状态和自定义配置：

```php
<?php

return [
    'enable' => true,

    'config' => [
        'option' => 'value',
    ],
];
```

### 2. Providers/ModuleNameServiceProvider.php

模块服务提供者，负责注册模块服务：

```php
<?php

namespace Modules\Blog\Providers;

use Illuminate\Support\ServiceProvider;

class BlogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 注册服务
    }

    public function boot(): void
    {
        // 启动服务
    }
}
```

### 3. Routes/*.php

路由文件，定义模块的路由：

```php
<?php

// web.php
Route::get('/', [HomeController::class, 'index'])->name('index');
```

## 命名空间规则

### 模块命名空间

所有模块类都遵循以下命名空间规则：

```
Modules\{ModuleName}
```

### 控制器命名空间

- Web 控制器：`Modules\{ModuleName}\Http\Controllers\Web`
- API 控制器：`Modules\{ModuleName}\Http\Controllers\Api`
- Admin 控制器：`Modules\{ModuleName}\Http\Controllers\Admin`

### 模型命名空间

```
Modules\{ModuleName}\Models
```

### 中间件命名空间

```
Modules\{ModuleName}\Http\Middleware
```

### 表单请求命名空间

```
Modules\{ModuleName}\Http\Requests
```

### 事件命名空间

```
Modules\{ModuleName}\Events
```

### 监听器命名空间

```
Modules\{ModuleName}\Listeners
```

### 观察者命名空间

```
Modules\{ModuleName}\Observers
```

### 策略类命名空间

```
Modules\{ModuleName}\Policies
```

### 仓库类命名空间

```
Modules\{ModuleName}\Repositories
```

## 视图命名空间

模块视图会自动注册命名空间，命名格式取决于配置：

- `lower`（默认）：`blog::view.name`
- `studly`：`Blog::view.name`
- `camel`：`blogModule::view.name`

## 路由命名规则

### 路由前缀

所有路由会自动添加模块前缀（可配置）：

```
/blog/*      # Web 路由
/api/blog/*  # API 路由
/admin/blog/* # Admin 路由
```

### 路由名称前缀

所有路由名称会自动添加模块前缀（可配置）：

```
blog.index
blog.posts.show
```

## 配置读取规则

模块配置通过以下方式读取：

```php
// 方式 1：使用 helper 函数
$value = module_config('Blog', 'config.key', 'default');

// 方式 2：使用 config 函数
$value = config('blog.config.key', 'default');

// 方式 3：使用模块实例
$module = module('Blog');
$value = $module->config('config.key', 'default');
```

## 自动加载

系统会自动加载以下模块组件：

1. **配置文件**：`Config/*.php`
2. **路由文件**：`Routes/*.php`
3. **服务提供者**：`Providers/*ServiceProvider.php`
4. **命令**：`Console/Commands/*.php`
5. **视图**：`Resources/views/*.blade.php`
6. **翻译文件**：`Resources/lang/*.php`
7. **迁移文件**：`Database/Migrations/*.php`
8. **事件**：`Events/*.php`
9. **监听器**：`Listeners/*.php`

## 模块启用/禁用

通过修改 `Config/config.php` 中的 `enable` 选项：

```php
'enable' => true,  // 启用模块
'enable' => false, // 禁用模块
```

禁用后，模块的所有自动加载组件将不会被加载。
