# 安装指南

## 系统要求

- PHP >= 8.2
- Laravel >= 11.0
- Composer 2.x

## 安装步骤

### 1. 使用 Composer 安装

```bash
composer require zxf/modules
```

### 2. 发布配置文件

```bash
php artisan vendor:publish --provider="zxf\\Modules\\ModulesServiceProvider"
```

这会在 `config` 目录下创建 `modules.php` 配置文件。

### 3. 配置模块路径

编辑 `config/modules.php` 文件，根据需要调整配置：

```php
return [
    // 模块根命名空间
    'namespace' => 'Modules',

    // 模块存储路径
    'path' => base_path('Modules'),

    // 模块静态资源发布路径
    'assets' => public_path('modules'),
];
```

### 4. 验证安装

```bash
# 查看模块命令列表
php artisan list | grep module

# 应该看到以下命令：
# module:list
# module:make
# module:info
# module:validate
# module:delete
# module:migrate
# module:migrate-reset
# module:migrate-refresh
# module:migrate-status
# module:make-controller
# module:make-model
# module:make-migration
# 等等...
```

### 5. 创建第一个模块

```bash
php artisan module:make Blog
```

这将在 `Modules` 目录下创建一个 `Blog` 模块，包含完整的目录结构。

### 6. 查看模块列表

```bash
php artisan module:list
```

你应该看到类似这样的输出：

```
+----+-------+---------+------------------------+-------------------+
| #  | Name  | Status  | Path                   | Namespace         |
+----+-------+---------+------------------------+-------------------+
| 1  | Blog  | Enabled | /path/to/Modules/Blog | Modules           |
+----+-------+---------+------------------------+-------------------+
Total: 1 module(s)
Enabled: 1 module(s)
Disabled: 0 module(s)
```

## 可选配置

### 配置自动加载

如果使用自定义的 `Modules` 目录，确保 `composer.json` 中的 `autoload` 部分包含该路径：

```json
{
    "autoload": {
        "psr-4": {
            "App\\": "app/",
            "Modules\\": "Modules/"
        }
    }
}
```

然后运行：

```bash
composer dump-autoload
```

### 发布模块资源

如果需要发布模块的静态资源到公共目录：

```bash
php artisan vendor:publish --tag=modules-assets
```

### 配置缓存（生产环境）

在生产环境，建议配置 Laravel 缓存：

```bash
php artisan config:cache
php artisan route:cache
```

## 卸载

如果需要卸载模块系统：

```bash
# 从 composer 中移除
composer remove zxf/modules

# 删除配置文件
rm config/modules.php

# 删除发布的资源
rm -rf public/modules
```

## 常见问题

### Q: 安装后命令无法使用？

A: 确保运行了 `composer dump-autoload` 重新生成自动加载文件。

### Q: 模块目录不存在？

A: 确保配置文件 `config/modules.php` 中的路径配置正确，并且目录有写入权限。

### Q: 命名空间冲突？

A: 如果 `Modules` 命名空间与现有代码冲突，可以在配置文件中修改 `namespace` 选项。

## 下一步

安装完成后，请继续阅读 [快速开始](02-quickstart.md) 了解如何创建和使用模块。
