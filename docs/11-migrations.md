# 数据库迁移

本文档详细介绍模块系统的数据库迁移功能，包括迁移的创建、运行、回滚等操作。

## 迁移命令一览表

### 创建迁移

| 命令                      | 说明     | 用法                                                                                                              |
|-------------------------|--------|-----------------------------------------------------------------------------------------------------------------|
| `module:make-migration` | 创建迁移文件 | `php artisan module:make-migration <module> <name> [--create=] [--update=] [--path=] [--realpath] [--fullpath]` |

### 运行和管理迁移

| 命令                        | 说明           | 用法                                                                             |
|---------------------------|--------------|--------------------------------------------------------------------------------|
| `module:migrate`          | 运行所有或指定模块的迁移 | `php artisan module:migrate [module] [--force] [--path=] [--seed] [--seeder=]` |
| `module:migrate-reset`    | 回滚最后一次迁移     | `php artisan module:migrate-reset [module] [--force] [--path=]`                |
| `module:migrate-refresh`  | 回滚并重新运行迁移    | `php artisan module:migrate-refresh [module] [--force] [--seed] [--seeder=]`   |
| `module:migrate-rollback` | 回滚指定步数的迁移    | `php artisan module:migrate-rollback [module] [--step=] [--force] [--path=]`   |
| `module:migrate-status`   | 查看迁移状态       | `php artisan module:migrate-status [module] [--path=]`                         |

---

## 目录

1. [迁移概述](#迁移概述)
2. [迁移命名规范](#迁移命名规范)
3. [创建迁移](#创建迁移)
4. [运行迁移](#运行迁移)
5. [回滚迁移](#回滚迁移)
6. [重置迁移](#重置迁移)
7. [迁移状态](#迁移状态)
8. [迁移最佳实践](#迁移最佳实践)
9. [常见问题](#常见问题)

---

## 迁移概述

### 什么是迁移

数据库迁移是一种版本控制数据库架构的方式。它允许您：

- ✅ 使用代码定义数据库结构
- ✅ 在团队成员之间共享数据库变更
- ✅ 轻松回滚到之前的版本
- ✅ 在不同数据库之间保持一致

### 模块迁移特点

模块系统的数据库迁移功能具有以下特点：

- **自动发现**：模块系统的迁移会自动注册到 Laravel 的迁移系统
- **独立管理**：每个模块的迁移文件相互独立，互不干扰
- **灵活执行**：可以单独运行某个模块的迁移，也可以运行所有模块的迁移
- **智能命名**：支持 Laravel 11+ 的智能命名规范，自动识别操作类型，无需手动指定表名
- **中文注释**：所有生成的迁移文件都包含详细的中文注释，便于理解和维护
- **完整中文提示**：命令行提示信息全部使用中文，提供友好的使用体验

---

## 迁移命名规范

模块系统的迁移生成命令支持 Laravel 11+ 的智能命名规范。通过迁移名称即可自动识别操作类型，无需额外指定表名。

### 支持的迁移类型

#### 1. 创建表

**命名格式**：`create_{table}_table`

**说明**：创建新的数据表

**示例**：
```bash
# 创建 users 表
php artisan module:make-migration Blog create_users_table

# 创建 posts 表
php artisan module:make-migration Blog create_posts_table
```

**生成的迁移**：
```php
public function up(): void
{
    Schema::create('users', function (Blueprint $table) {
        $table->id(); // 主键，自增 ID
        // $table->string('name')->comment('名称'); // 字符串类型，带注释
        // $table->text('description')->nullable()->comment('描述'); // 文本类型，允许为空
        // $table->string('email')->unique(); // 唯一索引
        // $table->integer('status')->default(0)->comment('状态'); // 整数类型，默认值
        // $table->foreignId('user_id')->constrained()->onDelete('cascade'); // 外键关联
        // $table->timestamps(); // created_at 和 updated_at 时间戳
        // $table->softDeletes(); // deleted_at 软删除时间戳

        // 根据您的需求添加更多字段
    });
}
```

#### 2. 删除表

**命名格式**：`drop_{table}_table`

**说明**：删除指定的数据表（如果存在）

**示例**：
```bash
# 删除 users 表
php artisan module:make-migration Blog drop_users_table
```

**生成的迁移**：
```php
public function up(): void
{
    // 删除数据表（如果存在）
    Schema::dropIfExists('users');
}

public function down(): void
{
    // 注意：删除表的迁移通常无法完全回滚
    // 如果需要，可以在这里重新创建表结构
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        // 根据原表结构添加其他字段
        // $table->string('name');
        // $table->timestamps();
    });
}
```

#### 3. 重命名表

**命名格式**：`rename_{old}_to_{new}_table`

**说明**：将数据表从旧名称重命名为新名称

**示例**：
```bash
# 将 users 表重命名为 customers
php artisan module:make-migration Blog rename_users_to_customers_table
```

**生成的迁移**：
```php
public function up(): void
{
    Schema::rename('users', 'customers');
}

public function down(): void
{
    Schema::rename('customers', 'users');
}
```

#### 4. 添加字段

**命名格式**：`add_{field(s)}_to_{table}_table`

**说明**：向现有数据表添加一个或多个字段

**单个字段**：
```bash
# 向 users 表添加 email 字段
php artisan module:make-migration Blog add_email_to_users_table
```

**多个字段**：
```bash
# 向 users 表添加 email 和 phone 字段
php artisan module:make-migration Blog add_email_and_phone_to_users_table
```

**生成的迁移**：
```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('email'); // 修改字段类型
        $table->string('phone'); // 修改字段类型
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('email');
        $table->dropColumn('phone');
    });
}
```

#### 5. 删除字段

**命名格式**：`drop_{field(s)}_from_{table}_table`

**说明**：从数据表删除一个或多个字段

**单个字段**：
```bash
# 从 users 表删除 email 字段
php artisan module:make-migration Blog drop_email_from_users_table
```

**多个字段**：
```bash
# 从 users 表删除 email 和 phone 字段
php artisan module:make-migration Blog drop_email_and_phone_from_users_table
```

**生成的迁移**：
```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('email');
        $table->dropColumn('phone');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('email');
        $table->string('phone');
    });
}
```

#### 6. 修改字段

**命名格式**：`change_{field(s)}_in_{table}_table`

**说明**：修改数据表字段的属性（如类型、长度等）

**示例**：
```bash
# 修改 users 表的 email 字段
php artisan module:make-migration Blog change_email_in_users_table
```

**生成的迁移**：
```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('email')->change(); // 修改字段定义
    });
}

public function down(): void
{
    // 回滚逻辑（可选）
}
```

#### 7. 添加索引

**命名格式**：`add_{index_type}_index_on_{table}_table`

**说明**：为数据表添加索引以提升查询性能

**示例**：
```bash
# 为 users 表的 email 字段添加索引
php artisan module:make-migration Blog add_email_index_on_users_table
```

**生成的迁移**：
```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->index('email');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropIndex('email');
    });
}
```

#### 8. 删除索引

**命名格式**：`drop_{index_type}_index_on_{table}_table`

**说明**：从数据表删除指定的索引

**示例**：
```bash
# 从 users 表删除 email 索引
php artisan module:make-migration Blog drop_email_index_on_users_table
```

**生成的迁移**：
```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropIndex('email');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->index('email');
    });
}
```

### 命名规范总结

| 操作类型 | 命名格式 | 示例 |
|---------|-----------|------|
| 创建表 | `create_{table}_table` | `create_users_table` |
| 删除表 | `drop_{table}_table` | `drop_users_table` |
| 重命名表 | `rename_{old}_to_{new}_table` | `rename_users_to_customers_table` |
| 添加字段 | `add_{field}_to_{table}_table` | `add_email_to_users_table` |
| 删除字段 | `drop_{field}_from_{table}_table` | `drop_email_from_users_table` |
| 修改字段 | `change_{field}_in_{table}_table` | `change_email_in_users_table` |
| 添加索引 | `add_{index}_index_on_{table}_table` | `add_email_index_on_users_table` |
| 删除索引 | `drop_{index}_index_on_{table}_table` | `drop_email_index_on_users_table` |

---

## 创建迁移

### 基本用法

```bash
# 创建迁移文件
php artisan module:make-migration {module} {name}
```

### 参数

- `module`：模块名称（必需）
- `name`：迁移名称（必需）

### 选项

| 选项 | 说明 | 示例 |
|-----|------|------|
| `--create=` | 要创建的表名（已弃用，请使用命名规范） | `--create=users` |
| `--table=` | 要修改的表名（已弃用，请使用命名规范） | `--table=users` |
| `--path=` | 迁移文件路径 | `--path=/custom/path` |
| `--realpath` | 指示提供的任何迁移文件路径都是预解析的绝对路径 | `--realpath` |
| `--fullpath` | 不在模块 Database/Migrations 目录中生成迁移 | `--fullpath` |

### 使用示例

#### 创建用户表

```bash
php artisan module:make-migration Blog create_users_table
```

输出示例：
```
成功在模块 [Blog] 中创建迁移文件 [2026_01_20_120000_create_users_table.php]

迁移类型: create
操作对象: users
```

生成的文件：`Modules/Blog/Database/Migrations/2026_01_20_120000_create_users_table.php`

#### 添加字段

```bash
php artisan module:make-migration Blog add_email_to_users_table
```

输出示例：
```
成功在模块 [Blog] 中创建迁移文件 [2026_01_20_120100_add_email_to_users_table.php]

迁移类型: add
操作对象: users
字段列表: email
```

#### 添加多个字段

```bash
php artisan module:make-migration Blog add_email_and_phone_to_users_table
```

输出示例：
```
成功在模块 [Blog] 中创建迁移文件 [2026_01_20_120200_add_email_and_phone_to_users_table.php]

迁移类型: add
操作对象: users
字段列表: email, phone
```

#### 删除字段

```bash
php artisan module:make-migration Blog drop_email_from_users_table
```

### 生成的文件结构

```
Modules/Blog/
└── Database/
    └── Migrations/
        ├── 2026_01_20_120000_create_users_table.php
        ├── 2026_01_20_120100_add_email_to_users_table.php
        └── 2026_01_20_120200_drop_email_from_users_table.php
```

### 迁移文件结构

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 执行迁移
     *
     * @return void
     */
    public function up(): void
    {
        // 迁移逻辑
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down(): void
    {
        // 回滚逻辑
    }
};
```

---

## 运行迁移

### 运行所有模块的迁移

```bash
php artisan module:migrate
```

### 运行指定模块的迁移

```bash
php artisan module:migrate Blog
```

### 命令选项

| 选项 | 说明 |
|-----|------|
| `--force` | 强制运行，不提示确认 |
| `--path=` | 指定迁移路径 |
| `--seed` | 运行迁移后执行数据填充 |
| `--seeder=` | 指定要运行的数据填充器 |

### 示例

#### 强制运行迁移

```bash
php artisan module:migrate --force
```

#### 运行迁移并填充数据

```bash
php artisan module:migrate --seed
```

#### 指定数据填充器

```bash
php artisan module:migrate --seeder=DatabaseSeeder
```

#### 运行指定模块的迁移

```bash
php artisan module:migrate Blog
```

---

## 回滚迁移

### 回滚最后一次迁移

```bash
# 回滚所有模块的最后一次迁移
php artisan module:migrate-reset

# 回滚指定模块的最后一次迁移
php artisan module:migrate-reset Blog
```

**命令选项**：
- `--force`：强制运行，不提示确认
- `--path=`：指定迁移路径

**示例**：
```bash
# 强制回滚
php artisan module:migrate-reset --force

# 指定模块并强制回滚
php artisan module:migrate-reset Blog --force
```

---

## 重置迁移

### 重置并重新运行迁移

重置迁移会回滚所有迁移，然后重新运行它们。

```bash
# 重置所有模块的迁移
php artisan module:migrate-refresh

# 重置指定模块的迁移
php artisan module:migrate-refresh Blog
```

**命令选项**：
- `--force`：强制运行
- `--seed`：运行迁移后执行数据填充
- `--seeder=`：指定要运行的数据填充器

**示例**：
```bash
# 重置并填充数据
php artisan module:migrate-refresh --seed

# 重置指定模块并使用特定填充器
php artisan module:migrate-refresh Blog --seeder=DatabaseSeeder
```

### 回滚多个步骤

```bash
# 回滚所有模块的最近 3 个迁移
php artisan module:migrate-rollback --step=3

# 回滚指定模块的最近 3 个迁移
php artisan module:migrate-rollback Blog --step=3
```

## 迁移状态

### 查看所有模块的迁移状态

```bash
php artisan module:migrate-status
```

### 查看指定模块的迁移状态

```bash
php artisan module:migrate-status Blog
```

### 迁移状态命令选项

```bash
# 仅查看待运行的迁移
php artisan module:migrate-status --pending

# 仅查看已运行的迁移
php artisan module:migrate-status --ran

# 查看不显示统计信息
php artisan module:migrate-status --no-stats

# 组合使用选项
php artisan module:migrate-status Blog --pending
```

### 输出示例

```
+---+--------+-------------------------+-------+----------+
| # | 模块   | 迁移文件                | 批次  | 状态     |
+---+--------+-------------------------+-------+----------+
| 1 | Blog   | 2024_01_01_000001_...   | 1     | 已运行   |
| 2 | Blog   | 2024_01_02_000002_...   | -     | 待运行   |
+---+--------+-------------------------+-------+----------+

迁移统计:
  模块总数: 1 / 3
  迁移文件总数: 2
  已运行: 1
  待运行: 1
```

### 状态说明

- **已运行**：迁移已经执行
- **待运行**：迁移尚未执行

### 统计信息说明

- **模块总数**：当前模块总数中包含迁移文件的模块数量
- **迁移文件总数**：所有迁移文件的数量
- **已运行**：已执行的迁移数量
- **待运行**：尚未执行的迁移数量

---

## 迁移最佳实践

### 1. 命名规范

**推荐**：
```bash
php artisan module:make-migration Blog create_users_table
```

**不推荐**：
```bash
php artisan module:make-migration Blog user_migration
```

使用规范的命名可以让其他开发者快速理解迁移的用途。

### 2. 小批量迁移

将大的变更拆分成多个小的迁移：

**推荐**：
```bash
php artisan module:make-migration Blog create_users_table
php artisan module:make-migration Blog add_email_to_users_table
php artisan module:make-migration Blog add_profile_to_users_table
```

**不推荐**：
```bash
php artisan module:make-migration Blog create_users_table_with_all_fields
```

### 3. 可逆性

确保每个迁移都可以回滚：

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('email');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('email');
    });
}
```

### 4. 使用事务

对于复杂的迁移，使用事务确保原子性：

```php
public function up(): void
{
    DB::transaction(function () {
        // 多个数据库操作
    });
}
```

### 5. 版本控制

始终将迁移文件提交到版本控制系统：

```bash
git add Modules/Blog/Database/Migrations/
git commit -m "Add user email field"
```

### 6. 禁止修改已发布的迁移

一旦迁移已经运行并发布到生产环境，就不要再修改它。

**推荐**：
```bash
# 创建新的迁移来修复问题
php artisan module:make-migration Blog fix_user_email_index
```

**不推荐**：
```bash
# 修改已运行的迁移文件
vim Modules/Blog/Database/Migrations/2024_01_20_120000_create_users_table.php
```

### 7. 测试迁移

在生产环境运行迁移之前，先在开发环境测试：

```bash
# 开发环境
php artisan module:migrate

# 测试数据
php artisan module:migrate --seed

# 验证应用功能
# ...

# 生产环境
php artisan module:migrate --force
```

### 8. 备份数据库

在运行迁移之前，始终备份数据库：

```bash
# MySQL
mysqldump -u username -p database_name > backup.sql

# PostgreSQL
pg_dump database_name > backup.sql
```

---

## 高级用法

### 条件迁移

在 `down()` 方法中使用条件语句：

```php
public function down(): void
{
    if (Schema::hasColumn('users', 'new_column')) {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('new_column');
        });
    }
}
```

### 迁移中的数据操作

```php
public function up(): void
{
    // 添加新字段
    Schema::table('users', function (Blueprint $table) {
        $table->string('full_name')->nullable();
    });

    // 迁移数据
    DB::table('users')
        ->whereNotNull('first_name')
        ->whereNotNull('last_name')
        ->update([
            'full_name' => DB::raw('CONCAT(first_name, " ", last_name)')
        ]);
}
```

### 批量操作

```php
public function up(): void
{
    // 添加新字段
    Schema::table('posts', function (Blueprint $table) {
        $table->integer('view_count')->default(0);
    });

    // 批量更新现有数据
    DB::table('posts')
        ->orderBy('id')
        ->chunk(1000, function ($posts) {
            foreach ($posts as $post) {
                DB::table('posts')
                    ->where('id', $post->id)
                    ->update(['view_count' => rand(0, 100)]);
            }
        });
}
```

---

## 常见问题

### Q1: 迁移文件可以删除吗？

**A**: 可以，但不推荐。如果您需要回滚到之前的版本，迁移文件是必需的。

### Q2: 如何修改已运行的迁移？

**A**: 不要直接修改已运行的迁移文件。创建一个新的迁移来进行修改：

```bash
php artisan module:make-migration Blog add_new_field_to_users_table
```

### Q3: 迁移失败怎么办？

**A**: 检查以下几点：

1. 确认数据库连接正常
2. 检查迁移文件中的语法错误
3. 查看日志文件：`storage/logs/laravel.log`
4. 在 `down()` 方法中添加回滚逻辑

### Q4: 如何回滚到特定版本？

**A**: 使用 `--step` 选项：

```bash
# 回滚 3 个迁移
php artisan module:migrate-rollback --step=3
```

### Q5: 多个模块的迁移会冲突吗？

**A**: 不会。每个模块的迁移文件相互独立，Laravel 会按时间戳顺序执行所有迁移。

---

## 相关文档

- [代码生成](10-code-generation.md) - 代码生成命令的详细使用
- [模型](03-module-structure.md) - 模块结构说明
- [命令参考](09-commands.md) - 所有可用命令的详细说明
