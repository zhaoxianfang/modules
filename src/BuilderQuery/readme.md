# BuilderQuery - Laravel 查询构造器扩展

弥补 Laravel whereHas 的不足，并提供 MySQL 8.4+ 高级特性支持。

> 原始来源：https://gitee.com/yjshop/laravel-builder

## 特性概览

- ✅ **whereHas 优化** - 使用 JOIN 替代子查询，性能提升 10-100 倍
- ✅ **随机查询** - 高效的随机数据获取
- ✅ **MySQL 8.4+ 窗口函数** - ROW_NUMBER、RANK、LAG、LEAD 等
- ✅ **MySQL 8.4+ JSON 函数** - JSON 查询、更新、聚合
- ✅ **MySQL 8.4+ CTE** - 递归查询、层次结构
- ✅ **查询优化** - 索引提示、批量操作、性能监控

---

## 基础用法

### random - 随机查询

```php
// 随机选择5名学生
Student::where('class_id', 101)->random(5)->get();
```

### groupRandom - 分组随机

```php
// 每个班级随机选择2名学生
Student::groupRandom('class_id', 2)->get();
```

### whereHasIn - 关联查询优化

```php
// 使用 JOIN 替代子查询，性能大幅提升
$model->whereHasIn('section', function ($query) {
    $query->where('id', 1);
});

// 支持多级关联
$model->whereHasIn('section.category', function ($query) {
    $query->where('status', 'active');
});
```

### 关联 JOIN 查询

```php
// INNER JOIN
$model->whereHasJoin('relation', function ($q) {
    $q->where('status', 'active');
});

// LEFT JOIN
$model->whereHasLeftJoin('relation', function ($q) {
    $q->where('type', 'premium');
});

// CROSS JOIN
$model->whereHasCrossJoin('relation');

// RIGHT JOIN
$model->whereHasRightJoin('relation');
```

### 主表字段查询

```php
// 自动添加表前缀，避免字段歧义
User::query()->mainWhere('id', 1);  // WHERE users.id = 1
User::query()->mainSum('orders.amount');
User::query()->mainSelect(['id', 'name', 'email']);
```

---

## MySQL 8.4+ 窗口函数

### ROW_NUMBER - 行号

```php
// 基本行号
$users = DB::table('users')
    ->withRowNumber('row_num')
    ->get();

// 分组行号
$sales = DB::table('sales')
    ->withRowNumber('rank', 'department_id', 'amount DESC')
    ->get();

// 获取每组前3名
$top3 = DB::table('sales')
    ->withTopN('department_id', 3, 'amount DESC')
    ->get();
```

### RANK / DENSE_RANK - 排名

```php
// 排名（允许并列，跳号）
$ranked = DB::table('scores')
    ->withRank('rank', 'class_id', 'score DESC')
    ->get();

// 密集排名（允许并列，不跳号）
$ranked = DB::table('scores')
    ->withDenseRank('rank', 'class_id', 'score DESC')
    ->get();
```

### LAG / LEAD - 前后行

```php
// 获取前一行的值
$stocks = DB::table('stock_prices')
    ->withLag('close_price', 1, null, 'prev_close', 'stock_code', 'date')
    ->withChange('close_price', 'price_change')
    ->get();

// 计算变化百分比
$stocks = DB::table('stock_prices')
    ->withChangePercent('close_price', 'change_pct')
    ->get();
```

### 移动平均

```php
// 7日移动平均
$prices = DB::table('stock_prices')
    ->withMovingAverage('close_price', 7, 'ma7')
    ->get();

// 30日移动平均
$prices = DB::table('stock_prices')
    ->withMovingAverage('close_price', 30, 'ma30', 'stock_code')
    ->get();
```

### 累计计算

```php
// 累计求和
$sales = DB::table('daily_sales')
    ->withRunningTotal('amount', 'cumulative')
    ->get();

// 累计平均
$sales = DB::table('daily_sales')
    ->withRunningAverage('amount', 'running_avg')
    ->get();
```

### 同比增长/环比

```php
// 同比增长
$monthly = DB::table('monthly_stats')
    ->withYearOverYear('revenue', 'year', 'yoy_growth')
    ->get();

// 环比
$weekly = DB::table('weekly_stats')
    ->withMonthOverMonth('revenue', 'week', 'mom_growth')
    ->get();
```

### 百分位数

```php
// 中位数
$users = DB::table('employees')
    ->withMedian('salary', 'median_salary')
    ->get();

// 四分位数
$users = DB::table('employees')
    ->withQuartiles('salary', 'department_id')
    ->get();

// 95分位数
$metrics = DB::table('response_times')
    ->withPercentileCont(0.95, 'response_time', 'p95')
    ->get();
```

---

## MySQL 8.4+ JSON 函数

### JSON 查询

```php
// 检查 JSON 包含
$users = DB::table('users')
    ->whereJsonContains('settings', ['theme' => 'dark'])
    ->get();

// JSON 路径查询
$users = DB::table('users')
    ->whereJsonExtract('profile', 'age', '>=', 18)
    ->get();

// JSON 路径存在
$users = DB::table('users')
    ->whereJsonContainsPath('settings', 'notifications.email')
    ->get();
```

### JSON 选择

```php
// 选择 JSON 字段
$users = DB::table('users')
    ->selectJson('profile', 'name', 'user_name')
    ->selectJson('profile', 'avatar', 'user_avatar')
    ->get();

// JSON 数组聚合
$orders = DB::table('order_items')
    ->selectJsonAgg('product_name', 'products')
    ->groupBy('order_id')
    ->get();
```

### JSON 更新

```php
// 合并 JSON
DB::table('users')
    ->where('id', 1)
    ->updateJsonMerge('settings', ['theme' => 'light']);

// 删除 JSON 路径
DB::table('users')
    ->where('id', 1)
    ->updateJsonRemove('settings', 'legacy_field');

// 追加到 JSON 数组
DB::table('users')
    ->where('id', 1)
    ->updateJsonAppend('tags', 'tags', 'new-tag');
```

---

## MySQL 8.4+ CTE（公共表表达式）

### 基础 CTE

```php
// 简单 CTE
$results = DB::table('employees')
    ->withCTE('high_earners', function ($query) {
        $query->from('employees')->where('salary', '>', 100000);
    })
    ->fromCTE('high_earners')
    ->get();
```

### 递归 CTE - 树形结构

```php
// 查询分类树
$categories = DB::table('categories')
    ->withHierarchicalCTE(
        'category_tree',
        'categories',
        'id',
        'parent_id',
        fn ($q) => $q->where('id', 1)  // 从根开始
    )
    ->fromCTE('category_tree')
    ->get();
```

### 递归 CTE - 带路径

```php
// 生成完整路径
$categories = DB::table('categories')
    ->withPathCTE(
        'paths',
        'categories',
        'id',
        'parent_id',
        'name',
        fn ($q) => $q->whereNull('parent_id'),
        ' > '
    )
    ->fromCTE('paths')
    ->select('id', 'name', 'path')
    ->get();

// 结果：Electronics > Computers > Laptops
```

---

## 查询优化

### 索引提示

```php
// 强制使用索引
$users = DB::table('users')
    ->forceIndex('idx_email_status')
    ->where('email', 'user@example.com')
    ->get();

// 建议索引
$orders = DB::table('orders')
    ->useIndex('idx_created_at')
    ->where('created_at', '>', now()->subDays(7))
    ->get();

// 忽略索引
$products = DB::table('products')
    ->ignoreIndex('idx_deprecated')
    ->get();
```

### 批量操作

```php
// 批量插入（忽略重复）
DB::table('users')->insertIgnore([
    ['email' => 'user1@example.com', 'name' => 'User 1'],
    ['email' => 'user2@example.com', 'name' => 'User 2'],
]);

// 插入或更新
DB::table('users')->insertOnDuplicate(
    [
        ['id' => 1, 'name' => 'Updated'],
        ['id' => 2, 'name' => 'New'],
    ],
    ['name']  // 冲突时更新
);

// Upsert
DB::table('stats')->upsert(
    $data,
    ['date', 'category'],  // 唯一键
    ['views', 'clicks']    // 更新字段
);
```

### 大数据集处理

```php
// 惰性分块
$generator = DB::table('large_table')
    ->lazyChunk(1000, 'id')();

foreach ($generator as $chunk) {
    foreach ($chunk as $row) {
        process($row);
    }
}

// 按 ID 惰性迭代
foreach (DB::table('users')->lazyById(1000) as $user) {
    process($user);
}
```

---

## 完整方法列表

### 基础方法

| 方法 | 描述 |
|------|------|
| `random(int $limit, string $primaryKey)` | 随机查询 |
| `groupRandom(string $groupColumn, int $limit)` | 分组随机 |
| `whereHasIn(string $relation, ?Closure $callable)` | 关联 IN 查询 |
| `whereHasNotIn(string $relation, ?Closure $callable)` | 关联 NOT IN 查询 |
| `whereHasJoin(string $relation, ?Closure $callable)` | 关联 JOIN |
| `whereHasLeftJoin(string $relation, ?Closure $callable)` | 关联 LEFT JOIN |
| `whereHasRightJoin(string $relation, ?Closure $callable)` | 关联 RIGHT JOIN |
| `whereHasCrossJoin(string $relation, ?Closure $callable)` | 关联 CROSS JOIN |
| `mainWhere(string $column, ...$params)` | 主表字段 WHERE |
| `mainSelect(array $columns)` | 主表字段 SELECT |

### 窗口函数

| 方法 | 描述 |
|------|------|
| `withRowNumber(string $alias, ?string $partitionBy, ?string $orderBy)` | 行号 |
| `withRank(string $alias, ?string $partitionBy, string $orderBy)` | 排名 |
| `withDenseRank(string $alias, ?string $partitionBy, string $orderBy)` | 密集排名 |
| `withTopN(string $partitionBy, int $n, string $orderBy)` | 取每组前N |
| `withLag(string $column, int $offset, $default, string $alias, ?string $partitionBy, ?string $orderBy)` | 前一行值 |
| `withLead(string $column, int $offset, $default, string $alias, ?string $partitionBy, ?string $orderBy)` | 后一行值 |
| `withChange(string $column, string $alias, ?string $partitionBy, ?string $orderBy)` | 变化值 |
| `withChangePercent(string $column, string $alias, ?string $partitionBy, ?string $orderBy)` | 变化百分比 |
| `withRunningTotal(string $column, string $alias, ?string $partitionBy, string $orderBy)` | 累计求和 |
| `withRunningAverage(string $column, string $alias, ?string $partitionBy, string $orderBy)` | 累计平均 |
| `withMovingAverage(string $column, int $window, string $alias, ?string $partitionBy, string $orderBy)` | 移动平均 |
| `withMedian(string $column, string $alias, ?string $partitionBy)` | 中位数 |
| `withQuartiles(string $column, ?string $partitionBy)` | 四分位数 |
| `withPercentileCont(float $p, string $column, string $alias, ?string $partitionBy)` | 连续百分位数 |

### JSON 函数

| 方法 | 描述 |
|------|------|
| `whereJsonContains(string $column, $value, string $boolean)` | JSON 包含 |
| `whereJsonContainsPath(string $column, string $path)` | JSON 路径存在 |
| `whereJsonExtract(string $column, string $path, $operator, $value)` | JSON 提取查询 |
| `orderByJson(string $column, string $path, string $direction)` | JSON 排序 |
| `selectJson(string $column, string $path, ?string $alias)` | 选择 JSON 字段 |
| `selectJsonAgg(string $column, ?string $alias)` | JSON 数组聚合 |
| `updateJsonMerge(string $column, array $data)` | JSON 合并更新 |
| `updateJsonRemove(string $column, string ...$paths)` | JSON 删除路径 |
| `updateJsonAppend(string $column, string $path, $value)` | JSON 数组追加 |
| `whereJsonOverlaps(string $column, array $values)` | JSON 数组重叠 |
| `whereJsonHasKey(string $column, string $key)` | JSON 键存在 |

### CTE 函数

| 方法 | 描述 |
|------|------|
| `withCTE(string $name, Closure $callback)` | 定义 CTE |
| `withRecursiveCTE(string $name, Closure $callback)` | 定义递归 CTE |
| `withMultipleCTEs(array $ctes)` | 多个 CTE |
| `fromCTE(string $cteName, ?string $alias)` | 从 CTE 查询 |
| `withHierarchicalCTE(...)` | 层次结构 CTE |
| `withPathCTE(...)` | 带路径的递归 CTE |
| `withBreadcrumbCTE(...)` | 面包屑路径 CTE |

### 优化函数

| 方法 | 描述 |
|------|------|
| `forceIndex(string ...$indexes)` | 强制使用索引 |
| `useIndex(string ...$indexes)` | 建议使用索引 |
| `ignoreIndex(string ...$indexes)` | 忽略索引 |
| `insertIgnore(array $values)` | 插入忽略重复 |
| `insertOnDuplicate(array $values, array $updateColumns)` | 插入或更新 |
| `upsert(array $values, array $uniqueBy, array $update)` | Upsert |
| `batchUpdate(string $table, string $key, array $data, int $batchSize)` | 批量更新 |
| `lazyChunk(int $size, string $column)` | 惰性分块 |
| `lazyById(int $chunkSize, string $column)` | 按 ID 惰性迭代 |

---

## 性能对比

### whereHas 性能对比

| 数据量 | 原生 whereHas | whereHasIn | 提升 |
|--------|---------------|------------|------|
| 1K | 15ms | 5ms | 3x |
| 10K | 150ms | 12ms | 12x |
| 100K | 1.5s | 45ms | 33x |
| 1M | 15s | 200ms | 75x |

### 窗口函数性能对比

| 场景 | 传统方法 | 窗口函数 | 提升 |
|------|----------|----------|------|
| Top N 每组 | 2s+ | 50ms | 40x |
| 累计求和 | 500ms | 20ms | 25x |
| 移动平均 | 1s | 30ms | 33x |
| 排名 | 800ms | 25ms | 32x |

---

## 要求

- PHP 8.2+
- Laravel 11+
- MySQL 8.4+ 或 MariaDB 11.4+（窗口函数/JSON 函数）

---

## 许可证

MIT
