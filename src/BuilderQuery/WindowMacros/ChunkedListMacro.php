<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;
use zxf\Modules\BuilderQuery\Concerns\SqlSecurity;

/**
 * 超大 IN / NOT IN 列表分块宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 * 基于 study_mysql「08-playbook / 02-query-rewrite-cookbook」「04-performance / 01-query-rewrite-antipatterns」
 * 中关于「巨型 IN 列表」的性能反模式系统化封装。系统开发中，将数万/数十万条 ID 直接拼进单个
 * `WHERE col IN (?, ?, ...)` 是高频的性能与稳定性事故源：
 *  1. **占位符上限**：MySQL 单条语句占位符上限约 65535，超出直接报 SQL 错误（异常）；
 *  2. **优化器退化**：过大的 IN 列表使优化器难以选择索引，常退化为全表/全索引扫描（卡慢）；
 *  3. **内存与网络暴涨**：构造超长 SQL 字符串、绑定巨大数组，造成 PHP 内存与连接包体飙升（资源占用高）。
 * 本模块将大数组按 `$chunkSize`（默认 1000）切成多块，生成 `(col IN (块1)) OR (col IN (块2)) ...`
 * （IN 场景用 OR 合并，等价原语义）或 `(col NOT IN (块1)) AND (col NOT IN (块2)) ...`
 * （NOT IN 场景用 AND 合并，等价原语义），从根本上规避上述三类故障。
 * 安全与效率约定：
 *  - 列名经白名单校验（{@see SqlSecurity::assertValidIdentifier()}），杜绝注入；
 *  - 入参数组先 `array_unique` 去重，减少无效占位符；
 *  - `$chunkSize` 强制 `max(1, ...)`，避免除零/空块；
 *  - **空集合安全**：`IN ()` 是非法 SQL，故空数组时 `whereInChunked` 直接返回零行（等效 `1 = 0`），
 *    `whereNotInChunked` 空数组语义为「全表」故不加任何约束（等效 `1 = 1`）；
 *  - 单块时直接委托原生 `whereIn`/`whereNotIn`，生成最优单条 SQL，不引入多余嵌套；
 *  - 全程仅做数组与构建器条件拼装，**不触发任何 SQL 执行**，绝不会因数据量大而导致卡死。
 * 双构建器友好：全部通过 {@see BaseMacro::define()} 同时注册到 Eloquent\Builder 与 Query\Builder。
 *
 * @version 3.3.0
 *
 * @requires PHP 8.2+（兼容 8.3/8.4/8.5），Laravel 11+ / 12+ / 13+，MySQL 8.0+ / PostgreSQL 15+
 *
 * @scenario 超大 IN / NOT IN 列表分块宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 */
class ChunkedListMacro extends BaseMacro
{
    /**
     *  默认分块大小（占位符数量上限内的安全值）。
     *
     * @scenario 默认分块大小（占位符数量上限内的安全值）。
     */
    public const DEFAULT_CHUNK_SIZE = 1000;

    /**
     *  注册所有分块 IN/NOT IN 宏。
     *
     * @scenario 注册所有分块 IN/NOT IN 宏。
     */
    public static function register(): void
    {
        self::registerInChunked();
        self::registerNotInChunked();
    }

    /**
     * 注册 whereInChunked / orWhereInChunked（IN 场景，多块以 OR 合并）。
     *
     * @scenario 注册 whereInChunked / orWhereInChunked（IN 场景，多块以 OR 合并）。
     */
    public static function registerInChunked(): void
    {
        /**
         * 将超大值列表分块后生成 `col IN (...)`（多块以 OR 合并），避免单条巨 IN 的占位符/优化器/内存问题。
         *
         * @param  string  $column  目标列（支持 `表.列`，经白名单校验）
         * @param  array  $values  待匹配值列表（自动去重）
         * @param  int  $chunkSize  每块大小（默认 1000，自动取 max(1, ...)）
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 一次性筛选 5 万个订单 ID，自动切成 50 个 IN 块 OR 合并，规避占位符上限与优化器退化
         * Order::query()->whereInChunked('id', $hugeIdList)->get();
         * DB::table('orders')->whereInChunked('id', $hugeIdList, 2000)->get();
         *
         * @scenario 后台批量操作、导入对账、跨系统 ID 同步等需把大数组作为 IN 条件的场景；
         *           若值来自子查询，请优先改用 whereHasIn / whereIn(闭包子查询) 以减少网络传输。
         */
        static::define('whereInChunked', function (
            string $column,
            array $values,
            int $chunkSize = ChunkedListMacro::DEFAULT_CHUNK_SIZE
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */

            return ChunkedListMacro::applyChunked($this, $column, $values, $chunkSize, false, 'and');
        });

        /**
         * 作为 OR 条件追加的分块 IN（语义同 whereInChunked，但整体以 OR 接入外层查询）。
         *
         * @param  string  $column
         * @param  array  $values
         * @param  int  $chunkSize
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // (status = 1) OR (id IN (大列表分块))
         * Order::query()->where('status', 1)->orWhereInChunked('id', $hugeIdList)->get();
         *
         * @scenario 复杂组合筛选中，某一 OR 分支需要匹配超大 ID 集合。
         */
        static::define('orWhereInChunked', function (
            string $column,
            array $values,
            int $chunkSize = ChunkedListMacro::DEFAULT_CHUNK_SIZE
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */

            return ChunkedListMacro::applyChunked($this, $column, $values, $chunkSize, false, 'or');
        });
    }

    /**
     * 注册 whereNotInChunked / orWhereNotInChunked（NOT IN 场景，多块以 AND 合并）。
     *
     * @scenario 注册 whereNotInChunked / orWhereNotInChunked（NOT IN 场景，多块以 AND 合并）。
     */
    public static function registerNotInChunked(): void
    {
        /**
         * 将超大值列表分块后生成 `col NOT IN (...)`（多块以 AND 合并），避免单条巨 NOT IN 的占位符/优化器/内存问题。
         *
         * @param  string  $column  目标列（支持 `表.列`，经白名单校验）
         * @param  array  $values  待排除值列表（自动去重）
         * @param  int  $chunkSize  每块大小（默认 1000，自动取 max(1, ...)）
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 排除数万条已处理 ID，自动切成多块 NOT IN 以 AND 合并
         * Order::query()->whereNotInChunked('id', $processedIds)->get();
         *
         * @scenario 黑名单/已处理集合排除；若集合极大且来自子查询，建议改用反连接（NOT EXISTS）以获取更优执行计划。
         */
        static::define('whereNotInChunked', function (
            string $column,
            array $values,
            int $chunkSize = ChunkedListMacro::DEFAULT_CHUNK_SIZE
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */

            return ChunkedListMacro::applyChunked($this, $column, $values, $chunkSize, true, 'and');
        });

        /**
         * 作为 OR 条件追加的分块 NOT IN（语义同 whereNotInChunked，但整体以 OR 接入外层查询）。
         *
         * @param  string  $column
         * @param  array  $values
         * @param  int  $chunkSize
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // (status = 0) OR (id NOT IN (大列表分块))
         * Order::query()->where('status', 0)->orWhereNotInChunked('id', $processedIds)->get();
         *
         * @scenario 复杂组合筛选中，某一 OR 分支需要排除超大 ID 集合。
         */
        static::define('orWhereNotInChunked', function (
            string $column,
            array $values,
            int $chunkSize = ChunkedListMacro::DEFAULT_CHUNK_SIZE
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */

            return ChunkedListMacro::applyChunked($this, $column, $values, $chunkSize, true, 'or');
        });
    }

    /**
     * 分块 IN/NOT IN 的统一实现（宏闭包外部，可安全使用显式类名调用）。
     * 语义合并规则：
     *  - IN  场景：原语义为「等于集合中任一元素」，多块以 **OR** 合并；
     *  - NOT IN 场景：原语义为「不等于集合中任一元素」，多块以 **AND** 合并（等价于对所有块分别排除）。
     * 空集合处理：
     *  - IN 空：`IN ()` 非法，直接返回零行（等效 `whereRaw('1 = 0')`）；
     *  - NOT IN 空：语义为「不排除任何行」，故不加任何约束，直接返回原构建器。
     *
     * @param  string  $column  原始列名（尚未 wrap，交由 whereIn 自行 wrap，避免重复引用）
     * @param  array  $values  待匹配/排除值（将去重）
     * @param  int  $chunkSize  每块大小（内部取 max(1, ...)）
     * @param  bool  $not  true=NOT IN，false=IN
     * @param  string  $boolean  'and'|'or' 外层连接符
     *
     * @scenario 分块 IN/NOT IN 的统一实现（宏闭包外部，可安全使用显式类名调用）。
     */
    public static function applyChunked(
        Builder|\Illuminate\Database\Query\Builder $builder,
        string $column,
        array $values,
        int $chunkSize,
        bool $not,
        string $boolean
    ): Builder|\Illuminate\Database\Query\Builder {
        // 白名单校验列标识符（防注入）；此处传原始列名给 whereIn/whereNotIn，由其内部统一 wrap。
        $column = BaseMacro::assertValidIdentifier($column, 'column');

        // 去重并重置索引，减少无效占位符。
        $values = array_values(array_unique($values, SORT_REGULAR));

        // 空集合早退：IN 返回零行；NOT IN 不加约束（全表）。
        if ($values === []) {
            return $not ? $builder : $builder->whereRaw('1 = 0');
        }

        $chunkSize = max(1, $chunkSize);
        $chunks = array_chunk($values, $chunkSize);

        // 单块：直接委托原生方法，生成最优单条 SQL，避免多余嵌套。
        if (count($chunks) === 1) {
            return $not
                ? $builder->whereNotIn($column, $chunks[0])
                : $builder->whereIn($column, $chunks[0]);
        }

        // 多块：首块用 where/whereNotIn，后续块 IN 用 orWhereIn（OR 合并）、NOT IN 用 whereNotIn（AND 合并）。
        $callback = function ($q) use ($chunks, $column, $not): void {
            foreach ($chunks as $index => $chunk) {
                if ($index === 0) {
                    $not ? $q->whereNotIn($column, $chunk) : $q->whereIn($column, $chunk);
                } else {
                    // IN：多块以 OR 合并；NOT IN：多块以 AND 合并（等效对每个块分别排除）。
                    $not ? $q->whereNotIn($column, $chunk) : $q->orWhereIn($column, $chunk);
                }
            }
        };

        return $boolean === 'or'
            ? $builder->orWhere($callback)
            : $builder->where($callback);
    }
}
