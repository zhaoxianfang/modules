# 文档目录索引

本文档提供了 Laravel 模块系统的完整文档索引。

## 📚 文档分类

### 🚀 入门指南
1. [功能一览](00-overview.md) - 所有功能和配置的完整表格
2. [安装指南](01-installation.md) - 详细的安装和配置步骤
3. [快速开始](02-quickstart.md) - 快速上手示例

### 🔧 核心功能
1. [模块结构](03-module-structure.md) - 完整的模块目录结构说明
2. [配置详解](04-configuration.md) - 所有配置选项的详细说明
3. [Helper 函数](05-helper-functions.md) - 40+ 个助手函数的完整参考
4. [智能模块检测](06-intelligent-detection.md) - 自动检测当前模块的机制

### 🌐 路由与视图
1. [路由指南](07-routes.md) - 路由配置和使用方法
2. [视图使用](08-views.md) - 视图命名空间和加载方法

### 🛠️ 开发指南
1. [命令参考](09-commands.md) - 所有 Artisan 命令的详细说明
2. [代码生成](10-code-generation.md) - 代码生成器的使用和配置
3. [迁移管理](11-migrations.md) - 数据库迁移的管理方法

### 🎯 最佳实践
1. [最佳实践](12-best-practices.md) - 模块开发的最佳实践
2. [架构设计](13-architecture.md) - 系统架构和设计原理

### 🔍 高级功能
1. [自动发现机制](14-auto-discovery.md) - 自动发现组件的详细机制
2. [Stub 模板映射](15-stub-mapping.md) - Stub 模板变量说明

### 🐛 调试与故障排除
1. [本地化文件检查](16-check-lang.md) - 检查模块本地化文件差异
2. [命令整理总结](COMMANDS_SUMMARY.md) - 所有命令的整理和更新记录

## 📖 按主题索引

### 模块管理
- 创建模块：[快速开始](02-quickstart.md)
- 列出模块：[命令参考](09-commands.md#module-list)
- 查看详情：[命令参考](09-commands.md#module-info)
- 验证模块：[命令参考](09-commands.md#module-validate)
- 删除模块：[命令参考](09-commands.md#module-delete)
- 发布资源：[命令参考](09-commands.md#module-publish)
- 检查本地化：[本地化文件检查](16-check-lang.md)
- 调试命令：[命令参考](09-commands.md#module-debug-commands)

### 代码生成
- 控制器：[代码生成](10-code-generation.md#控制器)
- 模型：[代码生成](10-code-generation.md#模型)
- 迁移：[代码生成](10-code-generation.md#迁移)
- 命令：[代码生成](10-code-generation.md#命令)
- 事件：[代码生成](10-code-generation.md#事件)
- 监听器：[代码生成](10-code-generation.md#监听器)

### 路由配置
- Web 路由：[路由指南](07-routes.md#web-路由)
- API 路由：[路由指南](07-routes.md#api-路由)
- Admin 路由：[路由指南](07-routes.md#admin-路由)
- 路由前缀：[配置详解](04-configuration.md#路由配置)

### 视图使用
- 视图命名空间：[视图使用](08-views.md#视图命名空间)
- 返回视图：[视图使用](08-views.md#返回视图)
- 视图继承：[视图使用](08-views.md#视图继承)

### 配置管理
- 模块配置：[配置详解](04-configuration.md#模块配置)
- 自定义配置：[配置详解](04-configuration.md#自定义配置)
- 配置读取：[Helper 函数](05-helper-functions.md#配置读取)

### Helper 函数
- 路径函数：[Helper 函数](05-helper-functions.md#路径函数)
- 配置函数：[Helper 函数](05-helper-functions.md#配置函数)
- 视图函数：[Helper 函数](05-helper-functions.md#视图函数)
- 路由函数：[Helper 函数](05-helper-functions.md#路由函数)

### 命令开发
- 创建命令：[代码生成](10-code-generation.md#命令生成)
- 命令注册：[自动发现机制](14-auto-discovery.md#命令发现)
- 调试命令：[命令参考](09-commands.md#module-debug-commands)

### 迁移管理
- 创建迁移：[迁移管理](11-migrations.md#创建迁移)
- 运行迁移：[迁移管理](11-migrations.md#运行迁移)
- 回滚迁移：[迁移管理](11-migrations.md#回滚迁移)
- 迁移状态：[迁移管理](11-migrations.md#查看状态)

### 事件系统
- 创建事件：[代码生成](10-code-generation.md#事件)
- 创建监听器：[代码生成](10-code-generation.md#监听器)
- 事件注册：[自动发现机制](14-auto-discovery.md#事件发现)

### 模型观察者
- 创建观察者：[代码生成](10-code-generation.md#观察者)
- 观察者注册：[自动发现机制](14-auto-discovery.md#观察者发现)

### 策略类
- 创建策略：[代码生成](10-code-generation.md#策略)
- 策略注册：[自动发现机制](14-auto-discovery.md#策略发现)

## 🎓 学习路径

### 初学者
1. 从 [快速开始](02-quickstart.md) 开始，创建第一个模块
2. 阅读 [模块结构](03-module-structure.md)，了解模块组织
3. 参考 [Helper 函数](05-helper-functions.md)，使用助手函数

### 进阶开发
1. 学习 [路由指南](07-routes.md) 和 [视图使用](08-views.md)
2. 掌握 [配置详解](04-configuration.md) 的高级配置
3. 了解 [自动发现机制](14-auto-discovery.md) 的工作原理
4. 参考 [最佳实践](12-best-practices.md)，提升代码质量

### 高级用户
1. 深入学习 [架构设计](13-architecture.md)
2. 掌握 [Stub 模板映射](15-stub-mapping.md)，自定义模板
3. 学习 [代码生成](10-code-generation.md)，扩展生成器
4. 研究 [自动发现机制](14-auto-discovery.md)，理解核心原理

## 🔧 快速查找

### 我想要...

#### 创建新模块
→ [快速开始](02-quickstart.md#创建模块)

#### 创建控制器
→ [代码生成 - 控制器](10-code-generation.md#控制器)

#### 创建模型
→ [代码生成 - 模型](10-code-generation.md#模型)

#### 添加路由
→ [路由指南](07-routes.md)

#### 返回视图
→ [视图使用](08-views.md)

#### 读取配置
→ [Helper 函数 - 配置](05-helper-functions.md#配置读取)

#### 创建命令
→ [代码生成 - 命令](10-code-generation.md#命令)

#### 创建迁移
→ [迁移管理 - 创建迁移](11-migrations.md#创建迁移)

#### 创建事件
→ [代码生成 - 事件](10-code-generation.md#事件)

#### 优化性能
→ [最佳实践](12-best-practices.md#性能优化)

#### 自定义模块结构
→ [配置详解](04-configuration.md#路径配置)

## 📝 文档更新

### 最新更新 (v5.0.0)
- ✅ 移除 composer.json 管理模块的逻辑，改用配置文件驱动
- ✅ 新增 `priority`、`aliases`、`providers`、`laravel_aliases` 元数据键
- ✅ `enable` → `enabled` 键名规范化
- ✅ `config` → `options` 自定义配置键重命名
- ✅ ModuleAutoDiscovery 支持配置文件声明的 providers/aliases

## 🔗 相关资源

### 官方文档
- [Laravel 文档](https://laravel.com/docs)
- [Laravel Artisan](https://laravel.com/docs/artisan)
- [Laravel 路由](https://laravel.com/docs/routing)
- [Laravel 视图](https://laravel.com/docs/views)

### 社区资源
- [GitHub 仓库](https://github.com/zxf/modules)
- [问题反馈](https://github.com/zxf/modules/issues)
- [功能建议](https://github.com/zxf/modules/discussions)

## 📖 文档规范

本文档遵循以下规范：
- 所有示例代码都经过测试
- 中文注释详细说明每个功能
- 提供完整的代码示例
- 包含常见问题和解决方案

## 💡 文档反馈

如果您发现文档中的错误或有改进建议，欢迎：
1. 提交 [Issue](https://github.com/zxf/modules/issues)
2. 发起 [Pull Request](https://github.com/zxf/modules/pulls)
3. 参与 [讨论](https://github.com/zxf/modules/discussions)

---

**版本**：5.0.0  
**最后更新**：2025-06-17  
**维护者**：zxf
