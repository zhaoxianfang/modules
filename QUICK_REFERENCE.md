# 快速参考指南

## 命令速查

### 模块管理

```bash
# 创建模块
php artisan module:make {ModuleName}

# 列出所有模块
php artisan module:list

# 查看模块信息
php artisan module:info {ModuleName}

# 验证模块
php artisan module:validate {ModuleName}

# 删除模块
php artisan module:delete {ModuleName}
```

### 代码生成

```bash
# 创建控制器
php artisan module:make-controller {Module} {ControllerName} --type=web|api|admin

# 创建模型
php artisan module:make-model {Module} {ModelName} --migration

# 创建迁移
php artisan module:make-migration {Module} {migration_name} --create=table_name

# 创建中间件
php artisan module:make-middleware {Module} {MiddlewareName}

# 创建表单请求
php artisan module:make-request {Module} {RequestName}

# 创建服务提供者
php artisan module:make-provider {Module} {ProviderName}

# 创建事件
php artisan module:make-event {Module} {EventName}

# 创建监听器
php artisan module:make-listener {Module} {ListenerName} --event=EventName

# 创建数据填充器
php artisan module:make-seeder {Module} {SeederName}

# 创建命令
php artisan module:make-command {Module} {CommandName} --command=signature

# 创建配置文件
php artisan module:make-config {Module} {ConfigName}

# 创建路由文件
php artisan module:make-route {Module} {RouteName} --type=web|api|admin
```

### 迁移管理

```bash
# 运行迁移
php artisan module:migrate [Module]

# 回滚迁移
php artisan module:migrate:reset [Module]

# 刷新迁移
php artisan module:migrate:refresh [Module]

# 查看迁移状态
php artisan module:migrate-status
```

## 助手函数

### 模块相关

```php
// 获取当前模块名称
module_name()

// 获取模块路径
module_path('Module', 'path/to/file')

// 检查模块是否存在
module_exists('Module')

// 检查模块是否启用
module_enabled('Module')

// 获取模块实例
module('Module')

// 获取所有模块
modules()
```

### 配置相关

```php
// 获取模块配置
module_config('Module', 'key', 'default')
```

### 视图相关

```php
// 获取模块视图路径
module_view_path('Module', 'view.name')

// 返回模块视图
module_view('Module', 'view.name', ['data' => $value])
```

### 路由相关

```php
// 获取当前模块
current_module()

// 获取模块路由前缀
module_route_path('Module', 'route')

// 生成模块路由 URL
module_route('Module', 'route.name', ['param' => $value])
```

### 资源相关

```php
// 生成模块静态资源 URL
module_asset('Module', 'path/to/asset.css')
```

### 命名空间相关

```php
// 获取模块命名空间
module_namespace('Module')

// 获取模块类完整类名
module_class('Module', 'Http\\Controllers\\Controller')

// 创建 Stub 生成器
module_stub('Module')
```

### 翻译相关

```php
// 获取模块翻译
module_lang('Module', 'key', ['replace' => 'value'])
```

## 视图使用

### 基本用法

```php
// 使用模块视图
view('module::view.name')

// 传递数据
view('module::view.name', ['data' => $value])

// 嵌套视图
view('module::nested.directory.view')
```

### 视图命名空间

- `blog::welcome` → `Modules/Blog/Resources/views/welcome.blade.php`
- `blog::post.index` → `Modules/Blog/Resources/views/post/index.blade.php`
- `blog::list.test` → `Modules/Blog/Resources/views/list/test.blade.php`

## 路由使用

### 基本用法

```php
// 定义路由
Route::get('/', [Controller::class, 'index'])->name('index');

// 使用路由
route('blog.index')
```

### 路由前缀

- Web 路由：`/blog/*`
- API 路由：`/api/blog/*`
- Admin 路由：`/admin/blog/*`

### 路由名称前缀

- 所有路由：`blog.route_name`

### 控制器命名空间

- Web 路由：`Modules\Blog\Http\Controllers\Web\`
- API 路由：`Modules\Blog\Http\Controllers\Api\`
- Admin 路由：`Modules\Blog\Http\Controllers\Admin\`

## 配置示例

### 基本配置

```php
// config/modules.php
return [
    'namespace' => 'Modules',
    'path' => base_path('Modules'),
];
```

### 路由中间件配置

```php
'middleware_groups' => [
    'web' => ['web', 'auth'],
    'api' => ['api', 'throttle:60,1'],
    'admin' => ['web', 'auth', 'admin'],
],
```

### 路由控制器命名空间映射

```php
'route_controller_namespaces' => [
    'web' => 'Web',
    'api' => 'Api',
    'admin' => 'Admin',
],
```

## 模块启用/禁用

```php
// Modules/Blog/Config/config.php
return [
    'enable' => true,  // true: 启用, false: 禁用
];
```

## 常见问题

### 如何访问模块配置？

```php
$value = config('blog.config.key', 'default');
```

### 如何在控制器中使用模块视图？

```php
return view('blog::view.name', ['data' => $data]);
```

### 如何生成模块路由 URL？

```php
$url = route('blog.route.name', ['id' => 1]);
```

### 如何访问模块静态资源？

```php
$url = asset('modules/blog/css/style.css');
```

### 如何禁用模块？

在模块配置文件中设置 `enable` 为 `false`。

### 如何自定义模块模板？

在 `config/modules.php` 中配置自定义 stubs 路径。

## 最佳实践

1. **使用类型提示**：始终使用类型提示提高代码可读性
2. **命名规范**：遵循 Laravel 和模块系统的命名规范
3. **模块隔离**：保持模块之间的独立性，避免直接依赖
4. **配置驱动**：使用配置文件而不是硬编码
5. **路由前缀**：利用路由前缀和命名前缀保持清晰
6. **视图命名空间**：使用视图命名空间避免冲突
7. **模块文档**：为每个模块编写 README 文档
8. **测试覆盖**：编写完整的测试用例

## 性能优化

1. **启用缓存**：在生产环境启用模块缓存
2. **按需加载**：禁用不需要的自动发现功能
3. **路由优化**：运行 `php artisan route:cache`
4. **配置缓存**：运行 `php artisan config:cache`
5. **视图编译**：运行 `php artisan view:cache`
