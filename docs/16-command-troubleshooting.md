# 模块命令故障排除指南

## 问题描述

当运行模块中的自定义命令时，可能会遇到以下错误：

```
ERROR  There are no commands defined in the "blog" namespace.
```

## 最新修复说明

### 修复日期：2026-01-20

**修复内容：**

1. **修复了命令签名生成问题**
   - 更新了 `command.stub` 模板，使用 `{{SIGNATURE}}` 变量而不是硬编码的 `{{LOWER_NAME}}:command-name`
   - 在 `ModuleMakeCommand` 中添加了 `{{LOWER_NAME}}` 变量的替换
   - 确保生成的命令签名正确（如 `blog:command` 而不是错误的格式）

2. **优化了命令注册机制**
   - 在 `ModuleAutoDiscovery` 中添加了静态全局命令缓存
   - 修改了服务提供者的 `boot()` 方法，在模块加载后立即注册模块命令
   - 使用 `ServiceProvider::commands()` 方法统一注册所有模块命令

3. **改进了命令发现逻辑**
   - 增强了 `discoverCommands()` 方法的日志记录
   - 添加了 `registerCommandsFallback()` 降级方案，确保命令注册的可靠性
   - 将发现的命令添加到全局缓存，供服务提供者使用

**技术细节：**

- 命令注册现在分为两个阶段：
  1. `ModuleAutoDiscovery::discoverCommands()`：扫描并缓存命令类名
  2. `ModulesServiceProvider::registerModuleCommands()`：统一注册所有命令
- 使用静态缓存避免重复扫描和注册
- 确保命令在 Laravel Console Application 启动之前就完成注册

## 问题原因

这个问题通常由以下几个原因导致：

### 1. 命令未正确注册到 Laravel Artisan

模块命令必须通过 `ModuleAutoDiscovery` 自动发现并注册到 Laravel 的 Console Application。

### 2. 命令签名错误

命令的 `$signature` 属性必须正确设置，例如：`blog:command-name`

### 3. 命令类未正确继承

命令类必须继承 `Illuminate\Console\Command` 并实现 `handle()` 方法。

### 4. 模块未启用

如果模块被禁用，命令将不会被注册。

## 诊断步骤

### 步骤 1：使用调试命令检查

```bash
php artisan module:debug-commands
```

这将显示所有模块的命令注册情况。

要检查特定模块：

```bash
php artisan module:debug-commands --module=Blog
```

调试命令会显示以下信息：

- 模块状态（已启用/已禁用）
- 命令目录是否存在
- 发现的命令文件
- 命令类的签名
- 发现日志
- 已注册到 Artisan 的命令

### 步骤 2：检查命令类

确保命令类满足以下条件：

```php
<?php

namespace Modules\Blog\Console\Commands;

use Illuminate\Console\Command;

class TestCommand extends Command
{
    protected $signature = 'blog:test';  // ← 必须设置正确的签名

    protected $description = '测试命令';

    public function handle(): int
    {
        $this->info('执行命令...');
        return Command::SUCCESS;
    }
}
```

### 步骤 3：检查模块配置

确保 `config/modules.php` 中的命令发现功能已启用：

```php
return [
    // ...
    'discovery' => [
        'commands' => true,  // ← 必须为 true
        // ...
    ],
];
```

### 步骤 4：检查模块服务提供者

确保模块的服务提供者（如果存在）没有覆盖命令注册逻辑。

## 常见问题与解决方案

### 问题 1：命令目录不存在

**症状**：调试命令显示 `Console/Commands 目录不存在`

**解决方案**：

```bash
# 使用 module:make-command 创建命令
php artisan module:make-command Blog TestCommand
```

这会在正确的目录创建命令文件。

### 问题 2：命令签名错误

**症状**：命令已注册但签名不对

**解决方案**：

检查命令类的 `$signature` 属性：

```php
protected $signature = 'blog:command-name';  // 正确
// protected $signature = 'module:blog:command-name';  // 错误，不要添加 module: 前缀
```

### 问题 3：命令类无效

**症状**：调试日志显示 `无效命令类`

**解决方案**：

确保：
1. 命令类继承自 `Illuminate\Console\Command`
2. 命令类不是抽象类
3. 命令类实现了 `handle()` 方法

```php
class TestCommand extends Command  // ← 必须继承
{
    // ...

    public function handle(): int  // ← 必须实现
    {
        // ...
    }
}
```

### 问题 4：模块被禁用

**症状**：模块状态显示 `已禁用`

**解决方案**：

```bash
# 启用模块
php artisan module:enable Blog

# 或者在 config/modules.php 中设置
'enabled' => [
    'Blog',
],
```

### 问题 5：命名空间错误

**症状**：命令类不存在

**解决方案**：

确保命令类的命名空间与模块结构匹配：

```
Modules/
  Blog/
    Console/
      Commands/
        TestCommand.php  // 命名空间: Modules\Blog\Console\Commands
```

## 自动发现机制

模块命令的自动发现过程：

1. **扫描命令目录**：`ModuleAutoDiscovery` 扫描 `Console/Commands/` 和 `Commands/` 目录
2. **验证命令类**：检查类是否继承 `Illuminate\Console\Command` 且不是抽象类
3. **注册命令**：通过 `$this->app->addCommands()` 将命令注册到 Laravel
4. **降级处理**：如果主方法失败，尝试通过 `$this->app['artisan']` 直接注册

## 日志调试

启用调试模式以查看详细的发现日志：

```php
// config/app.php
'debug' => true,
```

然后在 `storage/logs/laravel.log` 中查看模块命令发现的详细日志。

## 示例：创建并测试命令

### 1. 创建命令

```bash
php artisan module:make-command Blog TestCommand
```

### 2. 编辑命令签名（可选）

```bash
php artisan module:make-command Blog TestCommand --command=blog:my-test
```

### 3. 测试命令注册

```bash
php artisan module:debug-commands --module=Blog
```

### 4. 运行命令

```bash
php artisan blog:test
```

## 验证命令列表

列出所有可用的命令：

```bash
# 列出所有命令
php artisan list

# 搜索模块命令
php artisan list | grep blog
```

## 最佳实践

1. **使用模块名作为命令命名空间**：`blog:*`、`admin:*`、`shop:*`
2. **避免使用 `module:` 前缀**：这是本包的保留前缀
3. **保持命令签名简洁**：使用有意义且简短的名称
4. **提供描述信息**：为每个命令添加清晰的 `$description`
5. **使用调试命令**：在开发过程中使用 `module:debug-commands` 验证命令注册

## 高级调试

如果上述步骤都无法解决问题，可以手动检查：

```php
// 在 routes/console.php 或任意地方
$artisan = app('artisan');
$commands = $artisan->all();

// 查找特定模块的命令
foreach ($commands as $name => $command) {
    if (str_contains($name, 'blog')) {
        dump([
            'name' => $name,
            'class' => get_class($command),
            'signature' => $command->getName(),
            'description' => $command->getDescription(),
        ]);
    }
}
```

## 获取帮助

如果问题仍然存在，请提供以下信息以便快速诊断：

1. `php artisan module:debug-commands --module=YourModule` 的完整输出
2. 命令类的完整代码
3. `config/modules.php` 的配置
4. Laravel 和包的版本信息
