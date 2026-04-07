<?php

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Query\Builder;

/**
 * MySQL 8.4+ 排名函数宏
 *
 * MySQL 8.4 提供丰富的窗口排名函数，用于数据分析和分页
 *
 * @date 2026-04-07
 */
class RankingMacro
{
    /**
     * 注册宏
     */
    public static function register(): void
    {
        // withRowNumber - 行号（ROW_NUMBER）
        Builder::macro('withRowNumber', function (string $alias = 'row_num', ?string $partitionBy = null, ?string $orderBy = null) {
            /** @var Builder $this */
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';
            $orderClause = $orderBy ? "ORDER BY {$orderBy}" : 'ORDER BY (SELECT NULL)';

            return $this->selectRaw(
                "*, ROW_NUMBER() OVER ({$partitionClause} {$orderClause}) as {$alias}"
            );
        });

        // withRank - 排名（RANK，允许并列，跳号）
        Builder::macro('withRank', function (string $alias = 'rank', ?string $partitionBy = null, string $orderBy = 'id') {
            /** @var Builder $this */
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, RANK() OVER ({$partitionClause} ORDER BY {$orderBy}) as {$alias}"
            );
        });

        // withDenseRank - 密集排名（DENSE_RANK，允许并列，不跳号）
        Builder::macro('withDenseRank', function (string $alias = 'dense_rank', ?string $partitionBy = null, string $orderBy = 'id') {
            /** @var Builder $this */
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, DENSE_RANK() OVER ({$partitionClause} ORDER BY {$orderBy}) as {$alias}"
            );
        });

        // withPercentRank - 百分比排名（PERCENT_RANK）
        Builder::macro('withPercentRank', function (string $alias = 'percent_rank', ?string $partitionBy = null, string $orderBy = 'id') {
            /** @var Builder $this */
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, PERCENT_RANK() OVER ({$partitionClause} ORDER BY {$orderBy}) as {$alias}"
            );
        });

        // withCumeDist - 累积分布（CUME_DIST）
        Builder::macro('withCumeDist', function (string $alias = 'cume_dist', ?string $partitionBy = null, string $orderBy = 'id') {
            /** @var Builder $this */
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, CUME_DIST() OVER ({$partitionClause} ORDER BY {$orderBy}) as {$alias}"
            );
        });

        // withNTile - 分桶（NTILE，将数据分为 N 组）
        Builder::macro('withNTile', function (int $buckets, string $alias = 'ntile', ?string $partitionBy = null, string $orderBy = 'id') {
            /** @var Builder $this */
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, NTILE({$buckets}) OVER ({$partitionClause} ORDER BY {$orderBy}) as {$alias}"
            );
        });

        // withTopN - 获取每组前 N 条（使用窗口函数优化版）
        Builder::macro('withTopN', function (string $partitionBy, int $n = 1, string $orderBy = 'id DESC', ?string $alias = 'rn') {
            /** @var Builder $this */
            return $this->withRowNumber($alias, $partitionBy, $orderBy)
                ->having($alias, '<=', $n);
        });

        // withBottomN - 获取每组后 N 条
        Builder::macro('withBottomN', function (string $partitionBy, int $n = 1, string $orderBy = 'id ASC', ?string $alias = 'rn') {
            /** @var Builder $this */
            return $this->withRowNumber($alias, $partitionBy, $orderBy)
                ->having($alias, '<=', $n);
        });

        // withFirstValue - 分组第一个值
        Builder::macro('withFirstValue', function (string $column, string $alias, ?string $partitionBy = null, string $orderBy = 'id') {
            /** @var Builder $this */
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, FIRST_VALUE({$column}) OVER ({$partitionClause} ORDER BY {$orderBy}) as {$alias}"
            );
        });

        // withLastValue - 分组最后一个值
        Builder::macro('withLastValue', function (string $column, string $alias, ?string $partitionBy = null, string $orderBy = 'id') {
            /** @var Builder $this */
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, LAST_VALUE({$column}) OVER ({$partitionClause} ORDER BY {$orderBy} RANGE BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING) as {$alias}"
            );
        });

        // withNthValue - 分组第 N 个值
        Builder::macro('withNthValue', function (string $column, int $n, string $alias, ?string $partitionBy = null, string $orderBy = 'id') {
            /** @var Builder $this */
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, NTH_VALUE({$column}, {$n}) OVER ({$partitionClause} ORDER BY {$orderBy}) as {$alias}"
            );
        });

        // rankWithinGroup - 组内排名查询
        Builder::macro('rankWithinGroup', function (string $groupColumn, string $rankColumn, string $direction = 'desc') {
            /** @var Builder $this */
            $direction = strtoupper($direction);

            return $this->withRank('group_rank', $groupColumn, "{$rankColumn} {$direction}")
                ->orderBy($groupColumn)
                ->orderBy('group_rank');
        });

        // percentileCont - 连续百分位数（MySQL 8.4 PERCENTILE_CONT）
        Builder::macro('withPercentileCont', function (float $percentile, string $column, string $alias, ?string $partitionBy = null) {
            /** @var Builder $this */
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, PERCENTILE_CONT({$percentile}) WITHIN GROUP (ORDER BY {$column}) " .
                "OVER ({$partitionClause}) as {$alias}"
            );
        });

        // percentileDisc - 离散百分位数（MySQL 8.4 PERCENTILE_DISC）
        Builder::macro('withPercentileDisc', function (float $percentile, string $column, string $alias, ?string $partitionBy = null) {
            /** @var Builder $this */
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, PERCENTILE_DISC({$percentile}) WITHIN GROUP (ORDER BY {$column}) " .
                "OVER ({$partitionClause}) as {$alias}"
            );
        });

        // withMedian - 中位数（使用 PERCENTILE_CONT 实现）
        Builder::macro('withMedian', function (string $column, string $alias = 'median', ?string $partitionBy = null) {
            /** @var Builder $this */
            return $this->withPercentileCont(0.5, $column, $alias, $partitionBy);
        });

        // withQuartiles - 四分位数
        Builder::macro('withQuartiles', function (string $column, ?string $partitionBy = null) {
            /** @var Builder $this */
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY {$column}) OVER ({$partitionClause}) as q1, " .
                "PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY {$column}) OVER ({$partitionClause}) as q2, " .
                "PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY {$column}) OVER ({$partitionClause}) as q3"
            );
        });

        // withIQR - 四分位距（IQR = Q3 - Q1）
        Builder::macro('withIQR', function (string $column, string $alias = 'iqr', ?string $partitionBy = null) {
            /** @var Builder $this */
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this->selectRaw(
                "*, (PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY {$column}) OVER ({$partitionClause}) - " .
                "PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY {$column}) OVER ({$partitionClause})) as {$alias}"
            );
        });
    }
}
