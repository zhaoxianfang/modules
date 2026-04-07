# MySQL 8.4+ 窗口函数完整参考手册

> 本文档提供 `zxf/modules` 扩展包中所有 MySQL 8.4+ 窗口函数的完整参考。

## 目录

1. [分页函数](#分页函数)
2. [排名函数](#排名函数)
3. [分析函数](#分析函数)
4. [JSON 函数](#json-函数)
5. [CTE 函数](#cte-函数)
6. [时间序列函数](#时间序列函数)
7. [字符串函数](#字符串函数)
8. [条件聚合函数](#条件聚合函数)
9. [数据质量函数](#数据质量函数)

---

## 分页函数

### fastPaginate

高性能分页，使用窗口函数实现。

```php
DB::table('users')->fastPaginate(int $page, int $perPage, ?string $orderColumn, string $orderDirection, ?string $alias)
```

**参数：**
- `$page` - 页码（从1开始）
- `$perPage` - 每页记录数，默认20
- `$orderColumn` - 排序列名
- `$orderDirection` - 排序方向：'asc' 或 'desc'
- `$alias` - 行号列别名

**示例：**
```php
// 第1000页，每页50条
$users = DB::table('users')
    ->fastPaginate(1000, 50, 'created_at', 'desc')
    ->get();
```

---

## 排名函数

### withRowNumber

为每行分配唯一行号。

```php
DB::table('table')->withRowNumber(?string $alias, ?string $partitionBy, ?string $orderBy)
```

### withRank / withDenseRank

计算排名。

```php
DB::table('table')->withRank(string $alias, ?string $partitionBy, string $orderBy)
DB::table('table')->withDenseRank(string $alias, ?string $partitionBy, string $orderBy)
```

### withTopN

获取每组前N条记录。

```php
DB::table('table')->withTopN(string $partitionBy, int $n, string $orderBy)
```

---

## 分析函数

### withLag / withLead

获取前后行数据。

```php
DB::table('table')->withLag(string $column, int $offset, $default, ?string $alias, ?string $partitionBy, ?string $orderBy)
DB::table('table')->withLead(string $column, int $offset, $default, ?string $alias, ?string $partitionBy, ?string $orderBy)
```

### withMovingAverage

计算移动平均。

```php
DB::table('table')->withMovingAverage(string $column, int $window, ?string $alias, ?string $partitionBy, string $orderBy)
```

---

## 完整函数清单

| 类别 | 函数名 | 功能描述 |
|------|--------|----------|
| 分页 | fastPaginate | 高性能分页 |
| 分页 | seekPaginate | 键集分页 |
| 分页 | cursorPaginate | 游标分页 |
| 排名 | withRowNumber | 行号 |
| 排名 | withRank | 排名 |
| 排名 | withDenseRank | 密集排名 |
| 排名 | withTopN | 取前N |
| 分析 | withLag | 前一行 |
| 分析 | withLead | 后一行 |
| 分析 | withChange | 变化值 |
| 分析 | withMovingAverage | 移动平均 |
| 分析 | withMedian | 中位数 |
| 分析 | withOutlierFlag | 异常值检测 |

---

