<?php

namespace zxf\Modules\BuilderQuery;

use Illuminate\Database\Eloquent;
use Illuminate\Support\ServiceProvider;
use zxf\Modules\BuilderQuery\WindowMacros\AdvancedJsonMacro;
use zxf\Modules\BuilderQuery\WindowMacros\FastPaginationMacro;
use zxf\Modules\BuilderQuery\WindowMacros\GroupSortMacro;
use zxf\Modules\BuilderQuery\WindowMacros\LateralJoinMacro;
use zxf\Modules\BuilderQuery\WindowMacros\PivotMacro;
use zxf\Modules\BuilderQuery\WindowMacros\QualifyMacro;
use zxf\Modules\BuilderQuery\WindowMacros\RandomMacro;
use zxf\Modules\BuilderQuery\WindowMacros\RegexMacro;
use zxf\Modules\BuilderQuery\WindowMacros\SetOperationsMacro;
use zxf\Modules\BuilderQuery\WindowMacros\TableSampleMacro;
use zxf\Modules\BuilderQuery\WindowMacros\ValuesMacro;
use zxf\Modules\BuilderQuery\WindowMacros\WindowFunctionsMacro;
use zxf\Modules\BuilderQuery\WindowMacros\WithRecursiveMacro;
use zxf\Modules\BuilderQuery\WhereHasMacros\WhereHasCrossJoin;
use zxf\Modules\BuilderQuery\WhereHasMacros\WhereHasIn;
use zxf\Modules\BuilderQuery\WhereHasMacros\WhereHasJoin;
use zxf\Modules\BuilderQuery\WhereHasMacros\WhereHasLeftJoin;
use zxf\Modules\BuilderQuery\WhereHasMacros\WhereHasMorphIn;
use zxf\Modules\BuilderQuery\WhereHasMacros\WhereHasNotIn;
use zxf\Modules\BuilderQuery\WhereHasMacros\WhereHasRightJoin;

/**
 * Macros 宏定义构建器 - Laravel 11+ & MySQL 8.4+ 优化版
 *
 * 提供11大类查询宏扩展：
 * 1. whereHas优化 - 解决关联查询全表扫描问题
 * 2. 随机查询 - 高效随机数据获取
 * 3. 窗口函数 - MySQL 8.4+ 窗口函数支持
 * 4. 递归查询 - 树形结构数据处理
 * 5. 分页优化 - 超大表快速分页
 * 6. JSON操作 - 高级JSON查询
 * 7. 正则表达式 - 文本匹配功能
 * 8. 主表字段 - 自动表前缀
 * 9. 集合操作 - INTERSECT/EXCEPT支持
 * 10. QUALIFY过滤 - 窗口函数结果过滤
 * 11. LATERAL JOIN - 横向连接查询
 * 12. 行列转换 - PIVOT/UNPIVOT透视表
 * 13. 数据抽样 - 随机/分层/系统抽样
 * 14. VALUES构造 - 批量插入和UPSERT
 *
 * @package zxf\Modules\BuilderQuery
 * @version 2.1.0
 * @requires PHP 8.2+, Laravel 11+, MySQL 8.4+
 *
 * ============================================
 * 1. whereHas 优化系列 - 解决关联查询性能问题
 * ============================================
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
 *
 * ============================================
 * 2. 主表字段自动前缀系列 - 避免字段歧义
 * ============================================
 * @method $this mainWhere(string $column, mixed $operator = null, mixed $value = null) 主表字段WHERE条件，自动添加表前缀避免关联字段歧义
 * @method $this mainWhereIn(string $column, array $values) 主表字段IN查询，自动添加表前缀
 * @method $this mainWhereBetween(string $column, array $values) 主表字段BETWEEN查询，自动添加表前缀
 * @method $this mainOrderBy(string $column, string $direction = 'asc') 主表字段排序，自动添加表前缀
 * @method $this mainOrderByDesc(string $column) 主表字段降序排序，自动添加表前缀
 * @method $this mainSum(string $column) 主表字段求和，自动添加表前缀
 * @method $this mainPluck(string $column) 主表单字段查询，自动添加表前缀
 * @method $this mainSelect(array|string $columns) 主表字段选择，自动为所有字段添加表前缀
 *
 * ============================================
 * 3. 随机查询系列 - 高效随机数据获取
 * ============================================
 * @method $this random(int $limit = 10, string $primaryKey = 'id') 随机查询指定数量记录，使用窗口函数ROW_NUMBER优化性能
 * @method $this groupRandom(string $groupColumn, int $limit = 10, string $primaryKey = 'id') 分组随机查询，每组随机抽取指定数量记录
 *
 * ============================================
 * 4. 分组排序系列 - 窗口函数分组排名查询
 * ============================================
 * @method $this groupSort(string $groupBy, int|array $ranks, string $orderBy = 'read', string $direction = 'desc') 分组排序查询，获取每组指定排名的记录，如每组前3名
 *
 * ============================================
 * 5. 窗口函数系列 - MySQL 8.4+ 窗口函数支持
 * ============================================
 * 排名函数
 * @method $this rowNumber(string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'row_num') 为每行分配唯一连续序号，常用于分页、排名
 * @method $this rank(string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'rank_num') 排名函数，相同值排名相同，后续排名跳过（如1,2,2,4）
 * @method $this denseRank(string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'dense_rank_num') 密集排名，相同值排名相同，后续排名连续（如1,2,2,3）
 * @method $this percentRank(string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'percent_rank_val') 百分比排名，计算每行的相对排名位置（0-1之间）
 * @method $this ntile(int $buckets, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'bucket_num') 分桶函数，将数据均匀分配到N个桶中（如四分位数）
 *
 * 偏移函数
 * @method $this lag(string $column, int $offset = 1, mixed $default = null, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', ?string $alias = null) 获取当前行之前第N行的值，用于计算环比、同比
 * @method $this lead(string $column, int $offset = 1, mixed $default = null, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', ?string $alias = null) 获取当前行之后第N行的值，用于预测、趋势分析
 * @method $this firstValue(string $column, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', ?string $alias = null) 获取分区第一行的值，用于基准比较
 * @method $this lastValue(string $column, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', ?string $alias = null) 获取分区最后一行的值
 * @method $this nthValue(string $column, int $n, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', ?string $alias = null) 获取分区第N行的值
 *
 * 聚合窗口函数
 * @method $this sumOver(string $column, string|array|null $partitionBy = null, ?string $orderBy = null, string $direction = 'asc', ?string $alias = null) 滑动求和，计算分区累计和
 * @method $this avgOver(string $column, string|array|null $partitionBy = null, ?string $orderBy = null, string $direction = 'asc', ?string $alias = null) 滑动平均，计算分区移动平均
 * @method $this countOver(string $column, string|array|null $partitionBy = null, ?string $orderBy = null, string $direction = 'asc', ?string $alias = null) 滑动计数，计算分区行数
 * @method $this minOver(string $column, string|array|null $partitionBy = null, ?string $orderBy = null, string $direction = 'asc', ?string $alias = null) 滑动最小值
 * @method $this maxOver(string $column, string|array|null $partitionBy = null, ?string $orderBy = null, string $direction = 'asc', ?string $alias = null) 滑动最大值
 *
 * 框架窗口函数
 * @method $this rowsBetween(string $column, string $function, string $start, string $end, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'frame_result') 基于物理行范围的窗口计算
 * @method $this rangeBetween(string $column, string $function, string $start, string $end, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'frame_result') 基于值范围的窗口计算
 * @method $this cumulativeSum(string $column, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'cumulative_sum') 累计求和，从分区开始到当前行
 * @method $this movingAverage(string $column, int $windowSize, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'moving_avg') 移动平均，计算当前行前后N行的平均值
 * @method $this runningTotal(string $column, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'running_total') 运行总计，cumulativeSum的别名
 *
 * ============================================
 * 6. 分页优化系列 - 超大表快速分页
 * ============================================
 * @method \Illuminate\Contracts\Pagination\LengthAwarePaginator fastPaginate(int $perPage = 15, ?int $page = null, ?string $primaryKey = null, array $options = []) 智能快速分页，自动选择最优策略（延迟关联/窗口函数）
 * @method \Illuminate\Pagination\Paginator fastSimplePaginate(int $perPage = 15, ?int $page = null, ?string $primaryKey = null, array $options = []) 简单快速分页，不计算总数，适合无限滚动
 * @method \Illuminate\Contracts\Pagination\CursorPaginator cursorPaginate(int $perPage = 15, ?string $cursor = null, string $sortColumn = '', string $direction = 'asc', ?string $primaryKey = null, array $options = []) 游标分页，基于排序值定位，性能最佳
 * @method \Illuminate\Contracts\Pagination\LengthAwarePaginator seekPaginate(int $perPage = 100, ?array $bookmarks = null, int $page = 1, string $sortColumn = '', string $direction = 'asc', ?string $primaryKey = null, array $options = []) 寻址分页，基于书签的深度分页方案
 * @method \Illuminate\Contracts\Pagination\LengthAwarePaginator partitionPaginate(int $perPage = 15, ?int $page = null, ?string $partitionKey = null, array $options = []) 分区表分页，针对MySQL分区表优化
 *
 * ============================================
 * 7. JSON高级操作系列 - MySQL 8.4+ JSON函数
 * ============================================
 * JSON路径查询
 * @method $this jsonPath(string $column, string $path, string $alias, mixed $default = null) 使用JSON Path提取嵌套JSON值
 * @method $this jsonExtract(string $column, string $path, string $type = 'string', string $alias = '', mixed $default = null) 提取JSON值并转换为指定类型（int/float/bool/datetime等）
 * @method $this whereJsonPathExists(string $column, string $path, string $boolean = 'and') 筛选JSON Path存在的记录
 * @method $this whereJsonPathNotExists(string $column, string $path, string $boolean = 'and') 筛选JSON Path不存在的记录
 *
 * JSON数组操作
 * @method $this whereJsonArrayContains(string $column, mixed $value, ?string $path = null, string $boolean = 'and') 筛选JSON数组包含指定值的记录
 * @method $this whereJsonArrayContainsAny(string $column, array $values, ?string $path = null, string $boolean = 'and') 筛选JSON数组包含任意指定值的记录
 * @method $this whereJsonArrayContainsAll(string $column, array $values, ?string $path = null, string $boolean = 'and') 筛选JSON数组包含所有指定值的记录
 * @method $this jsonArrayLength(string $column, ?string $path = null, string $alias = 'array_length') 获取JSON数组长度
 * @method $this whereJsonArrayLength(string $column, int $count, string $operator = '=', ?string $path = null, string $boolean = 'and') 按JSON数组长度筛选
 * @method $this appendToJsonArray(string $column, mixed $value, ?string $path = null) 追加值到JSON数组
 * @method $this removeFromJsonArray(string $column, mixed $value, ?string $path = null) 从JSON数组移除值
 *
 * JSON对象操作
 * @method $this setJsonValue(string $column, string $path, mixed $value, bool $insert = false) 设置JSON对象的键值
 * @method $this removeJsonKey(string $column, string|array $paths) 删除JSON对象的键
 * @method $this mergeJson(string $column, array $data, ?string $path = null) 合并JSON对象
 * @method $this jsonKeys(string $column, ?string $path = null, string $alias = 'json_keys') 获取JSON对象的所有键
 *
 * JSON搜索与聚合
 * @method $this jsonSearch(string $column, string $search, string $mode = 'one', string $path = '$', string $alias = 'search_result') 在JSON中搜索值
 * @method $this whereJsonLike(string $column, string $pattern, ?string $path = null, string $boolean = 'and') 按JSON值模糊匹配筛选
 * @method $this jsonArrayAgg(string $column, string $alias = 'json_array', ?string $orderBy = null, string $direction = 'asc') 将多行聚合成JSON数组
 * @method $this jsonObjectAgg(string $keyColumn, string $valueColumn, string $alias = 'json_object') 将多行聚合成JSON对象
 * @method $this jsonRowAgg(array $columns, string $alias = 'json_rows') 将行聚合成JSON对象数组
 *
 * ============================================
 * 8. 正则表达式系列 - MySQL 8.4+ REGEXP函数
 * ============================================
 * 正则匹配
 * @method $this whereRegexp(string $column, string $pattern, string $mode = 'c', string $boolean = 'and') 正则表达式匹配筛选，mode支持c(区分大小写)/i(不区分)/m(多行)/n(点匹配换行)
 * @method $this whereNotRegexp(string $column, string $pattern, string $mode = 'c', string $boolean = 'and') 正则表达式不匹配筛选
 * @method $this whereRegexpAny(string $column, array $patterns, string $mode = 'c', string $boolean = 'and') 匹配任意一个正则模式
 *
 * 正则提取与替换
 * @method $this regexpExtract(string $column, string $pattern, int $group = 0, int $occurrence = 1, string $mode = 'c', string $alias = 'extracted') 使用正则提取子串，支持捕获组
 * @method $this regexpExtractAll(string $column, string $pattern, string $mode = 'c', string $alias = 'all_matches') 提取所有匹配项
 * @method $this regexpReplace(string $column, string $pattern, string $replacement, int $occurrence = 0, string $mode = 'c', string $alias = 'replaced') 使用正则替换文本，支持$1/$2引用捕获组
 * @method $this regexpReplaceBatch(string $column, array $replacements, string $mode = 'c', string $alias = 'replaced') 批量正则替换
 *
 * 正则位置与计数
 * @method $this regexpPosition(string $column, string $pattern, int $occurrence = 1, string $mode = 'c', int $returnOption = 0, string $alias = 'position') 查找正则匹配位置
 * @method $this regexpCount(string $column, string $pattern, string $mode = 'c', string $alias = 'match_count') 统计正则匹配次数
 * @method $this whereRegexpCount(string $column, string $pattern, int $count, string $operator = '=', string $mode = 'c', string $boolean = 'and') 按匹配次数筛选
 *
 * ============================================
 * 9. 递归查询系列 - 树形结构数据处理
 * ============================================
 * 层级关系查询（上下级查找）
 * @method $this withAllChildren(int $id, string $pidColumn = 'pid', int $maxDepth = 100, bool $includeSelf = false) 查找指定节点的所有后代子节点，递归向下查询，可包含/排除自身，结果含depth字段
 * @method $this withAllParents(int $id, string $pidColumn = 'pid', int $maxDepth = 100, bool $includeSelf = false) 查找指定节点的所有祖先父节点，递归向上查询直到根节点，结果含depth字段
 * @method $this withNthParent(int $id, int $n, string $pidColumn = 'pid') 查找指定节点的第N级父节点，0级为自己，1级为直接父节点，2级为祖父节点
 * @method $this withNthChildren(int $id, int $n, string $pidColumn = 'pid') 查找指定节点的第N级所有子节点，1级为直接子节点，2级为孙子节点
 *
 * 路径查询（面包屑、导航）
 * @method $this withFullPath(array $ids = [], array $conditions = [], string $pidColumn = 'pid', string $nameColumn = 'name', string $pathSeparator = ' > ') 查找节点的完整路径，返回absolute_path(名称路径)、path_ids(ID链)、depth(深度)字段
 * @method $this withBreadcrumbs(int $id, string $pidColumn = 'pid', string $nameColumn = 'name') 获取指定节点的面包屑路径（从根到该节点的祖先链），按层级升序排列
 * @method $this withPathLength(int $id, string $pidColumn = 'pid') 获取指定节点到根节点的路径长度，返回path_length字段表示层级深度（根为0）
 *
 * 节点关系判断
 * @method bool isParentOf(int $parentId, int $childId, string $pidColumn = 'pid', bool $strict = false) 检查parentId是否是childId的祖先节点，strict=true时只检查直接父节点
 * @method bool isChildOf(int $childId, int $parentId, string $pidColumn = 'pid', bool $strict = false) 检查childId是否是parentId的后代节点，strict=true时只检查直接子节点
 * @method $this withNearestAncestor(int $id1, int $id2, string $pidColumn = 'pid') 查找两个节点的最近公共祖先（LCA），用于判断节点亲缘关系
 *
 * 同级节点查询
 * @method $this withSiblings(int $id, string $pidColumn = 'pid', bool $includeSelf = false) 查找指定节点的所有同级节点（兄弟节点），即拥有相同父节点的其他节点
 *
 * 树形结构构建
 * @method $this withTree(?int $pid = null, string $pidColumn = 'pid', string $nameColumn = 'name', int $maxDepth = 100, string $pathSeparator = ' > ') 构建完整树形结构，从指定根节点开始递归查询整棵树，返回tree_path、path_ids、depth字段
 * @method $this withRoot(string $pidColumn = 'pid') 查找所有根节点（没有父节点的节点），即pid等于rootValue或为null的记录
 * @method $this withLeafNodes(string $pidColumn = 'pid') 查找所有叶子节点（没有子节点的节点），使用NOT EXISTS子查询实现
 * @method $this withDescendantsCount(int $id, string $pidColumn = 'pid') 获取指定节点的后代节点总数（子孙数量），返回descendants_count字段
 *
 * 通用递归
 * @method $this recursiveQuery(callable $baseQuery, callable $recursiveQuery, array $columns = ['*'], int $maxDepth = 100, string $depthColumn = 'depth') 通用递归CTE查询（高级用法），自定义基础查询和递归查询回调
 * @method $this resetRecursive() 重置递归查询条件，清除所有递归相关的查询状态和绑定
 *
 * ============================================
 * 10. 集合操作系列 - MySQL 8.0.31+ INTERSECT/EXCEPT
 * ============================================
 * @method $this intersect(\Closure|Builder $query, bool $all = false) 返回两个查询的交集（INTERSECT）
 * @method $this except(\Closure|Builder $query, bool $all = false) 返回在第一个查询中存在但第二个查询中不存在的记录（EXCEPT）
 * @method $this unionDistinct(\Closure|Builder $query) UNION DISTINCT显式去重的并集
 *
 * ============================================
 * 11. QUALIFY过滤系列 - MySQL 8.0.33+ 窗口函数过滤
 * ============================================
 * @method $this qualify(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and') 过滤窗口函数结果，类似HAVING对聚合的作用
 * @method $this orQualify(string $column, mixed $operator = null, mixed $value = null) OR条件的QUALIFY过滤
 * @method $this qualifyRaw(string $sql, array $bindings = [], string $boolean = 'and') 使用原始SQL的QUALIFY过滤
 * @method $this orQualifyRaw(string $sql, array $bindings = []) OR条件的QUALIFY RAW过滤
 *
 * ============================================
 * 12. LATERAL JOIN系列 - MySQL 8.0.14+ 横向连接
 * ============================================
 * @method $this lateralJoin(\Closure $callback, string $alias) LATERAL INNER JOIN，子查询可引用主查询列
 * @method $this lateralLeftJoin(\Closure $callback, string $alias) LATERAL LEFT JOIN，保留主表所有记录
 * @method $this lateralLimit(string $partitionColumn, string $orderColumn, string $direction = 'desc', int $limit = 5, string $alias = 'lateral_limit') 使用LATERAL实现高效Top-N查询
 * @method $this lateralAggregate(string $relationColumn, array $aggregates, ?string $whereColumn = null, mixed $whereValue = null, string $alias = 'agg_result') LATERAL聚合查询，计算行相关的聚合值
 *
 * ============================================
 * 13. 行列转换(PIVOT)系列 - 数据透视表
 * ============================================
 * @method $this pivot(string $pivotColumn, array $values, string $aggregateColumn, string $function = 'SUM', string|array|null $groupBy = null) 行转列透视，将某列值转换为多列
 * @method $this pivotCount(string $pivotColumn, array $values, string $aggregateColumn, string|array|null $groupBy = null) COUNT聚合透视快捷方法
 * @method $this pivotSum(string $pivotColumn, array $values, string $aggregateColumn, string|array|null $groupBy = null) SUM聚合透视快捷方法
 * @method $this pivotAvg(string $pivotColumn, array $values, string $aggregateColumn, string|array|null $groupBy = null) AVG聚合透视快捷方法
 * @method $this pivotMax(string $pivotColumn, array $values, string $aggregateColumn, string|array|null $groupBy = null) MAX聚合透视快捷方法
 * @method $this pivotMin(string $pivotColumn, array $values, string $aggregateColumn, string|array|null $groupBy = null) MIN聚合透视快捷方法
 * @method $this unpivot(array $columns, string $nameColumn = 'attribute', string $valueColumn = 'value') 列转行，将多列转换为名称-值对的行
 * @method $this crossTab(string $rowColumn, string $colColumn, string $aggregateColumn, string $function = 'SUM', ?array $colValues = null) 交叉表查询，双维透视生成矩阵式报表
 *
 * ============================================
 * 14. 数据抽样系列 - TABLESAMPLE和抽样算法
 * ============================================
 * @method $this sample(float $percentage, string $method = 'random') 表级抽样，按百分比随机或系统抽取样本
 * @method $this randomSample(int $sampleSize, ?string $seed = null) 精确随机抽样指定样本量，支持种子保证可重复性
 * @method $this stratifiedSample(string $stratumColumn, int $samplesPerStratum, ?string $seed = null) 分层抽样，按指定列分层每层抽取固定数量
 * @method $this systematicSample(int $interval, ?int $startOffset = null) 系统抽样（等距抽样），按固定间隔抽取
 *
 * ============================================
 * 15. VALUES构造系列 - 批量操作优化
 * ============================================
 * @method $this valuesQuery(array $rows, string $alias = 'val') 使用VALUES ROW构建内存表查询
 * @method $this valuesJoin(array $rows, string $alias, string $localKey, string $valuesKey, string $joinType = 'inner') 使用VALUES作为JOIN表
 * @method int valuesInsert(array $rows, int $chunkSize = 1000) 使用VALUES ROW语法进行高效批量插入
 * @method int batchUpsert(array $rows, array|string $uniqueBy, ?array $updateColumns = null, int $chunkSize = 1000) 批量插入或更新（INSERT ... ON DUPLICATE KEY UPDATE）
 */
class MacrosBuilder extends Eloquent\Builder
{
    /**
     * 注册所有宏指令
     *
     * 按照功能模块分组注册，便于管理和维护
     *
     * @param ServiceProvider $provider 服务提供者实例
     * @return void
     */
    public static function register(ServiceProvider $provider): void
    {
        // 1. whereHas 查询优化系列 - 解决关联查询全表扫描问题
        self::registerWhereHasInQuery($provider);

        // 2. 随机查询系列 - 高效随机数据获取
        RandomMacro::register();

        // 3. 分组排序系列 - 窗口函数分组排序
        GroupSortMacro::register();

        // 4. 递归查询系列 - 树形结构数据处理
        WithRecursiveMacro::register();

        // 5. MySQL 8.4+ 窗口函数系列 - 排名、偏移、聚合窗口函数
        WindowFunctionsMacro::register();

        // 6. 超大表分页优化系列 - 深度分页性能优化
        FastPaginationMacro::register();

        // 7. JSON 高级操作系列 - MySQL 8.4+ JSON 函数
        AdvancedJsonMacro::register();

        // 8. 正则表达式系列 - 强大的文本匹配功能
        RegexMacro::register();

        // 9. 集合操作系列 - INTERSECT/EXCEPT (MySQL 8.0.31+)
        SetOperationsMacro::register();

        // 10. QUALIFY 过滤系列 - 窗口函数结果过滤 (MySQL 8.0.33+)
        QualifyMacro::register();

        // 11. LATERAL JOIN 系列 - 横向连接 (MySQL 8.0.14+)
        LateralJoinMacro::register();

        // 12. 行列转换系列 - PIVOT/UNPIVOT 透视表
        PivotMacro::register();

        // 13. 数据抽样系列 - 随机/分层/系统抽样
        TableSampleMacro::register();

        // 14. VALUES 构造系列 - 批量插入和 UPSERT
        ValuesMacro::register();
    }

    public static function registerWhereHasInQuery(ServiceProvider $provider)
    {
        // in notIn
        Eloquent\Builder::macro('whereHasIn', function ($relationName, $callable = null) {
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

        Eloquent\Builder::macro('whereHasNotIn', function ($relationName, $callable = null) {
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

        // join(inner join) crossJoin leftJoin rightJoin
        Eloquent\Builder::macro('whereHasJoin', function ($relationName, $callable = null) {
            return (new WhereHasJoin($this, $relationName, function (Eloquent\Builder $builder, Eloquent\Builder $relationBuilder) use ($callable) {
                if ($callable) {
                    $relationBuilder->callScope($callable);

                    return $builder->addNestedWhereQuery($relationBuilder->getQuery());
                }

                return $builder;
            }))->execute();
        });

        Eloquent\Builder::macro('whereHasCrossJoin', function ($relationName, $callable = null) {
            return (new WhereHasCrossJoin($this, $relationName, function (Eloquent\Builder $builder, Eloquent\Builder $relationBuilder) use ($callable) {
                if ($callable) {
                    $relationBuilder->callScope($callable);

                    return $builder->addNestedWhereQuery($relationBuilder->getQuery());
                }

                return $builder;
            }))->execute();
        });

        Eloquent\Builder::macro('whereHasLeftJoin', function ($relationName, $callable = null) {
            return (new WhereHasLeftJoin($this, $relationName, function (Eloquent\Builder $builder, Eloquent\Builder $relationBuilder) use ($callable) {
                if ($callable) {
                    $relationBuilder->callScope($callable);

                    return $builder->addNestedWhereQuery($relationBuilder->getQuery());
                }

                return $builder;
            }))->execute();
        });

        Eloquent\Builder::macro('whereHasRightJoin', function ($relationName, $callable = null) {
            return (new WhereHasRightJoin($this, $relationName, function (Eloquent\Builder $builder, Eloquent\Builder $relationBuilder) use ($callable) {
                if ($callable) {
                    $relationBuilder->callScope($callable);

                    return $builder->addNestedWhereQuery($relationBuilder->getQuery());
                }

                return $builder;
            }))->execute();
        });

        // or in、 or notIn
        Eloquent\Builder::macro('orWhereHasIn', function ($relationName, $callable = null) {
            return $this->orWhere(function ($query) use ($relationName, $callable) {
                return $query->whereHasIn($relationName, $callable);
            });
        });

        Eloquent\Builder::macro('orWhereHasNotIn', function ($relationName, $callable = null) {
            return $this->orWhere(function ($query) use ($relationName, $callable) {
                return $query->whereHasNotIn($relationName, $callable);
            });
        });

        // morph in
        Eloquent\Builder::macro('whereHasMorphIn', WhereHasMorphIn::make());
        Eloquent\Builder::macro('orWhereHasMorphIn', function ($relation, $types, $callback = null) {
            return $this->whereHasMorphIn($relation, $types, $callback, 'or');
        });

        // 主表字段查询
        foreach (['Pluck', 'Sum', 'WhereBetween', 'WhereIn', 'Where', 'OrderBy', 'OrderByDesc'] as $macroAction) {
            Eloquent\Builder::macro('main'.$macroAction, function (...$params) use ($macroAction) {
                $params[0] = $this->getModel()->getTable().'.'.$params[0];

                return $this->{$macroAction}(...$params);
            });
        }

        Eloquent\Builder::macro('mainSelect', function ($columns = ['*']) {
            $table = $this->getModel()->getTable();
            $columns = is_array($columns) ? $columns : func_get_args();
            foreach ($columns as &$column) {
                $column = $table.'.'.$column;
            }

            return $this->select($columns);
        });
    }
}
