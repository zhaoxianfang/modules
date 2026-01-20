# Stub 映射文档

## 概述

本扩展包使用统一的 stub 映射系统来生成模块的所有文件。系统确保所有 29 个 stub 文件都能正确生成到对应的路径，并且每个文件的变量替换都是独立的，不会相互干扰。

## Stub 文件映射表

| 序号 | Stub 文件 | 目标文件路径 | 说明 |
|------|-----------|-------------|------|
| 1 | `provider.stub` | `Providers/{Module}ServiceProvider.php` | 服务提供者 |
| 2 | `config.stub` | `Config/{module}.php` | 模块配置文件 |
| 3 | `route/web.stub` | `Routes/web.php` | Web 路由文件 |
| 4 | `route/api.stub` | `Routes/api.php` | API 路由文件 |
| 5 | `route/admin.stub` | `Routes/admin.php` | Admin 路由文件 |
| 6 | `controller.base.stub` | `Http/Controllers/Controller.php` | 基础控制器 |
| 7 | `controller.stub` | `Http/Controllers/Web/{Module}Controller.php` | Web 控制器 |
| 8 | `controller.stub` | `Http/Controllers/Api/{Module}Controller.php` | API 控制器 |
| 9 | `controller.stub` | `Http/Controllers/Admin/{Module}Controller.php` | Admin 控制器 |
| 10 | `model.stub` | `Models/{Module}.php` | Eloquent 模型 |
| 11 | `observer.stub` | `Observers/{Module}Observer.php` | 模型观察者 |
| 12 | `policy.stub` | `Policies/{Module}Policy.php` | 策略类 |
| 13 | `repository.stub` | `Repositories/{Module}Repository.php` | 仓库类 |
| 14 | `request.stub` | `Http/Requests/{Module}Request.php` | 表单请求验证 |
| 15 | `resource.stub` | `Http/Resources/{Module}Resource.php` | API 资源类 |
| 16 | `middleware.stub` | `Http/Middleware/{Module}Middleware.php` | 中间件 |
| 17 | `command.stub` | `Console/Commands/{Module}Command.php` | Artisan 命令 |
| 18 | `event.stub` | `Events/{Module}Event.php` | 事件类 |
| 19 | `listener.stub` | `Listeners/{Module}Listener.php` | 事件监听器 |
| 20 | `seeder.stub` | `Database/Seeders/{Module}Seeder.php` | 数据填充器 |
| 21 | `test.stub` | `Tests/{Module}Test.php` | 单元测试 |
| 23 | `view.stub` | `Resources/views/welcome.blade.php` | 欢迎视图 |
| 24 | `view.index.stub` | `Resources/views/index.blade.php` | 列表视图 |
| 25 | `view.show.stub` | `Resources/views/show.blade.php` | 详情视图 |
| 26 | `layout.app.stub` | `Resources/views/layouts/app.blade.php` | App 布局 |
| 27 | `layout.simple.stub` | `Resources/views/layouts/simple.blade.php` | Simple 布局 |
| 28 | `readme.stub` | `README.md` | 模块说明文档 |
| 29 | `lang.stub` | `Resources/lang/zh-CN/messages.php` | 中文语言文件 |

## 可用的替换变量

### 模块名称变量

| 变量名 | 说明 | 示例（模块名为 Blog） |
|--------|------|----------------------|
| `{{NAME}}` | 模块名称（首字母大写） | `Blog` |
| `{{NAME_FIRST_LETTER}}` | 首字母 | `B` |
| `{{CAMEL_NAME}}` | 驼峰命名 | `blog` |
| `{{LOWER_CAMEL_NAME}}` | 小驼峰命名 | `blog` |
| `{{LOWER_NAME}}` | 全小写 | `blog` |
| `{{UPPER_NAME}}` | 全大写 | `BLOG` |
| `{{SLUG_NAME}}` | 虚线命名 | `blog` |
| `{{SNAKE_NAME}}` | 蛇形命名 | `blog` |

### 命名空间变量

| 变量名 | 说明 | 示例 |
|--------|------|------|
| `{{NAMESPACE}}` | 根命名空间 | `Modules` |
| `{{MODULE_NAMESPACE}}` | 模块命名空间 | `Modules\Blog` |
| `{{EVENT_NAMESPACE}}` | 事件命名空间 | `Modules\Blog\Events` |

### 控制器变量

| 变量名 | 说明 | 示例 |
|--------|------|------|
| `{{CONTROLLER_SUBNAMESPACE}}` | 控制器子命名空间 | `\Web`, `\Api`, `\Admin` |

### 类名变量

| 变量名 | 说明 | 示例 |
|--------|------|------|
| `{{CLASS}}` | 类名（动态设置） | `BlogController`, `BlogObserver`, `BlogRepository` |

### 路由变量

| 变量名 | 说明 | 示例 |
|--------|------|------|
| `{{ROUTE_PREFIX_VALUE}}` | 路由前缀值 | `blog`, `api/blog`, `blog/admin` |
| `{{ROUTE_NAME_PREFIX_VALUE}}` | 路由名称前缀值 | `web.blog.`, `api.blog.`, `admin.blog.` |
| `{{ROUTE_PREFIX_COMMENT}}` | 路由前缀注释 | `路由前缀: blog` |
| `{{ROUTE_NAME_PREFIX_COMMENT}}` | 路由名称前缀注释 | `路由名称前缀: web.blog.` |

### 命令变量

| 变量名 | 说明 | 示例 |
|--------|------|------|
| `{{SIGNATURE}}` | 命令签名 | `blog:command` |
| `{{DESCRIPTION}}` | 命令描述 | `模块 Blog 的示例命令` |

### 事件变量

| 变量名 | 说明 | 示例 |
|--------|------|------|
| `{{EVENT}}` | 事件类名 | `BlogEvent` |
| `{{EVENT_NAMESPACE}}` | 事件命名空间 | `Modules\Blog\Events` |

### 数据库变量

| 变量名 | 说明 | 示例 |
|--------|------|------|
| `{{TABLE}}` | 数据表名 | `blogs` |

### 其他变量

| 变量名 | 说明 | 示例 |
|--------|------|------|
| `{{MODULE_PATH}}` | 模块绝对路径 | `/path/to/Modules/Blog` |
| `{{DATE}}` | 当前日期 | `2026-01-19` |
| `{{YEAR}}` | 当前年份 | `2026` |
| `{{TIME}}` | 当前时间 | `14:30:45` |
| `{{DATETIME}}` | 日期时间 | `2026-01-19 14:30:45` |
| `{{VERSION}}` | 默认版本号 | `1.0.0` |

## 类名命名规范

生成的类文件遵循以下命名规范：

### 观察者

- 文件名：`{Module}Observer.php`
- 类名：`{Module}Observer`
- 示例：`BlogObserver.php` -> `BlogObserver`

### 策略

- 文件名：`{Module}Policy.php`
- 类名：`{Module}Policy`
- 示例：`BlogPolicy.php` -> `BlogPolicy`

### 仓库

- 文件名：`{Module}Repository.php`
- 类名：`{Module}Repository`
- 示例：`BlogRepository.php` -> `BlogRepository`

### 请求验证

- 文件名：`{Module}Request.php`
- 类名：`{Module}Request`
- 示例：`BlogRequest.php` -> `BlogRequest`

### 资源

- 文件名：`{Module}Resource.php`
- 类名：`{Module}Resource`
- 示例：`BlogResource.php` -> `BlogResource`

### 中间件

- 文件名：`{Module}Middleware.php`
- 类名：`{Module}Middleware`
- 示例：`BlogMiddleware.php` -> `BlogMiddleware`

### 命令

- 文件名：`{Module}Command.php`
- 类名：`{Module}Command`
- 示例：`BlogCommand.php` -> `BlogCommand`

### 事件

- 文件名：`{Module}Event.php`
- 类名：`{Module}Event`
- 示例：`BlogEvent.php` -> `BlogEvent`

### 监听器

- 文件名：`{Module}Listener.php`
- 类名：`{Module}Listener`
- 示例：`BlogListener.php` -> `BlogListener`

### 数据填充器

- 文件名：`{Module}Seeder.php`
- 类名：`{Module}Seeder`
- 示例：`BlogSeeder.php` -> `BlogSeeder`

### 测试

- 文件名：`{Module}Test.php`
- 类名：`{Module}Test`
- 示例：`BlogTest.php` -> `BlogTest`

### 控制器

- 文件名：`{Module}Controller.php`
- 类名：`{Module}Controller`
- 示例：`BlogController.php` -> `BlogController`

- 子目录控制器（Web/Api/Admin）：
  - 路径：`Http/Controllers/Web/{Module}Controller.php`
  - 类名：`{Module}Controller`
  - 命名空间：`Modules\Blog\Http\Controllers\Web`

## 变量替换机制

### 独立性保证

为避免不同文件间变量替换相互干扰，系统采用以下机制：

1. **读取阶段**：读取 stub 文件内容到内存
2. **替换阶段**：使用临时副本的替换变量进行替换
3. **写入阶段**：将替换后的内容写入目标文件

每个文件的替换操作都是独立的，不会污染全局状态。

### 替换顺序

变量替换按照定义的顺序执行，确保替换的正确性：

1. 首先应用默认替换变量（由 StubGenerator 自动生成）
2. 然后应用映射中定义的特定替换变量
3. 最后执行文件内容的 str_replace()

### 验证机制

StubGenerator 提供严格模式，可以检测未替换的变量：

- 如果 stub 中使用了未定义的变量，会记录警告
- 开发环境下会在日志中显示警告信息
- 生产环境下静默失败，不影响正常生成

## 扩展指南

### 添加新的 Stub 文件

1. 在 `src/Commands/stubs/` 目录创建新的 stub 文件
2. 在 `ModuleMakeCommand::initializeStubMapping()` 方法中添加映射
3. 确保类名和命名空间符合 PSR-4 规范

### 示例：添加一个新的 Stub

```php
// 1. 创建新 stub 文件
// src/Commands/stubs/custom.stub

// 2. 在 initializeStubMapping() 中添加映射
$this->stubMapping[] = [
    'stub' => 'custom.stub',
    'destination' => 'Custom/' . $moduleName . 'Custom.php',
    'replacements' => [
        '{{NAMESPACE}}' => $namespace,
        '{{NAME}}' => $moduleName,
        '{{CLASS}}' => $moduleName . 'Custom',
    ],
];
```

## 注意事项

1. **PSR-4 规范**：所有生成的类文件必须符合 PSR-4 规范
2. **命名一致性**：类名必须与文件名一致（不含 `.php` 扩展名）
3. **命名空间正确**：确保生成的命名空间与目录结构匹配
4. **变量完整性**：确保 stub 中使用的所有变量都在 replacements 中定义
5. **注释规范**：所有代码注释使用中文，移除 `@version`、`@since` 标签

## 调试

### 查看生成的文件

创建模块后，可以使用以下命令查看生成的文件：

```bash
# 列出模块的所有文件
find Modules/Blog -type f -name "*.php" -o -name "*.md" -o -name "*.blade.php"

# 查看生成的类名和命名空间
grep -r "^class " Modules/Blog/
grep -r "^namespace " Modules/Blog/
```

### 验证变量替换

开发环境下，StubGenerator 会记录变量替换警告：

```bash
# 查看日志
tail -f storage/logs/laravel.log | grep "Stub"
```
