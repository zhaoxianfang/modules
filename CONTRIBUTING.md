# 贡献指南

感谢您对 Laravel 模块系统项目的贡献！本指南将帮助您了解如何参与项目开发。

## 开发环境要求

- PHP 8.2+
- Composer 2.0+
- Laravel 11+ (用于测试)
- Git

## 设置开发环境

1. Fork 本项目到您的 GitHub 账户
2. 克隆您的 fork 到本地：
   ```bash
   git clone https://github.com/your-username/modules.git
   cd modules
   ```
3. 安装依赖：
   ```bash
   composer install
   ```

## 项目结构

```
modules/
├── config/                    # 配置文件
├── src/                      # 源代码
│   ├── Console/              # Artisan 命令
│   ├── Contracts/            # 接口定义
│   ├── Facades/              # Facade 类
│   ├── ModulesServiceProvider.php # 服务提供者
│   └── helper.php            # 辅助函数
├── stubs/                    # 代码生成模板
├── tests/                    # 测试文件
└── vendor/                   # 依赖包
```

## 代码规范

所有代码必须遵循以下规范：

### PHP 代码规范

1. **PSR-12 编码规范**：遵循 PSR-12 编码风格
2. **类型声明**：所有方法和函数必须包含类型声明
3. **严格类型**：每个 PHP 文件必须以 `declare(strict_types=1);` 开头
4. **命名规范**：
   - 类名：`StudlyCaps`
   - 方法名：`camelCase`
   - 变量名：`camelCase`
   - 常量名：`UPPER_SNAKE_CASE`

### 注释规范

1. **所有注释必须使用中文**
2. 类注释：包含类描述、作者、示例等
3. 方法注释：包含方法描述、参数说明、返回值说明
4. 复杂逻辑注释：解释算法或业务逻辑

### Laravel 规范

1. 遵循 Laravel 11+ 的最佳实践
2. 使用 Laravel 的服务容器和门面模式
3. 遵循 Laravel 的文件和目录结构

## 运行测试

项目使用 PHPUnit 进行测试。运行测试：

```bash
composer test
```

或直接运行 PHPUnit：

```bash
./vendor/bin/phpunit
```

运行特定测试文件：

```bash
./vendor/bin/phpunit tests/Unit/ModuleTest.php
```

## 添加新功能

### 1. 创建新功能分支

```bash
git checkout -b feature/new-feature-name
```

### 2. 编写代码

遵循上述代码规范，编写清晰、可测试的代码。

### 3. 添加测试

为新功能添加相应的单元测试和功能测试。

### 4. 更新文档

更新 README.md 或其他相关文档，确保文档与功能保持一致。

### 5. 提交代码

使用描述性的提交信息：

```bash
git add .
git commit -m "feat: 添加新功能名称"
```

提交信息格式遵循 [Conventional Commits](https://www.conventionalcommits.org/)：
- `feat:` 新功能
- `fix:` bug 修复
- `docs:` 文档更新
- `style:` 代码格式调整
- `refactor:` 代码重构
- `test:` 测试相关
- `chore:` 构建过程或辅助工具变动

### 6. 推送分支

```bash
git push origin feature/new-feature-name
```

### 7. 创建 Pull Request

在 GitHub 上创建 Pull Request，描述功能变更、测试情况和相关文档更新。

## 报告问题

如果您发现 bug 或有功能建议：

1. 在 [GitHub Issues](https://github.com/your-username/modules/issues) 中搜索是否已有类似问题
2. 如果没有，创建新 issue，包含：
   - 问题描述
   - 重现步骤
   - 期望行为
   - 实际行为
   - 环境信息（PHP 版本、Laravel 版本等）

## 开发工作流

### 代码审查

所有 Pull Request 都需要至少一名维护者审查。审查要点：

1. 代码质量
2. 测试覆盖率
3. 文档更新
4. 向后兼容性

### 合并策略

- 使用 "Squash and merge" 合并 Pull Request
- 确保提交历史清晰、整洁
- 合并后删除功能分支

## 发布流程

只有项目维护者可以发布新版本：

1. 更新 `CHANGELOG.md`（如果需要）
2. 更新版本号（遵循语义化版本）
3. 创建发布标签
4. 在 GitHub 上创建正式发布

## 行为准则

请遵守 [贡献者公约](https://www.contributor-covenant.org/version/2/0/code_of_conduct/)。确保您的贡献是建设性的、尊重的，并遵循项目的目标和标准。

## 获取帮助

如果您在贡献过程中遇到问题：

1. 查看现有文档和 issue
2. 在 issue 中提问
3. 联系项目维护者

---

再次感谢您的贡献！您的努力将帮助这个项目变得更好。