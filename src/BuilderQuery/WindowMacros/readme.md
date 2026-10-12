# MySQL 8.4+ 窗口函数与高级查询宏

本文档详细介绍 MySQL 8.4+ 窗口函数宏和其他高级查询宏的使用方法。

---

## 目录

1. [快速参考](#快速参考)
2. [随机查询](#随机查询)
3. [分组排序](#分组排序)
4. [窗口函数](#窗口函数)
5. [递归查询](#递归查询)
6. [分页优化](#分页优化)
7. [JSON 操作](#json-操作)
8. [正则表达式](#正则表达式)
9. [字符串函数](#字符串函数)

---

## 快速参考

### 函数速查表

| 类别 | 函数名 | 用途 |
|------|--------|------|
| 排名 | `rowNumber()` | 唯一行号 |
| 排名 | `rank()` | 排名（跳号） |
| 排名 | `denseRank()` | 密集排名 |
| 排名 | `percentRank()` | 百分比排名 |
| 排名 | `ntile()` | 分位数 |
| 偏移 | `lag()` | 前N行值 |
| 偏移 | `lead()` | 后N行值 |
| 偏移 | `firstValue()` | 首行值 |
| 偏移 | `lastValue()` | 末行值 |
| 偏移 | `nthValue()` | 第N行值 |
| 聚合 | `sumOver()` | 窗口求和 |
| 聚合 | `avgOver()` | 窗口平均 |
| 聚合 | `countOver()` | 窗口计数 |
| 聚合 | `minOver()` | 窗口最小值 |
| 聚合 | `maxOver()` | 窗口最大值 |
| 框架 | `rowsBetween()` | 基于行数的窗口 |
| 框架 | `rangeBetween()` | 基于值的窗口 |
| 统计 | `cumulativeSum()` | 累计和 |
| 统计 | `movingAverage()` | 移动平均 |
| 统计 | `runningTotal()` | 运行总计 |

---

## 随机查询

### random

使用窗口函数实现高效随机查询，避免 `ORDER BY RAND()` 的全表扫描问题。

```php
/**
 * 随机查询指定数量记录
 *
 * @param int $limit 返回记录数，默认10
 * @param string $primaryKey 主键名，默认'id'
 * @return Builder
 *
 * 性能特点：
 * - 小表（<1万行）：性能与 ORDER BY RAND() 相当
 * - 大表（>10万行）：性能提升 10-100 倍
 * - 原理：使用 ROW_NUMBER() OVER (ORDER BY RAND()) 子查询
 */

// 基础用法
$students = Student::where('class_id', 101)->random(5)->get();

// 带条件的随机查询
$posts = Post::where('status', 1)
    ->where('category_id', 5)
    ->random(10)
    ->get();

// 指定主键字段
$items = Item::where('active', true)->random(20, 'item_id')->get();
```

### groupRandom

按分组字段进行分组，每组随机取出指定数量记录。

```php
/**
 * 分组随机查询
 *
 * @param string $groupColumn 分组字段名
 * @param int $limit 每组返回记录数，默认10
 * @param string $primaryKey 主键名，默认'id'
 * @return Builder
 *
 * SQL 原理：
 * ROW_NUMBER() OVER (PARTITION BY groupColumn ORDER BY RAND())
 */

// 每个班级随机选择2名学生
$students = Student::groupRandom('class_id', 2)->get();

// 每个分类随机取3篇文章
$posts = Post::where('status', 1)->groupRandom('category_id', 3)->get();

// 指定主键
$products = Product::groupRandom('brand_id', 5, 'sku')->get();
```

---

## 分组排序

### groupSort

```php
/**
 * 分组排序查询 - 获取每组中指定排名的记录
 *
 * @param string $groupBy 分组字段名
 * @param int|array $ranks 排名：
 *   - int: 单个排名（1=第1名，-1=倒数第1名）
 *   - array: 排名范围 [start, end]
 * @param string $orderBy 排序字段名
 * @param string $direction 排序方向：asc|desc
 * @return Builder
 *
 * 应用场景：
 * - 获取每个分类的热门文章
 * - 获取每个班级的成绩前N名
 * - 获取每个地区的销售冠军
 */

// 查询每个文章分类下阅读量最高的第1到3名
$topPosts = Article::query()
    ->groupSort('category_id', [1, 3], 'read_count', 'desc')
    ->get();

// 获取每个分类下阅读量第1名的文章
$champions = Article::query()
    ->groupSort('category_id', 1, 'read_count', 'desc')
    ->get();

// 获取每个分类下阅读量倒数第1名的文章
$leastRead = Article::query()
    ->groupSort('category_id', -1, 'read_count', 'desc')
    ->get();

// 复杂条件
$topStudents = Student::where('status', 1)
    ->groupSort('class_id', [1, 5], 'total_score', 'desc')
    ->with('classInfo')
    ->get();
```

---

## 窗口函数

### 排名函数

#### rowNumber

```php
/**
 * 为每一行分配唯一的连续整数序号
 *
 * @param string|array|null $partitionBy 分区字段
 * @param string $orderBy 排序字段
 * @param string $direction asc|desc
 * @param string $alias 结果列别名
 * @return Builder
 *
 * 特点：
 * - 每个分区内的行号唯一且连续
 * - 即使排序值相同，行号也不同
 */

// 为每个部门的员工按工资排序编号
$employees = Employee::query()
    ->rowNumber('department_id', 'salary', 'desc', 'rank_in_dept')
    ->get();

// 全局行号（不分区）
$allEmployees = Employee::query()
    ->orderBy('salary', 'desc')
    ->rowNumber(null, 'salary', 'desc', 'global_rank')
    ->get();

// 多字段分区
$employees = Employee::query()
    ->rowNumber(['dept_id', 'team_id'], 'performance_score', 'desc')
    ->get();
```

#### rank

```php
/**
 * 为每一行分配排名，相同值会有相同排名，后续排名会跳过
 *
 * 排名序列示例（相同分数99分）：
 * 分数100 -> 排名1
 * 分数99  -> 排名2
 * 分数99  -> 排名2
 * 分数98  -> 排名4（跳过3）
 */

// 竞赛排名（允许并列，下一名次跳过）
$ranking = Competition::query()
    ->rank(null, 'score', 'desc', 'competition_rank')
    ->get();
```

#### denseRank

```php
/**
 * 密集排名，相同值排名相同，后续排名连续
 *
 * 排名序列示例（相同分数99分）：
 * 分数100 -> 排名1
 * 分数99  -> 排名2
 * 分数99  -> 排名2
 * 分数98  -> 排名3（连续）
 */

// 等级评定
$grades = Exam::query()
    ->denseRank('class_id', 'total_score', 'desc', 'class_rank')
    ->get();
```

#### percentRank

```php
/**
 * 计算每行的相对排名百分比
 * 公式：(rank - 1) / (总行数 - 1)
 * 结果范围：0 到 1
 * 0 = 最高，1 = 最低，0.5 = 中间
 */

// 计算学生在班级成绩的百分比排名
$students = Student::query()
    ->percentRank('class_id', 'exam_score', 'desc', 'percentile')
    ->get();

// 筛选前10%的学生
$top10Percent = Student::query()
    ->percentRank('class_id', 'exam_score', 'desc', 'percentile')
    ->having('percentile', '<=', 0.1)
    ->get();
```

#### ntile

```php
/**
 * 将数据分为N个桶（分位数），返回桶号
 *
 * @param int $buckets 桶的数量
 * @param string|array|null $partitionBy 分区字段
 * @param string $orderBy 排序字段
 * @param string $direction asc|desc
 * @param string $alias 结果列别名
 * @return Builder
 *
 * 常用分位数：
 * - 4 = 四分位数（Q1, Q2, Q3, Q4）
 * - 10 = 十分位数
 * - 100 = 百分位数
 */

// 四分位数分析
$sales = Sales::query()
    ->ntile(4, 'region', 'amount', 'desc', 'quartile')
    ->get();
// 结果：1=前25%, 2=25-50%, 3=50-75%, 4=后25%

// 十分位数分析
$performance = Performance::query()
    ->ntile(10, null, 'score', 'desc', 'decile')
    ->get();
// 结果：1=前10%, 10=后10%
```

---

## 递归查询

### 基础递归查询

```php
// 查找所有子节点（树形向下遍历）
$children = Category::withAllChildren(5)->get();

// 查找所有父节点（树形向上遍历）
$parents = Category::withAllParents(8)->get();

// 限制递归深度
$children = Category::withAllChildren(5, 'parent_id', 10)->get();
```

### 指定层级查询

```php
// 查找第N级父节点（自己算0级）
$grandparent = Category::withNthParent(10, 2)->first();

// 查找第N级子节点
$grandchildren = Category::withNthChildren(1, 2)->get();
```

### 完整示例

```php
// 构建完整的树形结构
$tree = Category::withTree()
    ->where('status', 1)
    ->get()
    ->map(function ($item) {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'depth' => $item->depth,
            'path' => $item->absolute_path,
        ];
    });
```

---

## 分页优化

### fastPaginate 策略说明

| 策略 | 适用场景 | 原理 |
|------|----------|------|
| offset | 小表/浅分页 | 传统 OFFSET LIMIT |
| deferred | 中等数据量 | 延迟关联法 |
| window | 大表/深分页 | ROW_NUMBER() 窗口函数 |

---

## JSON 操作

### JSON Path 语法

| 路径 | 说明 |
|------|------|
| `$` | 根元素 |
| `$.name` | 对象的 name 属性 |
| `$[0]` | 数组的第一个元素 |
| `$.items[0].price` | 嵌套路径 |
| `$.*` | 所有属性 |

---

## 正则表达式

### MySQL 8.4+ 正则模式

| 模式 | 说明 |
|------|------|
| `c` | 区分大小写 |
| `i` | 不区分大小写 |
| `m` | 多行模式 |
| `n` | 点号匹配换行 |
| `u` | Unicode 模式 |

---

## 字符串函数

基于 MySQL 8.0 内置字符串函数封装的高效查询能力，完整文档见 [BuilderQuery 主文档](../readme.md#17-字符串函数系列mysql-80-高效字符串处理)。

### 子串位置查找（分词查询匹配）

```php
// 单关键词命中
Post::query()->whereLocate('description', 'Laravel')->get();

// 多关键词全命中（AND）：内容必须同时包含 Laravel 与 MySQL
Article::query()->whereLocateAll('content', ['Laravel', 'MySQL'])->get();

// 多关键词任一命中（OR）
Article::query()->whereLocateAny('content', ['Laravel', 'MySQL'])->get();

// orWhereLocate - OR 条件组合
Post::query()->whereLocate('title', 'MySQL')->orWhereLocate('description', '数据库')->get();
```

### 字符长度排序与筛选

```php
// 按字符数升序（多字节安全）
User::query()->orderByCharLength('name')->get();

// 内容最长的文章排在最前
Post::query()->orderByDescCharLength('body')->get();

// 用户名长度大于等于 4
User::query()->whereCharLength('name', '>=', 4)->get();

// 自然排序：v1, v2, ..., v10 而非字典序 v1, v10, v2
Version::query()->orderByNatural('tag')->get();
```

### 自定义排序与列表查找

```php
// 按状态优先级自定义排序
Order::query()->fieldOrderBy('status', ['pending', 'shipped', 'delivered', 'completed'])->get();

// 逗号分隔标签列包含 "php"
Article::query()->whereFindInSet('tags', 'php')->get();

// 包含任意指定值 / 必须同时包含全部指定值
Article::query()->whereFindInSetAny('tags', ['php', 'go'])->get();
Article::query()->whereFindInSetAll('tags', ['php', 'mysql'])->get();
```

### 多列拼接搜索与提取

```php
// 在 姓名/手机号/邮箱 三字段中一站式搜索 "张"
User::query()->whereConcatLike(['name', 'phone', 'email'], '张')->get();

// 提取邮箱用户名部分（"a@b.com" -> "a"）
User::query()->substringIndex('email', '@', 1, 'username_part')->get();

// 每个分类下所有文章标题（逗号分隔）
Post::query()->groupBy('category_id')->groupConcat('title', 'titles')->get();
```

### 全文检索（MATCH...AGAINST）

参与全文检索的列需先建立 FULLTEXT 索引：

```sql
ALTER TABLE articles ADD FULLTEXT INDEX ft_title_body (title, body);
```

```php
// 自然语言全文搜索
Article::query()->whereFullText(['title', 'body'], 'Laravel 查询构造器')->get();

// 布尔模式：+word 必须包含  -word 必须排除  "短语" 精确匹配  word* 前缀匹配
Post::query()->whereFullTextBoolean('title', '+Laravel -Vue')->get();

// 按相关度从高到低排序
Article::query()
    ->whereFullText(['title', 'body'], 'Laravel')
    ->orderByFullTextRelevance(['title', 'body'], 'Laravel')
    ->get();
```

### 发音匹配与字符串变换

```php
// 英文发音相似匹配（"Smith" 与 "Smyth"）
Contact::query()->whereSoundex('last_name', 'Smith')->get();

// 手机号脱敏 / 去空格 / 大小写 / 反转 / 补零
User::query()->replaceString('phone', '138', '***', 'masked_phone')->get();
User::query()->trimString('name', 'clean_name')->get();
User::query()->lowerString('email', 'email_lower')->get();
Country::query()->upperString('code', 'code_upper')->get();
User::query()->reverseString('phone', 'reversed_phone')->get();
Order::query()->padString('order_no', 8, '0', 'left', 'padded_no')->get(); // "123" -> "00000123"
```

---

## 性能优化指南

### 1. 索引策略

```sql
-- 窗口函数优化索引
ALTER TABLE sales ADD INDEX idx_partition_order (department_id, salary);

-- 递归查询优化索引
ALTER TABLE categories ADD INDEX idx_parent (parent_id, id);
```

### 2. 大数据量处理

```php
// 超过10万行使用游标分页
if (User::count() > 100000) {
    return User::query()->cursorPaginate(20);
}

// 超过100万行使用寻址分页
if (Log::count() > 1000000) {
    return Log::query()->seekPaginate(100, $bookmarks, $page);
}
```

---

## 完整示例代码

### 示例1：销售排行榜

```php
$salesRanking = Sales::query()
    ->select('salesman_id', 'amount', 'region')
    ->rank('region', 'amount', 'desc', 'region_rank')
    ->percentRank('region', 'amount', 'desc', 'top_percent')
    ->having('region_rank', '<=', 10)
    ->get();
```

### 示例2：股票技术分析

```php
$stocks = StockPrice::query()
    ->where('stock_code', 'AAPL')
    ->orderBy('trade_date')
    ->movingAverage('close_price', 5, 'stock_code', 'trade_date', 'asc', 'ma5')
    ->movingAverage('close_price', 20, 'stock_code', 'trade_date', 'asc', 'ma20')
    ->lag('close_price', 1, null, 'stock_code', 'trade_date', 'asc', 'prev_close')
    ->selectRaw('close_price - prev_close as change_amount')
    ->selectRaw('(close_price - prev_close) / prev_close * 100 as change_percent')
    ->get();
```

### 示例3：组织架构查询

```php
// 获取部门下的所有员工（包括子部门）
$departmentIds = Department::withAllChildren($deptId)->pluck('id');
$employees = Employee::whereIn('department_id', $departmentIds)->get();

// 获取员工的完整汇报链
$managerIds = Employee::withAllParents($employeeId, 'manager_id')->pluck('id');
```

---

## 注意事项

1. **MySQL 版本**: 窗口函数需要 MySQL 8.0+（推荐 8.0.31+ 以使用 INTERSECT/EXCEPT，8.0.14+ 以使用 LATERAL）
2. **性能考虑**: 大数据量分页优先使用 `cursorPaginate`
3. **索引优化**: 窗口函数的分区字段和排序字段需要索引
4. **内存使用**: `jsonExtractAll` 和 `regexpExtractAll` 可能返回大量数据

---

## 安全性（v2.x 设计原则）

所有宏方法均遵循「安全优先」的 SQL 拼装规范，从根本上杜绝注入与跨数据库兼容问题：

1. **标识符白名单**：列名、别名、分组字段、JSON 路径、聚合函数名、框架边界、排序方向、
   正则模式、比较运算符均经过严格的正则白名单校验（`SqlSecurity` 工具层）。
   任何包含空格、引号、括号、注释符等可被用于注入的字符都会被拒绝并抛出 `InvalidArgumentException`。
2. **驱动无关引用**：标识符统一经由 grammar 的 `wrap()` 生成（MySQL 反引号、PostgreSQL/SQLite 双引号），
   不再硬编码 `` `column` `` 反引号。
3. **字面值参数绑定**：正则模式、替换文本、JSON 默认值、LIKE 模式、随机种子等所有用户输入的字面值
   一律通过 `?` 占位符走参数绑定，绝不裸拼进 SQL。
4. **驱动守卫**：方言相关宏统一经由 `Dialect` 抽象层生成适配当前连接的 SQL，并通过
   `assertSupported()`（校验当前驱动为 MySQL 8.0+ 或 PostgreSQL 15+）守卫，在不受支持的驱动
   （如 SQLite）上调用会抛出清晰异常，而非生成非法 SQL。
5. **默认最小权限**：未提供显式版本要求时，多数宏最低兼容 MySQL 8.0+ / PostgreSQL 15+（非文档示例中的更高版本）。

> ⚠️ 调用方责任：传入的 `column` / `path` / `alias` 等参数虽然经过白名单校验，但仍应仅接受
> **可信或已校验的字段名**（如来自模型属性、白名单映射），避免把攻击者可控的任意字符串作为字段名传入。

---

## 基于 study_mysql 的性能瓶颈映射与宏架构设计

本扩展包在 v3.2.0 / v3.3.0 持续对 `study_mysql` 知识库做**系统化梳理**，将其中记录的高频性能瓶颈与事故源
（见 `study_mysql` 的 `04-performance`、`07-appendix/01-pitfalls-compendium`、`08-playbook` 等章节）逐一映射为
标准化宏。下面给出「瓶颈 → study_mysql 出处 → 对应宏模块」的映射表，并说明系统架构设计原则。

### 一、性能瓶颈 → 宏模块映射表

| 系统开发常见瓶颈（现象） | study_mysql 出处 | 对应宏模块（系列） | 核心防护手段 |
|------|------|------|------|
| 巨型 `IN (...)` 占位符超限、优化器退化、PHP 内存暴涨 | `08-playbook/02-query-rewrite-cookbook`、`04-performance/01-query-rewrite-antipatterns` | ChunkedListMacro（28） | 大数组自动切块，OR/AND 合并；单块委托原生；空集合安全 |
| 亿级表 `COUNT(*)` 全表扫描、大屏/报表超时 | `08-playbook/04-statistics-acceleration`、`04-performance/07-bottlenecks-and-advanced-ops` | FastCountMacro（29） | 无过滤走数据字典 O(1) 估算；有过滤回退精确 COUNT；异常静默回退 |
| 无 WHERE 的 UPDATE/DELETE 全表误操作 | `07-appendix/01-pitfalls-compendium` | SafeWriteMacro（30）mustHaveWhere | 写前断言 WHERE，否则抛异常拦截 |
| 单条巨 DELETE 锁表 / undo 膨胀 / 实例卡死 | `04-performance/07-bottlenecks-and-advanced-ops`、`07-appendix/01-pitfalls-compendium` | SafeWriteMacro（30）deleteInChunks | 按主键分批删；删 0 行即停 + 最大迭代上限双防死循环 |
| `SELECT *` 放大 I/O、内存、带宽，覆盖索引失效 | `04-performance/02-indexing-and-access-paths`、`08-playbook` | SelectColumnMacro（27） | selectOnly/selectExcept/selectPrimaryKey |
| 优化器选错索引导致全表扫描 | `04-performance/03-optimizer` | IndexHintMacro（23） | USE/FORCE/IGNORE INDEX、optimizerHints、maxExecutionTime |
| 缺 LIMIT 导致全表结果集返回、连接耗尽 | `08-playbook`、`07-appendix/01-pitfalls-compendium` | QueryGuardMacro（24） | withLimitGuard 软守卫 / requireLimit 硬守卫（fail-fast） |
| 慢查询定位难、执行计划不透明 | `08-playbook/03-explain` | QueryGuardMacro（24）explain/explainAnalyze | 返回执行计划，诊断走索引/全扫/filesort |
| 每组最新/最大/前 N 条：GROUP BY 丢字段、关联子查询逐行执行 | `04-performance/05-window-functions` | TopNMacro（25） | ROW_NUMBER() 窗口函数一次性改写 |
| 多状态/多渠道统计写 N 条 COUNT/SUM（N+1 + 多次往返） | `04-performance/06-aggregation` | ConditionalAggregateMacro（26） | SUM/COUNT/AVG/MAX/MIN(CASE WHEN) 单条 SQL 多维聚合 |

> 上表仅列 v3.2.0 / v3.3.0 性能专项；其余系列（1–22）详见主文档与各模块 `@example`/`@scenario` 注释。

### 二、系统架构设计原则

所有性能/稳定性宏遵循统一架构，确保「**不出现长时间未响应、系统资源占用高、卡死与异常**」：

1. **宏只构建 SQL，不主动执行**：链式宏一律返回 `$this`；终端宏（`approximateCount` / `deleteInChunks`）才真正执行，
   且分批/估算逻辑把单次工作量限制在可控区间，杜绝一次性加载巨量数据。
2. **双构建器注册（ORM + DB 一致）**：经 `BaseMacro::define()` 同时注册到 `Eloquent\Builder` 与 `Query\Builder`，
   所有助手方法为 `public static` + 显式类名调用，规避 `Closure::bind` 把 `self::/static::` 重绑为 `Builder` 的致命陷阱。
3. **标识符白名单 + 参数绑定**：列名/索引名/表名经 `SqlSecurity::assertValidIdentifier` 校验后由 grammar `wrap()`，
   字面值一律 `?` 绑定，杜绝注入与非法标识符导致的查询异常。
4. **失败安全（fail-safe）优先于 fail-fast**：`approximateCount` 目录异常静默回退精确 COUNT；`deleteInChunks` 删 0 行即停；
   仅「无 WHERE 写操作」这类**不可逆灾难**才用 `mustHaveWhere` 主动 fail-fast 拦截。
5. **资源占用与数据量解耦**：分块 IN、分批删除、估算计数均把单次内存/锁开销钉死在「单批大小」量级，与待处理总量无关。
6. **方言统一经 `Dialect` 抽象**：MySQL / PostgreSQL 的语法差异（优化器提示、`information_schema` vs `pg_class`、索引提示等）
   全部收敛到 `Dialect`，宏类内不硬编码任何方言片段。

---

## 更新日志

### v3.3.0
- **失控防护与超大集合专项（第 28–30 系列）**：继续深挖 `study_mysql`「08-playbook / 02-query-rewrite-cookbook」
  「04-performance / 07-bottlenecks-and-advanced-ops」「07-appendix / 01-pitfalls-compendium」记录的高频事故源，
  针对用户「避免长时间未响应、资源占用高、卡死、异常」硬性要求，新增 3 个护栏类标准化模块：
  - `ChunkedListMacro`（第 28 系列）：`whereInChunked / whereNotInChunked / orWhereInChunked / orWhereNotInChunked`——
    将数万/数十万 ID 的巨型 `IN/NOT IN` 自动切块并以 OR/AND 合并，规避占位符上限异常、优化器退化与 PHP 内存暴涨；
    **单块直接委托原生**生成最优 SQL；**空集合安全**（IN 空→`1 = 0` 零行、NOT IN 空→不加约束）；仅拼装查询、**不触发执行**，数据量再大也不卡死。
  - `FastCountMacro`（第 29 系列）：`approximateCount()`——无过滤的简单表经 `information_schema.TABLES.TABLE_ROWS`
    （MySQL）/ `pg_class.reltuples`（PG）读取 **O(1) 估算行数**，有 WHERE/JOIN/GROUP/HAVING 或复杂 FROM 自动回退精确 `COUNT(*)`；
    目录查询一律 `try/catch` **静默回退**，调用方永远拿到可用数字，绝不向上抛异常。
  - `SafeWriteMacro`（第 30 系列）：`mustHaveWhere()` 写前闸门（缺 WHERE 立即抛 `RuntimeException` 拦截全表误操作）
    + `deleteInChunks()` 按主键分批安全删除（每批仅删有限行，复用 `clone()` 隔离取键/删除，`deleted === 0 即停` + `最大迭代上限 100000`
    双防死循环，内存占用仅与单批大小相关、与待删总量无关）。
- **`study_mysql` 瓶颈映射与架构设计文档**：新增「基于 study_mysql 的性能瓶颈映射与宏架构设计」章节，给出
  「瓶颈 → study_mysql 出处 → 对应宏模块」映射表与 6 条系统架构设计原则，系统化说明每个性能宏为何能杜绝卡死/高资源占用。
- **全面审查与修复（本轮）**：对所有 1–30 系列宏再做一轮错误/异常/漏洞/逻辑/功能/设计/架构审查——
  修复 `ChunkedListMacro`/`SafeWriteMacro` 宏闭包默认参数 `self::DEFAULT_CHUNK_SIZE` 被 `Macroable` 重绑为 `Builder` 的
  `Undefined constant` 致命（改为显式 `ChunkedListMacro::DEFAULT_CHUNK_SIZE` / `SafeWriteMacro::DEFAULT_CHUNK_SIZE`）；
  确认 `WhereHasMacros` 子目录零变更（硬约束保持）。
- **验证**：35+ PHP 文件全部 `php -l` 通过；202 个已注册宏在 MySQL / PostgreSQL 双 FakeBuilder 上逐一分发，
  **0 个结构性错误**（无私有/保护访问、self/static 陷阱、未定义常量等问题）；新增 3 模块另经 23 项忠实逻辑断言
  （分块块数、OR/AND 语义、空集合行为、写前闸门、近似计数回退、分批删除累计数）全部 PASS。

### v3.2.0

### v3.2.0
- **性能与稳定性专项（第 23–27 系列）**：基于 `study_mysql`「04-performance / 05-params-and-resource-tuning /
  07-bottlenecks-and-advanced-ops / 08-playbook」系统化新增 5 个性能/安全类标准化模块，直击系统开发常见瓶颈：
  - `IndexHintMacro`：USE/FORCE/IGNORE INDEX（MySQL 原生；PG 经 pg_hint_plan）、任意优化器提示、
    `maxExecutionTime`（单次查询执行时限守卫）、`straightJoin`。
  - `QueryGuardMacro`：LIMIT 软守卫 `withLimitGuard` / 硬守卫 `requireLimit`（fail-fast 防全表失控）、
    `explain` / `explainAnalyze`（返回执行计划，慢查询定位）。
  - `TopNMacro`：`whereTopNPerGroup`——用窗口函数 `ROW_NUMBER()` 实现「每组最新/最大/前 N 条」去重，
    替代 GROUP BY 丢字段与关联子查询逐行执行等低效写法。
  - `ConditionalAggregateMacro`：`selectSumIf/CountIf/AvgIf/MaxIf/MinIf`——单条 SQL 内完成多维度条件聚合，
    替代多次 `COUNT/SUM` 查询（N+1）。**已修正 AVG/MAX/MIN 非匹配行返回 NULL（而非 0）的语义，避免结果失真。**
  - `SelectColumnMacro`：`selectOnly/selectExcept/selectPrimaryKey`——消除 `SELECT *`，从源头降低 I/O、内存与带宽占用。
- **效率与资源占用保障**：所有宏仅构建 SQL、不在宏内主动执行查询；递归查询强制深度上限防环/防无限递归；
  新增 LIMIT 守卫与执行时限守卫，作为「长时间未响应 / 资源占用高 / 卡死」的兜底防线；详见主文档「性能优化建议 §4」。
- **`BaseMacro` 增强**：新增 `addSelectPrefix` / `addOptimizerHint`（幂等、可累加的 SELECT 前缀机制），
  统一驱动索引提示、优化器提示与 `STRAIGHT_JOIN` 注入；新增 `rawExpression` 助手。
- **深度加固（本轮全面复审发现的缺陷，已全部修复）**：
  - **优化器提示前缀幂等性**：早期实现每次追加前缀时从「已被前缀污染的列集合」重建，导致多次 `optimizerHints`/`maxExecutionTime`
    链式调用会几何级重复前缀（如 `/*+ A */ /*+ A */ /*+ B */ ...`）。现改为首次注入时捕获干净列集合（`['*']` 哨兵），
    后续重建均以此为准，多次调用仅顺序追加、绝不重复整段。
  - **`explain` / `explainAnalyze` 守卫**：`EXPLAIN ANALYZE` 会真实执行语句，若对 `DELETE/UPDATE/INSERT` 调用将造成数据误修改。
    新增 SQL 头关键字校验，仅允许以 `SELECT` / `WITH` 开头的读查询，其余一律拒绝，规避非预期执行。
  - **`TopN` 子查询健壮性**：修复外层 `whereIn` 直接引用 `_xbq_topn.orders.id`（带表限定的列名无法作为子查询列）的 SQL 错误；
    改为内部 SELECT 将主键别名化为 `_xbq_key`、外层引用 `_xbq_topn._xbq_key`，并以 `DB::table(DB::raw(...))->mergeBindings(...)` 的
    规范方式合并子查询绑定，彻底规避绑定顺序错位（即使模型全局作用域带来额外绑定也正确）。
  - **`selectExcept` 健壮性**：重写基础列名提取逻辑（剥离别名 `AS` 与表前缀），并修复 `columnBaseName` 因宏闭包 `static::` 重绑为
    `Builder` 导致的「调用私有方法」致命（改为 `public static` + 显式类名调用）。
  - **`withLimitGuard` 默认值 `self::` 陷阱**：闭包默认参数 `int $limit = self::DEFAULT_SAFE_LIMIT` 被 `Macroable` 重绑后解析为
    `Builder::DEFAULT_SAFE_LIMIT`（未定义）。改为显式 `QueryGuardMacro::DEFAULT_SAFE_LIMIT`，修复 `Undefined constant` 致命。
  - **`MacrosBuilder` 主表前缀宏修正**：`mainPluck/mainSum/mainWhere/...` 循环内误用驼峰方法名（`Pluck`/`Sum`/`Where`…），
    但 Builder 原生方法均为小写，运行时必然失败。已修正为小写方法名映射，并增加首个参数缺省保护。
  - **消除动态属性 E_DEPRECATED**：`SELECT` 前缀中间状态原以动态属性写入 Builder（PHP 8.2+ 触发 `E_DEPRECATED`）。
    改为 `WeakReference` 键控的静态存储，既无动态属性、又无内存泄漏、且规避对象 id 复用串号。
- **全面审查与修复**：对所有 19+27 系列宏再做一轮错误/异常/漏洞/逻辑/架构审查——修复条件聚合 AVG/MAX/MIN 的 `ELSE 0`
  语义缺陷，确认无宏在内部触发 `get()`/大结果集加载、无无界循环、标识符均经白名单校验；`WhereHasMacros` 子目录保持未修改。
- **验证**：35 个 PHP 文件全部 `php -l` 通过；195 个已注册宏在 MySQL 8.0+ / PostgreSQL 15+ 双 FakeBuilder 上
  逐一调用，0 个结构性错误（无私有/保护访问、trait 常量、闭包 `$this`、self 陷阱类问题）；新增宏另经功能抽查验证返回构建器且行为正确。

### v3.1.0
- **架构革新**：引入 `Concerns/BaseMacro` 标准化基类与「双构建器注册」机制，所有宏同时通过
  `Eloquent\Builder`（ORM）与 `Query\Builder`（`DB::table()`）注册，彻底解决历史版本「宏仅在 ORM 可用、
  DB 不可用」的合规缺口。
- **规范化改造**：每个宏模块统一 `extends BaseMacro`，提供 `public static function register(): void` 分组注册，
  每个宏闭包头部均含 `@example` 与 `@scenario` 中文注释，参数声明类型并经白名单/边界校验，链式宏返回 `$this`。
- **致命陷阱修复**：明确「闭包内禁止 `self::`、须用显式类名调用助手」（如 `RegexMacro::assertXxx` /
  `BaseMacro::wrapIdentifier`），规避 `Closure::bind` 将 `self::` 重绑为 `Builder` 导致的运行时致命。
- **新增 5 个标准化模块**（均双库通用、单一职责、示例/场景完整）：
  - `FullTextMacro`：从字符串函数模块独立，专门负责全文检索（自然语言/布尔/查询扩展/相关度排序/阈值/两阶段）；
  - `DateTimeMacro`：日期时间分桶（日/周/月/季/年、N 分钟）、索引友好范围、时段冲突检测；
  - `MathMacro`：NULL 安全比较、区间夹紧、多级兜底、加权排序、精度控制；
  - `LockingMacro`：行锁 SKIP LOCKED / NOWAIT、共享锁 SKIP LOCKED、会话级咨询锁；
  - `SearchRelevanceMacro`：无全文索引时的两阶段 LOCATE 相关度检索。
- **`Dialect` 扩展**：新增日期分桶 / 时间分桶 / NULL 安全比较 / 两阶段相关度 / 加权排序 / COALESCE 链等片段方法，
  全部返回 `[sql, bindings]` 并由 grammar 生成适配引用。
- **`SqlSecurity` 加固**：标识符/方向/正则模式/JSON 路径/聚合与窗口函数/框架边界白名单校验；常量改为 public
  以兼容跨 `Macroable` 作用域调用。
- **study_mysql 特性覆盖**：将 MySQL 8.0+ 文档集中正则/JSON/字符串/窗口/CTE/LATERAL/集合/PIVOT/抽样/随机/分页/
  全文/日期/数学/VALUES/层级树/锁等示例与常见开发瓶颈，系统化归纳并重构成上述标准化宏。
- **全面缺陷修复**：修复闭包内 `self::` 陷阱、私有/保护助手方法被重绑闭包调用、`$this` 进入箭头函数、
  trait 常量跨作用域不可见等问题，并通过 176 个宏的 MySQL/PostgreSQL 双驱动运行时冒烟测试（0 结构性错误）。

### v3.0.0
- 重构：全部 14 个查询宏扩展改为 MySQL 8.0+ / PostgreSQL 15+ 双库通用的规范化宏
  - 新增 `Concerns/Dialect` 方言抽象层，统一封装正则/JSON/字符串/抽样/UPSERT/VALUES 等方言差异片段
  - 所有标识符经 `SqlSecurity` 白名单校验并由 grammar `wrap()` 生成（MySQL 反引号 / PG 双引号），彻底移除硬编码反引号与 MySQL 专属 SQL
  - `REGEXP_LIKE`/`REGEXP_SUBSTR`/`REGEXP_REPLACE`/`REGEXP_INSTR`/`REGEXP_COUNT` 映射为 PG 的 `~`/`regexp_matches`/`regexp_replace`/`strpos`/`regexp_count`
  - JSON 操作统一走 `JSON_CONTAINS`/`JSON_SET`/`JSON_REMOVE`（MySQL）与 `@>`/`jsonb_set`/`jsonb_delete`（PG）
  - `FIND_IN_SET`→`ANY(string_to_array)`、`SUBSTRING_INDEX`→`split_part`+`reverse`、`GROUP_CONCAT`→`string_agg`、`RAND()`→`random()`、`ON DUPLICATE KEY UPDATE`→`ON CONFLICT`、`ROW()`→`()`（或反之）等差异由 Dialect 统一分发
  - 原有 `assertMysql()` 守卫升级为 `assertSupported()`（校验 MySQL 8.0+ / PostgreSQL 15+），不受支持的驱动（如 SQLite）仍会抛出清晰异常

### v2.3.0
- 新增：字符串函数系列（29 个宏）
  - LOCATE 分词查询匹配（whereLocate / whereLocateAll / whereLocateAny）
  - CHAR_LENGTH 多字节长度排序与筛选（orderByCharLength / whereCharLength / orderByNatural 自然排序）
  - FIELD 自定义枚举排序（fieldOrderBy）与 FIND_IN_SET 列表查找
  - CONCAT_WS 跨字段拼接搜索（whereConcatLike）与 SUBSTRING_INDEX 字段提取
  - GROUP_CONCAT 行转字符串聚合
  - MATCH...AGAINST 全文检索（自然语言 / 布尔模式 / 查询扩展 / 相关度排序）
  - SOUNDEX 发音相似匹配与 REPLACE/TRIM/LOWER/UPPER/REVERSE/LPAD/RPAD 字符串变换
- 全部接口经 `SqlSecurity` 白名单校验 + 字面值参数绑定 + `Dialect` 方言抽象（MySQL 8.0+ / PostgreSQL 15+ 双库通用）

### v2.1.0
- 安全：引入 `SqlSecurity` 公共工具层，所有宏方法消除 SQL 注入点
  - 列名 / 别名 / 排序方向 / 正则模式 / JSON 路径 / 聚合函数 / 框架边界全部白名单校验
  - 标识符统一经 grammar wrap（适配 MySQL 8.0+ / PostgreSQL 15+）
  - 字面值（正则、JSON 默认、LIKE、种子）全部参数绑定
  - 方言差异统一由 `Dialect` 抽象层处理（双库通用）

### v2.0.0
- 新增：窗口函数系列（25+ 函数）
- 新增：分页优化系列（5 种策略）
- 新增：JSON 操作系列（20+ 函数）
- 新增：正则表达式系列（15+ 函数）
- 优化：Laravel 11+ 兼容性
- 移除：缓存相关功能（遵循纯 SQL 优化原则）
