<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * 条件聚合宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 *
 * 基于 study_mysql「07-appendix / 02-sql-cheatsheet」「04-performance」中高频的「单次查询内按条件
 * 分组统计」模式，把 `SUM(CASE WHEN ... THEN ... ELSE 0 END)` 等条件聚合抽象为语义化宏。
 * 典型瓶颈：报表需要「各状态订单数」「各渠道金额合计」时，新手常写 N 条独立查询再在应用层拼装，
 * 导致 N+1 与多次往返；本宏用一条 SQL 完成多维度统计，显著降低 I/O 与延迟。
 *
 * 提供的宏：
 *  - selectSumIf：满足条件时累加（SUM CASE WHEN）
 *  - selectCountIf：满足条件时计数（COUNT CASE WHEN）
 *  - selectAvgIf：满足条件时求平均（AVG CASE WHEN）
 *  - selectMaxIf：满足条件时取最大值（MAX CASE WHEN）
 *  - selectMinIf：满足条件时取最小值（MIN CASE WHEN）
 *
 * 安全约束：聚合列名经白名单校验并 wrap；条件片段为开发者提供的 SQL（可含 ? 占位符），
 * 其绑定值通过 $conditionBindings 参数化传入，杜绝注入。
 *
 * @package zxf\Modules\BuilderQuery\WindowMacros
 * @version 3.2.0
 * @requires MySQL 8.0+ / PostgreSQL 15+
 */
class ConditionalAggregateMacro extends BaseMacro
{
    /** 注册所有条件聚合宏。 */
    public static function register(): void
    {
        self::registerSumIf();
        self::registerCountIf();
        self::registerAvgIf();
        self::registerMaxIf();
        self::registerMinIf();
    }

    /**
     * 注册 selectSumIf。
     */
    public static function registerSumIf(): void
    {
        /**
         * 仅对满足条件的行累加某列（SUM(CASE WHEN 条件 THEN 列 ELSE 0 END)）。
         *
         * @param string $column 被累加列
         * @param string $condition 条件 SQL 片段（可含 ? 占位符，如 "status = ? AND amount > ?"）
         * @param array $conditionBindings 条件对应的绑定值（按 ? 顺序）
         * @param string|null $alias 返回字段别名（默认 sum_if）
         * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 单条 SQL 同时统计「已支付金额」与「退款金额」
         * Order::query()
         *     ->selectSumIf('amount', 'status = ?', [1], 'paid_amount')
         *     ->selectSumIf('amount', 'status = ?', [4], 'refund_amount')
         *     ->first();
         *
         * @scenario 报表多维求和（按状态/渠道/时间区间等条件拆分合计），避免多次查询往返。
         */
        static::define('selectSumIf', function (
            string $column,
            string $condition,
            array $conditionBindings = [],
            ?string $alias = null
        ): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
            return ConditionalAggregateMacro::buildIf($this, 'SUM', $column, $condition, $conditionBindings, $alias ?? 'sum_if', null);
        });
    }

    /**
     * 注册 selectCountIf。
     */
    public static function registerCountIf(): void
    {
        /**
         * 仅统计满足条件的行数（COUNT(CASE WHEN 条件 THEN 1 END)）。
         *
         * @param string $column 参与计数的列（一般传主键或 '*' 等价列；NULL 不计入）
         * @param string $condition 条件 SQL 片段（可含 ?）
         * @param array $conditionBindings 条件绑定值
         * @param string|null $alias 别名（默认 count_if）
         * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 统计各状态订单数
         * Order::query()
         *     ->selectCountIf('id', 'status = ?', [1], 'paid_count')
         *     ->selectCountIf('id', 'status = ?', [0], 'unpaid_count')
         *     ->first();
         *
         * @scenario 单条 SQL 完成多条件计数（如 0/1 各有多少），替代多次 COUNT 查询。
         */
        static::define('selectCountIf', function (
            string $column,
            string $condition,
            array $conditionBindings = [],
            ?string $alias = null
        ): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
            return ConditionalAggregateMacro::buildIf($this, 'COUNT', $column, $condition, $conditionBindings, $alias ?? 'count_if', 1);
        });
    }

    /**
     * 注册 selectAvgIf。
     */
    public static function registerAvgIf(): void
    {
        /**
         * 仅对满足条件的行求平均（AVG(CASE WHEN 条件 THEN 列 END)）。
         *
         * @param string $column 被平均列
         * @param string $condition 条件 SQL 片段（可含 ?）
         * @param array $conditionBindings 条件绑定值
         * @param string|null $alias 别名（默认 avg_if）
         * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 已支付订单的平均金额
         * Order::query()->selectAvgIf('amount', 'status = ?', [1], 'paid_avg')->first();
         *
         * @scenario 按条件拆分的均值统计（如 新老客客单价对比）。
         */
        static::define('selectAvgIf', function (
            string $column,
            string $condition,
            array $conditionBindings = [],
            ?string $alias = null
        ): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
            return ConditionalAggregateMacro::buildIf($this, 'AVG', $column, $condition, $conditionBindings, $alias ?? 'avg_if', null);
        });
    }

    /**
     * 注册 selectMaxIf。
     */
    public static function registerMaxIf(): void
    {
        /**
         * 仅对满足条件的行取最大值（MAX(CASE WHEN 条件 THEN 列 END)）。
         *
         * @param string $column 目标列
         * @param string $condition 条件 SQL 片段（可含 ?）
         * @param array $conditionBindings 条件绑定值
         * @param string|null $alias 别名（默认 max_if）
         * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 已支付订单中的最高金额
         * Order::query()->selectMaxIf('amount', 'status = ?', [1], 'paid_max')->first();
         *
         * @scenario 条件维度下的最值对比（如 各渠道最高客单价）。
         */
        static::define('selectMaxIf', function (
            string $column,
            string $condition,
            array $conditionBindings = [],
            ?string $alias = null
        ): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
            return ConditionalAggregateMacro::buildIf($this, 'MAX', $column, $condition, $conditionBindings, $alias ?? 'max_if', null);
        });
    }

    /**
     * 注册 selectMinIf。
     */
    public static function registerMinIf(): void
    {
        /**
         * 仅对满足条件的行取最小值（MIN(CASE WHEN 条件 THEN 列 END)）。
         *
         * @param string $column 目标列
         * @param string $condition 条件 SQL 片段（可含 ?）
         * @param array $conditionBindings 条件绑定值
         * @param string|null $alias 别名（默认 min_if）
         * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 已支付订单中的最低金额
         * Order::query()->selectMinIf('amount', 'status = ?', [1], 'paid_min')->first();
         *
         * @scenario 条件维度下的最值对比（如 各渠道最低客单价）。
         */
        static::define('selectMinIf', function (
            string $column,
            string $condition,
            array $conditionBindings = [],
            ?string $alias = null
        ): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
            return ConditionalAggregateMacro::buildIf($this, 'MIN', $column, $condition, $conditionBindings, $alias ?? 'min_if', null);
        });
    }

    /**
     * 构建条件聚合表达式并以 selectRaw 输出。
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $builder
     * @param string $agg SUM|COUNT|AVG|MAX|MIN
     * @param string $column 目标列
     * @param string $condition 条件 SQL 片段
     * @param array $conditionBindings 条件绑定值
     * @param string $alias 别名
     * @param mixed $thenValue COUNT 时为 1，其余为列本身（null 表示用列）
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     */
    public static function buildIf(\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $builder, string $agg, string $column, string $condition, array $conditionBindings, string $alias, mixed $thenValue): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
    {
        $wrapped = BaseMacro::wrapColumn($builder, $column);
        $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
        $condition = trim($condition);

        if ($thenValue === null) {
            $thenExpr = $wrapped;
        } else {
            $thenExpr = (string) $thenValue; // COUNT 时为 1
        }

        // 聚合语义说明（避免非匹配行干扰结果）：
        //  - COUNT：非匹配返回 NULL（COUNT 自动忽略 NULL），用 THEN 1 计数
        //  - SUM：非匹配返回 0，等价于「只对匹配行累加」
        //  - AVG / MAX / MIN：非匹配必须返回 NULL（而非 0），否则会把 0 计入聚合导致结果失真
        //    （例如 avg 被一堆 0 拉低、max 被 0 干扰）
        $caseExpr = match ($agg) {
            'COUNT' => "CASE WHEN ({$condition}) THEN 1 END",
            'SUM'   => "CASE WHEN ({$condition}) THEN {$thenExpr} ELSE 0 END",
            default => "CASE WHEN ({$condition}) THEN {$thenExpr} END", // AVG / MAX / MIN
        };

        $sql = "{$agg}({$caseExpr}) AS {$alias}";

        return $builder->selectRaw($sql, $conditionBindings);
    }
}
