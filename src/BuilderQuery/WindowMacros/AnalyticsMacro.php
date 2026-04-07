<?php

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Query\Builder;

/**
 * MySQL 8.4+ 分析函数宏
 *
 * MySQL 8.4 提供丰富的窗口分析函数，用于复杂数据分析
 *
 * @date 2026-04-07
 */
class AnalyticsMacro
{
    /**
     * 注册宏
     */
    public static function register(): void
    {
        // withLag - 前一行值（LAG）
        Builder::macro('withLag', function (string $column, int $offset = 1, $default = null, string $alias = null, ?string $partitionBy = null, ?string $orderBy = null) {
            /** @var Builder $this */
            $alias = $alias ?: $column . '_lag';
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';
            $orderClause = $orderBy ? "ORDER BY {$orderBy}" : '';
            $defaultValue = $default === null ? 'NULL' : "'{$default}'";

            return $this->selectRaw(
                "*, LAG({$column}, {$offset}, {$defaultValue}) OVER ({$partitionClause} {$orderClause}) as {$alias}"
            );
        });

        // withLead - 后一行值（LEAD）
        Builder::macro('withLead', function (string $column, int $offset = 1, $default = null, string $alias = null, ?string $partitionBy = null, ?string $orderBy = null) {
            /** @var Builder $this */
            $alias = $alias ?: $column . '_lead';
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';
            $orderClause = $orderBy ? "ORDER BY {$orderBy}" : '';
            $defaultValue = $default === null ? 'NULL' : "'{$default}'";

            return $this->selectRaw(
                "*, LEAD({$column}, {$offset}, {$defaultValue}) OVER ({$partitionClause} {$orderClause}) as {$alias}"
            );
        });

        // withChange - 变化值（当前值 - 前值）
        Builder::macro('withChange', function (string $column, string $alias = null, ?string $partitionBy = null, ?string $orderBy = null) {
            /** @var Builder $this */
            $alias = $alias ?: $column . '_change';

            return $this->withLag($column, 1, null, '_lag_tmp', $partitionBy, $orderBy)
                ->selectRaw("{$column} - COALESCE(_lag_tmp, 0) as {$alias}");
        });

        // withChangePercent - 变化百分比
        Builder::macro('withChangePercent', function (string $column, string $alias = null, ?string $partitionBy = null, ?string $orderBy = null) {
            /** @var Builder $this */
            $alias = $alias ?: $column . '_change_pct';

            return $this->withLag($column, 1, null, '_lag_tmp', $partitionBy, $orderBy)
                ->selectRaw(
                    "CASE WHEN _lag_tmp IS NULL OR _lag_tmp = 0 THEN NULL " .
                    "ELSE ({$column} - _lag_tmp) / _lag_tmp * 100 END as {$alias}"
                );
        });

        // withRunningTotal - 累计求和（RUNNING SUM）
        Builder::macro('withRunningTotal', function (string $column, string $alias = null, ?string $partitionBy = null, string $orderBy = 'id') {
            /** @var Builder $this */
            $alias = $alias ?: $column . '_running_total';
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, SUM({$column}) OVER ({$partitionClause} ORDER BY {$orderBy} ROWS UNBOUNDED PRECEDING) as {$alias}"
            );
        });

        // withRunningAverage - 累计平均
        Builder::macro('withRunningAverage', function (string $column, string $alias = null, ?string $partitionBy = null, string $orderBy = 'id') {
            /** @var Builder $this */
            $alias = $alias ?: $column . '_running_avg';
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, AVG({$column}) OVER ({$partitionClause} ORDER BY {$orderBy} ROWS UNBOUNDED PRECEDING) as {$alias}"
            );
        });

        // withMovingAverage - 移动平均（MySQL 8.4 窗口帧）
        Builder::macro('withMovingAverage', function (string $column, int $window, string $alias = null, ?string $partitionBy = null, string $orderBy = 'id') {
            /** @var Builder $this */
            $alias = $alias ?: $column . '_ma' . $window;
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';
            $frame = "ROWS BETWEEN {$window} PRECEDING AND CURRENT ROW";

            return $this->selectRaw(
                "*, AVG({$column}) OVER ({$partitionClause} ORDER BY {$orderBy} {$frame}) as {$alias}"
            );
        });

        // withMovingSum - 移动求和
        Builder::macro('withMovingSum', function (string $column, int $window, string $alias = null, ?string $partitionBy = null, string $orderBy = 'id') {
            /** @var Builder $this */
            $alias = $alias ?: $column . '_ms' . $window;
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';
            $frame = "ROWS BETWEEN {$window} PRECEDING AND CURRENT ROW";

            return $this->selectRaw(
                "*, SUM({$column}) OVER ({$partitionClause} ORDER BY {$orderBy} {$frame}) as {$alias}"
            );
        });

        // withExponentialMovingAverage - 指数移动平均（EMA）
        Builder::macro('withEMA', function (string $column, float $alpha = 0.3, string $alias = null, ?string $partitionBy = null, string $orderBy = 'id') {
            /** @var Builder $this */
            $alias = $alias ?: $column . '_ema';
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, {$alpha} * {$column} + (1 - {$alpha}) * " .
                "LAG({$column}) OVER ({$partitionClause} ORDER BY {$orderBy}) as {$alias}"
            );
        });

        // withDifferenceFromFirst - 与第一个值的差
        Builder::macro('withDifferenceFromFirst', function (string $column, string $alias = null, ?string $partitionBy = null, string $orderBy = 'id') {
            /** @var Builder $this */
            $alias = $alias ?: $column . '_diff_first';
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, {$column} - FIRST_VALUE({$column}) OVER ({$partitionClause} ORDER BY {$orderBy}) as {$alias}"
            );
        });

        // withDifferenceFromLast - 与最后一个值的差
        Builder::macro('withDifferenceFromLast', function (string $column, string $alias = null, ?string $partitionBy = null, string $orderBy = 'id') {
            /** @var Builder $this */
            $alias = $alias ?: $column . '_diff_last';
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, {$column} - LAST_VALUE({$column}) OVER ({$partitionClause} ORDER BY {$orderBy} RANGE BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING) as {$alias}"
            );
        });

        // withPercentageOfTotal - 占总数的百分比
        Builder::macro('withPercentageOfTotal', function (string $column, string $alias = null, ?string $partitionBy = null) {
            /** @var Builder $this */
            $alias = $alias ?: $column . '_pct_total';
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, {$column} / NULLIF(SUM({$column}) OVER ({$partitionClause}), 0) * 100 as {$alias}"
            );
        });

        // withPercentageOfMax - 占最大值的百分比
        Builder::macro('withPercentageOfMax', function (string $column, string $alias = null, ?string $partitionBy = null) {
            /** @var Builder $this */
            $alias = $alias ?: $column . '_pct_max';
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, {$column} / NULLIF(MAX({$column}) OVER ({$partitionClause}), 0) * 100 as {$alias}"
            );
        });

        // withZScore - Z分数（标准化）
        Builder::macro('withZScore', function (string $column, string $alias = null, ?string $partitionBy = null) {
            /** @var Builder $this */
            $alias = $alias ?: $column . '_zscore';
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, ({$column} - AVG({$column}) OVER ({$partitionClause})) / " .
                "NULLIF(STDDEV({$column}) OVER ({$partitionClause}), 0) as {$alias}"
            );
        });

        // withOutlierFlag - 异常值标记（基于 IQR 方法）
        Builder::macro('withOutlierFlag', function (string $column, float $factor = 1.5, string $alias = 'is_outlier', ?string $partitionBy = null) {
            /** @var Builder $this */
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, CASE WHEN {$column} < (PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY {$column}) OVER ({$partitionClause}) - {$factor} * " .
                "(PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY {$column}) OVER ({$partitionClause}) - PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY {$column}) OVER ({$partitionClause}))) " .
                "OR {$column} > (PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY {$column}) OVER ({$partitionClause}) + {$factor} * " .
                "(PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY {$column}) OVER ({$partitionClause}) - PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY {$column}) OVER ({$partitionClause}))) " .
                "THEN 1 ELSE 0 END as {$alias}"
            );
        });

        // withYearOverYear - 同比增长率
        Builder::macro('withYearOverYear', function (string $column, string $yearColumn, ?string $alias = null) {
            /** @var Builder $this */
            $alias = $alias ?: $column . '_yoy';

            return $this->withLag($column, 1, null, '_last_year', $yearColumn)
                ->selectRaw(
                    "CASE WHEN _last_year IS NULL OR _last_year = 0 THEN NULL " .
                    "ELSE ({$column} - _last_year) / _last_year * 100 END as {$alias}"
                );
        });

        // withMonthOverMonth - 环比增长率
        Builder::macro('withMonthOverMonth', function (string $column, string $periodColumn, string $alias = null) {
            /** @var Builder $this */
            $alias = $alias ?: $column . '_mom';

            return $this->withLag($column, 1, null, '_last_period', $periodColumn)
                ->selectRaw(
                    "CASE WHEN _last_period IS NULL OR _last_period = 0 THEN NULL " .
                    "ELSE ({$column} - _last_period) / _last_period * 100 END as {$alias}"
                );
        });

        // withPeriodComparison - 任意期间比较
        Builder::macro('withPeriodComparison', function (string $column, int $periodsAgo, string $alias = null, ?string $partitionBy = null, ?string $orderBy = null) {
            /** @var Builder $this */
            $alias = $alias ?: $column . '_vs_' . $periodsAgo;

            return $this->withLag($column, $periodsAgo, null, '_past_value', $partitionBy, $orderBy)
                ->selectRaw(
                    "CASE WHEN _past_value IS NULL OR _past_value = 0 THEN NULL " .
                    "ELSE ({$column} - _past_value) / _past_value * 100 END as {$alias}"
                );
        });
    }
}
