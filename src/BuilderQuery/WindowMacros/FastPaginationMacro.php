<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\Cursor as FrameworkCursor;
use Illuminate\Pagination\CursorPaginator as FrameworkCursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * 带可声明 bookmarks 属性的分页器。
 * 用于规避 PHP 8.2+ 在「非 stdClass 类上设置未声明动态属性」的弃用告警（PHP 9.0 将变为致命错误）。
 * 对外访问 $paginator->bookmarks 与 instanceof LengthAwarePaginator 行为完全兼容。
 */
class FastLengthAwarePaginator extends LengthAwarePaginator
{
    /**
     * @var array<string, mixed>|null 寻址分页书签
     */
    public ?array $bookmarks = null;
}

/**
 * 超大表快速分页查询宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 * 基于标准 SQL（OFFSET/LIMIT、ROW_NUMBER() 窗口函数、WITH 公共表表达式）实现大表分页优化，
 * 因此同一套宏在 MySQL 8.0+ 与 PostgreSQL 15+ 上均可直接使用：
 * 1. 延迟关联法：先查询ID，再关联详情（避免全表扫描）
 * 2. 窗口函数法：使用 ROW_NUMBER() 高效定位行范围
 * 3. 智能自适应：根据表大小和页码自动选择最优策略
 * 4. 游标 / 寻址分页：基于排序书签的键集分页，O(1) 复杂度
 * 不使用缓存机制，完全基于 SQL 优化。
 * 安全性：排序列名 / 主键 / 表名 / CTE 别名经白名单校验并由 grammar wrap；分页偏移量为整数。
 *
 * @version 1.13.9
 *
 * @requires MySQL 8.0+ / PostgreSQL 15+
 */
class FastPaginationMacro extends BaseMacro
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
     */
    public static function register(): void
    {
        FastPaginationMacro::registerFastPaginate();
        FastPaginationMacro::registerFastSimplePaginate();
        FastPaginationMacro::registerCursorBasedPaginate();
        FastPaginationMacro::registerSeekPaginate();
        FastPaginationMacro::registerPartitionPaginate();
    }

    /**
     * 注册智能快速分页（推荐）
     * 自动根据数据量和页码深度选择最优分页策略：
     * - 小表/浅分页：使用传统 OFFSET LIMIT
     * - 大表/深分页：使用延迟关联或窗口函数
     */
    public static function registerFastPaginate(): void
    {
        /**
         * 智能快速分页 - 自动选择最优策略
         * 核心优势：
         * 1. 无需传入游标ID，自动处理
         * 2. 根据表大小和页码智能选择策略
         * 3. 保持 LengthAwarePaginator 兼容性
         * 4. 不使用缓存，纯 SQL 优化
         * 适用场景：
         * - 数据量从几千到几亿行的表
         * - 需要总页数和完整分页信息的场景
         * - 需要跳转到任意页码的场景
         *
         * @param  int  $perPage  每页数量，默认15
         * @param  int|null  $page  当前页码，默认从请求获取
         * @param  string  $primaryKey  主键字段，默认自动获取
         * @param  array  $options  额外选项：
         *                          - strategy: 强制指定策略 'auto'|'deferred'|'window'|'offset'
         *                          - countStrategy: 计数策略 'exact'|'approximate'|'skip'
         *                          - columns: 查询列，默认['*']
         * @return LengthAwarePaginator
         *
         * @example
         * // 基础用法（自动优化）
         * $users = User::query()->fastPaginate(20);
         * // 指定策略
         * $logs = Log::query()->fastPaginate(50, null, 'id', [
         *     'strategy' => 'window',
         *     'countStrategy' => 'approximate'
         * ]);
         * // 深度分页场景
         * $records = BigTable::query()->fastPaginate(30, 5000);
         */
        static::define('fastPaginate', function (
            int $perPage = 15,
            ?int $page = null,
            ?string $primaryKey = null,
            array $options = []
        ): LengthAwarePaginator {
            /**
             * @var Builder|QueryBuilder $this
             */
            $page = $page ?: (int) (optional(request())->input('page', 1) ?? 1);
            $page = max(1, (int) $page);
            $primaryKey = $primaryKey ?: BaseMacro::keyName($this);
            $strategy = $options['strategy'] ?? 'auto';
            $countStrategy = $options['countStrategy'] ?? 'exact';
            $columns = $options['columns'] ?? ['*'];

            // 获取表名
            $table = BaseMacro::resolveTable($this);

            // 判断是否需要使用优化策略
            $needsOptimization = FastPaginationMacro::shouldOptimize($this, $page, $perPage, $strategy);

            // 获取总数
            $total = FastPaginationMacro::getTotalCount($this, $countStrategy);

            // 如果没有数据，返回空分页
            if ($total === 0) {
                return new FastLengthAwarePaginator([], 0, $perPage, $page);
            }

            // 根据策略执行分页
            if ($needsOptimization && $strategy !== 'offset') {
                $results = FastPaginationMacro::executeOptimizedPaginate(
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

            return new FastLengthAwarePaginator(
                $results,
                $total,
                $perPage,
                $page,
                ['path' => optional(request())->url() ?? '']
            );
        });
    }

    /**
     * 注册简单快速分页
     * 不计算总数，只判断是否有下一页（性能更好）
     */
    public static function registerFastSimplePaginate(): void
    {
        /**
         * 简单快速分页 - 不计算总数
         * 特点：
         * - 不执行 COUNT 查询，性能更好
         * - 通过查询 perPage+1 来判断是否有下一页
         * - 适合无限滚动、加载更多场景
         *
         * @param  int  $perPage  每页数量
         * @param  int|null  $page  当前页码
         * @param  string  $primaryKey  主键字段
         * @param  array  $options  额外选项
         * @return Paginator
         *
         * @example
         * // 无限滚动加载
         * $posts = Post::query()->fastSimplePaginate(10);
         */
        static::define('fastSimplePaginate', function (
            int $perPage = 15,
            ?int $page = null,
            ?string $primaryKey = null,
            array $options = []
        ): Paginator {
            /**
             * @var Builder|QueryBuilder $this
             */
            $page = $page ?: (int) (optional(request())->input('page', 1) ?? 1);
            $page = max(1, (int) $page);
            $primaryKey = $primaryKey ?: BaseMacro::keyName($this);
            $columns = $options['columns'] ?? ['*'];

            $table = BaseMacro::resolveTable($this);

            // 使用延迟关联策略优化
            if ($page > FastPaginationMacro::DEEP_PAGE_THRESHOLD) {
                $results = FastPaginationMacro::executeDeferredPaginate(
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

            return new Paginator(
                $results,
                $perPage,
                $page,
                ['path' => optional(request())->url() ?? '']
            )->hasMorePagesWhen($hasMore);
        });
    }

    /**
     * 注册游标分页（基于排序值的键集分页）
     * 性能最佳，但只能顺序访问
     */
    public static function registerCursorBasedPaginate(): void
    {
        /**
         * 游标分页 - 基于排序值的高效分页
         * 核心原理：
         * - 不使用 OFFSET，而是通过排序值定位
         * - WHERE sort_column > last_value LIMIT n
         * - 时间复杂度 O(1)，与页码无关
         * 限制：
         * - 只能顺序访问（下一页）
         * - 不能跳转到任意页
         * - 数据变更可能导致重复或遗漏
         *
         * @param  int  $perPage  每页数量
         * @param  string|null  $cursor  游标值（上一页最后一条数据的排序值）
         * @param  string  $sortColumn  排序字段
         * @param  string  $direction  排序方向: asc|desc
         * @param  string  $primaryKey  主键字段（用于处理相同排序值）
         * @param  array  $options  额外选项
         * @return CursorPaginator
         *
         * @example
         * // 基础用法
         * $users = User::query()->cursorPaginate(20);
         * // 指定排序字段
         * $posts = Post::query()->cursorPaginate(10, null, 'published_at', 'desc');
         * // 使用上一页的游标
         * $nextPage = Post::query()->cursorPaginate(10, $cursor, 'published_at');
         */
        static::define('seekCursorPaginate', function (
            int $perPage = 15,
            string|FrameworkCursor|null $cursor = null,
            string $sortColumn = '',
            string $direction = 'asc',
            ?string $primaryKey = null,
            array $options = []
        ): CursorPaginator {
            /**
             * @var Builder|QueryBuilder $this
             */
            $direction = strtolower($direction);
            $primaryKey = $primaryKey ?: BaseMacro::keyName($this);
            $sortColumn = $sortColumn ?: $primaryKey;
            $columns = $options['columns'] ?? ['*'];

            // 克隆查询以避免修改原始查询
            $query = clone $this;

            // 解码游标。
            //
            // 对外统一使用【框架的游标对象】（与 nextCursor() / previousCursor() 同格式），
            // 这样 $nextCursor 直接回传给下一次请求即可闭环。
            // 同时兼容调用方直接传入"编码游标串"或"排序原始值"的旧用法。
            $currentCursor = null;
            if ($cursor instanceof FrameworkCursor) {
                $currentCursor = $cursor;
            } elseif (is_string($cursor) && $cursor !== '') {
                $currentCursor = FastPaginationMacro::decodeCursor($cursor, $sortColumn, $primaryKey);
            }

            if ($currentCursor !== null) {
                // Cursor 的参数以【列名】为键（框架 ensureOrderForCursorPagination 用
                // Str::afterLast 去掉表前缀），因此必须用 $sortColumn / $primaryKey 取值。
                $sortValue = FastPaginationMacro::cursorParameter($currentCursor, $sortColumn);
                $operator = $direction === 'asc' ? '>' : '<';

                if ($sortValue !== null) {
                    $pkValue = FastPaginationMacro::cursorParameter($currentCursor, $primaryKey);

                    // 二级排序（$sortColumn, $primaryKey）→ 游标同时携带主键，
                    // 否则排序值并列的行会在翻页时被跳过或重复。
                    $query->where(function ($q) use ($sortColumn, $sortValue, $primaryKey, $pkValue, $operator): void {
                        $q->where($sortColumn, $operator, $sortValue);

                        if ($pkValue !== null) {
                            $q->orWhere(function ($q2) use ($sortColumn, $sortValue, $primaryKey, $pkValue, $operator): void {
                                $q2->where($sortColumn, '=', $sortValue)
                                    ->where($primaryKey, $operator, $pkValue);
                            });
                        }
                    });
                }
            }

            // 添加排序
            $query->orderBy($sortColumn, $direction)
                ->orderBy($primaryKey, $direction); // 二级排序确保稳定性

            // 查询数据（多查一条判断是否有下一页）
            $results = $query->limit($perPage + 1)->get($columns);

            /**
             * 交给框架自带的 Illuminate\Pagination\CursorPaginator（真实实现）。
             *
             * 原实现返回 `new class(...) implements CursorPaginator` 匿名类，但只实现了
             * 4 个方法 —— 接口还要求 cursor() / previousCursor() / path() / url() /
             * fragment() / appendQuery() / query() / currentPage() / getCollection() 等，
             * 缺失的抽象方法会让【实例化那一行就抛
             * "Class contains N abstract methods"】，整个方法 100% 不可用。
             *
             * 两个必须遵守的契约（与框架原生 cursorPaginate 一致）：
             *  1) 传入的 items 必须是 perPage+1 条（多查一条），**不要自己 slice** ——
             *     框架的 hasMore = items->count() > perPage 依赖它，先裁剪会让
             *     hasMore 恒为 false，nextCursor() 永远返回 null（分页"到第二页就没了"）。
             *  2) 传入的 cursor 是【当前】游标（即刚才用于查询的那个），
             *     nextCursor()/previousCursor() 由框架按 hasMore + 末行/首行自动推导。
             */
            return new FrameworkCursorPaginator(
                $results,
                $perPage,
                $currentCursor,
                [
                    'path' => $options['path'] ?? (optional(request())->url() ?? ''),
                    // 游标参数列。框架的 AbstractCursorPaginator::getParametersForItem()
                    // 直接用 $this->parameters 去行对象上取值，而 $this->parameters 只能由
                    // options['parameters'] 提供（框架原生 cursorPaginate 也是这么传的，
                    // 见 Illuminate\Database\Concerns\BuildsQueries::paginateUsingCursor）。
                    // 漏传会得到"参数名为空"的游标：nextCursor() 变成一个不带任何值的对象，
                    // 回传后翻不到下一页 —— 分页停在第一页，且不报错。
                    'parameters' => array_values(array_unique([$sortColumn, $primaryKey])),
                ]
            );
        });
    }

    /**
     * 读取游标中某个列参数的值（列名可能带表前缀，兼容两种形态）。
     *
     * Cursor::parameter() 对缺失的键会抛 UnexpectedValueException，
     * 这里统一降级为 null，避免翻页时因游标形态差异直接 500。
     */
    public static function cursorParameter(FrameworkCursor $cursor, string $column): mixed
    {
        foreach ([$column, last(explode('.', $column))] as $key) {
            try {
                return $cursor->parameter($key);
            } catch (Throwable) {
                // 该键不存在，试下一个
            }
        }

        return null;
    }

    /**
     * 把外部传入的游标串解析为框架 Cursor。
     *
     * 优先按框架的编码游标解析（nextCursor() 的输出格式）；解析失败时降级为
     * 「裸排序值」游标，兼容手写 / 旧版调用方直接传排序值的用法。
     */
    public static function decodeCursor(string $cursor, string $sortColumn, string $primaryKey): ?FrameworkCursor
    {
        try {
            return FrameworkCursor::fromEncoded($cursor);
        } catch (Throwable) {
            // 不是编码游标，按裸排序值处理
        }

        return new FrameworkCursor([$sortColumn => $cursor, $primaryKey => null], true);
    }

    /**
     * 注册寻址分页（Seek Method）
     * 适合深分页场景，基于书签定位
     */
    public static function registerSeekPaginate(): void
    {
        /**
         * 寻址分页 - 基于书签的深度分页方案
         * 原理：
         * - 记录每页的边界值作为书签
         * - 通过书签快速定位到指定范围
         * - 比 OFFSET 快，比游标灵活
         *
         * @param  int  $perPage  每页数量
         * @param  array|null  $bookmarks  书签数组 ['page' => ['min' => x, 'max' => y]]
         * @param  int  $page  目标页码
         * @param  string  $sortColumn  排序字段
         * @param  string  $direction  排序方向
         * @param  string|null  $primaryKey  主键字段
         * @param  array  $options  额外选项
         * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
         *
         * @example
         * // 首次查询（无书签）
         * $result = BigTable::query()->seekPaginate(100);
         * $bookmarks = $result->bookmarks; // 保存书签供后续使用
         * // 使用书签快速跳转到第100页
         * $page100 = BigTable::query()->seekPaginate(100, $bookmarks, 100);
         */
        static::define('seekPaginate', function (
            int $perPage = 100,
            ?array $bookmarks = null,
            int $page = 1,
            string $sortColumn = '',
            string $direction = 'asc',
            ?string $primaryKey = null,
            array $options = []
        ): \Illuminate\Contracts\Pagination\LengthAwarePaginator {
            /**
             * @var Builder|QueryBuilder $this
             */
            $direction = strtolower($direction);
            $primaryKey = $primaryKey ?: BaseMacro::keyName($this);
            $sortColumn = $sortColumn ?: $primaryKey;
            $columns = $options['columns'] ?? ['*'];

            $table = BaseMacro::resolveTable($this);
            $offset = ($page - 1) * $perPage;

            // 如果有书签且目标页有书签，使用书签优化
            if ($bookmarks && isset($bookmarks[$page])) {
                $bookmark = $bookmarks[$page];

                $results = $this->clone();

                if (isset($bookmark['min_pk'], $bookmark['max_pk'])) {
                    // 复合书签：(排序列, 主键) 行值比较。
                    //
                    // 只按单列闭区间 `>= min AND <= max` 在并列值跨页时会把同一行
                    // 返回两次（第 N 页的 max 值同时出现在第 N+1 页的 min 里）；
                    // 而改成半开区间又会把「本页最后一个值」直接丢掉 —— 两者都不可接受。
                    // 行值比较是键集分页的标准做法，一页恰好对应一个连续的
                    // (排序列, 主键) 字典序区间，无重复无遗漏。
                    //
                    // 降序时运算符要互换：SQL 的 >= 是数值字典序"大于等于"，
                    // 与 desc 展示顺序相反。
                    [$loOp, $hiOp] = $direction === 'desc' ? ['<=', '>='] : ['>=', '<='];
                    $wSort = BaseMacro::wrapIdentifier($this, $sortColumn);
                    $wPk = BaseMacro::wrapIdentifier($this, $primaryKey);

                    $results->where(function ($q) use ($wSort, $wPk, $bookmark, $loOp, $hiOp): void {
                        $q->whereRaw("({$wSort}, {$wPk}) {$loOp} (?, ?)", [$bookmark['min'], $bookmark['min_pk']])
                            ->whereRaw("({$wSort}, {$wPk}) {$hiOp} (?, ?)", [$bookmark['max'], $bookmark['max_pk']]);
                    });
                } else {
                    // 兼容旧格式书签（只有 min/max）
                    $results->where($sortColumn, '>=', $bookmark['min'])
                        ->where($sortColumn, '<=', $bookmark['max']);
                }

                // 二级排序（排序值, 主键）保证并列值下的稳定顺序，
                // 否则同一页内并列行的相对次序由数据库随意决定，两次请求可能顺序不同。
                $results->orderBy($sortColumn, $direction)
                    ->orderBy($primaryKey, $direction);

                $results = $results->get($columns);
            } else {
                // 使用窗口函数优化
                $results = FastPaginationMacro::executeWindowPaginate(
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
            $paginator = new FastLengthAwarePaginator(
                $results,
                $total,
                $perPage,
                $page,
                ['path' => optional(request())->url() ?? '']
            );

            // 添加书签到结果（供下次使用）
            $paginator->bookmarks = FastPaginationMacro::generateBookmarks(
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
     * 针对 MySQL 8.0+ 分区表的优化分页
     */
    public static function registerPartitionPaginate(): void
    {
        /**
         * 分区表分页 - 针对分区表的并行扫描优化
         * MySQL 8.0+ 支持分区表的并行查询优化
         * 此宏利用分区剪枝和并行扫描提升性能
         *
         * @param  int  $perPage  每页数量
         * @param  int|null  $page  当前页码
         * @param  string|null  $partitionKey  分区键字段
         * @param  array  $options  额外选项
         * @return LengthAwarePaginator
         *
         * @example
         * // 按日期分区的大表
         * $logs = Log::query()->partitionPaginate(100, 1, 'created_date');
         */
        static::define('partitionPaginate', function (
            int $perPage = 15,
            ?int $page = null,
            ?string $partitionKey = null,
            array $options = []
        ): LengthAwarePaginator {
            /**
             * @var Builder|QueryBuilder $this
             */
            $page = $page ?: (int) (optional(request())->input('page', 1) ?? 1);
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
     * @param  Builder|QueryBuilder  $query  查询构建器
     * @param  int  $page  当前页码
     * @param  int  $perPage  每页数量
     * @param  string  $strategy  策略
     * @return string|false 优化策略或false
     */
    public static function shouldOptimize(
        Builder|QueryBuilder $query,
        int $page,
        int $perPage,
        string $strategy
    ): string|false {
        // 强制指定策略
        if ($strategy !== 'auto') {
            return in_array($strategy, ['window', 'deferred']) ? $strategy : false;
        }

        // 浅分页不需要优化
        if ($page <= FastPaginationMacro::DEEP_PAGE_THRESHOLD) {
            return false;
        }

        // 估算偏移量
        $offset = ($page - 1) * $perPage;

        // 超大偏移量使用窗口函数
        if ($offset > FastPaginationMacro::LARGE_TABLE_THRESHOLD) {
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
     * @param  Builder|QueryBuilder  $query  查询构建器
     * @param  string  $strategy  计数策略
     */
    public static function getTotalCount(Builder|QueryBuilder $query, string $strategy): int
    {
        // 跳过计数（返回估算值）
        if ($strategy === 'skip') {
            return PHP_INT_MAX;
        }

        // 近似计数（使用 EXPLAIN 估算）
        if ($strategy === 'approximate') {
            return FastPaginationMacro::getApproximateCount($query);
        }

        // 精确计数
        return $query->clone()->count();
    }

    /**
     * 获取近似记录数
     *
     * @param  Builder|QueryBuilder  $query  查询构建器
     */
    public static function getApproximateCount(Builder|QueryBuilder $query): int
    {
        $table = BaseMacro::resolveTable($query);
        $wrappedTable = BaseMacro::wrapIdentifier($query, $table);

        if (BaseMacro::isPostgres($query)) {
            // PostgreSQL：从统计信息 pg_class.reltuples 读取近似行数（非精确，但与 EXPLAIN 近似语义一致）
            $result = BaseMacro::connSelect($query, 'SELECT reltuples::bigint AS rows FROM pg_class WHERE oid = '.$wrappedTable.'::regclass');
        } else {
            // MySQL：EXPLAIN 返回估算行数
            $result = BaseMacro::connSelect($query, "EXPLAIN SELECT * FROM {$wrappedTable}");
        }

        if (! empty($result) && isset($result[0]->rows)) {
            return (int) $result[0]->rows;
        }

        // 回退到精确计数
        return $query->clone()->count();
    }

    /**
     * 执行优化分页
     *
     * @param  Builder|QueryBuilder  $query  查询构建器
     * @param  string  $table  表名
     * @param  string  $primaryKey  主键
     * @param  int  $page  页码
     * @param  int  $perPage  每页数量
     * @param  string  $strategy  策略
     * @param  array  $columns  查询列
     */
    public static function executeOptimizedPaginate(
        Builder|QueryBuilder $query,
        string $table,
        string $primaryKey,
        int $page,
        int $perPage,
        string $strategy,
        array $columns
    ): Collection {
        if ($strategy === 'window') {
            return FastPaginationMacro::executeWindowPaginate(
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

        return FastPaginationMacro::executeDeferredPaginate(
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
     * 策略：先查ID，再关联详情
     *
     * @param  Builder|QueryBuilder  $query  查询构建器
     * @param  string  $table  表名
     * @param  string  $primaryKey  主键
     * @param  int  $page  页码
     * @param  int  $perPage  每页数量
     * @param  array  $columns  查询列
     */
    public static function executeDeferredPaginate(
        Builder|QueryBuilder $query,
        string $table,
        string $primaryKey,
        int $page,
        int $perPage,
        array $columns
    ): Collection {
        $offset = ($page - 1) * $perPage;

        // 子查询的排序必须【稳定】：LIMIT/OFFSET 在无稳定序时，数据库可以任意择行，
        // 同一页两次请求可能拿到不同的 ID 集合。追加主键做二级排序。
        $hasPkTiebreak = FastPaginationMacro::hasOrderOn(BaseMacro::ordersOf($query), $primaryKey);

        // 构建子查询：只查询ID
        $subQuery = $query->clone()
            ->select($primaryKey)
            ->offset($offset)
            ->limit($perPage);

        if (! $hasPkTiebreak) {
            $subQuery->orderBy($primaryKey, 'asc');
        }

        // 主查询：根据ID获取完整数据
        $ids = $subQuery->pluck($primaryKey);

        if ($ids->isEmpty()) {
            return collect([]);
        }

        $main = $query->clone()->whereIn($primaryKey, $ids);

        // 原实现的致命疏漏：主查询**没有保留调用方的 orderBy**。
        // whereIn(...) 返回的行顺序取决于执行计划，而不是调用方要的排序 ——
        // 分页结果会错序，且同一页两次请求顺序可能不同。
        // 这里保留原排序；调用方完全没给排序时补一个主键序，保证结果稳定可复现。
        if (! $hasPkTiebreak) {
            $main->orderBy($primaryKey, 'asc');
        }

        return $main->get($columns);
    }

    /**
     * 判断 orders 列表里是否已有针对指定列的排序（列名可能被 grammar 引用过）。
     *
     * @param  array<int, mixed>  $orders
     */
    public static function hasOrderOn(array $orders, string $column): bool
    {
        foreach ($orders as $order) {
            if (! is_array($order)) {
                continue;
            }

            $target = (string) ($order['column'] ?? '');
            if ($target === '' || $target === $column) {
                continue;
            }

            // orders 里存的可能已被 grammar wrap（如 `id`），统一去掉反引号再比
            if (str_replace('`', '', $target) === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * 执行窗口函数分页
     * 使用 ROW_NUMBER() 高效定位行范围
     *
     * @param  Builder|QueryBuilder  $query  查询构建器
     * @param  string  $table  表名
     * @param  string  $primaryKey  主键
     * @param  string  $sortColumn  排序字段
     * @param  int  $page  页码
     * @param  int  $perPage  每页数量
     * @param  string  $direction  排序方向
     * @param  array  $columns  查询列
     */
    public static function executeWindowPaginate(
        Builder|QueryBuilder $query,
        string $table,
        string $primaryKey,
        string $sortColumn,
        int $page,
        int $perPage,
        string $direction,
        array $columns
    ): Collection {
        $offset = ($page - 1) * $perPage;
        $sortColumn = $sortColumn ?: $primaryKey;
        $direction = BaseMacro::assertDirection($direction);
        $primaryKey = BaseMacro::assertValidIdentifier($primaryKey, 'primaryKey');
        $sortColumn = BaseMacro::assertValidIdentifier($sortColumn, 'order');
        $wrappedTable = BaseMacro::wrapIdentifier($query, $table);
        $wrappedPk = BaseMacro::wrapIdentifier($query, $primaryKey);
        $wrappedSort = BaseMacro::wrapIdentifier($query, $sortColumn);

        // 构建列列表
        $isSelectAll = $columns === ['*'] || empty($columns) || (count($columns) === 1 && reset($columns) === '*');
        $columnList = $isSelectAll
            ? '*'
            : implode(', ', array_map(
                fn ($col) => BaseMacro::wrapIdentifier($query, BaseMacro::assertValidIdentifier((string) $col, 'column')),
                $columns
            ));

        // 获取原始查询的 WHERE 条件
        $baseQuery = $query->clone();
        // 清除排序 / 分页（稍后重新添加）。
        // 必须连同 bindings['order'] 一起清：调用方用过 orderByRaw('FIELD(x, ?, ?)', [...])
        // 时残留绑定会顶在 getBindings() 最前，而 SQL 里已没有对应占位符 → 绑定错位。
        BaseMacro::queryOf($baseQuery)->orders = [];
        BaseMacro::queryOf($baseQuery)->bindings['order'] = [];
        BaseMacro::queryOf($baseQuery)->limit = null;
        BaseMacro::queryOf($baseQuery)->offset = null;

        $whereSql = $baseQuery->toSql();
        $bindings = $baseQuery->getBindings();

        // 构建窗口函数分页查询
        $withTable = 'paginated_'.uniqid();
        $wrappedWith = BaseMacro::wrapIdentifier($query, $withTable);

        $sql = "
            WITH {$wrappedWith} AS (
                SELECT {$columnList},
                       ROW_NUMBER() OVER (ORDER BY {$wrappedSort} {$direction}) AS __row_num
                FROM {$wrappedTable}
                WHERE {$wrappedPk} IN (
                    SELECT {$wrappedPk} FROM ({$whereSql}) AS base_query
                )
            )
            SELECT * FROM {$wrappedWith}
            WHERE __row_num > {$offset} AND __row_num <= ".($offset + $perPage).'
            ORDER BY __row_num
        ';

        $results = BaseMacro::connSelect($query, $sql, $bindings);

        // DB::table() 等 Query\Builder 没有关联模型，modelOf 返回 null，需返回纯集合而非调用 hydrate（否则 null->hydrate() 致命错误）。
        $model = BaseMacro::modelOf($query);
        if ($model !== null) {
            return $model->hydrate($results);
        }

        return new Collection($results);
    }

    /**
     * 生成分页书签
     *
     * @param  Builder|QueryBuilder  $query  查询构建器
     * @param  string  $sortColumn  排序字段
     * @param  int  $perPage  每页数量
     * @param  string  $direction  排序方向
     */
    public static function generateBookmarks(
        Builder|QueryBuilder $query,
        string $sortColumn,
        int $perPage,
        string $direction
    ): array {
        $bookmarks = [];
        $page = 1;

        // 必须同时 select 主键、且排序带上主键：
        //  1) Eloquent 的 chunk() 用「上一批最后一行的主键」推进 offset/limit，
        //     只 select 排序列时该值为 null，每批偏移都算错 → **静默跳行**；
        //  2) 排序列存在并列值时（如 created_at 精确到秒），单列游标无法区分同值行，
        //     必须用 (排序列, 主键) 复合键才能唯一确定一页的边界。
        $primaryKey = BaseMacro::keyName($query);
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        $inner = $query->clone()
            ->orderBy($sortColumn, $direction)
            ->orderBy($primaryKey, $direction);

        $inner->select(array_values(array_unique([$primaryKey, $sortColumn])));

        $inner->chunk($perPage, function ($items) use (&$bookmarks, &$page, $sortColumn, $primaryKey) {
            if ($items->isNotEmpty()) {
                $bookmarks[$page] = [
                    'min' => $items->first()->{$sortColumn},
                    'max' => $items->last()->{$sortColumn},
                    // 复合书签：与 min/max 同序的边界主键
                    'min_pk' => $items->first()->{$primaryKey},
                    'max_pk' => $items->last()->{$primaryKey},
                ];
                $page++;
            }

            // 只生成前100页的书签
            return $page <= 100;
        });

        return $bookmarks;
    }
}
