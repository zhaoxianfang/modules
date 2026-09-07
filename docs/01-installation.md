# 安装指南

本指南将帮助你完成 zxf/modules 扩展包的安装和基本配置。

## 系统要求

| 依赖 | 最低版本 | 推荐版本 | 说明 |
|------|---------|---------|------|
| PHP | 8.2 | 8.3+ | 需要类型声明、match 表达式等特性 |
| Laravel | 11.0 | 12.0+ / 13.0+ | 支持 LTS 和最新主版本 |
| Composer | 2.x | 最新稳定版 | 依赖管理和自动加载 |

**需要的 PHP 扩展**：
- `ext-json`（JSON 处理）
- `ext-fileinfo`（文件类型检测）
- `ext-pdo`（数据库连接）

## 安装步骤

### 1. 使用 Composer 安装

```bash
composer require zxf/modules
```

安装后，Laravel 会自动发现并注册 `zxf\Modules\ModulesServiceProvider`（通过 Composer 的 `extra.laravel.providers`）。

### 2. 发布配置文件

**方式一：按服务提供者发布（推荐）**

```bash
php artisan vendor:publish --provider="zxf\\Modules\\ModulesServiceProvider"
```

这会同时发布配置文件和 stub 模板文件：
- `config/modules.php` → 全局配置文件
- `resources/stubs/modules/` → 自定义 stub 模板

**方式二：按标签选择性发布**

```bash
# 仅发布配置文件
php artisan vendor:publish --tag=modules-config

# 仅发布 stub 模板（用于自定义代码生成模板）
php artisan vendor:publish --tag=modules-stubs
```

### 3. 配置 PSR-4 自动加载

编辑项目根 `composer.json`，确保 `Modules` 命名空间在自动加载配置中：

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

然后重新生成自动加载文件：

```bash
composer dump-autoload
```

> **注意**：这是项目根 `composer.json` 的 PSR-4 自动加载配置。模块本身不再需要独立的 `composer.json`，模块元数据通过 `Config/{lower_name}.php` 管理。

### 4. 验证安装

运行以下命令确认安装成功：

```bash
# 查看所有模块相关命令
php artisan list | grep module
```

预期输出至少包含以下命令：

```
module:check-lang          # 检查模块语言文件完整性
module:debug-commands      # 调试模块命令注册状态
module:delete              # 删除指定模块
module:info                # 显示模块详细信息
module:list                # 列出所有模块及其状态
module:make                # 创建新模块
module:make-command        # 生成 Artisan 命令
module:make-config         # 生成配置文件
module:make-controller     # 生成控制器
module:make-event          # 生成事件类
module:make-listener       # 生成事件监听器
module:make-middleware     # 生成中间件
module:make-migration      # 生成数据库迁移
module:make-model          # 生成 Eloquent 模型
module:make-provider       # 生成服务提供者
module:make-request        # 生成表单请求
module:make-route          # 生成路由文件
module:make-seeder         # 生成数据填充器
module:migrate             # 运行模块迁移
module:migrate-refresh     # 刷新模块迁移
module:migrate-reset       # 回滚模块迁移
module:migrate-status      # 查看模块迁移状态
module:publish             # 发布模块静态资源
module:validate            # 验证模块完整性
```

### 5. 创建第一个模块

```bash
php artisan module:make Blog
```

这将创建完整的模块目录结构：

```
Modules/Blog/
├── Config/
│   └── blog.php              # 模块配置（元数据入口）
├── Http/
│   └── Controllers/
│       ├── Controller.php    # 基础控制器
│       ├── Web/              # Web 控制器
│       └── Api/              # API 控制器
├── Models/                   # 数据模型
├── Providers/
│   └── BlogServiceProvider.php
├── Resources/
│   └── views/                # Blade 视图
├── Routes/
│   ├── web.php               # Web 路由
│   └── api.php               # API 路由
├── Database/
│   └── Seeders/              # 数据填充
└── README.md
```

**创建选项**：

```bash
# 创建完整模块（包含所有可选组件）
php artisan module:make Blog --full

# 仅创建核心文件
php artisan module:make Blog

# 禁用模块（创建后默认不启用）
php artisan module:make Blog --disabled
```

### 6. 查看模块列表

```bash
php artisan module:list
```

预期输出：

```
+----+------+---------+-------+--------------------------+-----------+
| #  | Name | Status  | Prio  | Path                     | Namespace |
+----+------+---------+-------+--------------------------+-----------+
| 1  | Blog | Enabled | 1000  | /path/to/Modules/Blog    | Modules   |
+----+------+---------+-------+--------------------------+-----------+
Total: 1 module(s) | Enabled: 1 | Disabled: 0
```

### 7. 访问模块

创建完成后，访问 `http://your-app.test/blog` 即可看到模块的欢迎页面。

---

## 生产环境配置

### 启用模块缓存

在生产环境，强烈建议启用模块缓存以提升性能：

```bash
# .env 中设置
MODULES_CACHE_ENABLED=true
```

或直接编辑 `config/modules.php`：

```php
'cache' => [
    'enabled' => env('MODULES_CACHE_ENABLED', env('APP_ENV') === 'production'),
    'key'     => 'modules',
    'ttl'     => 3600,
],
```

### 发布配置文件与用户指南

```bash
# 发布包内配置文件到 config/modules.php
php artisan module:publish --config

# 发布多模块用户指南到 Modules 目录
php artisan module:publish --guide

# 目标文件已存在时强制覆盖
php artisan module:publish --config --force
```

> 注：`module:publish` **不接受模块名参数**，仅发布上述两类文件。

### Laravel 性能优化

```bash
# 配置缓存
php artisan config:cache

# 路由缓存
php artisan route:cache

# 视图缓存
php artisan view:cache
```

> **注意**：如果使用了 `config:cache`，确保加载模块配置的逻辑在缓存之后正确执行。

---

## 开发环境建议

### 关闭模块缓存

```bash
# .env
MODULES_CACHE_ENABLED=false
```

模块变更频繁时缓存可能过时，建议开发环境关闭。

### 开启调试模式

```bash
# .env
MODULES_DEBUG=true
```

启用后会在日志中输出详细的模块加载和自动发现过程。

### 自定义 Stub 模板

```bash
# 发布 stub 到项目
php artisan vendor:publish --tag=modules-stubs

# 编辑 resources/stubs/modules/ 下的模板
# 后续 module:make 命令将使用自定义模板
```

---

## 卸载

```bash
# 从 Composer 中移除
composer remove zxf/modules

# 删除配置文件
rm config/modules.php

# 删除发布的 stub
rm -rf resources/stubs/modules

# 删除发布的静态资源
rm -rf public/modules

# 删除模块目录（⚠️ 这将删除所有模块代码！请先备份）
# rm -rf Modules/
```

---

## 升级指南

### 从 v4.x 升级到 v5.0

v5.0 的主要变更是**不再使用 composer.json 管理模块**，全部迁移到 PHP 配置文件。

**迁移步骤**：

1. 为每个模块创建 `Config/{lower_name}.php`（如 `blog.php`）
2. 将 `composer.json` 中的 `extra.modules.*` 配置迁移到 PHP 配置
3. 将 `extra.laravel.providers` 迁移为 `providers` 键
4. 将 `extra.laravel.aliases` 迁移为 `laravel_aliases` 键
5. 删除各模块的 `composer.json` 文件

详见 [配置详解 - v5.0 迁移指南](04-configuration.md#五v50-迁移指南)。

---

## 常见问题

### Q: 安装后命令无法使用？

A: 确保运行了 `composer dump-autoload` 重新生成自动加载文件。如果问题仍存在，检查 `config/app.php` 中是否有冲突的服务提供者。

### Q: 模块目录不存在？

A: 确保 `config/modules.php` 中的路径配置正确，且目录有写入权限（Linux/macOS 上可能需要 `chmod -R 775 Modules/`）。

### Q: 命名空间冲突？

A: 修改 `config/modules.php` 中的 `namespace` 选项，如改为 `'App\Modules'`，并同步更新 `composer.json` 的 PSR-4 配置。

### Q: 模块的视图文件找不到？

A: 确认 `config/modules.php` 中 `discovery.views` 为 `true`，并运行 `php artisan view:clear` 清除视图缓存。

### Q: 能否将模块放在 vendor 或独立目录？

A: 可以。在 `config/modules.php` 的 `scan_paths` 中添加额外路径即可：

```php
'scan_paths' => [
    base_path('vendor/my-organization'),
    base_path('CustomModules'),
],
```

---

## 下一步

- 📖 [快速开始](02-quickstart.md) - 创建和使用第一个模块
- 📖 [模块结构](03-module-structure.md) - 了解模块目录结构
- 📖 [配置详解](04-configuration.md) - 完整的配置参考

