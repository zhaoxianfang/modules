# Laravel 查询构建器宏扩展 - 完整指南

专为 Laravel 11+ / 12+ / 13+ 与 MySQL 8.0+ / PostgreSQL 15+ 设计的通用高性能查询扩展包（通过 Dialect 方言抽象层同时支持两套数据库）。

## 功能概览

本扩展包提供 30 个系列宏功能，全部基于 **`BaseMacro` 标准化基类 + `Dialect` 方言抽象层** 构建，
**同时注册到 `Eloquent\Builder`（ORM）与 `Query\Builder`（`DB::table()` 原生查询）**，满足「必须友好支持
Laravel 11+ 的 ORM 和 DB」要求。所有 SQL 片段均经 `Dialect` 生成适配 MySQL 8.0+ / PostgreSQL 15+ 的语法，
标识符经白名单校验并由 grammar 生成引用，字面值一律参数绑定，杜绝 SQL 注入与方言耦合。

> **v3.2.0 新增「性能与稳定性」专项（第 23–27 系列）**：围绕 `study_mysql` 中「系统开发常见性能瓶颈」系统化封装——
> 索引/优化器提示、查询守卫（防失控）、分组 Top-N 去重、单条 SQL 多维条件聚合、列选择纪律（消除 `SELECT *`），
> 直接命中「慢查询、全表扫描、结果集失控、资源占用高、卡死」等高频痛点。

> **v3.3.0 新增「失控防护与超大集合」专项（第 28–30 系列）**：继续深挖 `study_mysql` 中
> 「08-playbook / 02-query-rewrite-cookbook」「04-performance / 07-bottlenecks-and-advanced-ops」「07-appendix / 01-pitfalls-compendium」
> 记录的高频事故源，针对用户「避免长时间未响应、资源占用高、卡死、异常」硬性要求，新增三类护栏宏：
> - **超大 IN/NOT IN 分块（ChunkedListMacro）**：数万/数十万 ID 一次性 `IN (...)` 会触发占位符上限异常、优化器退化、PHP 内存暴涨；
>   自动按块切分并以 OR/AND 合并，单块直接委托原生，空集合安全（IN→零行、NOT IN→全表）。
> - **大表计数加速（FastCountMacro）**：亿级表 `COUNT(*)` 拖垮连接、大屏超时；无过滤时经 `information_schema`/`pg_class` 读取 O(1) 估算，
>   有过滤/复杂 FROM 自动回退精确 COUNT，目录异常静默回退，永不向上抛错。
> - **防失控写入/删除护栏（SafeWriteMacro）**：无 WHERE 的 UPDATE/DELETE 误操作、单条巨 DELETE 锁表/undo 膨胀；
>   `mustHaveWhere()` 写前拦截全表操作，`deleteInChunks()` 按主键分批删除并内置「删 0 行即停 + 最大迭代上限」双防死循环。

| 系列 | 模块类 | 说明 |
|------|--------|------|
| 1. whereHas 优化 | WhereHasMacros | 解决关联查询全表扫描问题（IN / JOIN / 跨表） |
| 2. 主表字段 | QualifyMacro 等 | 自动表前缀避免歧义 |
| 3. 随机查询 | RandomMacro | 高效随机数据获取 |
| 4. 分组排序 | GroupSortMacro | 窗口函数分组排名查询 |
| 5. 窗口函数 | WindowFunctionsMacro | MySQL 8.0+ / PostgreSQL 15+ 窗口函数（排名/偏移/聚合/框架/统计） |
| 6. 递归查询 | WithRecursiveMacro | 树形结构数据处理（层级/路径/关系/树构建，含深度上限防环） |
| 7. 分页优化 | FastPaginationMacro | 超大表快速分页（游标/Seek/分区/延迟关联） |
| 8. JSON 操作 | AdvancedJsonMacro | 高级 JSON 查询和操作 |
| 9. 正则表达式 | RegexMacro | 强大的文本匹配功能（REGEXP_LIKE / ~） |
| 10. 集合操作 | SetOperationsMacro | INTERSECT / EXCEPT 集合运算 |
| 11. QUALIFY 过滤 | QualifyMacro | 窗口函数结果过滤（类似 HAVING） |
| 12. LATERAL JOIN | LateralJoinMacro | 横向连接，高效 Top-N 查询 |
| 13. 行列转换 | PivotMacro | PIVOT / UNPIVOT 数据透视表 |
| 14. 数据抽样 | TableSampleMacro | 随机/分层/系统抽样 |
| 15. VALUES 构造 | ValuesMacro | 批量插入和 UPSERT 优化 |
| 16. 向量相似度 | MacrosBuilder（原生桥接） | Laravel 13+ 原生向量/语义检索 |
| 17. 字符串函数 | StringFunctionsMacro | LOCATE 分词 / CHAR_LENGTH 排序 / SOUNDEX / 字符串变换（**全文检索已独立为第 18 项**） |
| 18. 全文检索 | FullTextMacro | MATCH...AGAINST / tsvector 全文检索（自然语言/布尔/查询扩展/两阶段） |
| 19. 日期时间分桶 | DateTimeMacro | 按 日/周/月/季/年、N 分钟分桶、时间段范围、时段冲突检测 |
| 20. 数值/比较运算 | MathMacro | NULL 安全比较 / 区间夹紧 / 多级兜底 / 加权排序 / 精度控制 |
| 21. 锁机制 | LockingMacro | 行锁 SKIP LOCKED / NOWAIT / 咨询锁（MySQL GET_LOCK / PG pg_advisory_lock） |
| 22. 相关度搜索 | SearchRelevanceMacro | 无全文索引时的 LOCATE 两阶段相关度检索 |
| 23. 索引与优化器提示 | IndexHintMacro | USE/FORCE/IGNORE INDEX、优化器提示、执行时限、STRAIGHT_JOIN（性能调优入口） |
| 24. 查询守卫与诊断 | QueryGuardMacro | LIMIT 软/硬守卫（防失控）、EXPLAIN / EXPLAIN ANALYZE（慢查询定位） |
| 25. 分组 Top-N | TopNMacro | 窗口函数去重，每组最新/最大/前 N 条（替代 GROUP BY 丢字段/关联子查询） |
| 26. 条件聚合 | ConditionalAggregateMacro | SUM/COUNT/AVG/MAX/MIN 的 IF 变体（单条 SQL 多维统计，替代 N+1） |
| 27. 列选择纪律 | SelectColumnMacro | selectOnly / selectExcept / selectPrimaryKey（消除 SELECT *，降 I/O 与内存） |
| 28. 超大 IN/NOT IN 分块 | ChunkedListMacro | whereInChunked / whereNotInChunked / orWhereInChunked / orWhereNotInChunked（规避占位符上限/优化器退化/内存暴涨） |
| 29. 大表计数加速 | FastCountMacro | approximateCount()（无过滤走数据字典 O(1) 估算，有过滤回退精确 COUNT） |
| 30. 防失控写入/删除护栏 | SafeWriteMacro | mustHaveWhere() 写前闸门 + deleteInChunks() 按主键分批安全删除（防全表误操作/锁表/卡死） |

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

## 架构与标准化设计（v3.1.0）

本扩展包经 v3.1.0 系统化重构，建立了统一的宏扩展架构，彻底解决历史版本「仅在 ORM 可用、DB 不可用」
「命名/注释/示例不规范」「闭包内 self:: 陷阱」等问题。

### 1. 双构建器注册（ORM + DB 友好）

所有宏均通过 `BaseMacro::define()` 同时注册到两个构建器，因此 `User::query()->macro()` 与
`DB::table('users')->macro()` **完全等价、均可使用**：

```php
// ORM 用法
User::query()->whereRegexp('name', '^张')->get();

// DB 原生用法（语法完全一致）
\DB::table('users')->whereRegexp('name', '^张')->get();
```

### 2. 标准化基类 `BaseMacro`

`Concerns/BaseMacro` 是每一个宏模块（位于 `WindowMacros` 命名空间）必须继承的基类，统一提供：

- `define()`：双构建器注册入口；
- `resolveTable()` / `modelOf()` / `keyName()` / `queryOf()` / `freshQuery()` / `resetSubQuery()`：
  跨 Eloquent 与 Query 构建器的安全助手；
- `wrapColumn()` / `wrapColumnOrStar()`：标识符安全 wrap。

### 3. 方言抽象层 `Dialect`（及 `SqlSecurity`）

所有依赖数据库专属语法的片段（正则、JSON、字符串、全文、抽样、批量写、随机、日期分桶、锁等）一律
经 `Dialect` 生成 `[sql, bindings]` 二元组，绝不在宏类内硬编码 `REGEXP_LIKE` / `JSON_EXTRACT` / 反引号。
标识符经 `SqlSecurity::assertValidIdentifier()` 白名单校验，再由 grammar `wrap()` 生成适配当前驱动的引用
（MySQL 反引号、PostgreSQL 双引号），字面值一律 `?` 绑定。

### 4. 宏编写规范（强制）

- 每个模块暴露 `public static function register(): void`，内部按功能分组调用 `self::registerXxx()`；
- 宏闭包内**禁止**使用 `self::`（会被 `Closure::bind` 重绑为 `Builder` 而致命），助手方法须用**显式类名**调用，
  如 `RegexMacro::assertXxx(...)` 或 `BaseMacro::wrapIdentifier(...)`；
- 每个宏闭包头部必须含 `@example`（用法示例）与 `@scenario`（适用场景）注释；
- 链式宏一律返回 `$this`（Builder），参数声明类型并经白名单/边界校验。

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

## 10. 集合操作系列（MySQL 8.0.31+ / PostgreSQL 15+，标准 SQL）

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

## 11. QUALIFY 过滤系列（MySQL 8.0.33+ / PostgreSQL 15+，通过子查询模拟）

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

## 12. LATERAL JOIN 系列（MySQL 8.0.14+ / PostgreSQL 15+）

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

## 17. 字符串函数系列（MySQL 8.0+ / PostgreSQL 15+ 高效字符串处理）

基于 MySQL 8.0 / PostgreSQL 15 字符串函数封装的高性能查询能力（方言差异由 `Dialect` 统一处理）：
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

> 全文检索在 v3.1.0 起已独立为 **第 18 项 `FullTextMacro` 模块**（单一职责、示例与场景更完整）。
> 下列宏仍由 `FullTextMacro` 提供，字符串函数模块仅保留 LOCATE/CHAR_LENGTH/SOUNDEX 等基础能力。

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

## 18. 全文检索系列（FullTextMacro，MySQL 8.0+ / PostgreSQL 15+）

基于 study_mysql「搜索、模糊匹配与相关度排名」系统化封装，全部经 `Dialect` 生成双库通用 SQL：

- MySQL：`MATCH(col) AGAINST(? IN NATURAL LANGUAGE MODE|IN BOOLEAN MODE|WITH QUERY EXPANSION)`，中文建议配 `FULLTEXT(col) WITH PARSER ngram`；
- PostgreSQL：`to_tsvector @@ to_tsquery`（需 GIN 索引），中文建议配 `pg_jieba` / `zhparser`。

```php
// 自然语言检索（最常用）
Article::query()->whereFullText(['title', 'body'], 'Laravel 查询构造器')->get();

// 布尔模式：+ 必须包含 / - 排除 / "短语" / word* 前缀
Post::query()->whereFullTextBoolean('title', '+Laravel -Vue')->get();

// 按相关度排序（需与 whereFullText 列/词一致）
Article::query()
    ->whereFullText(['title', 'body'], 'Laravel')
    ->orderByFullTextRelevance(['title', 'body'], 'Laravel')
    ->get();

// 将相关度分数作为字段返回（可展示「相关度 92%」）
Article::query()
    ->whereFullText(['title', 'body'], 'Laravel')
    ->selectFullTextScore(['title', 'body'], 'Laravel', 'relevance')
    ->get();

// 按相关度阈值过滤（降噪）
Article::query()
    ->whereFullText(['title', 'body'], 'Laravel')
    ->whereFullTextScoreGte(['title', 'body'], 'Laravel', 1.5)
    ->get();

// 两阶段检索：全文索引命中 OR LOCATE 兜底（召回更鲁棒）
Article::query()->whereFullTextOrLike(['title', 'body'], 'Laravel')->get();
```

## 19. 日期时间分桶系列（DateTimeMacro，MySQL 8.0+ / PostgreSQL 15+）

把「按时间维度聚合」的常见模式抽象为语义化宏，避免在各处手写易错、易注入的裸 SQL：

```php
// 按 日/周/月/季/年 分桶（用于统计报表）
Order::query()->dateBucket('created_at', 'month')->get();           // MySQL: DATE_FORMAT / PG: date_trunc
Order::query()->dateBucket('created_at', 'week')->get();

// 按 N 分钟分桶（如每 15 分钟一个时段）
Log::query()->timeBucket('created_at', 15)->get();                // MySQL: FROM_UNIXTIME / PG: to_timestamp

// 索引友好的日期范围（闭区间，走 created_at 索引）
Report::query()->whereDateRange('created_at', '2025-01-01', '2025-12-31')->get();

// 时段冲突检测（会议室/排期，区间重叠校验）
Room::query()->whereTimeOverlap('start_at', 'end_at', '2025-01-01 09:00', '2025-01-01 10:00')->get();
```

## 20. 数值/比较运算系列（MathMacro，MySQL 8.0+ / PostgreSQL 15+）

```php
// NULL 安全等值比较（NULL = NULL 判定为真，避免 where('x', null) 被转成 IS NULL 的漏判）
Category::query()->whereNullSafeEqual('parent_id', null)->get();   // MySQL: <=>  / PG: IS NOT DISTINCT FROM

// 区间夹紧封顶（评分/百分比限制）
Product::query()->selectClamp('discount', 0, 0.5, 'safe_discount')->get();   // GREATEST(?, LEAST(?, col))

// 多级兜底取值（昵称 > 用户名 > 默认值）
User::query()->selectCoalesce(['nickname','username'], '匿名用户', 'display_name')->get();  // COALESCE(..., ?)

// 加权综合排序（搜索/推荐复合打分）
Post::query()->orderByWeighted([
    ['column' => 'hot',   'weight' => 0.3],
    ['column' => 'sales', 'weight' => 0.2],
    ['column' => 'score', 'weight' => 0.5],
])->get();

// 精度控制（金额截断到 2 位，避免浮点误差）
Order::query()->selectRound('amount', 2, true, 'amount_2f')->get();   // MySQL: TRUNCATE / PG: TRUNC
```

## 21. 锁机制系列（LockingMacro，MySQL 8.0+ / PostgreSQL 15+）

```php
// 行锁 + SKIP LOCKED（抢单/任务调度，跳过已被锁定的行）
Task::query()->lockForUpdateSkipLocked()->where('status', 'pending')->get();

// 行锁 + NOWAIT（获取不到锁立即失败，避免等待）
Task::query()->lockForUpdateNoWait()->where('status', 'pending')->get();

// 共享锁 + SKIP LOCKED
Doc::query()->sharedLockSkipLocked()->where('id', 1)->get();

// 会话级咨询锁（跨请求互斥，MySQL GET_LOCK / PG pg_advisory_lock）
LockingMacro::advisoryLock('order:123');     // 业务处理...
LockingMacro::advisoryUnlock('order:123');
```

## 22. 相关度搜索系列（SearchRelevanceMacro，MySQL 8.0+ / PostgreSQL 15+）

无需全文索引的两阶段相关度检索，作为 MATCH...AGAINST 的轻量替代 / 兜底：

```php
// 按子串相关度命中筛选（无需全文索引）
Ticket::query()->whereRelevance('content', '退款')->get();

// 按多列组合相关度排序（越相关越靠前）
Product::query()->orderByRelevance(['name','description'], '手机')->get();

// 返回单列的命中相关度分数（可展示「匹配度 87%」）
Product::query()->selectRelevance('name', '手机', 'score')->get();

// 一站式轻量搜索（命中筛选 + 相关度排序）
Article::query()->searchRank(['title','body'], 'laravel')->get();
```

---

## 23. 索引与优化器提示系列（IndexHintMacro，MySQL 8.0+ / PostgreSQL 15+）

`study_mysql` 指出「优化器选错索引导致全表扫描」是生产环境最常见的性能瓶颈之一。本系列把
索引提示与优化器提示抽象为语义化宏，让开发者在确认执行计划后**精确引导优化器走正确索引 / 限制执行成本**。

```php
// 强制走复合索引，避免优化器误判（支持 USE/FORCE/IGNORE；PG 经 pg_hint_plan）
Order::query()->useIndex(['idx_user_created'])->where('user_id', $uid)->get();
Order::query()->forceIndex('idx_status')->where('status', 1)->get();
Order::query()->ignoreIndex('idx_bad')->whereBetween('created_at', [$s, $e])->get();

// 注入任意 MySQL 优化器提示（包裹在 /*+ ... */ 中，幂等仅出现一次）
Order::query()
    ->optimizerHints(['JOIN_PREFIX(u)', 'MAX_EXECUTION_TIME(3000)'])
    ->where('user_id', $uid)->get();

// 单次查询最大执行毫秒数（性能守卫，防卡死；PG 经 pg_hint_plan 的 MaxExecTime）
Report::query()->maxExecutionTime(5000)->where(...)->get();

// 强制 MySQL 按 FROM 顺序做连接（STRAIGHT_JOIN，仅 MySQL 有效）
DB::table('orders')->straightJoin()->join('users', 'users.id', '=', 'orders.user_id')->get();
```

> **双库说明**：`USE/FORCE/IGNORE INDEX` 为 MySQL 原生语法；PostgreSQL 无标准等价语法，经
> `pg_hint_plan` 扩展生成 `/*+ IndexScan/NoIndexScan */` 注释提示（未安装扩展则注释无害）。
> `MAX_EXECUTION_TIME` / `MaxExecTime` 同理。`STRAIGHT_JOIN` 仅 MySQL 生效，PG 下安全忽略。

## 24. 查询守卫与诊断系列（QueryGuardMacro，MySQL 8.0+ / PostgreSQL 15+）

聚焦「防止查询失控」——这是系统稳定性最常见的风险源。守卫类宏**不改变业务语义**，只做兜底 + 熔断，
确保大表 / 高并发 / 参数异常场景下不会出现「长时间未响应 / 资源占用高 / 卡死」。

```php
// 软守卫：仅当未设 LIMIT 时补安全上限（默认 1000），不破坏已有语义
$query->where(...)->withLimitGuard(1000)->get();

// 硬守卫：未设 LIMIT 或超阈值直接拒绝执行（fail-fast），适用于导出/遍历前置
->requireLimit(50000)->get();

// 返回执行计划（诊断慢查询第一抓手）
$plan = Order::query()->where('status', 1)->explain('json');
// 返回真实执行计划（含耗时/行数，会真正执行，开销更高，慎用于生产热路径）
$plan = Order::query()->where('status', 1)->explainAnalyze();
```

> `explain()` 在 MySQL 生成 `EXPLAIN [FORMAT=...]`，在 PostgreSQL 生成 `EXPLAIN (FORMAT ...)`，
> 结果由 `getConnection()->select()` 返回，可直接用于诊断是否走索引 / 全表扫描 / filesort。

## 25. 分组 Top-N 系列（TopNMacro，MySQL 8.0+ / PostgreSQL 15+）

`study_mysql` 高频模式「取每组最新/最大/前 N 条」（每用户最新订单、每分类 Top3、每文章最新评论）。
传统写法（GROUP BY 丢字段、关联子查询逐行执行）是大表性能瓶颈；本系列用窗口函数 `ROW_NUMBER()` 一次性改写。

```php
// 每个用户最新的一笔订单
Order::query()->whereTopNPerGroup('user_id', 'created_at', 'desc', 1)->get();
// 每个分类销量前 3 的商品
Product::query()->whereTopNPerGroup('category_id', 'sales', 'desc', 3)->get();
// 多列排序：先状态后时间
Order::query()->whereTopNPerGroup('user_id', ['status','created_at'], ['asc','desc'], 1)->get();
```

> 实现：构造 `ROW_NUMBER() OVER (PARTITION BY 分组 ORDER BY 排序) AS _rn` 子查询，再用
> `whereIn(主键, 子查询 WHERE _rn <= N)` 过滤，双构建器（ORM/DB）通用。

## 26. 条件聚合系列（ConditionalAggregateMacro，MySQL 8.0+ / PostgreSQL 15+）

报表常见的「按状态 / 渠道 / 时间区间拆分统计」，新手常写 N 条独立 `COUNT/SUM` 查询再应用层拼装（N+1 与多次往返）。
本系列用 `SUM/COUNT/AVG/MAX/MIN(CASE WHEN ...)` 在**单条 SQL** 内完成多维统计。

```php
// 单条 SQL 同时统计「已支付金额」与「退款金额」
Order::query()
    ->selectSumIf('amount', 'status = ?', [1], 'paid_amount')
    ->selectSumIf('amount', 'status = ?', [4], 'refund_amount')
    ->first();

// 各状态订单数（单条 SQL 完成多条件计数）
Order::query()
    ->selectCountIf('id', 'status = ?', [1], 'paid_count')
    ->selectCountIf('id', 'status = ?', [0], 'unpaid_count')
    ->first();

// 已支付订单的平均/最大/最小金额
Order::query()
    ->selectAvgIf('amount', 'status = ?', [1], 'paid_avg')
    ->selectMaxIf('amount', 'status = ?', [1], 'paid_max')
    ->selectMinIf('amount', 'status = ?', [1], 'paid_min')
    ->first();
```

> **语义正确性**：`SUM` 非匹配行返回 `0`（等价「仅累加匹配行」）；`COUNT` 用 `THEN 1`；
> `AVG/MAX/MIN` 非匹配行返回 `NULL`（被聚合函数忽略），**不会**把 0 计入结果而失真。

## 27. 列选择纪律系列（SelectColumnMacro，MySQL 8.0+ / PostgreSQL 15+）

`SELECT *` 是生产环境最常见的隐性性能与资源杀手（放大 I/O、内存、带宽，使覆盖索引失效）。
本系列把「只取需要的列」抽象为语义化宏，从源头减少结果集体积，直击「资源占用高 / 卡死」。

```php
// 列表页只取展示字段，避免拉取 body 等大字段
Article::query()->selectOnly(['id','title','created_at'])->paginate(20);

// 已 select 多列后剔除大字段（需先显式 select；MySQL/PG 无统一 SELECT * EXCEPT 语法）
Article::query()->select(['id','title','body','created_at'])->selectExcept('body')->get();

// 仅取主键（判断存在性 / 取 ID 列表等轻量场景）
$ids = Order::query()->where('status', 1)->selectPrimaryKey()->pluck('id');
```

## 28. 超大 IN / NOT IN 分块系列（ChunkedListMacro，MySQL 8.0+ / PostgreSQL 15+）

`study_mysql` 将「巨型 IN 列表」列为典型查询改写反模式：单条巨 `IN` 会撞上 MySQL 占位符上限（≈65535）、
使优化器退化全表扫描、并让 PHP 构造超长 SQL 字符串造成内存与网络暴涨。本系列把大数组按 **默认 1000** 切块，
生成 `(col IN 块1) OR (col IN 块2) ...`（IN 以 OR 合并、等价原语义）或 `(col NOT IN 块1) AND (col NOT IN 块2) ...`
（NOT IN 以 AND 合并、等价原语义），从源头规避上述三类故障。

```php
// 一次性筛选 5 万个订单 ID，自动切成 50 个 IN 块 OR 合并，规避占位符上限与优化器退化
Order::query()->whereInChunked('id', $hugeIdList)->get();
DB::table('orders')->whereInChunked('id', $hugeIdList, 2000)->get();

// 排除数万条已处理 ID，自动切成多块 NOT IN 以 AND 合并
Order::query()->whereNotInChunked('id', $processedIds)->get();

// 作为 OR 分支追加（复杂组合筛选中某一分支匹配超大集合）
Order::query()->where('status', 1)->orWhereInChunked('id', $hugeIdList)->get();
```

> **安全与效率约定**：列名经 `SqlSecurity::assertValidIdentifier` 白名单校验（防注入）；入参数组先 `array_unique` 去重；
> `$chunkSize` 强制 `max(1, ...)`；**空集合安全**——`IN ()` 非法故空数组返回零行（`1 = 0`），`NOT IN` 空数组语义为全表故不加约束；
> **单块最优**——仅 1 块时直接委托原生 `whereIn/whereNotIn`，不产生多余嵌套；全程只拼装查询、**不触发任何 SQL 执行**，绝不会因数据量大而卡死。

## 29. 大表计数加速系列（FastCountMacro，MySQL 8.0+ / PostgreSQL 15+）

对千万/亿级表执行 `SELECT COUNT(*)` 常全索引/全表扫描，拖垮连接、导致大屏与报表接口超时（长时间未响应）。
本系列在无过滤时直接读取**数据字典估算行数**（MySQL `information_schema.TABLES.TABLE_ROWS` / PostgreSQL `pg_class.reltuples`），
复杂度 O(1)、不触达业务数据、零锁零 undo；一旦带 WHERE/JOIN/GROUP/HAVING 或复杂 FROM，自动回退精确 `COUNT(*)`，保证结果正确。

```php
// 大屏展示「全站用户总数」概览：千万级用户表瞬间返回，无需全表扫描
$total = User::query()->approximateCount();

// 带条件的精确计数（自动识别并回退）
$paid  = Order::query()->where('status', 1)->approximateCount('id');
```

> **安全与效率约定**：仅读取系统目录视图，对线上零侵入；所有可能抛异常的目录查询均 `try/catch` 包裹，
> 失败时**静默回退精确 COUNT**，绝不向上暴露异常；估算值非实时（统计信息可能滞后），适用于概览/近似场景，
> 对账单、财务报表等强一致场景仍应使用精确 `count()`。

## 30. 防失控写入 / 删除护栏系列（SafeWriteMacro，MySQL 8.0+ / PostgreSQL 15+）

`study_mysql` 事故清单明确记载「无 WHERE 的 UPDATE/DELETE 误操作」「单条巨 DELETE 锁表 / undo 膨胀」
是生产最致命的稳定性事故源。本系列针对用户「避免卡死 / 资源占用高 / 异常」硬性要求，提供两道护栏：

```php
// 1) 写前闸门：缺少 WHERE 立即抛异常拦截，杜绝「少写一句 where 全表被改/被删」
Order::query()->where('id', 123)->mustHaveWhere()->delete();
// Order::query()->mustHaveWhere()->delete();   // ← 抛 RuntimeException 拦截

// 2) 按主键分批安全删除：千万级数据按每批 1000 行安全删除，不锁死整表
$removed = AuditLog::query()
    ->where('created_at', '<', now()->subDays(30))
    ->deleteInChunks(1000);
```

> **安全与效率约定**：删除按**主键**分批，复用原始 WHERE/JOIN 条件仅用于「取主键」，删除操作本身不带 JOIN，
> 兼容 MySQL「单表 DELETE 不支持 LIMIT + 多表语法」等约束；采用 `clone()`（Laravel 深拷贝）隔离「取键」与「删除」两套查询互不污染；
> `deleteInChunks` 内置 `删除 0 行即终止` + `最大迭代上限（100000）` 双重防死循环，杜绝 CPU/连接耗尽；
> 内存占用与单批数据量成正比（O(分片大小)），与待删总量无关，稳定可控。

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

### 4. 宏操作的效率与资源占用保障（v3.2.0 重点）

本扩展包的所有宏均遵循以下原则，确保「**不出现长时间未响应、系统资源占用高、卡死与异常**」：

1. **宏只构建 SQL，不主动执行**：所有宏仅拼接/改写查询（返回 `$this` 或结果集构建器），不会在宏内部调用 `->get()` / `->first()` / `->paginate()` 等触发实际查询的操作，避免宏本身造成不可控的数据加载与内存占用。
2. **递归深度上限（防环 / 防无限递归）**：`WithRecursiveMacro` 在递归成员中强制 `WHERE depth < ?`，即使数据存在环（a→b→a）也只会产出有限行，不会让数据库无限递归。
3. **LIMIT 守卫（防全表失控）**：`withLimitGuard()` 在缺失 `LIMIT` 时自动补安全上限；`requireLimit()` 在未设 LIMIT 或超过阈值时直接拒绝执行（fail-fast），杜绝「忘加分页导致全表返回」的事故。
4. **执行时限守卫（防卡死）**：`maxExecutionTime()` 通过 MySQL 优化器提示 / pg_hint_plan 限制单条查询最大执行毫秒数，作为资源占用的最后一道防线。
5. **列选择纪律（降 I/O 与内存）**：`selectOnly / selectExcept / selectPrimaryKey` 消除 `SELECT *`，从源头缩小结果集体积，减少磁盘 I/O、网络带宽与 PHP 内存占用。
6. **分组 Top-N 去重（替代 N+1 / 关联子查询）**：`whereTopNPerGroup` 用窗口函数一次性改写「每组前 N 条」，避免逐行关联子查询在大表上的性能崩塌。
7. **单条 SQL 多维统计（替代 N+1）**：`selectSumIf / selectCountIf / ...` 用条件聚合在一条语句内完成多维度统计，消除多次查询往返。
8. **标识符白名单 + 参数绑定（防注入与语法崩坏）**：所有列名 / 别名 / 索引名 / 表名经 `SqlSecurity::assertValidIdentifier` 白名单校验后由 grammar 生成引用；所有字面值一律参数绑定，杜绝 SQL 注入与因拼接非法标识符导致的查询异常。
9. **双构建器一致、异常清晰**：宏同时注册到 `Eloquent\Builder` 与 `Query\Builder`；不支持当前驱动的功能（如 PG 的 `STRAIGHT_JOIN`）会被安全忽略或抛出**明确可操作的异常**，而非静默产生错误 SQL。
10. **超大 IN/NOT IN 分块（防占位符异常 / 优化器退化 / 内存暴涨）**：`whereInChunked / whereNotInChunked / orWhereInChunked / orWhereNotInChunked` 将巨型值列表自动切块并以 OR/AND 合并，单块直接委托原生，空集合安全（IN→零行、NOT IN→全表），全程只拼装查询、**绝不触发 SQL 执行**，数据量再大也不会卡死。
11. **大表计数加速（防大屏/报表超时）**：`approximateCount()` 对无过滤的简单表经数据字典读取 O(1) 估算行数，有过滤/复杂 FROM 自动回退精确 `COUNT(*)`；目录查询异常一律 `try/catch` 静默回退，调用方永远拿到可用数字，绝不向上抛错。
12. **防失控写入/删除护栏（防全表误操作 / 锁表 / 卡死）**：`mustHaveWhere()` 在 `update/delete` 之前断言已带 WHERE，否则立即抛异常拦截全表操作；`deleteInChunks()` 按主键分批删除，内置「删除 0 行即终止 + 最大迭代上限」双防死循环，内存占用仅与单批大小相关、与待删总量无关，稳定可控。

---

## 兼容性说明

- **PHP**: 8.3+
- **Laravel**: 11+ / 12+ / 13+
- **MySQL**: 8.0+（窗口函数/字符串函数需 8.0+，LATERAL JOIN 需 8.0.14+，INTERSECT/EXCEPT 需 8.0.31+，QUALIFY 需 8.0.33+，全文检索需建 FULLTEXT 索引）
- **PostgreSQL**: 15+（窗口函数/递归 CTE/集合操作/正则 ~ 原生支持；JSON 操作走 jsonb 系列函数；全文检索走 to_tsvector/to_tsquery；UPSERT 走 ON CONFLICT；SOUNDEX 需启用 fuzzystrmatch 扩展）
- **无需缓存扩展**: 所有宏均为纯 SQL 优化，方言差异由 `zxf\Modules\BuilderQuery\Concerns\Dialect` 统一抽象

---

## 更多文档

- [窗口函数详细文档](WindowMacros/readme.md)
- [递归查询详细文档](WindowMacros/readme.md#递归查询宏)
