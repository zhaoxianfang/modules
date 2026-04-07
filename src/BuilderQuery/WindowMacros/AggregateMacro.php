<?php

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Query\Builder;

/**
 * MySQL 8.4+ 高级聚合函数宏
 *
 * MySQL 8.4 支持窗口聚合和列表聚合等高级功能
 *
 * @date 2026-04-07
 */
class AggregateMacro
{
    /**
     * 注册宏
     */
    public static function register(): void
    {
        // withListAgg - 列表聚合（GROUP_CONCAT 窗口函数版）
        Builder::macro('withListAgg', function (string $column, string $alias = null, ?string $partitionBy = null, string $orderBy = null, string $separator = ',') {
            /** @var Builder $this */
            $alias = $alias ?: $column . '_list';
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';
            $orderClause = $orderBy ? "ORDER BY {$orderBy}" : '';

            return $this->selectRaw(
                "*, GROUP_CONCAT({$column} {$orderClause} SEPARATOR '{$separator}') " .
                "OVER ({$partitionClause}) as {$alias}"
            );
        });

        // withCountDistinct - 窗口内唯一计数
        Builder::macro('withCountDistinct', function (string $column, string $alias = null, ?string $partitionBy = null) {
            /** @var Builder $this */
            $alias = $alias ?: $column . '_distinct_count';
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, COUNT(DISTINCT {$column}) OVER ({$partitionClause}) as {$alias}"
            );
        });

        // withRatioToReport - 占比报告
        Builder::macro('withRatioToReport', function (string $column, string $alias = null, ?string $partitionBy = null) {
            /** @var Builder $this */
            $alias = $alias ?: $column . '_ratio';
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, {$column} / NULLIF(SUM({$column}) OVER ({$partitionClause}), 0) as {$alias}"
            );
        });

        // withCumulativePercent - 累积百分比
        Builder::macro('withCumulativePercent', function (string $column, string $alias = null, ?string $partitionBy = null, string $orderBy = 'id') {
            /** @var Builder $this */
            $alias = $alias ?: $column . '_cumulative_pct';
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, SUM({$column}) OVER ({$partitionClause} ORDER BY {$orderBy} ROWS UNBOUNDED PRECEDING) / " .
                "NULLIF(SUM({$column}) OVER ({$partitionClause}), 0) * 100 as {$alias}"
            );
        });

        // withParetoAnalysis - 帕累托分析（80/20 法则）
        Builder::macro('withParetoAnalysis', function (string $valueColumn, string $categoryColumn, string $aliasPrefix = 'pareto') {
            /** @var Builder $this */
            return $this
                ->selectRaw("*, SUM({$valueColumn}) OVER (ORDER BY {$valueColumn} DESC ROWS UNBOUNDED PRECEDING) as {$aliasPrefix}_cumulative")
                ->selectRaw("SUM({$valueColumn}) OVER () as {$aliasPrefix}_total")
                ->selectRaw("SUM({$valueColumn}) OVER (ORDER BY {$valueColumn} DESC ROWS UNBOUNDED PRECEDING) / NULLIF(SUM({$valueColumn}) OVER (), 0) * 100 as {$aliasPrefix}_pct");
        });

        // withGroupTotals - 组内汇总
        Builder::macro('withGroupTotals', function (array $columns, ?string $partitionBy = null) {
            /** @var Builder $this */
            foreach ($columns as $column => $aggregations) {
                foreach ((array) $aggregations as $agg) {
                    $alias = "{$column}_{$agg}";
                    $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

                    $this->selectRaw(
                        "*, {$agg}({$column}) OVER ({$partitionClause}) as {$alias}"
                    );
                }
            }

            return $this;
        });

        // withPivotSummary - 透视汇总（模拟 PIVOT）
        Builder::macro('withPivotSummary', function (string $valueColumn, string $pivotColumn, array $pivotValues, string $aggregation = 'SUM') {
            /** @var Builder $this */
            foreach ($pivotValues as $value) {
                $alias = str_replace([' ', '-', '.'], '_', strtolower($value));
                $safeValue = is_string($value) ? "'{$value}'" : $value;

                $this->selectRaw(
                    "{$aggregation}(CASE WHEN {$pivotColumn} = {$safeValue} THEN {$valueColumn} ELSE 0 END) as {$alias}"
                );
            }

            return $this;
        });

        // withRunningStats - 运行统计（最小、最大、平均）
        Builder::macro('withRunningStats', function (string $column, string $aliasPrefix = null, ?string $partitionBy = null, string $orderBy = 'id') {
            /** @var Builder $this */
            $prefix = $aliasPrefix ?: $column;
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this
                ->selectRaw("*, MIN({$column}) OVER ({$partitionClause} ORDER BY {$orderBy} ROWS UNBOUNDED PRECEDING) as {$prefix}_running_min")
                ->selectRaw("MAX({$column}) OVER ({$partitionClause} ORDER BY {$orderBy} ROWS UNBOUNDED PRECEDING) as {$prefix}_running_max")
                ->selectRaw("AVG({$column}) OVER ({$partitionClause} ORDER BY {$orderBy} ROWS UNBOUNDED PRECEDING) as {$prefix}_running_avg");
        });

        // withVarianceStats - 方差统计
        Builder::macro('withVarianceStats', function (string $column, ?string $aliasPrefix = null, ?string $partitionBy = null) {
            /** @var Builder $this */
            $prefix = $aliasPrefix ?: $column;
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this
                ->selectRaw("*, VAR_POP({$column}) OVER ({$partitionClause}) as {$prefix}_var_pop")
                ->selectRaw("VAR_SAMP({$column}) OVER ({$partitionClause}) as {$prefix}_var_samp")
                ->selectRaw("STDDEV_POP({$column}) OVER ({$partitionClause}) as {$prefix}_stddev_pop")
                ->selectRaw("STDDEV_SAMP({$column}) OVER ({$partitionClause}) as {$prefix}_stddev_samp");
        });

        // withCorrelation - 相关系数（需要两列）
        Builder::macro('withCorrelation', function (string $column1, string $column2, string $alias = 'correlation', ?string $partitionBy = null) {
            /** @var Builder $this */
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, (COUNT(*) OVER ({$partitionClause}) * SUM({$column1} * {$column2}) OVER ({$partitionClause}) - " .
                "SUM({$column1}) OVER ({$partitionClause}) * SUM({$column2}) OVER ({$partitionClause})) / " .
                "SQRT((COUNT(*) OVER ({$partitionClause}) * SUM({$column1} * {$column1}) OVER ({$partitionClause}) - " .
                "SUM({$column1}) OVER ({$partitionClause}) * SUM({$column1}) OVER ({$partitionClause})) * " .
                "(COUNT(*) OVER ({$partitionClause}) * SUM({$column2} * {$column2}) OVER ({$partitionClause}) - " .
                "SUM({$column2}) OVER ({$partitionClause}) * SUM({$column2}) OVER ({$partitionClause}))) as {$alias}"
            );
        });

        // withLinearRegression - 简单线性回归
        Builder::macro('withLinearRegression', function (string $xColumn, string $yColumn, string $aliasPrefix = 'regression', ?string $partitionBy = null) {
            /** @var Builder $this */
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this
                ->selectRaw("*, (COUNT(*) OVER ({$partitionClause}) * SUM({$xColumn} * {$yColumn}) OVER ({$partitionClause}) - " .
                    "SUM({$xColumn}) OVER ({$partitionClause}) * SUM({$yColumn}) OVER ({$partitionClause})) / " .
                    "NULLIF(COUNT(*) OVER ({$partitionClause}) * SUM({$xColumn} * {$xColumn}) OVER ({$partitionClause}) - " .
                    "SUM({$xColumn}) OVER ({$partitionClause}) * SUM({$xColumn}) OVER ({$partitionClause}), 0) as {$aliasPrefix}_slope")
                ->selectRaw("AVG({$yColumn}) OVER ({$partitionClause}) - ({$aliasPrefix}_slope * AVG({$xColumn}) OVER ({$partitionClause})) as {$aliasPrefix}_intercept");
        });

        // withForecast - 预测值（基于线性回归）
        Builder::macro('withForecast', function (string $xColumn, string $yColumn, float $xValue, string $alias = 'forecast', ?string $partitionBy = null) {
            /** @var Builder $this */
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, (AVG({$yColumn}) OVER ({$partitionClause}) - ((COUNT(*) OVER ({$partitionClause}) * SUM({$xColumn} * {$yColumn}) OVER ({$partitionClause}) - " .
                "SUM({$xColumn}) OVER ({$partitionClause}) * SUM({$yColumn}) OVER ({$partitionClause})) / " .
                "NULLIF(COUNT(*) OVER ({$partitionClause}) * SUM({$xColumn} * {$xColumn}) OVER ({$partitionClause}) - " .
                "SUM({$xColumn}) OVER ({$partitionClause}) * SUM({$xColumn}) OVER ({$partitionClause}), 0)) * AVG({$xColumn}) OVER ({$partitionClause})) + " .
                "((COUNT(*) OVER ({$partitionClause}) * SUM({$xColumn} * {$yColumn}) OVER ({$partitionClause}) - " .
                "SUM({$xColumn}) OVER ({$partitionClause}) * SUM({$yColumn}) OVER ({$partitionClause})) / " .
                "NULLIF(COUNT(*) OVER ({$partitionClause}) * SUM({$xColumn} * {$xColumn}) OVER ({$partitionClause}) - " .
                "SUM({$xColumn}) OVER ({$partitionClause}) * SUM({$xColumn}) OVER ({$partitionClause}), 0)) * {$xValue} as {$alias}"
            );
        });
    }
}
