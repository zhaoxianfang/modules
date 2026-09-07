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
4. **驱动守卫**：依赖 MySQL 专属语法（`REGEXP_LIKE` / `JSON_EXTRACT` / `VALUES ROW` /
   `ON DUPLICATE KEY UPDATE` / `LATERAL` / `WITH RECURSIVE` / `TABLESAMPLE` 等）的宏入口均加
   `assertMysql()` 守卫，在非 MySQL 驱动上调用会抛出清晰异常，而非生成非法 SQL。
5. **默认最小权限**：未提供显式 MySQL 版本要求时，多数宏最低兼容 MySQL 8.0+（非文档示例中的 8.4）。

> ⚠️ 调用方责任：传入的 `column` / `path` / `alias` 等参数虽然经过白名单校验，但仍应仅接受
> **可信或已校验的字段名**（如来自模型属性、白名单映射），避免把攻击者可控的任意字符串作为字段名传入。

---

## 更新日志

### v2.3.0
- 新增：字符串函数系列（29 个宏）
  - LOCATE 分词查询匹配（whereLocate / whereLocateAll / whereLocateAny）
  - CHAR_LENGTH 多字节长度排序与筛选（orderByCharLength / whereCharLength / orderByNatural 自然排序）
  - FIELD 自定义枚举排序（fieldOrderBy）与 FIND_IN_SET 列表查找
  - CONCAT_WS 跨字段拼接搜索（whereConcatLike）与 SUBSTRING_INDEX 字段提取
  - GROUP_CONCAT 行转字符串聚合
  - MATCH...AGAINST 全文检索（自然语言 / 布尔模式 / 查询扩展 / 相关度排序）
  - SOUNDEX 发音相似匹配与 REPLACE/TRIM/LOWER/UPPER/REVERSE/LPAD/RPAD 字符串变换
- 全部接口经 `SqlSecurity` 白名单校验 + 字面值参数绑定 + `assertMysql()` 驱动守卫

### v2.1.0
- 安全：引入 `SqlSecurity` 公共工具层，所有宏方法消除 SQL 注入点
  - 列名 / 别名 / 排序方向 / 正则模式 / JSON 路径 / 聚合函数 / 框架边界全部白名单校验
  - 标识符统一经 grammar wrap（跨 MySQL / PostgreSQL / SQLite）
  - 字面值（正则、JSON 默认、LIKE、种子）全部参数绑定
  - MySQL 专属语法入口加 `assertMysql()` 驱动守卫

### v2.0.0
- 新增：窗口函数系列（25+ 函数）
- 新增：分页优化系列（5 种策略）
- 新增：JSON 操作系列（20+ 函数）
- 新增：正则表达式系列（15+ 函数）
- 优化：Laravel 11+ 兼容性
- 移除：缓存相关功能（遵循纯 SQL 优化原则）
