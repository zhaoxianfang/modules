<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * 索引提示与优化器提示宏（MySQL 8.0+ / PostgreSQL 15+）
 * 基于 study_mysql「04-performance / 02-index-design-and-optimizer」「08-playbook / 03-latency-reduction-recipes」
 * 系统化封装，把「引导优化器走正确索引 / 限制执行成本 / 强制连接顺序」等高频性能调优手段
 * 抽象为语义化宏。这些宏直接决定查询是否命中索引、是否发生全表扫描，是系统最常见的性能瓶颈入口。
 * 提供的宏：
 *  - useIndex / forceIndex / ignoreIndex：索引提示（MySQL 原生 INDEX HINT；PG 经 pg_hint_plan 的 IndexScan/NoIndexScan）
 *  - optimizerHints：任意 MySQL 优化器提示（MAX_EXECUTION_TIME / JOIN_ORDER / HASH_JOIN / SKIP_SCAN / SET_VAR 等）
 *  - maxExecutionTime：单次查询最大执行毫秒数（防卡死的性能守卫）
 *  - straightJoin：强制 MySQL 按 FROM 顺序做连接（特定大表 JOIN 场景可显著提速）
 * 双构建器友好：全部通过 {@see BaseMacro::addSelectPrefix()} / {@see BaseMacro::addOptimizerHint()}
 * 注入，SQL 片段均经 grammar wrap，字面量走参数绑定；PG 侧的提示依赖 pg_hint_plan 扩展，
 * 若未安装则注释无害（不会报错），已在各宏注释中说明。
 *
 * @version 3.2.0
 *
 * @requires MySQL 8.0+ / PostgreSQL 15+（PG 索引/优化器提示需 pg_hint_plan）
 *
 * @scenario 索引提示与优化器提示宏（MySQL 8.0+ / PostgreSQL 15+）
 */
class IndexHintMacro extends BaseMacro
{
    /**
     *  注册所有索引/优化器提示宏。
     *
     * @scenario 注册所有索引/优化器提示宏。
     */
    public static function register(): void
    {
        self::registerIndexHints();
        self::registerOptimizerHints();
    }

    /**
     * 注册索引提示宏（useIndex / forceIndex / ignoreIndex）。
     *
     * @scenario 注册索引提示宏（useIndex / forceIndex / ignoreIndex）。
     */
    public static function registerIndexHints(): void
    {
        /**
         * 建议优化器使用指定索引（USE INDEX）。
         * MySQL 生成 `tbl USE INDEX (idx)`（可指定 FOR JOIN/ORDER BY/GROUP BY）；
         * PostgreSQL 无原生等价语法，经 pg_hint_plan 生成 `/*+ IndexScan(tbl idx) *\/` 注释提示。
         *
         * @param  string|array  $index  索引名（可多个）
         * @param  string  $for  作用范围（仅 MySQL）：''|join|order|group，留空表示全部
         * @param  string|null  $table  表名（默认取当前构建器表名）
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 订单列表强制走 user_id + created_at 复合索引，避免优化器误判走全表
         * Order::query()->useIndex(['idx_user_created'])->where('user_id', $uid)->get();
         * DB::table('orders')->useIndex('idx_user_created', 'join')->where('user_id', $uid)->get();
         *
         * @scenario 慢查询因优化器选错索引导致全表扫描；经 EXPLAIN 确认后显式引导正确索引。
         */
        static::define('useIndex', function (string|array $index, string $for = '', ?string $table = null): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */

            return IndexHintMacro::applyIndexHint($this, (array) $index, 'use', $for, $table);
        });

        /**
         * 强制优化器使用指定索引（FORCE INDEX），即使其认为全表扫描更快。
         *
         * @param  string|array  $index  索引名（可多个）
         * @param  string  $for  作用范围（仅 MySQL）：''|join|order|group
         * @param  string|null  $table  表名（默认取当前构建器表名）
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 明知 idx_status 选择性极高，强制使用以覆盖优化器的错误估计
         * Order::query()->forceIndex('idx_status')->where('status', 1)->get();
         *
         * @scenario 优化器基于过期统计信息低估索引收益，导致本应走索引却走全表/错误索引。
         */
        static::define('forceIndex', function (string|array $index, string $for = '', ?string $table = null): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */

            return IndexHintMacro::applyIndexHint($this, (array) $index, 'force', $for, $table);
        });

        /**
         * 指示优化器忽略指定索引（IGNORE INDEX）。
         *
         * @param  string|array  $index  索引名（可多个）
         * @param  string  $for  作用范围（仅 MySQL）：''|join|order|group
         * @param  string|null  $table  表名（默认取当前构建器表名）
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 某索引因碎片严重反而更慢，临时忽略它
         * Order::query()->ignoreIndex('idx_bad')->whereBetween('created_at', [$s, $e])->get();
         *
         * @scenario 某个索引维护成本高或选择性差，强制让优化器放弃它。
         */
        static::define('ignoreIndex', function (string|array $index, string $for = '', ?string $table = null): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */

            return IndexHintMacro::applyIndexHint($this, (array) $index, 'ignore', $for, $table);
        });
    }

    /**
     * 注册优化器 / 执行守卫宏（optimizerHints / maxExecutionTime / straightJoin）。
     *
     * @scenario 注册优化器 / 执行守卫宏（optimizerHints / maxExecutionTime / straightJoin）。
     */
    public static function registerOptimizerHints(): void
    {
        /**
         * 注入任意 MySQL 优化器提示（包裹在 `/*+ ... *\/` 中，仅出现一次、幂等）。
         * 常见提示：MAX_EXECUTION_TIME(n)、JOIN_ORDER(t1,t2)、JOIN_PREFIX(t)、HASH_JOIN(t)、
         * NO_HASH_JOIN(t)、MERGE(d)、NO_MERGE(d)、SEMIJOIN(t)、SKIP_SCAN(t idx)、
         * NO_INDEX(t idx)、SET_VAR(var=value)、RESOURCE_GROUP(name)、QB_NAME(name)。
         * PostgreSQL 侧若安装了 pg_hint_plan，可用等价语法（IndexScan/NoIndexScan/HashJoin 等）。
         *
         * @param  string|array  $hints  提示内容（不含 `/*+ *\/` 包裹符；可传数组自动空格拼接）
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 强制连接顺序并限制单条查询 3 秒，避免拖垮整库
         * Order::query()
         *     ->optimizerHints(['JOIN_PREFIX(u)', 'MAX_EXECUTION_TIME(3000)'])
         *     ->where('user_id', $uid)->get();
         *
         * @scenario 复杂多表 JOIN 需要人工指定连接策略；或作为兜底强制执行时限。
         */
        static::define('optimizerHints', function (string|array $hints): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $hints = (array) $hints;
            foreach ($hints as $hint) {
                BaseMacro::addOptimizerHint($this, (string) $hint);
            }

            return $this;
        });

        /**
         * 限制单条查询最大执行时间（性能守卫，防卡死）。
         * MySQL：生成 `/*+ MAX_EXECUTION_TIME(n) *\/` 优化器提示（n 为毫秒）。
         * PostgreSQL：经 pg_hint_plan 生成 `/*+ MaxExecTime(n) *\/`（未安装扩展则注释无害）；
         * 若需无扩展强约束，请改在连接层设置 `statement_timeout`。
         *
         * @param  int  $milliseconds  最大执行毫秒数（如 3000 = 3 秒）
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 报表类重查询兜底 5 秒，超时即中断，避免拖垮连接池
         * Report::query()->maxExecutionTime(5000)->where(...)->get();
         *
         * @scenario 后台/报表/搜索等不可控查询，作为资源占用与响应时间的最后一道防线。
         */
        static::define('maxExecutionTime', function (int $milliseconds): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            if ($milliseconds < 1) {
                throw new \InvalidArgumentException('maxExecutionTime 必须大于 0（毫秒）。');
            }
            $hint = BaseMacro::isPostgres($this)
                ? "MaxExecTime({$milliseconds})"
                : "MAX_EXECUTION_TIME({$milliseconds})";
            BaseMacro::addOptimizerHint($this, $hint);

            return $this;
        });

        /**
         * 强制 MySQL 按 FROM 子句书写顺序执行表连接（STRAIGHT_JOIN）。
         * 仅 MySQL 有效；PostgreSQL 无等价关键字（会安全忽略并返回原构建器）。
         * 当大表驱动小表、或优化器连接顺序选择不佳导致慢查询时，可强制优化顺序。
         *
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 让大表 orders 作为驱动表先扫描，再关联小表
         * DB::table('orders')->straightJoin()->join('users', 'users.id', '=', 'orders.user_id')->get();
         *
         * @scenario 多表 JOIN 优化器选错驱动表，经 EXPLAIN 确认后强制连接顺序。
         */
        static::define('straightJoin', function (): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            if (BaseMacro::isMySql($this)) {
                BaseMacro::addSelectPrefix($this, 'STRAIGHT_JOIN');
            }

            return $this;
        });
    }

    /**
     * 应用索引提示（方言分支）。
     * - MySQL：将表名改写为 `tbl [FOR x] {USE|FORCE|IGNORE} INDEX (idx,...)`，经 from 表达式注入。
     * - PostgreSQL：经 pg_hint_plan 生成 `IndexScan/NoIndexScan` 优化器提示注释。
     *
     * @param  array<int,string>  $indexes  索引名列表
     * @param  string  $kind  use|force|ignore
     * @param  string  $for  ''|join|order|group
     * @param  string|null  $table  表名（默认取当前构建器表名）
     *
     * @scenario 应用索引提示（方言分支）。
     */
    public static function applyIndexHint(Builder|\Illuminate\Database\Query\Builder $builder, array $indexes, string $kind, string $for, ?string $table): Builder|\Illuminate\Database\Query\Builder
    {
        $table = $table ?? BaseMacro::resolveTable($builder);
        $table = BaseMacro::assertValidIdentifier($table, 'table');

        $indexList = [];
        foreach ($indexes as $index) {
            $indexList[] = BaseMacro::assertValidIdentifier((string) $index, 'index');
        }
        $indexSql = implode(', ', $indexList);

        if (BaseMacro::isMySql($builder)) {
            $keyword = strtoupper($kind).' INDEX';
            $forSql = $for !== '' ? ' FOR '.strtoupper($for) : '';
            $wrappedTable = BaseMacro::wrapIdentifier($builder, $table);
            $builder->from(BaseMacro::rawExpression("{$wrappedTable}{$forSql} {$keyword} ({$indexSql})"));

            return $builder;
        }

        // PostgreSQL：经 pg_hint_plan（IndexScan / NoIndexScan）
        $pgKind = $kind === 'ignore' ? 'NoIndexScan' : 'IndexScan';
        BaseMacro::addOptimizerHint($builder, "{$pgKind}({$table} {$indexSql})");

        return $builder;
    }
}
