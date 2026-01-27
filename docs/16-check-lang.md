# 模块本地化文件差异检查命令

## 概述

`module:check-lang` 命令用于检查模块内不同语言的翻译文件配置项是否一致，找出缺失的配置项。

## 功能特性

- ✅ 支持检查单个模块或所有模块
- ✅ 支持嵌套的配置项（如 `error.not_found`）
- ✅ 详细的差异报告，指出具体缺失的配置项
- ✅ 自定义本地化路径支持
- ✅ 彩色输出，易于阅读

## 使用方法

### 检查所有模块

```bash
php artisan module:check-lang
```

### 检查指定模块

```bash
php artisan module:check-lang Blog
```

### 使用自定义路径

```bash
php artisan module:check-lang Blog --path=lang
```

## 输出示例

### 场景 1: 配置项完整

```bash
$ php artisan module:check-lang Blog

正在检查模块 [Blog] 的本地化文件...

发现 3 种语言: en, zh-CN, es

✓ 所有语言的配置项完整一致！
```

### 场景 2: 存在差异

```bash
$ php artisan module:check-lang Blog

正在检查模块 [Blog] 的本地化文件...

发现 3 种语言: en, zh-CN, es

发现配置项差异：

语言 [zh-CN] 缺失的配置项：
  文件 [messages] 缺失 2 个配置项:
    - greeting.hello
    - error.not_found

语言 [es] 缺失的配置项：
  文件 [messages] 缺失 3 个配置项:
    - greeting.hello
    - error.not_found
    - error.unauthorized
```

### 场景 3: 检查所有模块

```bash
$ php artisan module:check-lang

正在检查所有模块的本地化文件...

+------------+---------+-----------+
| 模块名称   | 状态    | 缺失配置项数 |
+------------+---------+-----------+
| Blog       | 存在差异 | 5         |
| Admin      | 完整    | -         |
| Logs       | 无本地化目录 | -     |
+------------+---------+-----------+

模块 [Blog] 的详细差异：
语言列表: en, zh-CN, es

语言 [zh-CN] 缺失的配置项：
  文件 [messages] 缺失 2 个配置项:
    - greeting.hello
    - error.not_found
```

## 文件结构要求

模块本地化文件应按照以下结构组织：

```
Modules/Blog/Resources/lang/
├── en/
│   ├── messages.php
│   └── validation.php
├── zh-CN/
│   ├── messages.php
│   └── validation.php
└── es/
    ├── messages.php
    └── validation.php
```

### 翻译文件示例

`en/messages.php`:
```php
<?php

return [
    'welcome' => 'Welcome to the Blog module',
    'success' => 'Operation successful',
    'error' => 'Operation failed',

    'greeting' => [
        'hello' => 'Hello',
        'goodbye' => 'Goodbye',
    ],

    'error' => [
        'not_found' => 'Resource not found',
        'unauthorized' => 'Unauthorized access',
    ],
];
```

`zh-CN/messages.php`:
```php
<?php

return [
    'welcome' => '欢迎来到 Blog 模块',
    'success' => '操作成功',
    'error' => '操作失败',

    'greeting' => [
        'hello' => '你好',
        'goodbye' => '再见',
    ],

    'error' => [
        'not_found' => '资源不存在',
        'unauthorized' => '未授权访问',
    ],
];
```

## 命令参数

| 参数 | 说明 | 必需 | 默认值 |
|-----|------|------|-------|
| `name` | 模块名称 | 否 | 无（检查所有模块） |

| 选项 | 说明 | 默认值 |
|-----|------|-------|
| `--path=` | 自定义本地化路径 | `Resources/lang` |

## 使用建议

### 1. 定期检查

建议在 CI/CD 流程中集成此命令：

```yaml
# .github/workflows/ci.yml
- name: Check module translations
  run: php artisan module:check-lang
```

### 2. 保持配置同步

添加新配置项时，确保所有语言的翻译文件都包含该配置项：

```bash
# 1. 检查差异
php artisan module:check-lang Blog

# 2. 根据输出补充缺失的配置项
# 编辑对应的翻译文件

# 3. 再次检查确认
php artisan module:check-lang Blog
```

### 3. 团队协作规范

在团队中制定以下规范：
- 添加新语言时，复制现有语言的完整结构
- 添加新配置项时，同时更新所有语言文件
- 代码审查时检查翻译文件的一致性

## 注意事项

1. **至少需要两种语言**：如果模块只有一种语言，无法进行对比
2. **只检查键名**：命令只比较配置键名，不比较值的内容
3. **文件格式**：翻译文件必须是返回数组的 PHP 文件
4. **自动忽略**：空文件或格式错误的文件会被忽略

## 故障排除

### 问题：提示"无本地化目录"

**可能原因**：
- 模块下没有本地化目录
- 目录名称不正确

**解决方案**：
- 确认目录路径是否正确（默认：`Resources/lang`）
- 使用 `--path` 参数指定正确的路径
- 创建本地化目录

### 问题：提示"只有 X 种语言,无法进行对比"

**可能原因**：
- 模块内只有一种或零种语言

**解决方案**：
- 添加更多语言的翻译文件
- 至少需要两种语言才能进行对比

### 问题：某些配置项被标记为缺失

**可能原因**：
- 配置键名在不同语言文件中不一致
- 嵌套层级不正确

**解决方案**：
- 检查配置键名是否完全一致（包括大小写）
- 确保嵌套结构的层级正确

## 相关命令

- `php artisan module:make` - 创建新模块
- `php artisan module:list` - 列出所有模块
- `php artisan module:info` - 查看模块详情
- `php artisan module:validate` - 验证模块完整性

## 技术细节

### 检查算法

1. 扫描模块的本地化目录，获取所有语言文件夹
2. 对于每种语言，收集所有翻译文件
3. 从所有语言的所有文件中收集所有可能的配置键
4. 对于每种语言，对比其配置键与所有配置键的差异
5. 输出缺失的配置键列表

### 支持的配置结构

- 扁平结构：`'key' => 'value'`
- 嵌套结构：`'group' => ['subkey' => 'value']`
- 深层嵌套：`'group' => ['subgroup' => ['key' => 'value']]`

### 性能考虑

- 使用 PHP 原生函数，性能高效
- 支持大量模块和语言文件
- 内存占用合理，不会产生内存溢出

## 贡献

如果您发现任何问题或有改进建议，欢迎提交 Issue 或 Pull Request。

## 许可证

MIT License
