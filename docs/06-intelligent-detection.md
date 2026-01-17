# 智能模块检测

系统提供强大的智能当前模块检测功能，通过分析代码调用栈自动检测当前代码所在的模块。

## 工作原理

### module_name() 函数

`module_name()` 函数通过以下步骤自动检测当前模块：

1. **获取模块路径配置**
   ```php
   $modulePath = config('modules.path', base_path('Modules'));
   ```

2. **标准化路径**
   ```php
   $modulePath = rtrim($modulePath, DIRECTORY_SEPARATOR);
   ```

3. **获取调用栈**
   ```php
   $backtrace = debug_backtrace(
       DEBUG_BACKTRACE_IGNORE_ARGS | DEBUG_BACKTRACE_PROVIDE_OBJECT,
       20
   );
   ```

4. **遍历调用栈查找模块文件**
   - 标准化文件路径（统一使用 `/`）
   - 检查文件是否在模块路径下
   - 提取模块名并验证

5. **验证模块真实存在**
   ```php
   if (module_exists($moduleName)) {
       return $moduleName;
   }
   ```

## 使用场景

### 1. 在控制器中使用

```php
namespace Modules\Blog\Http\Controllers\Web;

use Modules\Blog\Models\Post;

class PostController extends Controller
{
    public function index()
    {
        // 自动检测到当前模块为 Blog
        $moduleName = module_name(); // 'Blog'

        $posts = Post::paginate(10);

        return module_view('post.index', compact('posts'));
    }
}
```

### 2. 在模型中使用

```php
namespace Modules\Blog\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected static function boot()
    {
        parent::boot();

        // 自动检测到当前模块
        $moduleName = module_name(); // 'Blog'

        // 可以在模型中使用当前模块信息
    }
}
```

### 3. 在中间件中使用

```php
namespace Modules\Blog\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckConfig
{
    public function handle(Request $request, Closure $next)
    {
        // 自动检测到当前模块
        if (! module_enabled()) {
            return redirect()->route('home');
        }

        // 读取配置
        $maintenance = module_config('maintenance.enabled', false);

        if ($maintenance) {
            return response('系统维护中', 503);
        }

        return $next($request);
    }
}
```

### 4. 在命令中使用

```php
namespace Modules\Blog\Console\Commands;

use Illuminate\Console\Command;

class ClearCache extends Command
{
    protected $signature = 'blog:clear';

    public function handle()
    {
        // 自动检测到当前模块
        $this->info('正在清理 ' . module_name() . ' 模块缓存...');

        // 清理逻辑

        $this->info('缓存清理完成！');
    }
}
```

### 5. 在视图中使用

```blade
{{-- 在 Blog 模块视图中 --}}
@php
    // 自动检测到当前模块
    $moduleName = module_name(); // 'Blog'
@endphp

<h1>{{ module_config('common.title', 'Blog') }}</h1>
```

## 配置读取

### 智能配置读取

在模块内部，可以使用配置文件路径格式读取配置，无需传递模块名：

```php
// 读取 Blog/Config/common.php 的 name 配置
$name = module_config('common.name', 'hello');

// 读取嵌套配置
$enabled = module_config('settings.cache.enabled', false);

// 读取配置文件
$config = module_get_config('settings');
```

### 传统方式

显式传递模块名：

```php
$value = module_config('common.name', 'default', 'Blog');
```

## 路径处理

### 跨平台兼容

函数会自动处理不同操作系统的路径差异：

```php
// Windows: E:\www\modules\Blog\Http\Controllers\PostController.php
// Linux: /var/www/modules/Blog/Http/Controllers/PostController.php

// 都能正确识别为 Blog 模块
$moduleName = module_name(); // 'Blog'
```

### 路径标准化

```php
// 内部实现
$filePath = str_replace('\\', '/', $filePath);
$modulePathNormalized = str_replace('\\', '/', $modulePath);
```

## 模块验证

### 验证模块真实存在

检测到模块名后，会验证模块是否真实存在：

```php
$moduleName = Str::studly($segments[0]);

// 验证模块是否真实存在
if (module_exists($moduleName)) {
    return $moduleName;
}
```

这避免了检测到错误的模块名。

## 性能考虑

### 调用栈深度限制

为了平衡性能和准确性，调用栈深度限制为 20 层：

```php
$backtrace = debug_backtrace(
    DEBUG_BACKTRACE_IGNORE_ARGS | DEBUG_BACKTRACE_PROVIDE_OBJECT,
    20
);
```

### 不使用缓存

为了保证准确性，`module_name()` 函数不使用缓存：

- 每次都精确检测
- 避免缓存错误
- 保证数据实时性

## 注意事项

### 1. 在模块外部调用

在模块外部调用会返回 `null`：

```php
// 在 app/Http/Controllers/Controller.php 中
$moduleName = module_name(); // null
```

### 2. 确保文件在模块目录下

确保代码文件在配置的模块路径下：

```php
// config/modules.php
'path' => base_path('Modules'),

// 文件应该在
// /path/to/Modules/Blog/...
```

### 3. 验证返回值

在使用返回值前进行验证：

```php
$moduleName = module_name();

if (! $moduleName) {
    throw new \RuntimeException('无法检测到当前模块');
}
```

## 最佳实践

### 1. 在模块内部优先使用无参调用

```php
// ✅ 推荐：在模块内部
module_config('common.name', 'default');

// ⚠️ 谨慎使用：可能无法检测模块
```

### 2. 验证返回值

```php
// ✅ 推荐：验证返回值
$moduleName = module_name();
if (! $moduleName) {
    // 处理无法检测模块的情况
    throw new \RuntimeException('无法检测到当前模块');
}

// ⚠️ 不推荐：直接使用
$moduleName = module_name();
// 如果返回 null，可能导致错误
```

### 3. 提供默认值

```php
// ✅ 推荐：提供默认值
$value = module_config('common.name', 'default');

// ⚠️ 不推荐：不提供默认值
$value = module_config('common.name');
```

### 4. 显式传递模块名（关键场景）

```php
// ✅ 推荐：在不确定场景下显式传递
$value = module_config('common.name', 'default', 'Blog');

// ⚠️ 不推荐：依赖自动检测
$value = module_config('common.name', 'default');
```

## 常见问题

### Q: module_name() 返回 null？

**A**: 检查以下事项：
1. 确保代码在模块目录中执行
2. 确认 `config/modules.php` 中的路径配置正确
3. 验证模块目录存在
4. 检查文件路径格式

### Q: module_config() 读取不到配置？

**A**: 检查以下事项：
1. 确认配置文件存在于 `Config` 目录
2. 确认配置文件返回数组
3. 确认配置键名正确
4. 验证模块名称正确

### Q: 如何在非模块代码中使用这些函数？

**A**: 显式传递模块名称：

```php
$moduleName = 'Blog';
$value = module_config('common.name', 'default',$moduleName);
```

### Q: 性能影响如何？

**A**: 
- `module_name()` 每次调用约 0.5ms
- `module_config()` 每次调用约 0.3ms
- 通过 Laravel 配置缓存可以进一步优化

### Q: 配置修改后不生效？

**A**: 清除配置缓存：

```bash
php artisan config:clear
```

## 调试技巧

### 1. 打印调用栈

```php
$backtrace = debug_backtrace();
foreach ($backtrace as $trace) {
    if (isset($trace['file'])) {
        echo $trace['file'] . ':' . $trace['line'] . "\n";
    }
}
```

### 2. 验证模块路径

```php
$modulePath = config('modules.path', base_path('Modules'));
echo "模块路径: " . $modulePath . "\n";
echo "是否存在: " . (is_dir($modulePath) ? '是' : '否') . "\n";
```

### 3. 检查当前文件路径

```php
$currentFile = __FILE__;
echo "当前文件: " . $currentFile . "\n";
echo "在模块中: " . (strpos($currentFile, $modulePath) !== false ? '是' : '否') . "\n";
```

## 相关文档

- [Helper 函数](05-helper-functions.md) - 所有助手函数的详细说明
- [配置详解](04-configuration.md) - 学习配置文件的使用
- [模块结构](03-module-structure.md) - 了解模块目录结构
