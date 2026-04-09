<?php

namespace zxf\Modules\BuilderQuery;

use Illuminate\Database\Eloquent;
use Illuminate\Support\ServiceProvider;
use zxf\Modules\BuilderQuery\WindowMacros\AdvancedJsonMacro;
use zxf\Modules\BuilderQuery\WindowMacros\FastPaginationMacro;
use zxf\Modules\BuilderQuery\WindowMacros\GroupSortMacro;
use zxf\Modules\BuilderQuery\WindowMacros\RandomMacro;
use zxf\Modules\BuilderQuery\WindowMacros\RegexMacro;
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
 * 提供8大类查询宏扩展：
 * 1. whereHas优化 - 解决关联查询全表扫描
 * 2. 随机查询 - 高效随机数据获取
 * 3. 窗口函数 - MySQL 8.4+ 窗口函数支持
 * 4. 递归查询 - 树形结构数据处理
 * 5. 分页优化 - 超大表快速分页
 * 6. JSON操作 - 高级JSON查询
 * 7. 正则表达式 - 文本匹配功能
 * 8. 主表字段 - 自动表前缀
 *
 * @package zxf\Modules\BuilderQuery
 * @version 2.0.0
 * @requires PHP 8.2+, Laravel 11+, MySQL 8.4+
 *
 * @method $this whereHasIn(string $relation, ?\Closure $callable = null)
 * @method $this orWhereHasIn(string $relation, ?\Closure $callable = null)
 * @method $this whereHasNotIn(string $relation, ?\Closure $callable = null)
 * @method $this orWhereHasNotIn(string $relation, ?\Closure $callable = null)
 * @method $this whereHasJoin(string $relation, ?\Closure $callable = null)
 * @method $this whereHasCrossJoin(string $relation, ?\Closure $callable = null)
 * @method $this whereHasLeftJoin(string $relation, ?\Closure $callable = null)
 * @method $this whereHasRightJoin(string $relation, ?\Closure $callable = null)
 * @method $this whereHasMorphIn(string $relation, $types, ?\Closure $callable = null)
 * @method $this orWhereHasMorphIn(string $relation, $types, ?\Closure $callable = null)
 * @method $this mainWhere(string $column, mixed $operator = null, mixed $value = null)
 * @method $this mainWhereIn(string $column, array $values)
 * @method $this mainWhereBetween(string $column, array $values)
 * @method $this mainOrderBy(string $column, string $direction = 'asc')
 * @method $this mainOrderByDesc(string $column)
 * @method $this mainSum(string $column)
 * @method $this mainPluck(string $column)
 * @method $this mainSelect(array|string $columns)
 * @method $this random(int $limit = 10, string $primaryKey = 'id')
 * @method $this groupRandom(string $groupColumn, int $limit = 10, string $primaryKey = 'id')
 * @method $this groupSort(string $groupBy, int|array $ranks, string $orderBy = 'read', string $direction = 'desc')
 * @method $this rowNumber(string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'row_num')
 * @method $this rank(string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'rank_num')
 * @method $this denseRank(string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'dense_rank_num')
 * @method $this percentRank(string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'percent_rank_val')
 * @method $this ntile(int $buckets, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'bucket_num')
 * @method $this lag(string $column, int $offset = 1, mixed $default = null, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', ?string $alias = null)
 * @method $this lead(string $column, int $offset = 1, mixed $default = null, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', ?string $alias = null)
 * @method $this firstValue(string $column, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', ?string $alias = null)
 * @method $this lastValue(string $column, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', ?string $alias = null)
 * @method $this nthValue(string $column, int $n, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', ?string $alias = null)
 * @method $this sumOver(string $column, string|array|null $partitionBy = null, ?string $orderBy = null, string $direction = 'asc', ?string $alias = null)
 * @method $this avgOver(string $column, string|array|null $partitionBy = null, ?string $orderBy = null, string $direction = 'asc', ?string $alias = null)
 * @method $this countOver(string $column, string|array|null $partitionBy = null, ?string $orderBy = null, string $direction = 'asc', ?string $alias = null)
 * @method $this minOver(string $column, string|array|null $partitionBy = null, ?string $orderBy = null, string $direction = 'asc', ?string $alias = null)
 * @method $this maxOver(string $column, string|array|null $partitionBy = null, ?string $orderBy = null, string $direction = 'asc', ?string $alias = null)
 * @method $this rowsBetween(string $column, string $function, string $start, string $end, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'frame_result')
 * @method $this rangeBetween(string $column, string $function, string $start, string $end, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'frame_result')
 * @method $this cumulativeSum(string $column, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'cumulative_sum')
 * @method $this movingAverage(string $column, int $windowSize, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'moving_avg')
 * @method $this runningTotal(string $column, string|array|null $partitionBy = null, string $orderBy = '', string $direction = 'asc', string $alias = 'running_total')
 * @method \Illuminate\Contracts\Pagination\LengthAwarePaginator fastPaginate(int $perPage = 15, ?int $page = null, ?string $primaryKey = null, array $options = [])
 * @method \Illuminate\Pagination\Paginator fastSimplePaginate(int $perPage = 15, ?int $page = null, ?string $primaryKey = null, array $options = [])
 * @method \Illuminate\Contracts\Pagination\CursorPaginator cursorPaginate(int $perPage = 15, ?string $cursor = null, string $sortColumn = '', string $direction = 'asc', ?string $primaryKey = null, array $options = [])
 * @method \Illuminate\Contracts\Pagination\LengthAwarePaginator seekPaginate(int $perPage = 100, ?array $bookmarks = null, int $page = 1, string $sortColumn = '', string $direction = 'asc', ?string $primaryKey = null, array $options = [])
 * @method \Illuminate\Contracts\Pagination\LengthAwarePaginator partitionPaginate(int $perPage = 15, ?int $page = null, ?string $partitionKey = null, array $options = [])
 * @method $this jsonPath(string $column, string $path, string $alias, mixed $default = null)
 * @method $this jsonExtract(string $column, string $path, string $type = 'string', string $alias = '', mixed $default = null)
 * @method $this whereJsonPathExists(string $column, string $path, string $boolean = 'and')
 * @method $this whereJsonPathNotExists(string $column, string $path, string $boolean = 'and')
 * @method $this whereJsonArrayContains(string $column, mixed $value, ?string $path = null, string $boolean = 'and')
 * @method $this whereJsonArrayContainsAny(string $column, array $values, ?string $path = null, string $boolean = 'and')
 * @method $this whereJsonArrayContainsAll(string $column, array $values, ?string $path = null, string $boolean = 'and')
 * @method $this jsonArrayLength(string $column, ?string $path = null, string $alias = 'array_length')
 * @method $this whereJsonArrayLength(string $column, int $count, string $operator = '=', ?string $path = null, string $boolean = 'and')
 * @method $this whereRegexp(string $column, string $pattern, string $mode = 'c', string $boolean = 'and')
 * @method $this whereNotRegexp(string $column, string $pattern, string $mode = 'c', string $boolean = 'and')
 * @method $this whereRegexpAny(string $column, array $patterns, string $mode = 'c', string $boolean = 'and')
 * @method $this regexpExtract(string $column, string $pattern, int $group = 0, int $occurrence = 1, string $mode = 'c', string $alias = 'extracted')
 * @method $this regexpExtractAll(string $column, string $pattern, string $mode = 'c', string $alias = 'all_matches')
 * @method $this regexpReplace(string $column, string $pattern, string $replacement, int $occurrence = 0, string $mode = 'c', string $alias = 'replaced')
 * @method $this regexpReplaceBatch(string $column, array $replacements, string $mode = 'c', string $alias = 'replaced')
 * @method $this regexpPosition(string $column, string $pattern, int $occurrence = 1, string $mode = 'c', int $returnOption = 0, string $alias = 'position')
 * @method $this regexpCount(string $column, string $pattern, string $mode = 'c', string $alias = 'match_count')
 * @method $this whereRegexpCount(string $column, string $pattern, int $count, string $operator = '=', string $mode = 'c', string $boolean = 'and')
 * @method $this withAllChildren(int $id, string $pidColumn = 'pid', int $maxDepth = 100)
 * @method $this withAllParents(int $id, string $pidColumn = 'pid', int $maxDepth = 100)
 * @method $this withNthParent(int $id, int $n, string $pidColumn = 'pid')
 * @method $this withNthChildren(int $id, int $n, string $pidColumn = 'pid')
 * @method $this withFullPath(array $ids = [], array $conditions = [], string $pidColumn = 'pid', string $nameColumn = 'name', string $pathSeparator = ' > ')
 * @method bool isParentOf(int $parentId, int $childId, string $pidColumn = 'pid')
 * @method $this withSiblings(int $id, string $pidColumn = 'pid', bool $includeSelf = false)
 * @method $this withTree(?int $pid = null, string $pidColumn = 'pid', string $nameColumn = 'name', int $maxDepth = 100, string $pathSeparator = ' > ')
 * @method $this recursiveQuery(callable $baseQuery, callable $recursiveQuery, array $columns = ['*'], int $maxDepth = 100, string $depthColumn = 'depth')
 * @method $this resetRecursive()
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
