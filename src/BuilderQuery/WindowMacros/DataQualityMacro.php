<?php

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Query\Builder;

/**
 * MySQL 8.4+ 数据质量检测窗口函数宏
 *
 * 提供数据质量检测和清洗功能，帮助识别数据异常、缺失和重复。
 * 所有函数均为通用型设计，适用于任意数据表和字段。
 *
 * @date 2026-04-07
 */
class DataQualityMacro
{
    /**
     * 注册所有数据质量检测宏函数
     *
     * @return void
     */
    public static function register(): void
    {
        /**
         * withNullCheck - 空值检测
         *
         * 检测指定列是否为空，并统计空值比例。
         *
         * @param string $column 要检测的列名
         * @param string|null $alias 输出列别名
         * @param string|null $partitionBy 分组列名（可选）
         *
         * @return Builder 返回查询构造器
         */
        Builder::macro('withNullCheck', function (
            string $column,
            ?string $alias = null,
            ?string $partitionBy = null
        ) {
            /** @var Builder $this */
            $alias = $alias ?? $column . '_is_null';
            $partitionClause = $partitionBy ? "PARTITION BY {$this->grammar->wrap($partitionBy)}" : '';

            return $this->selectRaw(
                "*, CASE WHEN {$this->grammar->wrap($column)} IS NULL THEN 1 ELSE 0 END as {$this->grammar->wrap($alias)}"
            )->selectRaw(
                "SUM(CASE WHEN {$this->grammar->wrap($column)} IS NULL THEN 1 ELSE 0 END) OVER ({$partitionClause}) / COUNT(*) OVER ({$partitionClause}) as {$this->grammar->wrap($alias . '_ratio')}"
            );
        });

        /**
         * withDuplicateFlag - 重复值标记
         *
         * 标记重复记录，方便识别和处理数据重复问题。
         *
         * @param string|array $columns 用于判断重复的列名（单个或多个）
         * @param string|null $alias 输出列别名
         *
         * @return Builder 返回查询构造器
         */
        Builder::macro('withDuplicateFlag', function (
            string|array $columns,
            ?string $alias = null
        ) {
            /** @var Builder $this */
            $alias = $alias ?? 'is_duplicate';
            $columns = is_array($columns) ? $columns : [$columns];
            $columnList = implode(', ', array_map([$this->grammar, 'wrap'], $columns));

            return $this->selectRaw(
                "*, CASE WHEN COUNT(*) OVER (PARTITION BY {$columnList}) > 1 THEN 1 ELSE 0 END as {$this->grammar->wrap($alias)}"
            );
        });

        /**
         * withOutlierDetection - 异常值检测（多方法）
         *
         * 使用多种统计方法检测异常值。
         *
         * @param string $column 数值列名
         * @param string $method 检测方法：'iqr'(默认), 'zscore', 'mad'
         * @param float $threshold 阈值（IQR倍数或Z分数阈值）
         * @param string|null $alias 输出列别名
         * @param string|null $partitionBy 分组列名（可选）
         *
         * @return Builder 返回查询构造器
         */
        Builder::macro('withOutlierDetection', function (
            string $column,
            string $method = 'iqr',
            float $threshold = 1.5,
            ?string $alias = null,
            ?string $partitionBy = null
        ) {
            /** @var Builder $this */
            $alias = $alias ?? 'is_outlier';
            $partitionClause = $partitionBy ? "PARTITION BY {$this->grammar->wrap($partitionBy)}" : '';

            return match ($method) {
                'zscore' => $this->selectRaw(
                    "*, CASE WHEN ABS(({$this->grammar->wrap($column)} - AVG({$this->grammar->wrap($column)}) OVER ({$partitionClause})) / NULLIF(STDDEV({$this->grammar->wrap($column)}) OVER ({$partitionClause}), 0)) > {$threshold} THEN 1 ELSE 0 END as {$this->grammar->wrap($alias)}"
                ),
                'mad' => $this->selectRaw(
                    "*, CASE WHEN ABS({$this->grammar->wrap($column)} - PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY {$this->grammar->wrap($column)}) OVER ({$partitionClause})) > {$threshold} * AVG(ABS({$this->grammar->wrap($column)} - PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY {$this->grammar->wrap($column)}) OVER ({$partitionClause}))) OVER ({$partitionClause}) THEN 1 ELSE 0 END as {$this->grammar->wrap($alias)}"
                ),
                default => $this->selectRaw(
                    "*, CASE WHEN {$this->grammar->wrap($column)} < (PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY {$this->grammar->wrap($column)}) OVER ({$partitionClause}) - {$threshold} * (PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY {$this->grammar->wrap($column)}) OVER ({$partitionClause}) - PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY {$this->grammar->wrap($column)}) OVER ({$partitionClause}))) OR {$this->grammar->wrap($column)} > (PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY {$this->grammar->wrap($column)}) OVER ({$partitionClause}) + {$threshold} * (PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY {$this->grammar->wrap($column)}) OVER ({$partitionClause}) - PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY {$this->grammar->wrap($column)}) OVER ({$partitionClause}))) THEN 1 ELSE 0 END as {$this->grammar->wrap($alias)}"
                ),
            };
        });

        /**
         * withDataCompleteness - 数据完整度评分
         *
         * 计算每条记录的数据完整度百分比。
         *
         * @param array $columns 要检查的列名数组
         * @param string|null $alias 输出列别名
         *
         * @return Builder 返回查询构造器
         */
        Builder::macro('withDataCompleteness', function (
            array $columns,
            ?string $alias = null
        ) {
            /** @var Builder $this */
            $alias = $alias ?? 'completeness_score';
            $totalColumns = count($columns);
            $caseStatements = [];

            foreach ($columns as $column) {
                $caseStatements[] = "CASE WHEN {$this->grammar->wrap($column)} IS NOT NULL THEN 1 ELSE 0 END";
            }

            $completenessExpr = '(' . implode(' + ', $caseStatements) . ')';

            return $this->selectRaw(
                "*, {$completenessExpr} / {$totalColumns} as {$this->grammar->wrap($alias)}"
            );
        });

        /**
         * withConsistencyCheck - 数据一致性检查
         *
         * 检查相关字段之间的一致性。
         *
         * @param string $column1 第一个列名
         * @param string $column2 第二个列名
         * @param string $operator 比较运算符：'=', '!=', '>', '<', '>=', '<='
         * @param string|null $alias 输出列别名
         *
         * @return Builder 返回查询构造器
         */
        Builder::macro('withConsistencyCheck', function (
            string $column1,
            string $column2,
            string $operator = '=',
            ?string $alias = null
        ) {
            /** @var Builder $this */
            $alias = $alias ?? 'is_consistent';

            return $this->selectRaw(
                "*, CASE WHEN {$this->grammar->wrap($column1)} {$operator} {$this->grammar->wrap($column2)} THEN 1 ELSE 0 END as {$this->grammar->wrap($alias)}"
            );
        });

        /**
         * withPatternMatch - 模式匹配检测
         *
         * 检测字符串是否符合指定正则表达式模式。
         *
         * @param string $column 字符串列名
         * @param string $pattern 正则表达式模式
         * @param string|null $alias 输出列别名
         *
         * @return Builder 返回查询构造器
         */
        Builder::macro('withPatternMatch', function (
            string $column,
            string $pattern,
            ?string $alias = null
        ) {
            /** @var Builder $this */
            $alias = $alias ?? 'pattern_match';

            return $this->selectRaw(
                "*, CASE WHEN {$this->grammar->wrap($column)} REGEXP '{$pattern}' THEN 1 ELSE 0 END as {$this->grammar->wrap($alias)}"
            );
        });

        /**
         * withRangeValidation - 范围有效性检查
         *
         * 检查数值是否在指定有效范围内。
         *
         * @param string $column 数值列名
         * @param float|null $min 最小值（null表示不限制）
         * @param float|null $max 最大值（null表示不限制）
         * @param string|null $alias 输出列别名
         *
         * @return Builder 返回查询构造器
         */
        Builder::macro('withRangeValidation', function (
            string $column,
            ?float $min = null,
            ?float $max = null,
            ?string $alias = null
        ) {
            /** @var Builder $this */
            $alias = $alias ?? 'in_range';
            $conditions = [];

            if ($min !== null) {
                $conditions[] = "{$this->grammar->wrap($column)} >= {$min}";
            }
            if ($max !== null) {
                $conditions[] = "{$this->grammar->wrap($column)} <= {$max}";
            }

            $conditionStr = empty($conditions) ? '1=1' : implode(' AND ', $conditions);

            return $this->selectRaw(
                "*, CASE WHEN {$conditionStr} THEN 1 ELSE 0 END as {$this->grammar->wrap($alias)}"
            );
        });
    }
}
