# Laravel 查询构建器宏扩展 - 完整指南

专为 Laravel 11+ / 12+ / 13+ 和 MySQL 8.0+ 设计的高性能查询扩展包。

## 功能概览

本扩展包提供 17 个系列宏功能：

| 系列 | 功能数量 | 说明 |
|------|---------|------|
| 1. whereHas 优化 | 10+ | 解决关联查询全表扫描问题 |
| 2. 主表字段 | 8 | 自动表前缀避免歧义 |
| 3. 随机查询 | 2 | 高效随机数据获取 |
| 4. 分组排序 | 1 | 窗口函数分组排名查询 |
| 5. 窗口函数 | 25+ | MySQL 8.0+ 窗口函数支持 |
| 6. 递归查询 | 16+ | 树形结构数据处理（层级/路径/关系/树构建） |
| 7. 分页优化 | 5 | 超大表快速分页 |
| 8. JSON 操作 | 20+ | 高级 JSON 查询和操作 |
| 9. 正则表达式 | 15+ | 强大的文本匹配功能 |
| 10. 集合操作 | 3 | INTERSECT/EXCEPT 集合运算 |
| 11. QUALIFY 过滤 | 4 | 窗口函数结果过滤（类似 HAVING） |
| 12. LATERAL JOIN | 4 | 横向连接，高效 Top-N 查询 |
| 13. 行列转换 | 8 | PIVOT/UNPIVOT 数据透视表 |
| 14. 数据抽样 | 4 | 随机/分层/系统抽样 |
| 15. VALUES 构造 | 4 | 批量插入和 UPSERT 优化 |
| 16. 向量相似度 | 2 | Laravel 13+ 原生向量/语义检索 |
| 17. 字符串函数 | 30+ | LOCATE 分词 / CHAR_LENGTH 排序 / 全文检索等 |

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

// orWhereHasNotIn - OR 条件组合
User::query()
    ->whereHasIn('posts')
    ->orWhereHasNotIn('comments')
    ->get();
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

## 3. 分组排序系列

### groupSort

分组排序查询，使用窗口函数获取每组指定排名的记录。

```php
// 每个分类阅读量最高的前 3 篇文章
$topPosts = Post::query()->groupSort('category_id', 3, 'read', 'desc')->get();

// 获取多个排名（每个部门工资第 1 和第 2 高的员工）
$topEmployees = Employee::query()->groupSort('department_id', [1, 2], 'salary', 'desc')->get();

// 自定义排序字段与主键
$topSales = Sales::query()->groupSort('region', 5, 'amount', 'desc', 'id')->get();
```

---

## 4. 窗口函数系列

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

## 5. 递归查询系列

### 基础递归查询

```php
// 获取所有子节点（不包含自身）
$children = Category::withAllChildren(5)->get();

// 获取所有子节点（包含自身）
$children = Category::withAllChildren(5, 'parent_id', 100, true)->get();

// 获取所有父节点（向上递归）
$parents = Category::withAllParents(8)->get();

// 限制递归深度
$children = Category::withAllChildren(5, 'parent_id', 10)->get();
```

### 指定层级查询

```php
// 获取第N级父节点（0级是自己，1级是直接父节点）
$grandparent = Category::withNthParent(10, 2)->first();

// 获取第N级子节点（1级是直接子节点）
$grandchildren = Category::withNthChildren(1, 2)->get();
```

### 路径查询

```php
// 获取完整路径（带 absolute_path、path_ids、depth 字段）
$paths = Category::withFullPath([1, 2, 3])->get();

// 自定义路径格式
$paths = Category::withFullPath(
    [1, 2, 3],
    ['status' => 1],
    'parent_id',
    'name',
    ' / '
)->get();

// 面包屑导航（从根到当前节点的祖先链）
$breadcrumbs = Category::withBreadcrumbs(15)->pluck('name')->implode(' > ');

// 路径长度（到根节点的深度）
$depth = Category::withPathLength(15)->value('path_length');
```

### 层级关系判断

```php
// 检查节点A是否是节点B的祖先（递归检查）
$isParent = Category::isParentOf(1, 5);

// 严格模式：只检查直接父节点
$isDirectParent = Category::isParentOf(1, 5, 'parent_id', true);

// 检查是否为后代节点
$isChild = Category::isChildOf(5, 1);

// 查找两个节点的最近公共祖先
$ancestor = Category::withNearestAncestor(15, 20)->first();
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

// 查找所有根节点
$roots = Category::withRoot()->get();

// 查找所有叶子节点（没有子节点的节点）
$leaves = Category::withLeafNodes()->get();

// 获取后代数量
$count = Category::withDescendantsCount(1)->value('descendants_count');
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

// 设置根节点值（默认为0，可设为null）
WithRecursiveMacro::setRootValue(null); // 使用NULL作为根节点标识

// resetRecursive - 重置递归查询条件，清除递归状态与绑定
Category::query()->recursiveQuery(...)->resetRecursive()->where('id', 1)->get();
```

---

## 6. 分页优化系列

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

## 7. JSON 操作系列

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

// 追加值到数组（返回受影响行数）
Article::query()->where('id', 1)->appendToJsonArray('tags', 'php')->get();

// 从数组移除值
Article::query()->where('id', 1)->removeFromJsonArray('tags', 'php')->get();
```

### JSON 对象修改

```php
// setJsonValue - 设置/插入 JSON 键值
User::query()->where('id', 1)->setJsonValue('settings', '$.notifications.email', true)->get();
User::query()->where('id', 1)->setJsonValue('settings', '$.theme', 'dark', true)->get(); // insert=true 键不存在则插入

// removeJsonKey - 删除 JSON 键（支持多路径）
User::query()->where('id', 1)->removeJsonKey('settings', '$.theme')->get();
User::query()->where('id', 1)->removeJsonKey('settings', ['$.a', '$.b'])->get();

// mergeJson - 合并 JSON 对象
User::query()->where('id', 1)->mergeJson('settings', ['lang' => 'zh-CN', 'timezone' => 'Asia/Shanghai'])->get();

// jsonKeys - 获取 JSON 对象的所有键
User::query()->jsonKeys('settings', null, 'setting_keys')->first();
```

### JSON 搜索

```php
// jsonSearch - 在 JSON 中搜索值，返回匹配路径
// mode: one 返回第一个匹配路径 | all 返回所有匹配路径
Product::query()->jsonSearch('metadata', 'iphone', 'all', '$', 'matches')->get();

// whereJsonLike - 按 JSON 值模糊匹配筛选
User::query()->whereJsonLike('settings', 'zh%', '$.lang')->get();
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

## 8. 正则表达式系列

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

// regexpPosition - 查找正则匹配位置（1-based）
// returnOption: 0 返回匹配起点 | 1 返回匹配终点之后的位置
User::query()->regexpPosition('email', '@', 1, 'c', 0, 'at_pos')->get();
```

---

## 9. 主表字段系列

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

## 10. 集合操作系列（MySQL 8.0.31+）

INTERSECT 和 EXCEPT 集合运算。

```php
// INTERSECT - 交集：既购买了A又购买了B的用户
$users = User::query()->whereExists(function ($q) {
    $q->select('user_id')->from('orders')->where('product_id', 1);
})->intersect(function ($q) {
    $q->select('id')->from('users')->whereExists(function ($sq) {
        $sq->select('user_id')->from('orders')->where('product_id', 2);
    });
})->get();

// EXCEPT - 差集：活跃但未验证邮箱的用户
$users = User::query()->where('status', 'active')
    ->except(function ($q) {
        $q->select('id')->from('users')->whereNotNull('email_verified_at');
    })->get();

// UNION DISTINCT - 显式去重
User::query()->where('role', 'admin')
    ->unionDistinct(function ($q) {
        $q->select('users.*')->from('users')
          ->join('permissions', 'users.id', '=', 'permissions.user_id')
          ->where('permissions.level', '>=', 5);
    })->get();
```

---

## 11. QUALIFY 过滤系列（MySQL 8.0.33+）

过滤窗口函数结果，无需子查询或 CTE。

```php
// 查找每个部门工资排名前3的员工
Employee::query()
    ->select('*')
    ->selectRaw('RANK() OVER (PARTITION BY department_id ORDER BY salary DESC) as dept_rank')
    ->qualify('dept_rank', '<=', 3)
    ->get();

// 销售额超过部门平均的员工
Sales::query()
    ->select('*')
    ->selectRaw('amount - AVG(amount) OVER (PARTITION BY department_id) as above_avg')
    ->qualify('above_avg', '>', 0)
    ->get();

// 复杂条件
Employee::query()
    ->selectRaw('DENSE_RANK() OVER (PARTITION BY dept_id ORDER BY score DESC) as dr')
    ->qualifyRaw('dr BETWEEN ? AND ?', [1, 5])
    ->get();

// orQualify - OR 条件
Employee::query()
    ->selectRaw('RANK() OVER (PARTITION BY dept_id ORDER BY salary DESC) as rk')
    ->qualify('rk', '<=', 3)
    ->orQualify('rk', '=', 10)
    ->get();

// orQualifyRaw - OR 条件的原始 SQL
Employee::query()
    ->selectRaw('SUM(salary) OVER (PARTITION BY dept_id) as dept_sum')
    ->qualifyRaw('dept_sum > 10000')
    ->orQualifyRaw('dept_id = ?', [1])
    ->get();
```

---

## 12. LATERAL JOIN 系列（MySQL 8.0.14+）

横向连接，子查询可引用主查询列。

```php
// 每个用户最近的3条订单
User::query()
    ->lateralJoin(function ($subQuery) {
        $subQuery->select('*')->from('orders')
            ->whereColumn('user_id', 'users.id')
            ->orderBy('created_at', 'desc')
            ->limit(3);
    }, 'recent_orders')
    ->get();

// lateralLeftJoin - LATERAL LEFT JOIN，保留主表所有记录（无匹配时子查询列为 NULL）
User::query()
    ->lateralLeftJoin(function ($subQuery) {
        $subQuery->select('*')->from('orders')
            ->whereColumn('user_id', 'users.id')
            ->orderBy('created_at', 'desc')
            ->limit(1);
    }, 'latest_order')
    ->get();

// 高效 Top-N：每个分类销量最高的5个商品
Product::query()
    ->lateralLimit('category_id', 'sales_count', 'desc', 5, 'top_products')
    ->get();

// 行相关聚合：每个订单的客户历史总消费
Order::query()
    ->lateralAggregate('user_id', [
        ['column' => 'amount', 'function' => 'SUM', 'alias' => 'customer_total'],
        ['column' => 'amount', 'function' => 'AVG', 'alias' => 'customer_avg'],
    ], 'status', 'completed', 'customer_stats')
    ->get();
```

---

## 13. 行列转换（PIVOT）系列

数据透视表功能。

```php
// PIVOT - 行转列：每个用户各状态订单金额
Order::query()
    ->pivot('status', ['pending', 'paid', 'shipped', 'completed'], 'amount', 'SUM', 'user_id')
    ->get();
// 结果: user_id | pending | paid | shipped | completed

// 快捷聚合透视
Order::query()->pivotCount('status', ['pending', 'paid'], 'id', 'region')->get();
Order::query()->pivotSum('category', ['A', 'B', 'C'], 'amount', 'month')->get();

// 更多聚合快捷方法
Order::query()->pivotAvg('category', ['A', 'B', 'C'], 'amount', 'month')->get(); // 平均金额
Order::query()->pivotMax('category', ['A', 'B', 'C'], 'amount', 'month')->get(); // 最大金额
Order::query()->pivotMin('category', ['A', 'B', 'C'], 'amount', 'month')->get(); // 最小金额

// UNPIVOT - 列转行
MonthlySales::query()
    ->unpivot([
        ['column' => 'jan_sales', 'alias' => '一月'],
        ['column' => 'feb_sales', 'alias' => '二月'],
        ['column' => 'mar_sales', 'alias' => '三月'],
    ], 'month', 'sales_amount')
    ->get();

// 交叉表：按地区和月份统计销售额
Sales::query()
    ->crossTab('region', 'month', 'amount', 'SUM', ['01', '02', '03', '04'])
    ->get();
```

---

## 14. 数据抽样系列

高效数据抽样，适合大数据分析和统计估算。

```php
// 随机抽取10%的数据
User::query()->sample(10)->get();

// 精确抽取100条随机记录
User::query()->randomSample(100)->get();

// 可重复抽样（使用种子）
Survey::query()->randomSample(500, 'seed_2024')->get();

// 分层抽样：按性别每层抽50人
User::query()->stratifiedSample('gender', 50)->get();

// 系统抽样（等距）：每隔10条抽1条
Log::query()->systematicSample(10)->get();
```

---

## 15. VALUES 构造系列

批量操作优化，使用 VALUES ROW 语法。

```php
// valuesQuery - 直接查询 VALUES 构造的内存表（无需物理表）
$rows = [
    ['id' => 1, 'name' => '张三'],
    ['id' => 2, 'name' => '李四'],
];
User::query()
    ->valuesQuery($rows, 'tmp_users')
    ->where('tmp_users.id', '>', 1)
    ->get();

// 使用 VALUES 作为临时表 JOIN
$statusMap = [
    ['id' => 1, 'status' => 'active'],
    ['id' => 2, 'status' => 'inactive'],
];
User::query()
    ->valuesJoin($statusMap, 'status_map', 'id', 'id')
    ->select('users.*', 'status_map.status as override_status')
    ->get();

// 高效批量插入
Log::query()->valuesInsert([
    ['level' => 'info', 'message' => 'User login', 'created_at' => now()],
    ['level' => 'error', 'message' => 'DB fail', 'created_at' => now()],
]);

// 批量插入或更新（UPSERT）
User::query()->batchUpsert([
    ['id' => 1, 'name' => '张三', 'email' => 'zhang@example.com', 'updated_at' => now()],
    ['id' => 2, 'name' => '李四', 'email' => 'li@example.com', 'updated_at' => now()],
], 'id', ['name', 'email', 'updated_at']);

// 大批量插入自动分块
$data = array_map(fn ($i) => ['name' => "Item {$i}", 'sort' => $i], range(1, 10000));
Item::query()->valuesInsert($data, 1000);
```

---

## 16. 向量相似度搜索系列（Laravel 13+）

基于 Laravel 13 的原生向量/语义检索能力，需数据库提供向量索引支持。

```php
// 按向量相似度筛选（相似度阈值 0.8 以上才返回）
$related = Article::query()
    ->whereVectorSimilarTo('embedding', $embeddingVector, 0.8)
    ->get();

// 按向量距离排序（最近邻检索，默认升序：最相似在前）
$nearest = Article::query()
    ->orderByVectorDistance('embedding', $embeddingVector)
    ->limit(10)
    ->get();
```

**注意**：向量列需要数据库（如 MySQL HeatWave / PostgreSQL pgvector 等）提供向量索引与距离函数支持，否则无法使用。

---

## 17. 字符串函数系列（MySQL 8.0+ 高效字符串处理）

基于 MySQL 8.0 内置字符串函数封装的高性能查询能力：
- **LOCATE**：查找子字符串首次出现的位置，天然支持分词查询匹配
- **CHAR_LENGTH**：多字节安全的字符数计算（中英文均按 1 字符计）
- **FIELD / FIND_IN_SET**：自定义排序与逗号分隔列表成员查找
- **CONCAT_WS / SUBSTRING_INDEX / GROUP_CONCAT**：拼接搜索、字段提取、行转字符串聚合
- **MATCH...AGAINST**：全文检索（自然语言 / 布尔模式 / 查询扩展）
- **SOUNDEX**：英文发音相似匹配
- **REPLACE / TRIM / LOWER / UPPER / REVERSE / LPAD / RPAD**：字符串变换

### 17.1 子串位置查找（分词查询匹配）

```php
// whereLocate - 查找描述中包含 "Laravel" 的记录
Post::query()->whereLocate('description', 'Laravel')->get();

// 分词查询：包含 "高级" 或 "查询" 任意一词（空格分隔自动分词）
Article::query()->whereLocate('content', '高级 查询')->get();

// orWhereLocate - OR 条件组合
Post::query()
    ->whereLocate('title', 'MySQL')
    ->orWhereLocate('description', '数据库')
    ->get();

// whereLocateAll - 多关键词全命中（AND）：内容必须同时包含 Laravel 与 MySQL
Article::query()->whereLocateAll('content', ['Laravel', 'MySQL'])->get();

// 搜索框多关键词（全部命中才返回）
$keywords = explode(' ', trim($request->input('q')));
Post::query()->whereLocateAll('title', $keywords)->get();

// whereLocateAny - 多关键词任一命中（OR）：包含任意一个即返回
Article::query()->whereLocateAny('content', ['Laravel', 'MySQL'])->get();
```

### 17.2 字符长度排序与筛选

```php
// orderByCharLength - 按字符数升序（多字节安全）
User::query()->orderByCharLength('name')->get();

// orderByDescCharLength - 内容最长的文章排在最前
Post::query()->orderByDescCharLength('body')->get();

// whereCharLength - 用户名长度大于等于 4 的账号
User::query()->whereCharLength('name', '>=', 4)->get();

// orWhereCharLength - OR 条件的长度筛选
Product::query()
    ->whereCharLength('name', '=', 3)
    ->orWhereCharLength('description', '>', 50)
    ->get();

// orderByNatural - 自然排序：v1, v2, ..., v10 而非字典序 v1, v10, v2
Version::query()->orderByNatural('tag')->get();
```

### 17.3 自定义排序与列表查找

```php
// fieldOrderBy - 按状态优先级自定义排序：待支付 -> 待发货 -> 已发货 -> 已完成
Order::query()->fieldOrderBy('status', ['pending', 'shipped', 'delivered', 'completed'])->get();

// whereFindInSet - 逗号分隔标签列包含 "php"
Article::query()->whereFindInSet('tags', 'php')->get();

// orWhereFindInSet - OR 条件
Post::query()
    ->whereFindInSet('categories', 'news')
    ->orWhereFindInSet('tags', 'hot')
    ->get();

// whereFindInSetAny - 包含任意指定值
Article::query()->whereFindInSetAny('tags', ['php', 'go'])->get();

// whereFindInSetAll - 必须同时包含全部指定值
Article::query()->whereFindInSetAll('tags', ['php', 'mysql'])->get();
```

### 17.4 多列拼接搜索与提取

```php
// whereConcatLike - 在 姓名/手机号/邮箱 三字段中一站式搜索 "张"
User::query()->whereConcatLike(['name', 'phone', 'email'], '张')->get();

// orWhereConcatLike - OR 条件
Order::query()
    ->whereLocate('remark', '加急')
    ->orWhereConcatLike(['title', 'content'], '催单')
    ->get();

// substringIndex - 提取邮箱用户名部分（"a@b.com" -> "a"）
User::query()->substringIndex('email', '@', 1, 'username_part')->get();

// 提取 IPv4 最后一段（"192.168.1.10" -> "10"），count 为负数表示从右提取
Log::query()->substringIndex('ip', '.', -1, 'last_segment')->get();
```

### 17.5 字符串聚合

```php
// groupConcat - 每个分类下所有文章标题（逗号分隔）
Post::query()
    ->groupBy('category_id')
    ->groupConcat('title', 'titles')
    ->get();

// 订单关联商品名列表（竖线分隔、去重）
OrderItem::query()
    ->where('order_id', 100)
    ->groupConcat('product_name', 'names', '|', true)
    ->first();
```

### 17.6 全文检索（MATCH...AGAINST）

参与全文检索的列必须先建立 FULLTEXT 索引：

```sql
ALTER TABLE articles ADD FULLTEXT INDEX ft_title_body (title, body);
```

```php
// 自然语言全文搜索（最常用）
Article::query()->whereFullText(['title', 'body'], 'Laravel 查询构造器')->get();

// 查询扩展模式（自动补充同义词，召回率更高）
Article::query()->whereFullText('body', '数据库优化', 'expansion')->get();

// whereFullTextBoolean - 布尔模式，支持分词语法：
//   +word 必须包含   -word 必须排除   "短语" 精确匹配   word* 前缀匹配
Post::query()->whereFullTextBoolean('title', '+Laravel -Vue')->get();
Post::query()->whereFullTextBoolean('body', '"query builder"')->get();
Post::query()->whereFullTextBoolean('tags', 'php*')->get();

// orderByFullTextRelevance - 按相关度从高到低排序（需与 whereFullText 配合）
Article::query()
    ->whereFullText(['title', 'body'], 'Laravel')
    ->orderByFullTextRelevance(['title', 'body'], 'Laravel')
    ->get();
```

### 17.7 发音匹配与字符串变换

```php
// whereSoundex - 英文发音相似匹配（"Smith" 与 "Smyth"）
Contact::query()->whereSoundex('last_name', 'Smith')->get();

// orWhereSoundex - OR 条件
Contact::query()
    ->whereSoundex('first_name', 'John')
    ->orWhereSoundex('last_name', 'Jonson')
    ->get();

// replaceString - 手机号脱敏
User::query()->replaceString('phone', substr($phone, 0, 3), '***', 'masked_phone')->get();

// trimString - 去除首尾空格
User::query()->trimString('name', 'clean_name')->get();

// lowerString / upperString - 大小写转换
User::query()->lowerString('email', 'email_lower')->get();
Country::query()->upperString('code', 'code_upper')->get();

// reverseString - 反转字符串
User::query()->reverseString('phone', 'reversed_phone')->get();

// padString - 订单号左侧补零到 8 位（"123" -> "00000123"）
Order::query()->padString('order_no', 8, '0', 'left', 'padded_no')->get();
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

- **PHP**: 8.3+
- **Laravel**: 11+ / 12+ / 13+
- **MySQL**: 8.0+（窗口函数/字符串函数需 8.0+，LATERAL JOIN 需 8.0.14+，INTERSECT/EXCEPT 需 8.0.31+，QUALIFY 需 8.0.33+，全文检索需建 FULLTEXT 索引）
- **无需缓存扩展**: 所有宏均为纯 SQL 优化

---

## 更多文档

- [窗口函数详细文档](WindowMacros/readme.md)
- [递归查询详细文档](WindowMacros/readme.md#递归查询宏)
