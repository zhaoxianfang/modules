# MySQL 8.4+ 高级特性支持

本文档介绍 `zxf/modules` 扩展包对 MySQL 8.4+ 版本高级特性的支持。

## 概述

MySQL 8.4 引入了大量强大的新特性，包括增强的窗口函数、JSON 函数、CTE（公共表表达式）等。本扩展包提供了一系列 Laravel 查询构造器宏，让您可以方便地使用这些高级特性。

## 安装要求

- MySQL 8.4+ 或 MariaDB 11.4+
- Laravel 11+
- PHP 8.2+

## 窗口函数

### 基础窗口函数

#### ROW_NUMBER - 行号

为每行分配唯一的行号：

```php
use Illuminate\Support\Facades\DB;

// 基本用法
$users = DB::table('users')
    ->withRowNumber('row_num')
    ->get();

// 分组行号
$ranked = DB::table('sales')
    ->withRowNumber('row_num', 'department_id', 'amount DESC')
    ->get();

// 获取每组前3名
$top3 = DB::table('sales')
    ->withTopN('department_id', 3, 'amount DESC')
    ->get();
```

#### RANK / DENSE_RANK - 排名

```php
// RANK - 允许并列，跳过后续排名
$sales = DB::table('sales')
    ->withRank('sales_rank', 'region', 'amount DESC')
    ->get();

// DENSE_RANK - 允许并列，不跳过后续排名
$sales = DB::table('sales')
    ->withDenseRank('sales_rank', 'region', 'amount DESC')
    ->get();
```

#### LAG / LEAD - 前后行引用

```php
// 获取前一行数值（用于计算变化）
$stocks = DB::table('stock_prices')
    ->withLag('close_price', 1, null, 'prev_close', 'stock_code', 'date')
    ->withChange('close_price', 'price_change', 'stock_code', 'date')
    ->get();

// 获取后一行数值
$orders = DB::table('orders')
    ->withLead('order_amount', 1, null, 'next_order', 'customer_id', 'created_at')
    ->get();
```

### 分析函数

#### 移动平均

```php
// 7日移动平均
$prices = DB::table('stock_prices')
    ->withMovingAverage('close_price', 7, 'ma7', 'stock_code', 'date')
    ->get();

// 30日移动平均
$prices = DB::table('stock_prices')
    ->withMovingAverage('close_price', 30, 'ma30', 'stock_code', 'date')
    ->get();
```

#### 累计求和

```php
// 累计销售额
$sales = DB::table('daily_sales')
    ->withRunningTotal('amount', 'cumulative_sales', 'region', 'date')
    ->get();
```

#### 同比增长/环比

```php
// 同比增长率
$monthly = DB::table('monthly_stats')
    ->withYearOverYear('revenue', 'year', 'yoy_growth')
    ->get();

// 环比增长率
$weekly = DB::table('weekly_stats')
    ->withMonthOverMonth('revenue', 'week_number', 'mom_growth')
    ->get();
```

#### 百分位数

```php
// 中位数
$salaries = DB::table('employees')
    ->withMedian('salary', 'median_salary', 'department_id')
    ->get();

// 四分位数
$scores = DB::table('test_scores')
    ->withQuartiles('score', 'class_id')
    ->get();

// 自定义百分位数
$data = DB::table('metrics')
    ->withPercentileCont(0.95, 'response_time', 'p95', 'service')
    ->get();
```

#### 异常值检测

```php
// 基于 IQR 方法标记异常值
$sensors = DB::table('sensor_readings')
    ->withOutlierFlag('temperature', 1.5, 'is_outlier', 'sensor_id')
    ->where('is_outlier', 0)  // 只获取正常值
    ->get();
```

### 排名函数

#### NTILE - 分桶

```php
// 将数据分为4个桶（四分位数）
$products = DB::table('products')
    ->withNTile(4, 'quartile', 'category_id', 'sales DESC')
    ->get();

// A/B 测试分组
$users = DB::table('users')
    ->withNTile(2, 'test_group', null, 'id')
    ->get();
```

#### FIRST_VALUE / LAST_VALUE

```php
// 获取分组内第一个和最后一个值
$employees = DB::table('employees')
    ->withFirstValue('salary', 'first_hired_salary', 'department_id', 'hire_date')
    ->withLastValue('salary', 'latest_hired_salary', 'department_id', 'hire_date')
    ->get();
```

## JSON 函数

### JSON 查询

```php
// 检查 JSON 字段是否包含值
$users = DB::table('users')
    ->whereJsonContains('settings', ['theme' => 'dark'])
    ->get();

// 检查 JSON 路径是否存在
$users = DB::table('users')
    ->whereJsonContainsPath('settings', 'notifications.email')
    ->get();

// JSON 字段查询
$users = DB::table('users')
    ->whereJsonExtract('settings', 'age', '>=', 18)
    ->get();

// 按 JSON 字段排序
$products = DB::table('products')
    ->orderByJson('attributes', 'price', 'desc')
    ->get();
```

### JSON 选择

```php
// 选择 JSON 子字段
$users = DB::table('users')
    ->selectJson('profile', 'name', 'user_name')
    ->selectJson('profile', 'avatar', 'user_avatar')
    ->get();

// JSON 数组聚合
$orders = DB::table('order_items')
    ->selectJsonAgg('product_name', 'products_json')
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
    ->updateJsonRemove('settings', 'notifications.push', 'legacy_field');

// 向 JSON 数组追加
DB::table('users')
    ->where('id', 1)
    ->updateJsonAppend('tags', 'tags', 'new-tag');
```

### JSON 搜索

```php
// JSON 数组重叠
$products = DB::table('products')
    ->whereJsonOverlaps('tags', ['electronics', 'gadgets'])
    ->get();

// JSON 键检查
$users = DB::table('users')
    ->whereJsonHasKey('settings', 'privacy')
    ->get();

// JSON 模糊搜索
$users = DB::table('users')
    ->whereJsonSearch('profile', 'developer')
    ->get();
```

## CTE (公共表表达式)

### 基础 CTE

```php
// 简单 CTE
$results = DB::table('employees')
    ->withCTE('high_earners', function ($query) {
        $query->from('employees')->where('salary', '>', 100000);
    })
    ->fromCTE('high_earners')
    ->get();

// 多个 CTE
$results = DB::table('sales')
    ->withMultipleCTEs([
        'q1_sales' => fn ($q) => $q->from('sales')->whereBetween('date', ['2024-01-01', '2024-03-31']),
        'q2_sales' => fn ($q) => $q->from('sales')->whereBetween('date', ['2024-04-01', '2024-06-30']),
    ])
    ->fromCTE('q1_sales')
    ->union(DB::table('q2_sales'))
    ->get();
```

### 递归 CTE

#### 层次结构查询

```php
// 查询树形结构
$categories = DB::table('categories')
    ->withHierarchicalCTE(
        'category_tree',
        'categories',
        'id',
        'parent_id',
        fn ($q) => $q->where('id', 1)  // 从根分类开始
    )
    ->fromCTE('category_tree')
    ->get();
```

#### 带路径的递归 CTE

```php
// 生成分类路径
$categories = DB::table('categories')
    ->withPathCTE(
        'category_paths',
        'categories',
        'id',
        'parent_id',
        'name',
        fn ($q) => $q->whereNull('parent_id'),  // 从顶级分类开始
        ' > '  // 路径分隔符
    )
    ->fromCTE('category_paths')
    ->select('id', 'name', 'path')
    ->get();

// 结果示例：
// id: 5, name: "Laptops", path: "Electronics > Computers > Laptops"
```

#### 面包屑路径

```php
// 生成面包屑（从叶子节点向上）
$paths = DB::table('categories')
    ->withBreadcrumbCTE(
        'breadcrumbs',
        'categories',
        'id',
        'parent_id',
        'name',
        fn ($q) => $q->where('id', 15)  // 从特定分类开始向上
    )
    ->fromCTE('breadcrumbs')
    ->select('id', 'name', 'breadcrumb', 'level')
    ->get();
```

#### 循环检测

```php
// 检测递归中的循环（防止无限递归）
$paths = DB::table('employee_hierarchy')
    ->withCycleCTE(
        'hierarchy_check',
        'employee_hierarchy',
        'employee_id',
        'manager_id',
        fn ($q) => $q->where('employee_id', 1)
    )
    ->fromCTE('hierarchy_check')
    ->where('is_cycle', 0)  // 排除循环路径
    ->get();
```

## 高级聚合

### 列表聚合

```php
// 将多行聚合为列表
$departments = DB::table('employees')
    ->select('department_id')
    ->withListAgg('name', 'employee_names', 'department_id', 'name ASC', ', ')
    ->groupBy('department_id')
    ->get();

// 结果：employee_names: "Alice, Bob, Charlie"
```

### 帕累托分析（80/20 法则）

```php
// 分析主要贡献者
$sales = DB::table('products')
    ->select('product_name', 'revenue')
    ->orderByDesc('revenue')
    ->withParetoAnalysis('revenue', 'product_name')
    ->get();

// 获取贡献80%收入的产品
$topProducts = $sales->where('pareto_pct', '<=', 80);
```

### 透视表

```php
// 模拟透视表
$pivot = DB::table('sales')
    ->select('year')
    ->withPivotSummary('amount', 'quarter', ['Q1', 'Q2', 'Q3', 'Q4'], 'SUM')
    ->groupBy('year')
    ->get();

// 结果：
// year: 2024, Q1: 10000, Q2: 15000, Q3: 12000, Q4: 18000
```

### 组内统计

```php
// 同时计算多个统计指标
$stats = DB::table('sales')
    ->select('region', 'product_id')
    ->withGroupTotals([
        'amount' => ['SUM', 'AVG', 'MAX', 'MIN'],
        'quantity' => ['SUM', 'AVG'],
    ], 'region')
    ->groupBy('region', 'product_id')
    ->get();
```

## 统计分析

### 方差和标准差

```php
$stats = DB::table('test_scores')
    ->select('subject')
    ->withVarianceStats('score', 'score_stats', 'subject')
    ->groupBy('subject')
    ->get();

// 结果包含：
// score_stats_var_pop - 总体方差
// score_stats_var_samp - 样本方差
// score_stats_stddev_pop - 总体标准差
// score_stats_stddev_samp - 样本标准差
```

### 相关系数

```php
// 计算两列的相关性
$correlation = DB::table('marketing_data')
    ->withCorrelation('ad_spend', 'revenue', 'spend_revenue_correlation')
    ->value('spend_revenue_correlation');
```

### 简单线性回归

```php
// 线性回归分析
$regression = DB::table('sales_data')
    ->select('month')
    ->withLinearRegression('month', 'sales', 'sales_trend', 'region')
    ->groupBy('month')
    ->get();

// 预测未来值
$forecast = DB::table('sales_data')
    ->withForecast('month', 'sales', 13, 'predicted_sales', 'region')
    ->value('predicted_sales');
```

## 性能优化

### 索引提示

```php
// 强制使用索引
$users = DB::table('users')
    ->forceIndex('idx_email', 'idx_status')
    ->where('email', 'like', '%@example.com')
    ->where('status', 'active')
    ->get();

// 忽略索引
$users = DB::table('users')
    ->ignoreIndex('idx_deprecated')
    ->get();

// 建议索引
$users = DB::table('users')
    ->useIndex('idx_created_at')
    ->where('created_at', '>', now()->subDays(7))
    ->get();
```

### 查询优化

```php
// 优化小结果集
$recent = DB::table('logs')
    ->bufferResult()
    ->where('created_at', '>', now()->subHour())
    ->get();

// 计算总行数（优化分页）
$page = DB::table('products')
    ->calcFoundRows()
    ->limit(20)
    ->offset(0)
    ->get();

$total = DB::selectOne('SELECT FOUND_ROWS() as total')->total;

// 并行查询（MySQL 8.4）
$largeTable = DB::table('big_data')
    ->parallel(8)
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
        ['id' => 1, 'name' => 'Updated', 'email' => 'user@example.com'],
        ['id' => 2, 'name' => 'New User', 'email' => 'new@example.com'],
    ],
    ['name', 'email']  // 冲突时更新的字段
);

// Upsert（现代语法）
DB::table('stats')->upsert(
    [
        ['date' => '2024-01-01', 'views' => 100, 'clicks' => 10],
        ['date' => '2024-01-02', 'views' => 150, 'clicks' => 15],
    ],
    ['date'],  // 唯一键
    ['views', 'clicks']  // 更新字段
);

// 批量更新
DB::table('products')->batchUpdate(
    'products',
    'id',
    [
        1 => ['price' => 99.99, 'stock' => 100],
        2 => ['price' => 149.99, 'stock' => 50],
    ],
    100  // 批次大小
);
```

### 大数据集处理

```php
// 惰性分块（减少内存占用）
$generator = DB::table('large_table')
    ->lazyChunk(1000, 'id')();

foreach ($generator as $chunk) {
    foreach ($chunk as $row) {
        // 处理每一行
        process($row);
    }
}

// 按 ID 惰性迭代
foreach (DB::table('users')->lazyById(1000) as $user) {
    process($user);
}
```

## 锁优化

```php
// 跳过锁定的行
$task = DB::table('jobs')
    ->where('status', 'pending')
    ->forUpdateSkipLocked()
    ->first();

// 不等待锁
$task = DB::table('jobs')
    ->where('status', 'pending')
    ->forUpdateNowait()
    ->first();
```

## 最佳实践

### 1. 窗口函数使用场景

- **分页**：使用 `ROW_NUMBER()` 替代 `OFFSET` 提高大偏移量性能
- **分组取 Top N**：使用 `RANK()` 或 `DENSE_RANK()`
- **时间序列分析**：使用 `LAG()`/`LEAD()` 计算变化率
- **累积计算**：使用窗口聚合函数避免自连接

### 2. JSON 函数使用建议

- 为常用 JSON 路径创建虚拟列和索引
- 避免在 WHERE 子句中使用复杂的 JSON 表达式
- 使用 JSON 数组代替多表关联（一对多关系）

### 3. CTE 使用建议

- 复杂查询使用 CTE 提高可读性
- 递归 CTE 注意设置最大递归深度
- 大数据集避免深层递归

### 4. 性能考虑

- 始终为窗口函数的 `PARTITION BY` 和 `ORDER BY` 列创建索引
- 使用索引提示优化复杂查询
- 大数据集使用惰性处理避免内存溢出

## 兼容性说明

| 特性 | MySQL 8.0 | MySQL 8.4 | MariaDB 11.4 |
|------|-----------|-----------|--------------|
| 窗口函数 | ✅ | ✅ 优化 | ✅ |
| JSON 函数 | ✅ | ✅ 增强 | ⚠️ 部分支持 |
| 递归 CTE | ✅ | ✅ | ✅ |
| 索引提示 | ✅ | ✅ | ✅ |
| 并行查询 | ❌ | ✅ | ❌ |

## 参考文档

- [MySQL 8.4 Window Functions](https://dev.mysql.com/doc/refman/8.4/en/window-functions.html)
- [MySQL 8.4 JSON Functions](https://dev.mysql.com/doc/refman/8.4/en/json-functions.html)
- [MySQL 8.4 CTE](https://dev.mysql.com/doc/refman/8.4/en/with.html)
