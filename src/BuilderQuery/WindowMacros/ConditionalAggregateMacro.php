<?php

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Query\Builder;

/**
 * MySQL 8.4+ 条件聚合窗口函数宏
 *
 * 提供强大的条件聚合功能，支持复杂的数据透视和分析场景。
 * 所有函数均为通用型设计，适用于任意数据表和字段组合。
 *
 * @date 2026-04-07
 */
class ConditionalAggregateMacro
{
    /**
     * 注册所有条件聚合窗口函数宏
     *
     * @return void
     */
    public static function register(): void
    {
        /**
         * withConditionalCount - 条件计数
         *
         * 根据指定条件对记录进行计数，支持复杂的条件表达式。
         *
         * @param string $condition 条件表达式，如 "status = 'active'"
         * @param string|null $alias 输出列别名
         * @param string|null $partitionBy 分组列名（可选）
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 统计每个分类下活跃商品数量
         * DB::table('products')
         *     ->withConditionalCount("status = 'active'", 'active_count', 'category_id')
         *     ->select('category_id', 'active_count')
         *     ->get();
         */
        Builder::macro('withConditionalCount', function (
            string $condition,
            ?string $alias = null,
            ?string $partitionBy = null
        ) {
            /** @var Builder $this */
            $alias = $alias ?? 'conditional_count';
            $partitionClause = $partitionBy ? "PARTITION BY {$this->grammar->wrap($partitionBy)}" : '';

            return $this->selectRaw(
                "*, COUNT(CASE WHEN {$condition} THEN 1 END) OVER ({$partitionClause}) as {$this->grammar->wrap($alias)}"
            );
        });

        /**
         * withConditionalSum - 条件求和
         *
         * 根据条件对指定列进行求和。
         *
         * @param string $column 数值列名
         * @param string $condition 条件表达式
         * @param string|null $alias 输出列别名
         * @param string|null $partitionBy 分组列名（可选）
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 计算每个用户已完成订单的总金额
         * DB::table('orders')
         *     ->withConditionalSum('amount', "status = 'completed'", 'completed_amount', 'user_id')
         *     ->select('user_id', 'completed_amount')
         *     ->get();
         */
        Builder::macro('withConditionalSum', function (
            string $column,
            string $condition,
            ?string $alias = null,
            ?string $partitionBy = null
        ) {
            /** @var Builder $this */
            $alias = $alias ?? 'conditional_sum';
            $partitionClause = $partitionBy ? "PARTITION BY {$this->grammar->wrap($partitionBy)}" : '';

            return $this->selectRaw(
                "*, SUM(CASE WHEN {$condition} THEN {$this->grammar->wrap($column)} ELSE 0 END) OVER ({$partitionClause}) as {$this->grammar->wrap($alias)}"
            );
        });

        /**
         * withConditionalAvg - 条件平均值
         *
         * 根据条件计算指定列的平均值。
         *
         * @param string $column 数值列名
         * @param string $condition 条件表达式
         * @param string|null $alias 输出列别名
         * @param string|null $partitionBy 分组列名（可选）
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 计算每个产品类别中评分大于4的商品平均价格
         * DB::table('products')
         *     ->withConditionalAvg('price', 'rating > 4', 'avg_premium_price', 'category_id')
         *     ->select('category_id', 'avg_premium_price')
         *     ->get();
         */
        Builder::macro('withConditionalAvg', function (
            string $column,
            string $condition,
            ?string $alias = null,
            ?string $partitionBy = null
        ) {
            /** @var Builder $this */
            $alias = $alias ?? 'conditional_avg';
            $partitionClause = $partitionBy ? "PARTITION BY {$this->grammar->wrap($partitionBy)}" : '';

            return $this->selectRaw(
                "*, AVG(CASE WHEN {$condition} THEN {$this->grammar->wrap($column)} END) OVER ({$partitionClause}) as {$this->grammar->wrap($alias)}"
            );
        });

        /**
         * withConditionalMax - 条件最大值
         *
         * 根据条件获取指定列的最大值。
         *
         * @param string $column 数值列名
         * @param string $condition 条件表达式
         * @param string|null $alias 输出列别名
         * @param string|null $partitionBy 分组列名（可选）
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 获取每个用户最近一次登录的时间
         * DB::table('user_activities')
         *     ->withConditionalMax('activity_time', "activity_type = 'login'", 'last_login_time', 'user_id')
         *     ->select('user_id', 'last_login_time')
         *     ->get();
         */
        Builder::macro('withConditionalMax', function (
            string $column,
            string $condition,
            ?string $alias = null,
            ?string $partitionBy = null
        ) {
            /** @var Builder $this */
            $alias = $alias ?? 'conditional_max';
            $partitionClause = $partitionBy ? "PARTITION BY {$this->grammar->wrap($partitionBy)}" : '';

            return $this->selectRaw(
                "*, MAX(CASE WHEN {$condition} THEN {$this->grammar->wrap($column)} END) OVER ({$partitionClause}) as {$this->grammar->wrap($alias)}"
            );
        });

        /**
         * withConditionalMin - 条件最小值
         *
         * 根据条件获取指定列的最小值。
         *
         * @param string $column 数值列名
         * @param string $condition 条件表达式
         * @param string|null $alias 输出列别名
         * @param string|null $partitionBy 分组列名（可选）
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 获取每个订单首次支付的时间
         * DB::table('order_payments')
         *     ->withConditionalMin('payment_time', "status = 'success'", 'first_payment_time', 'order_id')
         *     ->select('order_id', 'first_payment_time')
         *     ->get();
         */
        Builder::macro('withConditionalMin', function (
            string $column,
            string $condition,
            ?string $alias = null,
            ?string $partitionBy = null
        ) {
            /** @var Builder $this */
            $alias = $alias ?? 'conditional_min';
            $partitionClause = $partitionBy ? "PARTITION BY {$this->grammar->wrap($partitionBy)}" : '';

            return $this->selectRaw(
                "*, MIN(CASE WHEN {$condition} THEN {$this->grammar->wrap($column)} END) OVER ({$partitionClause}) as {$this->grammar->wrap($alias)}"
            );
        });

        /**
         * withPivotAggregate - 数据透视聚合
         *
         * 将行数据转换为列数据，实现数据透视表效果。
         *
         * @param string $pivotColumn 透视列名（值将成为新列）
         * @param string $valueColumn 值列名
         * @param string $aggregate 聚合函数：'SUM', 'AVG', 'MAX', 'MIN', 'COUNT'
         * @param array $pivotValues 透视值列表
         * @param string|null $aliasPrefix 列名前缀
         * @param string|null $partitionBy 分组列名（可选）
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 透视统计各状态订单金额
         * DB::table('orders')
         *     ->withPivotAggregate('status', 'amount', 'SUM', ['pending', 'paid', 'shipped'], 'amt_')
         *     ->select('user_id')
         *     ->selectRaw('amt_pending, amt_paid, amt_shipped')
         *     ->groupBy('user_id')
         *     ->get();
         */
        Builder::macro('withPivotAggregate', function (
            string $pivotColumn,
            string $valueColumn,
            string $aggregate,
            array $pivotValues,
            ?string $aliasPrefix = null,
            ?string $partitionBy = null
        ) {
            /** @var Builder $this */
            $aliasPrefix = $aliasPrefix ?? $pivotColumn . '_';
            $aggregate = strtoupper($aggregate);

            foreach ($pivotValues as $value) {
                $safeAlias = $aliasPrefix . preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$value);
                $safeValue = is_string($value) ? "'{$value}'" : $value;

                $sql = "{$aggregate}(CASE WHEN {$this->grammar->wrap($pivotColumn)} = {$safeValue} THEN {$this->grammar->wrap($valueColumn)} END) as {$safeAlias}";
                $this->selectRaw($sql);
            }

            if ($partitionBy) {
                $this->groupBy($partitionBy);
            }

            return $this;
        });

        /**
         * withRunningConditionalSum - 条件累计求和
         *
         * 根据条件进行累计求和，支持窗口滑动。
         *
         * @param string $column 数值列名
         * @param string $condition 条件表达式
         * @param string|null $alias 输出列别名
         * @param string|null $partitionBy 分组列名（可选）
         * @param string|null $orderBy 排序列名（可选）
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 计算累计收入（只统计已付款订单）
         * DB::table('orders')
         *     ->withRunningConditionalSum('amount', "status = 'paid'", 'revenue', null, 'created_at')
         *     ->select('created_at', 'revenue')
         *     ->get();
         */
        Builder::macro('withRunningConditionalSum', function (
            string $column,
            string $condition,
            ?string $alias = null,
            ?string $partitionBy = null,
            ?string $orderBy = null
        ) {
            /** @var Builder $this */
            $alias = $alias ?? 'running_conditional_sum';
            $partitionClause = $partitionBy ? "PARTITION BY {$this->grammar->wrap($partitionBy)}" : '';
            $orderClause = $orderBy ? "ORDER BY {$orderBy}" : '';

            return $this->selectRaw(
                "*, SUM(CASE WHEN {$condition} THEN {$this->grammar->wrap($column)} ELSE 0 END) OVER ({$partitionClause} {$orderClause} ROWS UNBOUNDED PRECEDING) as {$this->grammar->wrap($alias)}"
            );
        });

        /**
         * withRatio - 占比计算
         *
         * 计算某列值占总体的比例。
         *
         * @param string $column 数值列名
         * @param string|null $alias 输出列别名
         * @param string|null $partitionBy 分组列名（可选）
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 计算每个商品销售额占分类总销售额的比例
         * DB::table('order_items')
         *     ->withRatio('total_price', 'sales_ratio', 'category_id')
         *     ->select('product_name', 'total_price', 'sales_ratio')
         *     ->get();
         */
        Builder::macro('withRatio', function (
            string $column,
            ?string $alias = null,
            ?string $partitionBy = null
        ) {
            /** @var Builder $this */
            $alias = $alias ?? 'ratio';
            $partitionClause = $partitionBy ? "PARTITION BY {$this->grammar->wrap($partitionBy)}" : '';

            return $this->selectRaw(
                "*, {$this->grammar->wrap($column)} / NULLIF(SUM({$this->grammar->wrap($column)}) OVER ({$partitionClause}), 0) as {$this->grammar->wrap($alias)}"
            );
        });

        /**
         * withContribution - 贡献度分析（帕累托累积占比）
         *
         * 计算各项目对总体的累积贡献度，用于帕累托分析（80/20法则）。
         *
         * @param string $column 数值列名
         * @param string|null $alias 输出列别名
         * @param string|null $partitionBy 分组列名（可选）
         * @param string|null $orderBy 排序列名（可选）
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 分析各产品销售额贡献度，找出贡献80%销售额的核心产品
         * DB::table('products')
         *     ->orderByDesc('sales_amount')
         *     ->withContribution('sales_amount', 'contribution_pct')
         *     ->having('contribution_pct', '<=', 0.8)
         *     ->get();
         */
        Builder::macro('withContribution', function (
            string $column,
            ?string $alias = null,
            ?string $partitionBy = null,
            ?string $orderBy = null
        ) {
            /** @var Builder $this */
            $alias = $alias ?? 'contribution';
            $partitionClause = $partitionBy ? "PARTITION BY {$this->grammar->wrap($partitionBy)}" : '';
            $orderClause = $orderBy ? "ORDER BY {$orderBy}" : '';

            return $this->selectRaw(
                "*, SUM({$this->grammar->wrap($column)}) OVER ({$partitionClause} {$orderClause} ROWS UNBOUNDED PRECEDING) / NULLIF(SUM({$this->grammar->wrap($column)}) OVER ({$partitionClause}), 0) as {$this->grammar->wrap($alias)}"
            );
        });

        /**
         * withFrequencyDistribution - 频率分布分析
         *
         * 分析数值列的频率分布，统计各区间内的记录数。
         *
         * @param string $column 数值列名
         * @param int $buckets 分桶数量，默认 10
         * @param string|null $aliasPrefix 输出列名前缀
         * @param string|null $partitionBy 分组列名（可选）
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 分析用户年龄分布
         * DB::table('users')
         *     ->withFrequencyDistribution('age', 5, 'age_group')
         *     ->select('age_group_bucket', 'age_group_count')
         *     ->get();
         */
        Builder::macro('withFrequencyDistribution', function (
            string $column,
            int $buckets = 10,
            ?string $aliasPrefix = null,
            ?string $partitionBy = null
        ) {
            /** @var Builder $this */
            $aliasPrefix = $aliasPrefix ?? 'freq_';
            $partitionClause = $partitionBy ? "PARTITION BY {$this->grammar->wrap($partitionBy)}" : '';

            return $this->selectRaw(
                "*, NTILE({$buckets}) OVER ({$partitionClause} ORDER BY {$this->grammar->wrap($column)}) as {$aliasPrefix}bucket"
            )->selectRaw(
                "COUNT(*) OVER (PARTITION BY {$aliasPrefix}bucket {$partitionClause}) as {$aliasPrefix}count"
            );
        });
    }
}
