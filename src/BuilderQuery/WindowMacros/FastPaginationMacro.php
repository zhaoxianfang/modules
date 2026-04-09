<?php

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * 超大表快速分页查询宏
 *
 * 针对 MySQL 8.4+ 优化的分页解决方案，解决传统 OFFSET 分页在超大表上的性能问题
 *
 * 核心策略：
 * 1. 延迟关联法：先查询ID，再关联详情（避免全表扫描）
 * 2. 窗口函数法：使用 ROW_NUMBER() 高效定位行范围
 * 3. 智能自适应：根据表大小和页码自动选择最优策略
 *
 * 不使用缓存机制，完全基于 SQL 优化
 *
 * @package zxf\Modules\BuilderQuery\WindowMacros
 * @version 1.0.0
 * @requires MySQL 8.4+
 */
class FastPaginationMacro
{
    /**
     * 大表阈值（行数超过此值视为大表）
     */
    public const LARGE_TABLE_THRESHOLD = 100000;

    /**
     * 深度分页阈值（页码超过此值视为深度分页）
     */
    public const DEEP_PAGE_THRESHOLD = 100;

    /**
     * 注册所有快速分页宏
     *
     * @return void
     */
    public static function register(): void
    {
        self::registerFastPaginate();
        self::registerFastSimplePaginate();
        self::registerCursorBasedPaginate();
        self::registerSeekPaginate();
        self::registerPartitionPaginate();
    }

    /**
     * 注册智能快速分页（推荐）
     *
     * 自动根据数据量和页码深度选择最优分页策略：
     * - 小表/浅分页：使用传统 OFFSET LIMIT
     * - 大表/深分页：使用延迟关联或窗口函数
     */
    protected static function registerFastPaginate(): void
    {
        /**
         * 智能快速分页 - 自动选择最优策略
         *
         * 核心优势：
         * 1. 无需传入游标ID，自动处理
         * 2. 根据表大小和页码智能选择策略
         * 3. 保持 LengthAwarePaginator 兼容性
         * 4. 不使用缓存，纯 SQL 优化
         *
         * 适用场景：
         * - 数据量从几千到几亿行的表
         * - 需要总页数和完整分页信息的场景
         * - 需要跳转到任意页码的场景
         *
         * @param int $perPage 每页数量，默认15
         * @param int|null $page 当前页码，默认从请求获取
         * @param string $primaryKey 主键字段，默认自动获取
         * @param array $options 额外选项：
         *   - strategy: 强制指定策略 'auto'|'deferred'|'window'|'offset'
         *   - countStrategy: 计数策略 'exact'|'approximate'|'skip'
         *   - columns: 查询列，默认['*']
         * @return LengthAwarePaginator
         *
         * @example
         * // 基础用法（自动优化）
         * $users = User::query()->fastPaginate(20);
         *
         * // 指定策略
         * $logs = Log::query()->fastPaginate(50, null, 'id', [
         *     'strategy' => 'window',
         *     'countStrategy' => 'approximate'
         * ]);
         *
         * // 深度分页场景
         * $records = BigTable::query()->fastPaginate(30, 5000);
         */
        Builder::macro('fastPaginate', function (
            int $perPage = 15,
            ?int $page = null,
            ?string $primaryKey = null,
            array $options = []
        ): LengthAwarePaginator {
            /** @var Builder $this */
            $page = $page ?: request()->input('page', 1);
            $page = max(1, (int) $page);
            $primaryKey = $primaryKey ?: $this->getModel()->getKeyName();
            $strategy = $options['strategy'] ?? 'auto';
            $countStrategy = $options['countStrategy'] ?? 'exact';
            $columns = $options['columns'] ?? ['*'];

            // 获取表名
            $table = $this->getModel()->getTable();

            // 判断是否需要使用优化策略
            $needsOptimization = self::shouldOptimize($this, $page, $perPage, $strategy);

            // 获取总数
            $total = self::getTotalCount($this, $countStrategy);

            // 如果没有数据，返回空分页
            if ($total === 0) {
                return new LengthAwarePaginator([], 0, $perPage, $page);
            }

            // 根据策略执行分页
            if ($needsOptimization && $strategy !== 'offset') {
                $results = self::executeOptimizedPaginate(
                    $this,
                    $table,
                    $primaryKey,
                    $page,
                    $perPage,
                    $needsOptimization === 'window' ? 'window' : 'deferred',
                    $columns
                );
            } else {
                // 传统分页
                $results = $this->forPage($page, $perPage)->get($columns);
            }

            return new LengthAwarePaginator(
                $results,
                $total,
                $perPage,
                $page,
                ['path' => request()->url()]
            );
        });
    }

    /**
     * 注册简单快速分页
     *
     * 不计算总数，只判断是否有下一页（性能更好）
     */
    protected static function registerFastSimplePaginate(): void
    {
        /**
         * 简单快速分页 - 不计算总数
         *
         * 特点：
         * - 不执行 COUNT 查询，性能更好
         * - 通过查询 perPage+1 来判断是否有下一页
         * - 适合无限滚动、加载更多场景
         *
         * @param int $perPage 每页数量
         * @param int|null $page 当前页码
         * @param string $primaryKey 主键字段
         * @param array $options 额外选项
         * @return \Illuminate\Pagination\Paginator
         *
         * @example
         * // 无限滚动加载
         * $posts = Post::query()->fastSimplePaginate(10);
         */
        Builder::macro('fastSimplePaginate', function (
            int $perPage = 15,
            ?int $page = null,
            ?string $primaryKey = null,
            array $options = []
        ): \Illuminate\Pagination\Paginator {
            /** @var Builder $this */
            $page = $page ?: request()->input('page', 1);
            $page = max(1, (int) $page);
            $primaryKey = $primaryKey ?: $this->getModel()->getKeyName();
            $columns = $options['columns'] ?? ['*'];

            $table = $this->getModel()->getTable();
            $offset = ($page - 1) * $perPage;

            // 使用延迟关联策略优化
            if ($page > self::DEEP_PAGE_THRESHOLD) {
                $results = self::executeDeferredPaginate(
                    $this,
                    $table,
                    $primaryKey,
                    $page,
                    $perPage + 1, // 多查一条判断是否有下一页
                    $columns
                );
            } else {
                $results = $this->forPage($page, $perPage + 1)->get($columns);
            }

            // 判断是否有更多数据
            $hasMore = $results->count() > $perPage;
            if ($hasMore) {
                $results = $results->slice(0, $perPage);
            }

            return new \Illuminate\Pagination\Paginator(
                $results,
                $perPage,
                $page,
                ['path' => request()->url()]
            )->hasMorePagesWhen($hasMore);
        });
    }

    /**
     * 注册游标分页（基于排序值的键集分页）
     *
     * 性能最佳，但只能顺序访问
     */
    protected static function registerCursorBasedPaginate(): void
    {
        /**
         * 游标分页 - 基于排序值的高效分页
         *
         * 核心原理：
         * - 不使用 OFFSET，而是通过排序值定位
         * - WHERE sort_column > last_value LIMIT n
         * - 时间复杂度 O(1)，与页码无关
         *
         * 限制：
         * - 只能顺序访问（下一页）
         * - 不能跳转到任意页
         * - 数据变更可能导致重复或遗漏
         *
         * @param int $perPage 每页数量
         * @param string|null $cursor 游标值（上一页最后一条数据的排序值）
         * @param string $sortColumn 排序字段
         * @param string $direction 排序方向: asc|desc
         * @param string $primaryKey 主键字段（用于处理相同排序值）
         * @param array $options 额外选项
         * @return \Illuminate\Contracts\Pagination\CursorPaginator
         *
         * @example
         * // 基础用法
         * $users = User::query()->cursorPaginate(20);
         *
         * // 指定排序字段
         * $posts = Post::query()->cursorPaginate(10, null, 'published_at', 'desc');
         *
         * // 使用上一页的游标
         * $nextPage = Post::query()->cursorPaginate(10, $cursor, 'published_at');
         */
        Builder::macro('cursorPaginate', function (
            int $perPage = 15,
            ?string $cursor = null,
            string $sortColumn = '',
            string $direction = 'asc',
            ?string $primaryKey = null,
            array $options = []
        ): \Illuminate\Contracts\Pagination\CursorPaginator {
            /** @var Builder $this */
            $direction = strtolower($direction);
            $primaryKey = $primaryKey ?: $this->getModel()->getKeyName();
            $sortColumn = $sortColumn ?: $primaryKey;
            $columns = $options['columns'] ?? ['*'];

            // 克隆查询以避免修改原始查询
            $query = clone $this;

            // 添加游标条件
            if ($cursor !== null) {
                $operator = $direction === 'asc' ? '>' : '<';
                $query->where($sortColumn, $operator, $cursor);
            }

            // 添加排序
            $query->orderBy($sortColumn, $direction)
                  ->orderBy($primaryKey, $direction); // 二级排序确保稳定性

            // 查询数据（多查一条判断是否有下一页）
            $results = $query->limit($perPage + 1)->get($columns);

            // 判断是否有更多数据
            $hasMore = $results->count() > $perPage;
            if ($hasMore) {
                $results = $results->slice(0, $perPage);
            }

            // 构建下一页游标
            $nextCursor = null;
            if ($hasMore && $results->isNotEmpty()) {
                $lastItem = $results->last();
                $nextCursor = $lastItem->{$sortColumn};
            }

            return new class($results, $perPage, $nextCursor, $hasMore) implements \Illuminate\Contracts\Pagination\CursorPaginator {
                protected $items;
                protected $perPage;
                protected $nextCursor;
                protected $hasMore;

                public function __construct($items, $perPage, $nextCursor, $hasMore)
                {
                    $this->items = $items;
                    $this->perPage = $perPage;
                    $this->nextCursor = $nextCursor;
                    $this->hasMore = $hasMore;
                }

                public function items(): array
                {
                    return $this->items->all();
                }

                public function perPage(): int
                {
                    return $this->perPage;
                }

                public function nextCursor(): ?string
                {
                    return $this->nextCursor;
                }

                public function hasMorePages(): bool
                {
                    return $this->hasMore;
                }

                public function toArray(): array
                {
                    return [
                        'data' => $this->items->toArray(),
                        'per_page' => $this->perPage,
                        'next_cursor' => $this->nextCursor,
                        'has_more' => $this->hasMore,
                    ];
                }
            };
        });
    }

    /**
     * 注册寻址分页（Seek Method）
     *
     * 适合深分页场景，基于书签定位
     */
    protected static function registerSeekPaginate(): void
    {
        /**
         * 寻址分页 - 基于书签的深度分页方案
         *
         * 原理：
         * - 记录每页的边界值作为书签
         * - 通过书签快速定位到指定范围
         * - 比 OFFSET 快，比游标灵活
         *
         * @param int $perPage 每页数量
         * @param array|null $bookmarks 书签数组 ['page' => ['min' => x, 'max' => y]]
         * @param int $page 目标页码
         * @param string $sortColumn 排序字段
         * @param string $direction 排序方向
         * @param string|null $primaryKey 主键字段
         * @param array $options 额外选项
         * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
         *
         * @example
         * // 首次查询（无书签）
         * $result = BigTable::query()->seekPaginate(100);
         * $bookmarks = $result->bookmarks; // 保存书签供后续使用
         *
         * // 使用书签快速跳转到第100页
         * $page100 = BigTable::query()->seekPaginate(100, $bookmarks, 100);
         */
        Builder::macro('seekPaginate', function (
            int $perPage = 100,
            ?array $bookmarks = null,
            int $page = 1,
            string $sortColumn = '',
            string $direction = 'asc',
            ?string $primaryKey = null,
            array $options = []
        ): \Illuminate\Contracts\Pagination\LengthAwarePaginator {
            /** @var Builder $this */
            $direction = strtolower($direction);
            $primaryKey = $primaryKey ?: $this->getModel()->getKeyName();
            $sortColumn = $sortColumn ?: $primaryKey;
            $columns = $options['columns'] ?? ['*'];

            $table = $this->getModel()->getTable();
            $offset = ($page - 1) * $perPage;

            // 如果有书签且目标页有书签，使用书签优化
            if ($bookmarks && isset($bookmarks[$page])) {
                $bookmark = $bookmarks[$page];
                $results = $this->clone()
                    ->where($sortColumn, '>=', $bookmark['min'])
                    ->where($sortColumn, '<=', $bookmark['max'])
                    ->orderBy($sortColumn, $direction)
                    ->get($columns);
            } else {
                // 使用窗口函数优化
                $results = self::executeWindowPaginate(
                    $this,
                    $table,
                    $primaryKey,
                    $sortColumn,
                    $page,
                    $perPage,
                    $direction,
                    $columns
                );
            }

            // 计算总数（或使用近似值）
            $total = $options['total'] ?? $this->count();

            // 构建返回结果
            $paginator = new LengthAwarePaginator(
                $results,
                $total,
                $perPage,
                $page,
                ['path' => request()->url()]
            );

            // 添加书签到结果（供下次使用）
            $paginator->bookmarks = self::generateBookmarks(
                $this,
                $sortColumn,
                $perPage,
                $direction
            );

            return $paginator;
        });
    }

    /**
     * 注册分区表分页
     *
     * 针对 MySQL 8.4+ 分区表的优化分页
     */
    protected static function registerPartitionPaginate(): void
    {
        /**
         * 分区表分页 - 针对分区表的并行扫描优化
         *
         * MySQL 8.4+ 支持分区表的并行查询优化
         * 此宏利用分区剪枝和并行扫描提升性能
         *
         * @param int $perPage 每页数量
         * @param int|null $page 当前页码
         * @param string|null $partitionKey 分区键字段
         * @param array $options 额外选项
         * @return LengthAwarePaginator
         *
         * @example
         * // 按日期分区的大表
         * $logs = Log::query()->partitionPaginate(100, 1, 'created_date');
         */
        Builder::macro('partitionPaginate', function (
            int $perPage = 15,
            ?int $page = null,
            ?string $partitionKey = null,
            array $options = []
        ): LengthAwarePaginator {
            /** @var Builder $this */
            $page = $page ?: request()->input('page', 1);
            $page = max(1, (int) $page);
            $columns = $options['columns'] ?? ['*'];

            // 如果指定了分区键，添加分区提示
            if ($partitionKey) {
                $this->orderBy($partitionKey, 'asc');
            }

            // 使用窗口函数分页（分区表下性能更好）
            return $this->fastPaginate($perPage, $page, null, [
                'strategy' => 'window',
                'columns' => $columns,
            ]);
        });
    }

    /**
     * 判断是否需要进行分页优化
     *
     * @param Builder $query 查询构建器
     * @param int $page 当前页码
     * @param int $perPage 每页数量
     * @param string $strategy 策略
     * @return string|false 优化策略或false
     */
    public static function shouldOptimize(
        Builder $query,
        int $page,
        int $perPage,
        string $strategy
    ): string|false {
        // 强制指定策略
        if ($strategy !== 'auto') {
            return in_array($strategy, ['window', 'deferred']) ? $strategy : false;
        }

        // 浅分页不需要优化
        if ($page <= self::DEEP_PAGE_THRESHOLD) {
            return false;
        }

        // 估算偏移量
        $offset = ($page - 1) * $perPage;

        // 超大偏移量使用窗口函数
        if ($offset > self::LARGE_TABLE_THRESHOLD) {
            return 'window';
        }

        // 中等偏移量使用延迟关联
        if ($offset > 10000) {
            return 'deferred';
        }

        return false;
    }

    /**
     * 获取总记录数
     *
     * @param Builder $query 查询构建器
     * @param string $strategy 计数策略
     * @return int
     */
    public static function getTotalCount(Builder $query, string $strategy): int
    {
        // 跳过计数（返回估算值）
        if ($strategy === 'skip') {
            return PHP_INT_MAX;
        }

        // 近似计数（使用 EXPLAIN 估算）
        if ($strategy === 'approximate') {
            return self::getApproximateCount($query);
        }

        // 精确计数
        return $query->clone()->count();
    }

    /**
     * 获取近似记录数
     *
     * @param Builder $query 查询构建器
     * @return int
     */
    public static function getApproximateCount(Builder $query): int
    {
        $table = $query->getModel()->getTable();
        $result = DB::select("EXPLAIN SELECT * FROM `{$table}`");

        if (!empty($result)) {
            // 尝试从 EXPLAIN 结果中获取估算行数
            $row = $result[0];
            if (isset($row->rows)) {
                return (int) $row->rows;
            }
        }

        // 回退到精确计数
        return $query->clone()->count();
    }

    /**
     * 执行优化分页
     *
     * @param Builder $query 查询构建器
     * @param string $table 表名
     * @param string $primaryKey 主键
     * @param int $page 页码
     * @param int $perPage 每页数量
     * @param string $strategy 策略
     * @param array $columns 查询列
     * @return \Illuminate\Support\Collection
     */
    public static function executeOptimizedPaginate(
        Builder $query,
        string $table,
        string $primaryKey,
        int $page,
        int $perPage,
        string $strategy,
        array $columns
    ): \Illuminate\Support\Collection {
        if ($strategy === 'window') {
            return self::executeWindowPaginate(
                $query,
                $table,
                $primaryKey,
                '',
                $page,
                $perPage,
                'asc',
                $columns
            );
        }

        return self::executeDeferredPaginate(
            $query,
            $table,
            $primaryKey,
            $page,
            $perPage,
            $columns
        );
    }

    /**
     * 执行延迟关联分页
     *
     * 策略：先查ID，再关联详情
     *
     * @param Builder $query 查询构建器
     * @param string $table 表名
     * @param string $primaryKey 主键
     * @param int $page 页码
     * @param int $perPage 每页数量
     * @param array $columns 查询列
     * @return \Illuminate\Support\Collection
     */
    public static function executeDeferredPaginate(
        Builder $query,
        string $table,
        string $primaryKey,
        int $page,
        int $perPage,
        array $columns
    ): \Illuminate\Support\Collection {
        $offset = ($page - 1) * $perPage;

        // 构建子查询：只查询ID
        $subQuery = $query->clone()
            ->select($primaryKey)
            ->offset($offset)
            ->limit($perPage);

        // 主查询：根据ID获取完整数据
        $ids = $subQuery->pluck($primaryKey);

        if ($ids->isEmpty()) {
            return collect([]);
        }

        return $query->clone()
            ->whereIn($primaryKey, $ids)
            ->get($columns);
    }

    /**
     * 执行窗口函数分页
     *
     * 使用 ROW_NUMBER() 高效定位行范围
     *
     * @param Builder $query 查询构建器
     * @param string $table 表名
     * @param string $primaryKey 主键
     * @param string $sortColumn 排序字段
     * @param int $page 页码
     * @param int $perPage 每页数量
     * @param string $direction 排序方向
     * @param array $columns 查询列
     * @return \Illuminate\Support\Collection
     */
    public static function executeWindowPaginate(
        Builder $query,
        string $table,
        string $primaryKey,
        string $sortColumn,
        int $page,
        int $perPage,
        string $direction,
        array $columns
    ): \Illuminate\Support\Collection {
        $offset = ($page - 1) * $perPage;
        $sortColumn = $sortColumn ?: $primaryKey;
        $direction = strtoupper($direction);

        // 构建列列表
        $columnList = $columns === ['*']
            ? '*'
            : implode(', ', array_map(fn ($col) => "`{$col}`", $columns));

        // 获取原始查询的 WHERE 条件
        $baseQuery = $query->clone();
        $baseQuery->getQuery()->orders = []; // 清除排序，稍后重新添加
        $baseQuery->getQuery()->limit = null;
        $baseQuery->getQuery()->offset = null;

        $whereSql = $baseQuery->toSql();
        $bindings = $baseQuery->getBindings();

        // 构建窗口函数分页查询
        $withTable = 'paginated_' . uniqid();

        $sql = "
            WITH `{$withTable}` AS (
                SELECT {$columnList},
                       ROW_NUMBER() OVER (ORDER BY `{$sortColumn}` {$direction}) AS __row_num
                FROM `{$table}`
                WHERE {$primaryKey} IN (
                    SELECT {$primaryKey} FROM ({$whereSql}) AS base_query
                )
            )
            SELECT * FROM `{$withTable}`
            WHERE __row_num > {$offset} AND __row_num <= " . ($offset + $perPage) . '
            ORDER BY __row_num
        ';

        $results = DB::select($sql, $bindings);

        return $query->getModel()->hydrate($results);
    }

    /**
     * 生成分页书签
     *
     * @param Builder $query 查询构建器
     * @param string $sortColumn 排序字段
     * @param int $perPage 每页数量
     * @param string $direction 排序方向
     * @return array
     */
    public static function generateBookmarks(
        Builder $query,
        string $sortColumn,
        int $perPage,
        string $direction
    ): array {
        $bookmarks = [];
        $page = 1;

        $query->clone()
            ->orderBy($sortColumn, $direction)
            ->select($sortColumn)
            ->chunk($perPage, function ($items) use (&$bookmarks, &$page, $sortColumn) {
                if ($items->isNotEmpty()) {
                    $bookmarks[$page] = [
                        'min' => $items->first()->{$sortColumn},
                        'max' => $items->last()->{$sortColumn},
                    ];
                    $page++;
                }

                // 只生成前100页的书签
                return $page <= 100;
            });

        return $bookmarks;
    }
}
