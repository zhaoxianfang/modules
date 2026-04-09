# Laravel 查询构建器宏扩展 - 完整指南

专为 Laravel 11+ 和 MySQL 8.4+ 设计的高性能查询扩展包。

## 功能概览

本扩展包提供 8 大类宏功能：

| 类别 | 功能数量 | 说明 |
|------|---------|------|
| whereHas 优化 | 10+ | 解决关联查询全表扫描问题 |
| 随机查询 | 2 | 高效随机数据获取 |
| 窗口函数 | 25+ | MySQL 8.4+ 窗口函数支持 |
| 递归查询 | 10 | 树形结构数据处理 |
| 分页优化 | 5 | 超大表快速分页 |
| JSON 操作 | 20+ | 高级 JSON 查询和操作 |
| 正则表达式 | 15+ | 强大的文本匹配功能 |
| 主表字段 | 8 | 自动表前缀避免歧义 |

---

## 快速开始

### 安装

```bash
composer require zxf/modules
```

### 基础使用

```php
use Illuminate\Database\Eloquent\Builder;

// 所有宏已自动注册，可直接使用
$users = User::query()
    ->whereHasIn('posts', fn($q) => $q->where('status', 1))
    ->random(5)
    ->get();
```

---

## 1. whereHas 优化系列

解决 Laravel 原生 `whereHas` 导致的全表扫描性能问题。

### whereHasIn

使用 `EXISTS` 子查询优化关联查询。

```php
// 基础用法
User::query()->whereHasIn('posts', function ($query) {
    $query->where('status', 1);
})->get();

// 多级关联
User::query()->whereHasIn('posts.comments', function ($query) {
    $query->where('is_approved', true);
})->get();
```

### whereHasNotIn

查询不存在关联关系的记录。

```php
// 查找没有订单的用户
User::query()->whereHasNotIn('orders')->get();
```

### 关联 Join 方法

```php
// INNER JOIN
User::query()->whereHasJoin('profile', fn($q) => $q->where('age', '>', 18))->get();

// LEFT JOIN
User::query()->whereHasLeftJoin('orders')->get();

// RIGHT JOIN
User::query()->whereHasRightJoin('posts')->get();

// CROSS JOIN
User::query()->whereHasCrossJoin('categories')->get();
```

### 多态关联

```php
// 多态关联查询
Comment::query()->whereHasMorphIn('commentable', [Post::class, Video::class])->get();

// OR 条件
Comment::query()->orWhereHasMorphIn('commentable', [Post::class], function ($q) {
    $q->where('published', true);
})->get();
```

---

## 2. 随机查询系列

### random

高效随机查询，使用窗口函数优化。

```php
// 随机获取 5 条记录
$posts = Post::query()->where('status', 1)->random(5)->get();

// 指定主键字段
$items = Item::query()->random(10, 'item_id')->get();
```

### groupRandom

分组随机查询，每组随机取指定数量。

```php
// 每个分类随机取 3 篇文章
$posts = Post::query()->groupRandom('category_id', 3)->get();

// 每个班级随机取 2 名学生
$students = Student::query()->groupRandom('class_id', 2, 'student_no')->get();
```

---

## 3. 窗口函数系列

### 排名函数

```php
// rowNumber - 唯一行号
Employee::query()
    ->rowNumber('department_id', 'salary', 'desc', 'rank_in_dept')
    ->get();

// rank - 排名（跳号）
Exam::query()
    ->rank(null, 'score', 'desc', 'rank_num')
    ->get();

// denseRank - 密集排名（不跳号）
Sales::query()
    ->denseRank('region', 'amount', 'desc', 'region_rank')
    ->get();

// percentRank - 百分比排名
Student::query()
    ->percentRank('class_id', 'score', 'desc', 'percentile')
    ->get();

// ntile - 分位数
Sales::query()
    ->ntile(4, 'region', 'amount', 'desc', 'quartile')  // 四分位数
    ->get();
```

### 偏移函数

```php
// lag - 前N行值（环比分析）
DailySales::query()
    ->lag('amount', 1, 0, null, 'sale_date', 'asc', 'prev_day')
    ->selectRaw('amount - prev_day as day_over_day')
    ->get();

// lead - 后N行值（预测分析）
Orders::query()
    ->lead('created_at', 1, null, 'user_id', 'created_at', 'asc', 'next_order')
    ->get();

// firstValue / lastValue - 首尾值
StockPrice::query()
    ->firstValue('open_price', 'stock_code', 'trade_date', 'asc', 'month_first')
    ->lastValue('close_price', 'stock_code', 'trade_date', 'asc', 'month_last')
    ->get();

// nthValue - 第N行值
ExamResult::query()
    ->nthValue('score', 3, 'class_id', 'score', 'desc', 'third_place')
    ->get();
```

### 聚合窗口函数

```php
// 窗口聚合（保持明细行）
Sales::query()
    ->sumOver('amount', 'department_id', null, 'asc', 'dept_total')
    ->avgOver('amount', 'department_id', null, 'asc', 'dept_avg')
    ->countOver('*', 'department_id', null, 'asc', 'dept_count')
    ->minOver('amount', 'department_id', null, 'asc', 'dept_min')
    ->maxOver('amount', 'department_id', null, 'asc', 'dept_max')
    ->selectRaw('amount / dept_total * 100 as percentage')
    ->get();
```

### 框架窗口函数

```php
// rowsBetween - 基于行数的窗口
StockPrice::query()
    // 3日移动平均（当前行及前2行）
    ->rowsBetween('close_price', 'AVG', '2 PRECEDING', 'CURRENT ROW', 'stock_code', 'date', 'asc', 'ma3')
    // 从分区开始到当前行的累计和
    ->rowsBetween('volume', 'SUM', 'UNBOUNDED PRECEDING', 'CURRENT ROW', 'stock_code', 'date', 'asc', 'cumulative_vol')
    ->get();

// rangeBetween - 基于值范围的窗口
Measurements::query()
    ->rangeBetween('value', 'AVG', '10 PRECEDING', '10 FOLLOWING', 'sensor_id', 'reading', 'asc', 'smoothed')
    ->get();
```

### 统计函数

```php
// 累计和
Sales::query()->cumulativeSum('amount', 'region', 'date', 'asc', 'running_total')->get();

// 移动平均
StockPrice::query()->movingAverage('close_price', 2, 'stock_code', 'date', 'asc', 'ma5')->get();

// 运行总计（累计和的别名）
Orders::query()->runningTotal('amount', 'customer_id', 'created_at', 'asc')->get();
```

---

## 4. 递归查询系列

### 基础递归查询

```php
// 获取所有子节点
$children = Category::withAllChildren(5)->get();

// 获取所有父节点
$parents = Category::withAllParents(8)->get();

// 限制递归深度
$children = Category::withAllChildren(5, 'parent_id', 10)->get();
```

### 指定层级查询

```php
// 获取第N级父节点（0级是自己）
$grandparent = Category::withNthParent(10, 2)->first();

// 获取第N级子节点
$grandchildren = Category::withNthChildren(1, 2)->get();
```

### 路径查询

```php
// 获取完整路径（带 absolute_path 字段）
$paths = Category::withFullPath([1, 2, 3])->get();

// 自定义路径格式
$paths = Category::withFullPath(
    [1, 2, 3],
    ['status' => 1],
    'parent_id',
    'name',
    ' / '
)->get();
```

### 层级关系判断

```php
// 检查节点A是否是节点B的父节点
$isParent = Category::isParentOf(1, 5);
```

### 同级节点

```php
// 获取同级节点（不包含自己）
$siblings = Category::withSiblings(5)->get();

// 包含自己
$siblings = Category::withSiblings(5, 'parent_id', true)->get();
```

### 树形结构

```php
// 获取完整树
$tree = Category::withTree()->where('status', 1)->get();

// 从指定节点开始的子树
$subTree = Category::withTree(5, 'parent_id', 'title', 5, ' -> ')->get();
```

### 通用递归查询

```php
// 自定义递归逻辑
$custom = Category::recursiveQuery(
    // 基础查询
    function ($query, $withTable) {
        $table = $query->getModel()->getTable();
        return "SELECT *, 0 AS depth FROM `{$table}` WHERE `parent_id` = 1";
    },
    // 递归查询
    function ($query, $withTable) {
        $table = $query->getModel()->getTable();
        return "SELECT t.*, r.depth + 1 AS depth FROM `{$table}` t
                JOIN `{$withTable}` r ON t.`parent_id` = r.`id`";
    },
    ['id', 'name', 'parent_id'],
    5
)->get();
```

---

## 5. 分页优化系列

### fastPaginate

智能快速分页，自动选择最优策略。

```php
// 基础用法（自动优化）
$users = User::query()->fastPaginate(20);

// 指定策略
$logs = Log::query()->fastPaginate(50, null, 'id', [
    'strategy' => 'window',        // 强制使用窗口函数策略
    'countStrategy' => 'approximate', // 近似计数提升性能
]);

// 深度分页（第5000页）
$records = BigTable::query()->fastPaginate(30, 5000);
```

策略选项：
- `strategy`: `auto` | `deferred` | `window` | `offset`
- `countStrategy`: `exact` | `approximate` | `skip`

### fastSimplePaginate

简单快速分页（不计算总数，性能更好）。

```php
// 适合无限滚动
$posts = Post::query()->fastSimplePaginate(10);

// 判断是否有更多数据
$posts->hasMorePages();
```

### cursorPaginate

游标分页（键集分页），性能最佳。

```php
// 基础游标分页
$users = User::query()->cursorPaginate(20);

// 指定排序字段
$posts = Post::query()->cursorPaginate(10, null, 'published_at', 'desc');

// 使用上一页的游标获取下一页
$nextPage = Post::query()->cursorPaginate(10, $cursor, 'published_at');
```

**注意**：游标分页只能顺序访问，不能跳转到任意页码。

### seekPaginate

寻址分页，适合深度分页场景。

```php
// 首次查询获取书签
$result = BigTable::query()->seekPaginate(100);
$bookmarks = $result->bookmarks; // 保存书签

// 使用书签快速跳转到第100页
$page100 = BigTable::query()->seekPaginate(100, $bookmarks, 100);
```

### partitionPaginate

分区表专用分页优化。

```php
// 按日期分区的大表
$logs = Log::query()->partitionPaginate(100, 1, 'created_date');
```

---

## 6. JSON 操作系列

### JSON 路径查询

```php
// 提取 JSON 值
User::query()
    ->jsonPath('settings', '$.notifications.email', 'email_enabled', false)
    ->get();

// 提取并转换类型
Product::query()
    ->jsonExtract('metadata', '$.stock', 'int', 'stock_qty', 0)
    ->jsonExtract('metadata', '$.price', 'float', 'unit_price')
    ->jsonExtract('metadata', '$.is_active', 'bool', 'active_status')
    ->get();
```

### JSON 存在性检查

```php
// 检查 JSON Path 是否存在
User::query()->whereJsonPathExists('settings', '$.email')->get();

// 检查不存在
User::query()->whereJsonPathNotExists('settings', '$.deleted_at')->get();
```

### JSON 数组操作

```php
// 数组包含
Article::query()->whereJsonArrayContains('tags', 'php')->get();

// 包含任意一个
Article::query()->whereJsonArrayContainsAny('tags', ['php', 'laravel', 'mysql'])->get();

// 包含所有
Article::query()->whereJsonArrayContainsAll('tags', ['php', 'mysql'])->get();

// 数组长度
Article::query()->jsonArrayLength('tags', null, 'tag_count')->get();

// 按数组长度筛选
Article::query()->whereJsonArrayLength('tags', 3)->get();      // 恰好3个
Article::query()->whereJsonArrayLength('tags', 5, '>=')->get(); // 至少5个
```

### JSON 聚合

```php
// 聚合成 JSON 数组
Order::query()
    ->select('user_id')
    ->jsonArrayAgg('product_id', 'products', 'created_at', 'desc')
    ->groupBy('user_id')
    ->get();

// 聚合成 JSON 对象
Config::query()->jsonObjectAgg('key', 'value', 'config_object')->first();

// 聚合完整行
Order::query()
    ->select('user_id')
    ->jsonRowAgg(['id', 'product_name', 'price'], 'items')
    ->groupBy('user_id')
    ->get();
```

---

## 7. 正则表达式系列

### 正则匹配

```php
// 基础匹配
User::query()->whereRegexp('email', '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$')->get();

// 不区分大小写
User::query()->whereRegexp('username', '^admin', 'i')->get();

// 不匹配
User::query()->whereNotRegexp('email', '@tempmail\.com$', 'i')->get();

// 匹配任意模式
User::query()->whereRegexpAny('email', ['@gmail\.com$', '@yahoo\.com$'])->get();
```

匹配模式：
- `c`: 区分大小写（默认）
- `i`: 不区分大小写
- `m`: 多行模式
- `n`: 点号匹配换行

### 正则提取

```php
// 提取子串
User::query()
    ->regexpExtract('email', '@([^@]+)$', 1, 1, 'i', 'domain')
    ->get();

// 提取所有匹配
Article::query()
    ->regexpExtractAll('content', '[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}', 'i', 'emails')
    ->get();
```

### 正则替换

```php
// 隐藏手机号中间四位
User::query()
    ->regexpReplace('phone', '(\d{3})\d{4}(\d{4})', '$1****$2', 0, 'c', 'masked_phone')
    ->get();

// 批量替换
Article::query()
    ->regexpReplaceBatch('content', [
        ['pattern' => '<script[^>]*>.*?</script>', 'replacement' => ''],
        ['pattern' => '<[^>]+>', 'replacement' => ''],
        ['pattern' => '\s+', 'replacement' => ' '],
    ], 'i', 'clean_content')
    ->get();
```

### 正则统计

```php
// 统计匹配次数
Article::query()
    ->regexpCount('content', 'https?://[^\s<>"\']+', 'i', 'link_count')
    ->get();

// 按匹配次数筛选
Article::query()->whereRegexpCount('content', 'https?://', 3, '>=', 'i')->get();
```

---

## 8. 主表字段系列

自动添加表前缀，避免关联查询时的字段歧义。

```php
// WHERE 条件
User::query()->mainWhere('id', 1); // WHERE users.id = 1

// WHERE IN
User::query()->mainWhereIn('id', [1, 2, 3]);

// WHERE BETWEEN
User::query()->mainWhereBetween('created_at', ['2024-01-01', '2024-12-31']);

// ORDER BY
User::query()->mainOrderBy('name', 'asc');
User::query()->mainOrderByDesc('created_at');

// 聚合
User::query()->mainSum('balance');

// PLUCK
User::query()->mainPluck('email');

// SELECT
User::query()->mainSelect(['id', 'name', 'email']);
```

---

## 性能优化建议

### 1. 索引优化

```sql
-- 窗口函数排序字段需要索引
ALTER TABLE sales ADD INDEX idx_region_amount (region, amount);

-- JSON 查询需要虚拟列索引（MySQL 8.0.13+）
ALTER TABLE users ADD COLUMN email_enabled TINYINT AS (JSON_EXTRACT(settings, '$.notifications.email')) VIRTUAL;
ALTER TABLE users ADD INDEX idx_email_enabled (email_enabled);

-- 正则查询需要全文索引（替代方案）
ALTER TABLE articles ADD FULLTEXT INDEX ft_content (content);
```

### 2. 分页策略选择

| 数据量 | 页码深度 | 推荐策略 |
|--------|----------|----------|
| < 1万 | 任意 | 传统分页 |
| 1万-10万 | < 100 | 传统分页 |
| 1万-10万 | > 100 | fastPaginate |
| > 10万 | 任意 | cursorPaginate |
| > 100万 | 任意 | seekPaginate + 书签 |

### 3. 窗口函数性能

```php
// 好：分区字段和排序字段都有索引
Employee::query()->rowNumber('department_id', 'salary', 'desc')->get();

// 避免：大量数据无分区
BigTable::query()->rowNumber(null, 'id', 'asc')->get(); // 全表排序，性能差
```

---

## 兼容性说明

- **PHP**: 8.2+
- **Laravel**: 11+
- **MySQL**: 8.4+
- **无需缓存扩展**: 所有宏均为纯 SQL 优化

---

## 更多文档

- [窗口函数详细文档](WindowMacros/readme.md)
- [递归查询详细文档](WindowMacros/readme.md#递归查询宏)
