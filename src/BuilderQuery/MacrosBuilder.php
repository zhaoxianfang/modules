<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery;

use Illuminate\Database\Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\ServiceProvider;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;
use zxf\Modules\BuilderQuery\Concerns\Dialect;
use zxf\Modules\BuilderQuery\Concerns\SqlSecurity;
use zxf\Modules\BuilderQuery\WhereHasMacros\WhereHasCrossJoin;
use zxf\Modules\BuilderQuery\WhereHasMacros\WhereHasIn;
use zxf\Modules\BuilderQuery\WhereHasMacros\WhereHasJoin;
use zxf\Modules\BuilderQuery\WhereHasMacros\WhereHasLeftJoin;
use zxf\Modules\BuilderQuery\WhereHasMacros\WhereHasMorphIn;
use zxf\Modules\BuilderQuery\WhereHasMacros\WhereHasNotIn;
use zxf\Modules\BuilderQuery\WhereHasMacros\WhereHasRightJoin;
use zxf\Modules\BuilderQuery\WindowMacros\AdvancedJsonMacro;
use zxf\Modules\BuilderQuery\WindowMacros\ChunkedListMacro;
use zxf\Modules\BuilderQuery\WindowMacros\ConditionalAggregateMacro;
use zxf\Modules\BuilderQuery\WindowMacros\DateTimeMacro;
use zxf\Modules\BuilderQuery\WindowMacros\FastCountMacro;
use zxf\Modules\BuilderQuery\WindowMacros\FastPaginationMacro;
use zxf\Modules\BuilderQuery\WindowMacros\FullTextMacro;
use zxf\Modules\BuilderQuery\WindowMacros\GroupSortMacro;
use zxf\Modules\BuilderQuery\WindowMacros\IndexHintMacro;
use zxf\Modules\BuilderQuery\WindowMacros\LateralJoinMacro;
use zxf\Modules\BuilderQuery\WindowMacros\LockingMacro;
use zxf\Modules\BuilderQuery\WindowMacros\MathMacro;
use zxf\Modules\BuilderQuery\WindowMacros\PivotMacro;
use zxf\Modules\BuilderQuery\WindowMacros\QualifyMacro;
use zxf\Modules\BuilderQuery\WindowMacros\QueryGuardMacro;
use zxf\Modules\BuilderQuery\WindowMacros\RandomMacro;
use zxf\Modules\BuilderQuery\WindowMacros\RegexMacro;
use zxf\Modules\BuilderQuery\WindowMacros\SafeWriteMacro;
use zxf\Modules\BuilderQuery\WindowMacros\SearchRelevanceMacro;
use zxf\Modules\BuilderQuery\WindowMacros\SelectColumnMacro;
use zxf\Modules\BuilderQuery\WindowMacros\SetOperationsMacro;
use zxf\Modules\BuilderQuery\WindowMacros\StringFunctionsMacro;
use zxf\Modules\BuilderQuery\WindowMacros\TableSampleMacro;
use zxf\Modules\BuilderQuery\WindowMacros\TopNMacro;
use zxf\Modules\BuilderQuery\WindowMacros\ValuesMacro;
use zxf\Modules\BuilderQuery\WindowMacros\WindowFunctionsMacro;
use zxf\Modules\BuilderQuery\WindowMacros\WithRecursiveMacro;

/**
 * Macros 宏定义构建器 - Laravel 11+ / 12+ / 13+ & MySQL 8.0+ / PostgreSQL 15+ 通用版
 * 提供 30 大类查询宏扩展（v3.3.0 持续深化：新增超大 IN/NOT IN 分块、大表计数加速、
 * 防失控写入/删除护栏等性能与稳定性类宏）。所有宏模块均继承
 * {@see BaseMacro}，通过 {@see Dialect}
 * 方言抽象层生成适配当前数据库驱动（MySQL 8.0+ 反引号 / PostgreSQL 15+ 双引号）的 SQL，
 * 字面值一律走参数绑定，标识符经白名单校验，彻底消除 SQL 注入与方言耦合；并通过
 * {@see BaseMacro::define()} 将宏同时注册到
 * Eloquent\Builder 与 Query\Builder，确保 `Model::query()` 与 `DB::table()` 双友好。
 * 宏分类（按功能用途与场景）：
 * 1. whereHas优化 - 解决关联查询全表扫描问题
 * 2. 随机查询 - 高效随机数据获取
 * 3. 分组排序 - 窗口函数分组排名（每组 Top-N）
 * 4. 递归查询 - 树形结构数据处理（WITH RECURSIVE）
 * 5. 窗口函数 - 排名/偏移/聚合/框架窗口
 * 6. 分页优化 - 超大表快速/游标/寻址分页
 * 7. JSON操作 - 高级JSON查询（JSON_CONTAINS / @> 等）
 * 8. 正则表达式 - 文本匹配功能（REGEXP_LIKE / ~ 等）
 * 9. 主表字段 - 自动表前缀
 * 10. 集合操作 - INTERSECT/EXCEPT 支持
 * 11. QUALIFY过滤 - 窗口函数结果过滤
 * 12. LATERAL JOIN - 横向连接查询
 * 13. 行列转换 - PIVOT/UNPIVOT 透视表
 * 14. 数据抽样 - 随机/分层/系统抽样
 * 15. VALUES构造 - 批量插入和 UPSERT
 * 16. 字符串函数 - LOCATE分词/CHAR_LENGTH排序/列表查找/拼接搜索/提取/聚合/变换/SOUNDEX
 * 17. 全文检索 - MATCH...AGAINST / tsvector（独立模块，单一职责）
 * 18. 日期时间 - 时间分桶/索引友好范围/时段重叠冲突检测
 * 19. 数值运算 - NULL安全比较/夹紧/多级兜底/加权排序/精度控制
 * 20. 并发与行锁 - SKIP LOCKED / NOWAIT / 共享锁 / 咨询锁
 * 21. 相关度搜索 - 无索引 LOCATE 兜底检索与综合打分
 * 22. 向量相似度 - Laravel 13+ 原生向量/语义检索（Eloquent 桥接 + Query 原生）
 * 23. 索引与优化器提示 - USE/FORCE/IGNORE INDEX、优化器提示、执行时限、STRAIGHT_JOIN（性能调优入口）
 * 24. 查询守卫与诊断 - LIMIT 软/硬守卫、EXPLAIN / EXPLAIN ANALYZE（防失控 + 慢查询定位）
 * 25. 分组 Top-N - 每组最新/最大/前 N 条（窗口函数去重，替代 GROUP BY 丢字段/关联子查询）
 * 26. 条件聚合 - SUM/COUNT/AVG/MAX/MIN 的 IF 变体（单条 SQL 多维统计，替代 N+1）
 * 27. 列选择纪律 - selectOnly / selectExcept / selectPrimaryKey（消除 SELECT *，降 I/O 与内存）
 * 28. 超大 IN/NOT IN 分块 - whereInChunked / whereNotInChunked（规避占位符上限/优化器退化/内存暴涨，防卡死）
 * 29. 大表计数加速 - approximateCount（无过滤走数据字典 O(1) 估算，有过滤回退精确 COUNT，防大屏超时）
 * 30. 防失控写入/删除护栏 - mustHaveWhere 写前闸门 + deleteInChunks 按主键分批安全删除（防全表误操作/锁表）
 *
 * @version 1.13.9
 *
 * @requires PHP 8.2+（兼容 8.3/8.4/8.5）, Laravel 11+ / 12+ / 13+, MySQL 8.0+ / PostgreSQL 15+
 * ============================================
 * 1. whereHas 优化系列 - 解决关联查询性能问题
 * ============================================
 *
 * @method $this whereHasIn(string $relation, ?\Closure $callable = null) 使用IN子查询优化whereHas，避免全表扫描，适用于关联查询筛选
 * @method $this orWhereHasIn(string $relation, ?\Closure $callable = null) OR条件的whereHasIn，用于组合查询中的关联筛选
 * @method $this whereHasNotIn(string $relation, ?\Closure $callable = null) 使用NOT IN子查询，查找不满足关联条件的记录
 * @method $this orWhereHasNotIn(string $relation, ?\Closure $callable = null) OR条件的whereHasNotIn
 * @method $this whereHasJoin(string $relation, ?\Closure $callable = null) 使用INNER JOIN方式优化关联查询，适合需要关联表字段的场景
 * @method $this whereHasCrossJoin(string $relation, ?\Closure $callable = null) 使用CROSS JOIN方式关联查询
 * @method $this whereHasLeftJoin(string $relation, ?\Closure $callable = null) 使用LEFT JOIN方式关联查询，保留主表所有记录
 * @method $this whereHasRightJoin(string $relation, ?\Closure $callable = null) 使用RIGHT JOIN方式关联查询
 * @method $this whereHasMorphIn(string $relation, $types, ?\Closure $callable = null) 多态关联的IN查询优化
 * @method $this orWhereHasMorphIn(string $relation, $types, ?\Closure $callable = null) OR条件的多态关联IN查询
 *                                                                                       ============================================
 *                                                                                       2. 主表字段自动前缀系列 - 避免字段歧义
 *                                                                                       ============================================
 * @method $this mainWhere(string $column, mixed $operator = null, mixed $value = null) 主表字段WHERE条件，自动添加表前缀避免关联字段歧义
 * @method $this mainWhereIn(string $column, array $values) 主表字段IN查询，自动添加表前缀
 * @method $this mainWhereBetween(string $column, array $values) 主表字段BETWEEN查询，自动添加表前缀
 * @method $this mainOrderBy(string $column, string $direction = 'asc') 主表字段排序，自动添加表前缀
 * @method $this mainOrderByDesc(string $column) 主表字段降序排序，自动添加表前缀
 * @method $this mainSum(string $column) 主表字段求和，自动添加表前缀
 * @method $this mainPluck(string $column) 主表单字段查询，自动添加表前缀
 * @method $this mainSelect(array|string $columns) 主表字段选择，自动为所有字段添加表前缀
 *                                                 ============================================
 *                                                 3. 随机查询系列 - 高效随机数据获取
 *                                                 ============================================
 * @method $this random(int $limit = 10, string $primaryKey = 'id') 随机查询指定数量记录，使用窗口函数ROW_NUMBER优化性能
 * @method $this groupRandom(string $groupColumn, int $limit = 10, string $primaryKey = 'id') 分组随机查询，每组随机抽取指定数量记录
 *                                                                                            ============================================
 *                                                                                            4. 分组排序系列 - 窗口函数分组排名查询
 *                                                                                            ============================================
 * @method $this groupSort(string $groupBy, int|array $ranks, string $orderBy = 'read', string $direction = 'desc') 分组排序查询，获取每组指定排名的记录，如每组前3名
 *                                                                                                                  ============================================
 *                                                                                                                  5. 窗口函数系列 - MySQL 8.0+ / PostgreSQL 15+ 窗口函数支持
 *                                                                                                                  ============================================
 *                                                                                                                  排名函数
 * @method $this rowNumber(string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'row_num') 为每行分配唯一连续序号，常用于分页、排名
 * @method $this rank(string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'rank_num') 排名函数，相同值排名相同，后续排名跳过（如1,2,2,4）
 * @method $this denseRank(string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'dense_rank_num') 密集排名，相同值排名相同，后续排名连续（如1,2,2,3）
 * @method $this percentRank(string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'percent_rank_val') 百分比排名，计算每行的相对排名位置（0-1之间）
 * @method $this ntile(int $buckets, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'bucket_num') 分桶函数，将数据均匀分配到N个桶中（如四分位数）
 *                                                                                                                                                         偏移函数
 * @method $this lag(string $column, int $offset = 1, mixed $default = null, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', ?string $alias = null) 获取当前行之前第N行的值，用于计算环比、同比
 * @method $this lead(string $column, int $offset = 1, mixed $default = null, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', ?string $alias = null) 获取当前行之后第N行的值，用于预测、趋势分析
 * @method $this firstValue(string $column, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', ?string $alias = null) 获取分区第一行的值，用于基准比较
 * @method $this lastValue(string $column, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', ?string $alias = null) 获取分区最后一行的值
 * @method $this nthValue(string $column, int $n, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', ?string $alias = null) 获取分区第N行的值
 *                                                                                                                                                               聚合窗口函数
 * @method $this sumOver(string $column, string|array|null $partitionBy = null, ?string $orderBy = null, string $direction = 'asc', ?string $alias = null) 滑动求和，计算分区累计和
 * @method $this avgOver(string $column, string|array|null $partitionBy = null, ?string $orderBy = null, string $direction = 'asc', ?string $alias = null) 滑动平均，计算分区移动平均
 * @method $this countOver(string $column, string|array|null $partitionBy = null, ?string $orderBy = null, string $direction = 'asc', ?string $alias = null) 滑动计数，计算分区行数
 * @method $this minOver(string $column, string|array|null $partitionBy = null, ?string $orderBy = null, string $direction = 'asc', ?string $alias = null) 滑动最小值
 * @method $this maxOver(string $column, string|array|null $partitionBy = null, ?string $orderBy = null, string $direction = 'asc', ?string $alias = null) 滑动最大值
 *                                                                                                                                                         框架窗口函数
 * @method $this rowsBetween(string $column, string $function, string $start, string $end, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'frame_result') 基于物理行范围的窗口计算
 * @method $this rangeBetween(string $column, string $function, string $start, string $end, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'frame_result') 基于值范围的窗口计算
 * @method $this cumulativeSum(string $column, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'cumulative_sum') 累计求和，从分区开始到当前行
 * @method $this movingAverage(string $column, int $windowSize, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'moving_avg') 移动平均，计算当前行前后N行的平均值
 * @method $this runningTotal(string $column, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'running_total') 运行总计，cumulativeSum的别名
 *                                                                                                                                                                     ============================================
 *                                                                                                                                                                     6. 分页优化系列 - 超大表快速分页
 *                                                                                                                                                                     ============================================
 * @method \Illuminate\Contracts\Pagination\LengthAwarePaginator fastPaginate(int $perPage = 15, ?int $page = null, ?string $primaryKey = null, array $options = []) 智能快速分页，自动选择最优策略（延迟关联/窗口函数）
 * @method \Illuminate\Pagination\Paginator fastSimplePaginate(int $perPage = 15, ?int $page = null, ?string $primaryKey = null, array $options = []) 简单快速分页，不计算总数，适合无限滚动
 * @method \Illuminate\Contracts\Pagination\CursorPaginator cursorPaginate(int $perPage = 15, ?string $cursor = null, string $sortColumn = '', string $direction = 'asc', ?string $primaryKey = null, array $options = []) 游标分页，基于排序值定位，性能最佳
 * @method \Illuminate\Contracts\Pagination\LengthAwarePaginator seekPaginate(int $perPage = 100, ?array $bookmarks = null, int $page = 1, string $sortColumn = '', string $direction = 'asc', ?string $primaryKey = null, array $options = []) 寻址分页，基于书签的深度分页方案
 * @method \Illuminate\Contracts\Pagination\LengthAwarePaginator partitionPaginate(int $perPage = 15, ?int $page = null, ?string $partitionKey = null, array $options = []) 分区表分页，针对MySQL分区表优化
 *                                                                                                                                                                          ============================================
 *                                                                                                                                                                          7. JSON高级操作系列 - MySQL 8.0+ / PostgreSQL 15+ JSON 函数（JSON_CONTAINS / @> / jsonb_set 等）
 *                                                                                                                                                                          ============================================
 *                                                                                                                                                                          JSON路径查询
 * @method $this jsonPath(string $column, string $path, string $alias, mixed $default = null) 使用JSON Path提取嵌套JSON值
 * @method $this jsonExtract(string $column, string $path, string $type = 'string', string $alias = '', mixed $default = null) 提取JSON值并转换为指定类型（int/float/bool/datetime等）
 * @method $this whereJsonPathExists(string $column, string $path, string $boolean = 'and') 筛选JSON Path存在的记录
 * @method $this whereJsonPathNotExists(string $column, string $path, string $boolean = 'and') 筛选JSON Path不存在的记录
 *                                                                                             JSON数组操作
 * @method $this whereJsonArrayContains(string $column, mixed $value, ?string $path = null, string $boolean = 'and') 筛选JSON数组包含指定值的记录
 * @method $this whereJsonArrayContainsAny(string $column, array $values, ?string $path = null, string $boolean = 'and') 筛选JSON数组包含任意指定值的记录
 * @method $this whereJsonArrayContainsAll(string $column, array $values, ?string $path = null, string $boolean = 'and') 筛选JSON数组包含所有指定值的记录
 * @method $this jsonArrayLength(string $column, ?string $path = null, string $alias = 'array_length') 获取JSON数组长度
 * @method $this whereJsonArrayLength(string $column, int $count, string $operator = '=', ?string $path = null, string $boolean = 'and') 按JSON数组长度筛选
 * @method $this appendToJsonArray(string $column, mixed $value, ?string $path = null) 追加值到JSON数组
 * @method $this removeFromJsonArray(string $column, mixed $value, ?string $path = null) 从JSON数组移除值
 *                                                                                       JSON对象操作
 * @method $this setJsonValue(string $column, string $path, mixed $value, bool $insert = false) 设置JSON对象的键值
 * @method $this removeJsonKey(string $column, string|array $paths) 删除JSON对象的键
 * @method $this mergeJson(string $column, array $data, ?string $path = null) 合并JSON对象
 * @method $this jsonKeys(string $column, ?string $path = null, string $alias = 'json_keys') 获取JSON对象的所有键
 *                                                                                           JSON搜索与聚合
 * @method $this jsonSearch(string $column, string $search, string $mode = 'one', string $path = '$', string $alias = 'search_result') 在JSON中搜索值
 * @method $this whereJsonLike(string $column, string $pattern, ?string $path = null, string $boolean = 'and') 按JSON值模糊匹配筛选
 * @method $this jsonArrayAgg(string $column, string $alias = 'json_array', ?string $orderBy = null, string $direction = 'asc') 将多行聚合成JSON数组
 * @method $this jsonObjectAgg(string $keyColumn, string $valueColumn, string $alias = 'json_object') 将多行聚合成JSON对象
 * @method $this jsonRowAgg(array $columns, string $alias = 'json_rows') 将行聚合成JSON对象数组
 *                                                                       ============================================
 *                                                                       8. 正则表达式系列 - MySQL 8.0+ / PostgreSQL 15+ 正则（REGEXP_LIKE / ~ 等）
 *                                                                       ============================================
 *                                                                       正则匹配
 * @method $this whereRegexp(string $column, string $pattern, string $mode = 'c', string $boolean = 'and') 正则表达式匹配筛选，mode支持c(区分大小写)/i(不区分)/m(多行)/n(点匹配换行)
 * @method $this whereNotRegexp(string $column, string $pattern, string $mode = 'c', string $boolean = 'and') 正则表达式不匹配筛选
 * @method $this whereRegexpAny(string $column, array $patterns, string $mode = 'c', string $boolean = 'and') 匹配任意一个正则模式
 *                                                                                                            正则提取与替换
 * @method $this regexpExtract(string $column, string $pattern, int $group = 0, int $occurrence = 1, string $mode = 'c', string $alias = 'extracted') 使用正则提取子串，支持捕获组
 * @method $this regexpExtractAll(string $column, string $pattern, string $mode = 'c', string $alias = 'all_matches') 提取所有匹配项
 * @method $this regexpReplace(string $column, string $pattern, string $replacement, int $occurrence = 0, string $mode = 'c', string $alias = 'replaced') 使用正则替换文本，支持$1/$2引用捕获组
 * @method $this regexpReplaceBatch(string $column, array $replacements, string $mode = 'c', string $alias = 'replaced') 批量正则替换
 *                                                                                                                       正则位置与计数
 * @method $this regexpPosition(string $column, string $pattern, int $occurrence = 1, string $mode = 'c', int $returnOption = 0, string $alias = 'position') 查找正则匹配位置
 * @method $this regexpCount(string $column, string $pattern, string $mode = 'c', string $alias = 'match_count') 统计正则匹配次数
 * @method $this whereRegexpCount(string $column, string $pattern, int $count, string $operator = '=', string $mode = 'c', string $boolean = 'and') 按匹配次数筛选
 *                                                                                                                                                  ============================================
 *                                                                                                                                                  9. 递归查询系列 - 树形结构数据处理
 *                                                                                                                                                  ============================================
 *                                                                                                                                                  层级关系查询（上下级查找）
 * @method $this withAllChildren(int $id, string $pidColumn = 'pid', int $maxDepth = 100, bool $includeSelf = false) 查找指定节点的所有后代子节点，递归向下查询，可包含/排除自身，结果含depth字段
 * @method $this withAllParents(int $id, string $pidColumn = 'pid', int $maxDepth = 100, bool $includeSelf = false) 查找指定节点的所有祖先父节点，递归向上查询直到根节点，结果含depth字段
 * @method $this withNthParent(int $id, int $n, string $pidColumn = 'pid') 查找指定节点的第N级父节点，0级为自己，1级为直接父节点，2级为祖父节点
 * @method $this withNthChildren(int $id, int $n, string $pidColumn = 'pid') 查找指定节点的第N级所有子节点，1级为直接子节点，2级为孙子节点
 *                                                                           路径查询（面包屑、导航）
 * @method $this withFullPath(array $ids = [], array $conditions = [], string $pidColumn = 'pid', string $nameColumn = 'name', string $pathSeparator = ' > ') 查找节点的完整路径，返回absolute_path(名称路径)、path_ids(ID链)、depth(深度)字段
 * @method $this withBreadcrumbs(int $id, string $pidColumn = 'pid', string $nameColumn = 'name') 获取指定节点的面包屑路径（从根到该节点的祖先链），按层级升序排列
 * @method $this withPathLength(int $id, string $pidColumn = 'pid') 获取指定节点到根节点的路径长度，返回path_length字段表示层级深度（根为0）
 *                                                                  节点关系判断
 * @method bool isParentOf(int $parentId, int $childId, string $pidColumn = 'pid', bool $strict = false) 检查parentId是否是childId的祖先节点，strict=true时只检查直接父节点
 * @method bool isChildOf(int $childId, int $parentId, string $pidColumn = 'pid', bool $strict = false) 检查childId是否是parentId的后代节点，strict=true时只检查直接子节点
 * @method $this withNearestAncestor(int $id1, int $id2, string $pidColumn = 'pid') 查找两个节点的最近公共祖先（LCA），用于判断节点亲缘关系
 *                                                                                  同级节点查询
 * @method $this withSiblings(int $id, string $pidColumn = 'pid', bool $includeSelf = false) 查找指定节点的所有同级节点（兄弟节点），即拥有相同父节点的其他节点
 *                                                                                           树形结构构建
 * @method $this withTree(?int $pid = null, string $pidColumn = 'pid', string $nameColumn = 'name', int $maxDepth = 100, string $pathSeparator = ' > ') 构建完整树形结构，从指定根节点开始递归查询整棵树，返回tree_path、path_ids、depth字段
 * @method $this withRoot(string $pidColumn = 'pid') 查找所有根节点（没有父节点的节点），即pid等于rootValue或为null的记录
 * @method $this withLeafNodes(string $pidColumn = 'pid') 查找所有叶子节点（没有子节点的节点），使用NOT EXISTS子查询实现
 * @method $this withDescendantsCount(int $id, string $pidColumn = 'pid') 获取指定节点的后代节点总数（子孙数量），返回descendants_count字段
 *                                                                        通用递归
 * @method $this recursiveQuery(callable $baseQuery, callable $recursiveQuery, array $columns = ['*'], int $maxDepth = 100, string $depthColumn = 'depth') 通用递归CTE查询（高级用法），自定义基础查询和递归查询回调
 * @method $this resetRecursive() 重置递归查询条件，清除所有递归相关的查询状态和绑定
 *                                ============================================
 *                                10. 集合操作系列 - MySQL 8.0.31+ / PostgreSQL 15+ INTERSECT/EXCEPT（标准 SQL）
 *                                ============================================
 * @method $this intersect(\Closure|Builder $query, bool $all = false) 返回两个查询的交集（INTERSECT）
 * @method $this except(\Closure|Builder $query, bool $all = false) 返回在第一个查询中存在但第二个查询中不存在的记录（EXCEPT）
 * @method $this unionDistinct(\Closure|Builder $query) UNION DISTINCT显式去重的并集
 *                                                      ============================================
 *                                                      11. QUALIFY过滤系列 - MySQL 8.0.33+ / PostgreSQL 15+ 窗口函数结果过滤（通过子查询/派生表模拟）
 *                                                      ============================================
 * @method $this qualify(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and') 过滤窗口函数结果，类似HAVING对聚合的作用
 * @method $this orQualify(string $column, mixed $operator = null, mixed $value = null) OR条件的QUALIFY过滤
 * @method $this qualifyRaw(string $sql, array $bindings = [], string $boolean = 'and') 使用原始SQL的QUALIFY过滤
 * @method $this orQualifyRaw(string $sql, array $bindings = []) OR条件的QUALIFY RAW过滤
 *                                                               ============================================
 *                                                               12. LATERAL JOIN系列 - MySQL 8.0.14+ / PostgreSQL 15+ 横向连接
 *                                                               ============================================
 * @method $this lateralJoin(\Closure $callback, string $alias) LATERAL INNER JOIN，子查询可引用主查询列
 * @method $this lateralLeftJoin(\Closure $callback, string $alias) LATERAL LEFT JOIN，保留主表所有记录
 * @method $this lateralLimit(string $partitionColumn, string $orderColumn, string $direction = 'desc', int $limit = 5, string $alias = 'lateral_limit') 使用LATERAL实现高效Top-N查询
 * @method $this lateralAggregate(string $relationColumn, array $aggregates, ?string $whereColumn = null, mixed $whereValue = null, string $alias = 'agg_result') LATERAL聚合查询，计算行相关的聚合值
 *                                                                                                                                                                ============================================
 *                                                                                                                                                                13. 行列转换(PIVOT)系列 - 数据透视表
 *                                                                                                                                                                ============================================
 * @method $this pivot(string $pivotColumn, array $values, string $aggregateColumn, string $function = 'SUM', string|array|null $groupBy = null) 行转列透视，将某列值转换为多列
 * @method $this pivotCount(string $pivotColumn, array $values, string $aggregateColumn, string|array|null $groupBy = null) COUNT聚合透视快捷方法
 * @method $this pivotSum(string $pivotColumn, array $values, string $aggregateColumn, string|array|null $groupBy = null) SUM聚合透视快捷方法
 * @method $this pivotAvg(string $pivotColumn, array $values, string $aggregateColumn, string|array|null $groupBy = null) AVG聚合透视快捷方法
 * @method $this pivotMax(string $pivotColumn, array $values, string $aggregateColumn, string|array|null $groupBy = null) MAX聚合透视快捷方法
 * @method $this pivotMin(string $pivotColumn, array $values, string $aggregateColumn, string|array|null $groupBy = null) MIN聚合透视快捷方法
 * @method $this unpivot(array $columns, string $nameColumn = 'attribute', string $valueColumn = 'value') 列转行，将多列转换为名称-值对的行
 * @method $this crossTab(string $rowColumn, string $colColumn, string $aggregateColumn, string $function = 'SUM', ?array $colValues = null) 交叉表查询，双维透视生成矩阵式报表
 *                                                                                                                                           ============================================
 *                                                                                                                                           14. 数据抽样系列 - TABLESAMPLE和抽样算法
 *                                                                                                                                           ============================================
 * @method $this sample(float $percentage, string $method = 'random') 表级抽样，按百分比随机或系统抽取样本
 * @method $this randomSample(int $sampleSize, ?string $seed = null) 精确随机抽样指定样本量，支持种子保证可重复性
 * @method $this stratifiedSample(string $stratumColumn, int $samplesPerStratum, ?string $seed = null) 分层抽样，按指定列分层每层抽取固定数量
 * @method $this systematicSample(int $interval, ?int $startOffset = null) 系统抽样（等距抽样），按固定间隔抽取
 *                                                                         ============================================
 *                                                                         15. VALUES构造系列 - 批量操作优化
 *                                                                         ============================================
 * @method $this valuesQuery(array $rows, string $alias = 'val') 使用VALUES ROW构建内存表查询
 * @method $this valuesJoin(array $rows, string $alias, string $localKey, string $valuesKey, string $joinType = 'inner') 使用VALUES作为JOIN表
 * @method int valuesInsert(array $rows, int $chunkSize = 1000) 使用VALUES ROW语法进行高效批量插入
 * @method int batchUpsert(array $rows, array|string $uniqueBy, ?array $updateColumns = null, int $chunkSize = 1000) 批量插入或更新（MySQL: INSERT ... ON DUPLICATE KEY UPDATE / PostgreSQL: INSERT ... ON CONFLICT ... DO UPDATE）
 *                                                                                                                   ============================================
 *                                                                                                                   16. 向量相似度搜索系列 - Laravel 13+ 原生向量/语义检索
 *                                                                                                                   ============================================
 * @method $this whereVectorSimilarTo(string $column, mixed $value, ?float $minSimilarity = null) 按向量相似度筛选记录（需 Laravel 13+ 与向量数据库支持）
 * @method $this orderByVectorDistance(string $column, mixed $value) 按向量距离排序（最近邻检索，升序）
 *                                                                   ============================================
 *                                                                   17. 字符串函数系列 - MySQL 8.0+ / PostgreSQL 15+ 高效字符串处理
 *                                                                   ============================================
 *                                                                   子串位置查找（分词查询匹配）
 * @method $this whereLocate(string $column, string $search, string $boolean = 'and') 使用LOCATE查找子串首次出现位置并筛选命中记录，支持空格分隔的多词分词匹配
 * @method $this orWhereLocate(string $column, string $search) OR条件的whereLocate
 * @method $this whereLocateAll(string $column, array $keywords) 多关键词全命中查询（AND连接），字段须同时包含所有关键词
 * @method $this whereLocateAny(string $column, array $keywords) 多关键词任一命中查询（OR连接），字段包含任意关键词即返回
 *                                                               字符长度排序与筛选（多字节安全）
 * @method $this orderByCharLength(string $column, string $direction = 'asc') 按字段字符数排序（CHAR_LENGTH），中英文均按1字符计
 * @method $this orderByDescCharLength(string $column) 按字段字符数降序排序
 * @method $this whereCharLength(string $column, string $operator, int $length, string $boolean = 'and') 按字段字符长度筛选（如密码长度校验）
 * @method $this orWhereCharLength(string $column, string $operator, int $length) OR条件的字符长度筛选
 * @method $this orderByNatural(string $column, string $direction = 'asc') 自然排序，字符串内嵌数字按数值大小排序（如V1,V2,V10而非V1,V10,V2）
 *                                                                         自定义排序与列表查找
 * @method $this fieldOrderBy(string $column, array $order, string $direction = 'asc') 按FIELD函数自定义值顺序排序（如状态优先级：待支付->已发货）
 * @method $this whereFindInSet(string $column, mixed $value, string $boolean = 'and') 逗号分隔列表字段成员查找（FIND_IN_SET），比LIKE更准确
 * @method $this orWhereFindInSet(string $column, mixed $value) OR条件的列表成员查找
 * @method $this whereFindInSetAny(string $column, array $values) 列表字段包含任意指定值（OR）
 * @method $this whereFindInSetAll(string $column, array $values) 列表字段包含全部指定值（AND）
 *                                                                多列拼接搜索与提取
 * @method $this whereConcatLike(array $columns, string $keyword, string $boolean = 'and') 多列CONCAT_WS拼接后LIKE模糊搜索，实现跨字段一站式检索
 * @method $this orWhereConcatLike(array $columns, string $keyword) OR条件的多列拼接搜索
 * @method $this substringIndex(string $column, string $delimiter, int $count, string $alias = 'part') 按分隔符提取子串（SUBSTRING_INDEX），count正数从左、负数从右
 *                                                                                                     字符串聚合
 * @method $this groupConcat(string $column, string $alias = 'items', string $separator = ',', bool $distinct = false) 行转字符串聚合（GROUP_CONCAT），配合groupBy将多行值拼接
 *                                                                                                                     全文检索（MySQL: MATCH...AGAINST + FULLTEXT 索引 / PostgreSQL: to_tsvector + to_tsquery）
 * @method $this whereFullText(array|string $columns, string $keyword, string $mode = 'natural', string $boolean = 'and') 全文检索筛选，mode: natural自然语言|boolean布尔|expansion查询扩展
 * @method $this whereFullTextBoolean(array|string $columns, string $query, string $boolean = 'and') 布尔模式全文检索，支持+word必须包含/-word排除/"短语"/word*前缀等分词语法
 * @method $this orderByFullTextRelevance(array|string $columns, string $keyword, string $mode = 'natural', string $direction = 'desc') 按全文检索相关度分数排序
 * @method $this selectFullTextScore(array|string $columns, string $keyword, string $alias = 'relevance', string $mode = 'natural') 将相关度分数作为字段返回（供展示/二次排序）
 * @method $this whereFullTextScoreGte(array|string $columns, string $keyword, float $minScore = 1.0, string $mode = 'natural') 按相关度阈值过滤（降噪）
 * @method $this whereFullTextOrLike(array|string $columns, string $keyword, string $boolean = 'and') 两阶段检索：全文索引命中 或 LOCATE 子串兜底
 *                                                                                                    日期时间（时间分桶 / 索引友好范围 / 时段重叠）
 * @method $this dateBucket(string $column, string $unit = 'month', string $alias = 'bucket') 按日/周/月/季/年分桶（自动 select + groupBy）
 * @method $this timeBucket(string $column, int $minutes = 15, string $alias = 'bucket') 按固定 N 分钟分桶（15/30 分钟粒度报表）
 * @method $this whereDateRange(string $column, mixed $start, mixed $end, bool $endInclusive = true, string $boolean = 'and') 索引友好日期范围筛选（列不被函数包裹）
 * @method $this whereTimeOverlap(string $startCol, string $endCol, mixed $startVal, mixed $endVal, string $boolean = 'and') 时段重叠冲突检测（会议室/排期/库存占用）
 *                                                                                                                           数值运算（NULL安全比较 / 夹紧 / 兜底 / 加权 / 精度）
 * @method $this whereNullSafeEqual(string $column, mixed $value, string $boolean = 'and') NULL 安全等值比较（MySQL <=> / PG IS NOT DISTINCT FROM）
 * @method $this selectClamp(string $column, mixed $min, mixed $max, string $alias = 'clamped') 将列值夹紧到 [min,max]（GREATEST+LEAST）
 * @method $this selectCoalesce(array $columns, mixed $default, string $alias = 'value') 多级兜底取值（COALESCE 链）
 * @method $this orderByWeighted(array $items, string $direction = 'desc') 多列按权重线性组合加权排序（搜索综合打分）
 * @method $this selectRound(string $column, int $decimals = 2, bool $truncate = false, string $alias = 'rounded') 四舍五入/截断到指定小数位（ROUND/TRUNCATE）
 *                                                                                                                 并发与行锁（SKIP LOCKED / NOWAIT / 共享锁 / 咨询锁）
 * @method $this lockForUpdateSkipLocked() 加排他行锁并跳过已被锁定的行（SKIP LOCKED）
 * @method $this lockForUpdateNoWait() 加排他行锁，拿不到锁立即失败（NOWAIT）
 * @method $this sharedLockSkipLocked() 加共享锁并跳过已被锁定的行（共享锁 + SKIP LOCKED）
 * @method $this advisoryLock(int|string $key, bool $session = false) 获取咨询锁（MySQL GET_LOCK / PG pg_advisory_lock）
 * @method $this advisoryUnlock(int|string $key) 释放咨询锁（与 advisoryLock 配对）
 *                                               相关度搜索（无全文索引的 LOCATE 兜底检索）
 * @method $this whereRelevance(string $column, string $keyword, string $boolean = 'and') 按子串相关度命中筛选
 * @method $this orderByRelevance(array|string $columns, string $keyword, string $direction = 'desc') 按多列组合相关度排序
 * @method $this selectRelevance(string $column, string $keyword, string $alias = 'relevance') 返回单列相关度分数
 * @method $this searchRank(array|string $columns, string $keyword, string $direction = 'desc') 组合「命中筛选 + 相关度排序」一站式轻量搜索
 * @method $this whereSoundex(string $column, string $value, string $boolean = 'and') 发音相似匹配（SOUNDEX），适用于英文姓名模糊匹配
 * @method $this orWhereSoundex(string $column, string $value) OR条件的发音相似匹配
 * @method $this replaceString(string $column, string $search, string $replace, string $alias = 'replaced') SELECT中替换字符串（REPLACE）
 * @method $this trimString(string $column, string $alias = 'trimmed') SELECT中去除首尾空格（TRIM）
 * @method $this lowerString(string $column, string $alias = 'lower_val') SELECT中转小写（LOWER）
 * @method $this upperString(string $column, string $alias = 'upper_val') SELECT中转大写（UPPER）
 * @method $this reverseString(string $column, string $alias = 'reversed') SELECT中反转字符串（REVERSE）
 * @method $this padString(string $column, int $length, string $padString, string $position = 'left', string $alias = 'padded') SELECT中填充字符串到指定长度（LPAD/RPAD），用于编号格式化
 *                                                                                                                              ===== 23. 索引与优化器提示（性能调优入口）=====
 * @method $this useIndex(string|array $index, string $for = '', ?string $table = null) 建议优化器使用指定索引（MySQL USE INDEX；PG 经 pg_hint_plan IndexScan）
 * @method $this forceIndex(string|array $index, string $for = '', ?string $table = null) 强制优化器使用指定索引（MySQL FORCE INDEX；PG 经 pg_hint_plan）
 * @method $this ignoreIndex(string|array $index, string $for = '', ?string $table = null) 指示优化器忽略指定索引（MySQL IGNORE INDEX；PG 经 pg_hint_plan NoIndexScan）
 * @method $this optimizerHints(string|array $hints) 注入任意 MySQL 优化器提示（包裹在 /*+ ... *\/ 中，幂等仅出现一次）
 * @method $this maxExecutionTime(int $milliseconds) 限制单条查询最大执行毫秒数（性能守卫，防卡死；MySQL 原生 / PG 经 pg_hint_plan）
 * @method $this straightJoin() 强制 MySQL 按 FROM 顺序执行连接（STRAIGHT_JOIN；仅 MySQL 有效）
 *                              ===== 24. 查询守卫与诊断（防失控 + 慢查询定位）=====
 * @method $this withLimitGuard(int $limit = 1000) 软守卫：仅当未设 LIMIT 时补安全上限，避免意外全表返回/扫描
 * @method $this requireLimit(?int $maxAllowed = null) 硬守卫：未设 LIMIT 或超阈值直接拒绝执行（fail-fast）
 * @method mixed explain(?string $format = null) 返回 EXPLAIN 执行计划（诊断慢查询第一抓手）
 * @method mixed explainAnalyze(bool $verbose = false) 返回 EXPLAIN ANALYZE 真实执行计划（含耗时/行数，开销更高）
 *                                                     ===== 25. 分组 Top-N（窗口函数去重）=====
 * @method $this whereTopNPerGroup(string|array $partitionBy, string|array $orderBy, string|array $direction = 'desc', int $n = 1, ?string $keyColumn = null) 仅保留每个分组内排最前的 N 条（每组最新/最大/前 N 条）
 *                                                                                                                                                            ===== 26. 条件聚合（单条 SQL 多维统计）=====
 * @method $this selectSumIf(string $column, string $condition, array $conditionBindings = [], ?string $alias = null) 满足条件时累加（SUM CASE WHEN）
 * @method $this selectCountIf(string $column, string $condition, array $conditionBindings = [], ?string $alias = null) 满足条件时计数（COUNT CASE WHEN）
 * @method $this selectAvgIf(string $column, string $condition, array $conditionBindings = [], ?string $alias = null) 满足条件时求平均（AVG CASE WHEN）
 * @method $this selectMaxIf(string $column, string $condition, array $conditionBindings = [], ?string $alias = null) 满足条件时取最大（MAX CASE WHEN）
 * @method $this selectMinIf(string $column, string $condition, array $conditionBindings = [], ?string $alias = null) 满足条件时取最小（MIN CASE WHEN）
 *                                                                                                                    ===== 27. 列选择纪律（消除 SELECT *）=====
 * @method $this selectOnly(string|array $columns) 只保留指定列（精确投影，替代 SELECT *）
 * @method $this selectExcept(string|array $columns) 从当前显式列中剔除指定列（需先显式 select）
 * @method $this selectPrimaryKey() 只取主键列（轻量投影，用于仅取 ID/关联键）
 *
 * @scenario Macros 宏定义构建器 - Laravel 11+ / 12+ / 13+ & MySQL 8.0+ / PostgreSQL 15+ 通用版
 */
class MacrosBuilder extends Builder
{
    use SqlSecurity;

    /**
     * 宏注册幂等守卫：register() 只需执行一次。
     * 服务提供者可能在同一进程内被多次 boot（如测试、容器重建、多个 Provider 串联），
     * 若每次都重新 register 约 60 个宏，虽被 Macroable 覆盖、不报错，但重复构造闭包、重复
     * 注册 Eloquent/Query 双构建器，徒增启动开销。本标志位确保整组宏仅注册一次。
     *
     * @scenario 服务提供者可能在同一进程内被多次 boot（如测试、容器重建、多个 Provider 串联），
     */
    private static bool $registered = false;

    /**
     * 注册所有宏指令
     * 按照功能模块分组注册，便于管理和维护。
     * 自带幂等守卫：同一进程内重复调用直接返回，避免重复注册带来的启动开销。
     *
     * @param  ServiceProvider  $provider  服务提供者实例
     *
     * @scenario 注册所有宏指令
     */
    public static function register(ServiceProvider $provider): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        // 1. whereHas 查询优化系列 - 解决关联查询全表扫描问题
        self::registerWhereHasInQuery($provider);

        // 2. 随机查询系列 - 高效随机数据获取
        RandomMacro::register();

        // 3. 分组排序系列 - 窗口函数分组排序
        GroupSortMacro::register();

        // 4. 递归查询系列 - 树形结构数据处理
        WithRecursiveMacro::register();

        // 5. 窗口函数系列 - MySQL 8.0+ / PostgreSQL 15+ 排名、偏移、聚合窗口函数
        WindowFunctionsMacro::register();

        // 6. 超大表分页优化系列 - 深度分页性能优化
        FastPaginationMacro::register();

        // 7. JSON 高级操作系列 - MySQL 8.0+ / PostgreSQL 15+ JSON 函数
        AdvancedJsonMacro::register();

        // 8. 正则表达式系列 - 强大的文本匹配功能（REGEXP_LIKE / ~ 等）
        RegexMacro::register();

        // 9. 集合操作系列 - INTERSECT/EXCEPT（MySQL 8.0.31+ / PostgreSQL 15+ 标准 SQL）
        SetOperationsMacro::register();

        // 10. QUALIFY 过滤系列 - 窗口函数结果过滤（MySQL 8.0.33+ / PostgreSQL 15+ 子查询模拟）
        QualifyMacro::register();

        // 11. LATERAL JOIN 系列 - 横向连接（MySQL 8.0.14+ / PostgreSQL 15+）
        LateralJoinMacro::register();

        // 12. 行列转换系列 - PIVOT/UNPIVOT 透视表
        PivotMacro::register();

        // 13. 数据抽样系列 - 随机/分层/系统抽样
        TableSampleMacro::register();

        // 14. VALUES 构造系列 - 批量插入和 UPSERT
        ValuesMacro::register();

        // 15. 字符串函数系列 - LOCATE 分词 / CHAR_LENGTH 排序 / 列表查找 / 拼接搜索 / 提取 / 聚合 / 变换 / SOUNDEX
        StringFunctionsMacro::register();

        // 16. 全文检索系列 - 独立模块（单一职责），MATCH...AGAINST / tsvector 通用封装
        FullTextMacro::register();

        // 17. 日期时间系列 - 时间分桶 / 索引友好范围 / 时段重叠冲突检测
        DateTimeMacro::register();

        // 18. 数值运算系列 - NULL 安全比较 / 夹紧 / 多级兜底 / 加权排序 / 精度控制
        MathMacro::register();

        // 19. 并发与行锁系列 - SKIP LOCKED / NOWAIT / 共享锁 / 咨询锁
        LockingMacro::register();

        // 20. 相关度搜索系列 - 无全文索引的 LOCATE 兜底检索与综合打分
        SearchRelevanceMacro::register();

        // 23. 索引与优化器提示系列 - USE/FORCE/IGNORE INDEX、优化器提示、执行时限、STRAIGHT_JOIN
        IndexHintMacro::register();

        // 24. 查询守卫与诊断系列 - LIMIT 软/硬守卫、EXPLAIN / EXPLAIN ANALYZE
        QueryGuardMacro::register();

        // 25. 分组 Top-N 系列 - 每组最新/最大/前 N 条（窗口函数去重）
        TopNMacro::register();

        // 26. 条件聚合系列 - SUM/COUNT/AVG/MAX/MIN 的 IF 变体（单条 SQL 多维统计）
        ConditionalAggregateMacro::register();

        // 27. 列选择纪律系列 - selectOnly / selectExcept / selectPrimaryKey（消除 SELECT *）
        SelectColumnMacro::register();

        // 28. 超大 IN/NOT IN 列表分块系列 - whereInChunked / whereNotInChunked（规避占位符上限/优化器退化/内存暴涨）
        ChunkedListMacro::register();

        // 29. 大表计数加速系列 - approximateCount（无过滤走数据字典 O(1) 估算，有过滤回退精确 COUNT）
        FastCountMacro::register();

        // 30. 防失控写入/删除护栏系列 - mustHaveWhere 写前闸门 + deleteInChunks 按主键分批安全删除
        SafeWriteMacro::register();

        // 22. 向量相似度搜索系列 - Laravel 13+ 原生向量/语义检索支持（双构建器友好）
        self::registerVectorSearch($provider);
    }

    /**
     * 注册 Laravel 13+ 向量相似度搜索宏（Eloquent 桥接；Query\Builder 走原生方法）
     * Laravel 13 在 Query\Builder 底层已提供原生向量检索方法（如 PostgreSQL + pgvector 的
     * whereVectorSimilarTo / orderByVectorDistance），因此 `DB::table()` 路径可直接调用原生方法，
     * 无需宏桥接；本模块仅针对 Eloquent\Builder 做桥接，使 `Model::query()` 亦可使用向量检索。
     * 这样既满足「ORM 与 DB 双友好」，又避免同名宏覆盖原生方法导致递归。
     * 注意：该能力依赖底层数据库驱动与 Laravel 13+ 的查询语法，
     * 在 Laravel 11/12 或不支持向量的数据库上调用会抛出异常，属预期行为。
     *
     * @scenario 注册 Laravel 13+ 向量相似度搜索宏（Eloquent 桥接；Query\Builder 走原生方法）
     */
    public static function registerVectorSearch(ServiceProvider $provider): void
    {
        /**
         * whereVectorSimilarTo —— 按向量相似度筛选（语义/近邻匹配），桥接 Laravel 13 原生向量检索。
         * 底层依赖数据库向量能力（如 PostgreSQL + pgvector）。当表存在向量列（embedding）时，
         * 本宏可在 Eloquent 查询中以「相似度阈值」做过滤，常用于 RAG 召回、语义搜索、推荐相似度等场景。
         * 列名会先经 {@see MacrosBuilder::assertValidIdentifier()} 校验，防止列名注入。
         * 设计要点：仅针对 Eloquent\Builder 注册宏；Query\Builder（即 `DB::table()` 路径）直接使用
         * Laravel 13 原生方法，避免同名宏覆盖原生方法引发递归。当调用方未显式传入 $minSimilarity 时，
         * 不向下透传该参数，让框架使用默认阈值 0.6，避免传入 null 造成 `1 - null` 的语义偏差。
         *
         * @param  string  $column  向量列名（标识符，经白名单校验）
         * @param  mixed  $value  查询向量（数组或可序列化为向量的格式，依底层驱动而定）
         * @param  float|null  $minSimilarity  最小相似度阈值，null 表示使用框架默认 0.6
         * @return Builder
         *
         * @example
         * // 基于向量列的语义相似度召回（使用框架默认阈值 0.6）
         * $vec = [0.12, -0.34, 0.78, /* ... *\/];
         * Post::query()->whereVectorSimilarTo('embedding', $vec)->get();
         * // 显式提高相似度门槛，仅召回高度相关结果
         * Post::query()->whereVectorSimilarTo('embedding', $vec, 0.85)->get();
         *
         * @scenario 语义搜索 / 相似文档推荐 / 向量库近邻检索。需底层数据库与 Laravel 13+ 支持向量类型，
         *           否则运行期会抛出异常（属预期行为）。
         */
        Builder::macro('whereVectorSimilarTo', function (string $column, $value, ?float $minSimilarity = null) {
            // 校验列名标识符（与其他宏一致，防止列名注入）
            $column = MacrosBuilder::assertValidIdentifier($column, 'column');

            $query = $this->getQuery();

            if ($minSimilarity === null) {
                return $query->whereVectorSimilarTo($column, $value);
            }

            return $query->whereVectorSimilarTo($column, $value, $minSimilarity);
        });

        /**
         * orderByVectorDistance —— 按向量距离升序排序（最近邻检索排序）。
         * 与 whereVectorSimilarTo 配套使用，将结果按「与给定向量的距离」由近到远排序，
         * 实现 K 近邻（KNN）检索。仅走升序（最近邻优先）；底层 Laravel 13 原生方法不接收排序方向参数，
         * 故此处仅透传列名与向量值。列名经 {@see MacrosBuilder::assertValidIdentifier()} 校验。
         *
         * @param  string  $column  向量列名（标识符，经白名单校验）
         * @param  mixed  $value  排序基准向量
         * @return Builder
         *
         * @example
         * // 召回与给定向量最相近的 10 条记录（先按距离排序，再取前 N）
         * $vec = [0.12, -0.34, 0.78, /* ... *\/];
         * Post::query()
         *     ->whereVectorSimilarTo('embedding', $vec)
         *     ->orderByVectorDistance('embedding', $vec)
         *     ->limit(10)
         *     ->get();
         *
         * @scenario 向量相似度结果集的排序 / 最近邻排序分页。需底层数据库与 Laravel 13+ 支持向量类型。
         */
        Builder::macro('orderByVectorDistance', function (string $column, $value) {
            // 校验列名标识符
            $column = MacrosBuilder::assertValidIdentifier($column, 'column');

            return $this->getQuery()->orderByVectorDistance($column, $value);
        });
    }

    public static function registerWhereHasInQuery(ServiceProvider $provider): void
    {
        /**
         * whereHasIn —— 用 IN 子查询优化关联筛选，避免 whereHas 默认产生的全表 EXISTS 扫描。
         * 适用：关联筛选（如「存在有效订单的用户」）。相比原生 whereHas，本宏生成
         * `WHERE 主表.主键 IN (SELECT 关联表.外键 ...)` 子查询，常能命中索引、显著降低扫描行数。
         *
         * @param  string  $relationName  关联名（支持点号嵌套，如 'orders.items'）
         * @param  \Closure|null  $callable  关联查询约束闭包（null 表示仅判断关联是否存在）
         * @return Builder
         *
         * @example
         * // 查询「存在已支付订单」的用户（等价于 whereHas 但走 IN 子查询）
         * User::query()->whereHasIn('orders', fn ($q) => $q->where('status', 'paid'))->get();
         *
         * @scenario 关联筛选 + 大表关联场景；如关联表很小、或需要关联表字段排序/聚合，请用 whereHasJoin 系列。
         */
        Builder::macro('whereHasIn', function ($relationName, $callable = null) {
            return (new WhereHasIn($this, $relationName, function ($nextRelation, $builder) use ($callable) {
                if ($nextRelation) {
                    return $builder->whereHasIn($nextRelation, $callable);
                }
                if ($callable) {
                    return $builder->callScope($callable);
                }

                return $builder;
            }))->execute();
        });

        /**
         * whereHasNotIn —— 用 NOT IN 子查询筛选「不满足关联条件」的记录（关联反选）。
         *
         * @param  string  $relationName  关联名（支持点号嵌套）
         * @param  \Closure|null  $callable  关联查询约束闭包
         * @return Builder
         *
         * @example
         * // 查询「没有待处理订单」的用户
         * User::query()->whereHasNotIn('orders', fn ($q) => $q->where('status', 'pending'))->get();
         *
         * @scenario 黑名单式关联反选；超大关联表建议改用 NOT EXISTS 以获得更优执行计划。
         */
        Builder::macro('whereHasNotIn', function ($relationName, $callable = null) {
            return (new WhereHasNotIn($this, $relationName, function ($nextRelation, $builder) use ($callable) {
                if ($nextRelation) {
                    return $builder->whereHasNotIn($nextRelation, $callable);
                }

                if ($callable) {
                    return $builder->callScope($callable);
                }

                return $builder;
            }))->execute();
        });

        /**
         * whereHasJoin —— 用 INNER JOIN 优化关联筛选，并可直接使用关联表字段。
         * 适用：需要「按关联表字段排序 / 聚合 / 取关联表列」的关联筛选。内部自动去重
         * （同一次链式调用内对同一关联仅 JOIN 一次），避免重复 JOIN 导致结果翻倍。
         *
         * @param  string  $relationName  关联名（支持点号嵌套）
         * @param  \Closure|null  $callable  关联查询约束闭包（接收关系构建器，可追加 where 等）
         * @return Builder
         *
         * @example
         * // 查询有订单的用户，并按订单金额排序
         * User::query()
         *     ->whereHasJoin('orders', fn ($q) => $q->where('status', 'paid'))
         *     ->orderByDesc('orders.amount')
         *     ->get();
         *
         * @scenario 关联筛选 + 需要关联表字段/排序/聚合；关联为「一对多」会产生主表重复行，需配合 distinct/count 处理。
         */
        Builder::macro('whereHasJoin', function ($relationName, $callable = null) {
            // $self 捕获主构建器：嵌套关联（'a.b'）的下一级 JOIN 必须加在【主查询】上，
            // 因为 JOIN 无法像 where 条件那样通过 addNestedWhereQuery 内嵌进子查询。
            $self = $this;

            return (new WhereHasJoin($this, $relationName, function ($nextRelation, Builder $relationBuilder) use ($callable, $self) {
                if ($nextRelation) {
                    return $self->whereHasJoin($nextRelation, $callable);
                }

                if ($callable) {
                    $relationBuilder->callScope($callable);
                }

                return $relationBuilder;
            }))->execute();
        });

        /**
         * whereHasCrossJoin —— 用 CROSS JOIN（笛卡尔积）关联查询。
         * 适用：需要关联表每一行都参与匹配的特殊场景（如与配置/字典表全量组合）。一般业务慎用。
         *
         * @param  string  $relationName
         * @param  \Closure|null  $callable
         * @return Builder
         *
         * @example
         * // 与标签字典做交叉组合
         * Product::query()->whereHasCrossJoin('tags', fn ($q) => $q->where('enabled', 1))->get();
         *
         * @scenario whereHasCrossJoin —— 用 CROSS JOIN（笛卡尔积）关联查询。
         */
        Builder::macro('whereHasCrossJoin', function ($relationName, $callable = null) {
            // $self 捕获主构建器：嵌套关联（'a.b'）的下一级 JOIN 必须加在【主查询】上，
            // 因为 JOIN 无法像 where 条件那样通过 addNestedWhereQuery 内嵌进子查询。
            $self = $this;

            return (new WhereHasCrossJoin($this, $relationName, function ($nextRelation, Builder $relationBuilder) use ($callable, $self) {
                if ($nextRelation) {
                    return $self->whereHasCrossJoin($nextRelation, $callable);
                }

                if ($callable) {
                    $relationBuilder->callScope($callable);
                }

                return $relationBuilder;
            }))->execute();
        });

        /**
         * whereHasLeftJoin —— 用 LEFT JOIN 关联查询，保留主表全部记录（即使关联不匹配）。
         *
         * @param  string  $relationName
         * @param  \Closure|null  $callable
         * @return Builder
         *
         * @example
         * // 列出全部用户，并筛选出「有已支付订单」的用户（未匹配者也保留）
         * User::query()->whereHasLeftJoin('orders', fn ($q) => $q->where('status', 'paid'))->get();
         *
         * @scenario whereHasLeftJoin —— 用 LEFT JOIN 关联查询，保留主表全部记录（即使关联不匹配）。
         */
        Builder::macro('whereHasLeftJoin', function ($relationName, $callable = null) {
            // $self 捕获主构建器：嵌套关联（'a.b'）的下一级 JOIN 必须加在【主查询】上，
            // 因为 JOIN 无法像 where 条件那样通过 addNestedWhereQuery 内嵌进子查询。
            $self = $this;

            return (new WhereHasLeftJoin($this, $relationName, function ($nextRelation, Builder $relationBuilder) use ($callable, $self) {
                if ($nextRelation) {
                    return $self->whereHasLeftJoin($nextRelation, $callable);
                }

                if ($callable) {
                    $relationBuilder->callScope($callable);
                }

                return $relationBuilder;
            }))->execute();
        });

        /**
         * whereHasRightJoin —— 用 RIGHT JOIN 关联查询（以关联表为主，保留关联表全部记录）。
         *
         * @param  string  $relationName
         * @param  \Closure|null  $callable
         * @return Builder
         *
         * @example
         * // 以订单为主，反向关联用户
         * Order::query()->whereHasRightJoin('user', fn ($q) => $q->where('active', 1))->get();
         *
         * @scenario whereHasRightJoin —— 用 RIGHT JOIN 关联查询（以关联表为主，保留关联表全部记录）。
         */
        Builder::macro('whereHasRightJoin', function ($relationName, $callable = null) {
            // $self 捕获主构建器：嵌套关联（'a.b'）的下一级 JOIN 必须加在【主查询】上，
            // 因为 JOIN 无法像 where 条件那样通过 addNestedWhereQuery 内嵌进子查询。
            $self = $this;

            return (new WhereHasRightJoin($this, $relationName, function ($nextRelation, Builder $relationBuilder) use ($callable, $self) {
                if ($nextRelation) {
                    return $self->whereHasRightJoin($nextRelation, $callable);
                }

                if ($callable) {
                    $relationBuilder->callScope($callable);
                }

                return $relationBuilder;
            }))->execute();
        });

        /**
         * orWhereHasIn —— 以 OR 条件追加 whereHasIn 关联筛选。
         *
         * @param  string  $relationName
         * @param  \Closure|null  $callable
         * @return Builder
         *
         * @example
         * // 有已支付订单 OR 有有效优惠券
         * User::query()
         *     ->whereHasIn('orders', fn ($q) => $q->where('status', 'paid'))
         *     ->orWhereHasIn('coupons', fn ($q) => $q->where('valid', 1))
         *     ->get();
         *
         * @scenario orWhereHasIn —— 以 OR 条件追加 whereHasIn 关联筛选。
         */
        Builder::macro('orWhereHasIn', function ($relationName, $callable = null) {
            return $this->orWhere(function ($query) use ($relationName, $callable) {
                return $query->whereHasIn($relationName, $callable);
            });
        });

        /**
         * orWhereHasNotIn —— 以 OR 条件追加 whereHasNotIn 关联反选。
         *
         * @param  string  $relationName
         * @param  \Closure|null  $callable
         * @return Builder
         *
         * @example
         * // 没有待处理订单 OR 没有退款记录
         * User::query()
         *     ->whereHasNotIn('orders', fn ($q) => $q->where('status', 'pending'))
         *     ->orWhereHasNotIn('refunds')
         *     ->get();
         *
         * @scenario orWhereHasNotIn —— 以 OR 条件追加 whereHasNotIn 关联反选。
         */
        Builder::macro('orWhereHasNotIn', function ($relationName, $callable = null) {
            return $this->orWhere(function ($query) use ($relationName, $callable) {
                return $query->whereHasNotIn($relationName, $callable);
            });
        });

        /**
         * whereHasMorphIn —— 多态关联的 IN 子查询优化（MorphTo / MorphOne / MorphMany / MorphToMany）。
         * 将多态类型逐一展开为 `OR (morph_type = ? AND 关联 IN (...))`，避免原生 morph 关联的复杂 EXISTS。
         * 传入 `['*']` 可自动加载该多态列的全部已注册模型类型。
         *
         * @param  string  $relation  多态关联名
         * @param  string|array  $types  多态类型（模型类或别名），'*' 表示全部
         * @param  \Closure|null  $callback  关联查询约束闭包（接收 $query 与 $type）
         * @param  string  $boolean  连接条件：and|or
         * @return Builder
         *
         * @example
         * // 查询「被评论且评论已审核」的文章（commentable 多态）
         * Post::query()->whereHasMorphIn('comments', ['App\Models\Post'], fn ($q) => $q->where('approved', 1))->get();
         *
         * @scenario 多态关联（评论/点赞/附件等）的筛选优化。
         */
        Builder::macro('whereHasMorphIn', WhereHasMorphIn::make());
        /**
         * orWhereHasMorphIn —— 以 OR 条件追加多态关联 IN 子查询筛选。
         * 等价于在既有查询上追加 `OR (morph_type = ? AND 关联 IN (...))`，与 whereHasMorphIn 共享
         * 展开逻辑，仅连接条件变为 or。常用于「满足 A 多态条件，或满足 B 多态条件」的组合筛选。
         *
         * @param  string  $relation  多态关联名
         * @param  string|array  $types  多态类型（模型类或别名），'*' 表示全部
         * @param  \Closure|null  $callback  关联查询约束闭包（接收 $query 与 $type）
         * @return Builder
         *
         * @example
         * // 「被已审核评论关联」 OR 「被已点赞关联」的文章
         * Post::query()
         *     ->whereHasMorphIn('comments', ['App\Models\Post'], fn ($q) => $q->where('approved', 1))
         *     ->orWhereHasMorphIn('likes', ['App\Models\Post'])
         *     ->get();
         *
         * @scenario 多态关联的多条件 OR 组合筛选。
         */
        Builder::macro('orWhereHasMorphIn', function ($relation, $types, $callback = null) {
            return $this->whereHasMorphIn($relation, $types, $callback, 'or');
        });

        // 主表字段查询：将首个列参数自动前缀主表名，避免关联查询时列名歧义。
        // 注意：Builder 原生方法均为小写（pluck / sum / whereBetween / whereIn / where / orderBy / orderByDesc），
        // 故循环映射为小写方法名，避免调用不存在的驼峰方法导致运行时错误。
        // 主表字段查询族：为首个列参数自动前缀主表名，避免关联查询（JOIN）下的列名歧义。
        // 以下循环按 Builder 原生小写方法名（pluck / sum / whereBetween / whereIn / where / orderBy / orderByDesc）
        // 生成对应的 main{Pascal} 宏：mainPluck / mainSum / mainWhereBetween / mainWhereIn / mainWhere /
        // mainOrderBy / mainOrderByDesc。原生方法均为小写，故映射为小写方法名调用，避免调用不存在的
        // 驼峰方法导致运行时错误。
        foreach (['pluck', 'sum', 'whereBetween', 'whereIn', 'where', 'orderBy', 'orderByDesc'] as $method) {
            /**
             * main{Pascal} 族 —— 自动为首个列参数前缀「主表名.」，避免关联查询（JOIN）下的列名歧义。
             * 生成的方法：mainPluck / mainSum / mainWhereBetween / mainWhereIn / mainWhere /
             * mainOrderBy / mainOrderByDesc。调用时首个列参数会被改写为 `{主表}.{列}`，其余参数原样透传。
             *
             * @param  mixed  ...$params  与原生方法一致（首个参数为列名）
             * @return Builder
             *
             * @example
             * // mainWhere('status', 1) 等价于 where('users.status', 1)
             * User::query()->whereHasJoin('orders')->mainWhere('status', 1)->get();
             * // mainOrderByDesc('created_at') 等价于 orderByDesc('users.created_at')
             * User::query()->whereHasJoin('orders')->mainOrderByDesc('created_at')->get();
             *
             * @scenario main{Pascal} 族 —— 自动为首个列参数前缀「主表名.」，避免关联查询（JOIN）下的列名歧义。
             */
            Builder::macro('main'.ucfirst($method), function (...$params) use ($method) {
                if (isset($params[0])) {
                    $params[0] = $this->getModel()->getTable().'.'.$params[0];
                }

                return $this->{$method}(...$params);
            });
        }

        /**
         * mainSelect —— 为主表的每个列自动加表前缀后 select，避免关联查询下 `SELECT *` 的列歧义。
         *
         * @param  array|string  $columns  列名（字符串或数组），均视作主表列并自动前缀表名
         * @return Builder
         *
         * @example
         * // 仅取主表字段并加前缀（适合 JOIN 后避免列名冲突）
         * User::query()->whereHasJoin('orders')->mainSelect(['id', 'email'])->get();
         *
         * @scenario mainSelect —— 为主表的每个列自动加表前缀后 select，避免关联查询下 `SELECT *` 的列歧义。
         */
        Builder::macro('mainSelect', function ($columns = ['*']) {
            $table = $this->getModel()->getTable();
            $columns = is_array($columns) ? $columns : func_get_args();
            foreach ($columns as &$column) {
                $column = $table.'.'.$column;
            }

            return $this->select($columns);
        });
    }
}
