<?php

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * MySQL 8.4+ 窗口函数宏集合
 *
 * 提供丰富的窗口函数支持，包括：
 * - 排名函数: rowNumber, rank, denseRank, percentRank
 * - 偏移函数: lag, lead, firstValue, lastValue, nthValue
 * - 聚合窗口函数: sumOver, avgOver, countOver, minOver, maxOver
 * - 分区分组: partitionBy
 * - 框架窗口: rowsBetween, rangeBetween
 * - 累计统计: cumulativeSum, movingAverage
 *
 * @package zxf\Modules\BuilderQuery\WindowMacros
 * @version 1.0.0
 * @requires MySQL 8.4+
 */
class WindowFunctionsMacro
{
    /**
     * 注册所有窗口函数宏
     *
     * @return void
     */
    public static function register(): void
    {
        self::registerRankingFunctions();
        self::registerOffsetFunctions();
        self::registerAggregateWindowFunctions();
        self::registerFrameWindowFunctions();
        self::registerStatisticalFunctions();
    }

    /**
     * 注册排名函数
     *
     * 包含: rowNumber, rank, denseRank, percentRank, ntile
     */
    protected static function registerRankingFunctions(): void
    {
        /**
         * 为每一行分配唯一的连续整数序号
         *
         * 应用场景：生成行号、分页辅助、数据排序编号
         *
         * @param string|array $partitionBy 分区字段，支持字符串或数组
         * @param string $orderBy 排序字段，默认主键
         * @param string $direction 排序方向: asc|desc
         * @param string $alias 结果列别名，默认 'row_num'
         * @return Builder
         *
         * @example
         * // 为每个部门的员工按工资排序编号
         * Employee::query()->rowNumber('department_id', 'salary', 'desc', 'rank_in_dept')->get();
         *
         * // 全局行号
         * Employee::query()->rowNumber(null, 'created_at', 'desc')->get();
         *
         * // 多字段分区
         * Employee::query()->rowNumber(['dept_id', 'team_id'], 'performance_score', 'desc')->get();
         */
        Builder::macro('rowNumber', function (
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            string $alias = 'row_num'
        ): Builder {
            /** @var Builder $this */
            $direction = strtoupper($direction);
            $orderColumn = $orderBy ?: $this->getModel()->getKeyName();

            $partitionClause = self::buildPartitionClause($partitionBy);
            $orderClause = $orderColumn ? "ORDER BY `{$orderColumn}` {$direction}" : '';

            $windowExpr = "ROW_NUMBER() OVER ({$partitionClause} {$orderClause}) AS `{$alias}`";

            return $this->addSelect(DB::raw($windowExpr));
        });

        /**
         * 为每一行分配排名，相同值会有相同排名，后续排名会跳过
         *
         * 特点：相同值排名相同，下一个排名 = 当前排名 + 相同值数量
         * 应用场景：竞赛排名（允许并列，下一名次跳过）
         *
         * @param string|array|null $partitionBy 分区字段
         * @param string $orderBy 排序字段
         * @param string $direction 排序方向: asc|desc
         * @param string $alias 结果列别名，默认 'rank_num'
         * @return Builder
         *
         * @example
         * // 竞赛排名，相同分数并列，下一名次跳过
         * Competition::query()->rank(null, 'score', 'desc', 'competition_rank')->get();
         * // 结果: 第一名100分, 第二名99分, 第二名99分, 第四名98分...
         */
        Builder::macro('rank', function (
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            string $alias = 'rank_num'
        ): Builder {
            /** @var Builder $this */
            $direction = strtoupper($direction);
            $orderColumn = $orderBy ?: $this->getModel()->getKeyName();

            $partitionClause = self::buildPartitionClause($partitionBy);
            $orderClause = $orderColumn ? "ORDER BY `{$orderColumn}` {$direction}" : '';

            $windowExpr = "RANK() OVER ({$partitionClause} {$orderClause}) AS `{$alias}`";

            return $this->addSelect(DB::raw($windowExpr));
        });

        /**
         * 为每一行分配排名，相同值会有相同排名，后续排名不跳过
         *
         * 特点：相同值排名相同，下一个排名连续
         * 应用场景：等级评定、连续排名场景
         *
         * @param string|array|null $partitionBy 分区字段
         * @param string $orderBy 排序字段
         * @param string $direction 排序方向: asc|desc
         * @param string $alias 结果列别名，默认 'dense_rank_num'
         * @return Builder
         *
         * @example
         * // 等级评定，相同分数并列，下一名次连续
         * Exam::query()->denseRank('class_id', 'total_score', 'desc', 'class_rank')->get();
         * // 结果: 第1名100分, 第2名99分, 第2名99分, 第3名98分...
         */
        Builder::macro('denseRank', function (
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            string $alias = 'dense_rank_num'
        ): Builder {
            /** @var Builder $this */
            $direction = strtoupper($direction);
            $orderColumn = $orderBy ?: $this->getModel()->getKeyName();

            $partitionClause = self::buildPartitionClause($partitionBy);
            $orderClause = $orderColumn ? "ORDER BY `{$orderColumn}` {$direction}" : '';

            $windowExpr = "DENSE_RANK() OVER ({$partitionClause} {$orderClause}) AS `{$alias}`";

            return $this->addSelect(DB::raw($windowExpr));
        });

        /**
         * 计算每行的相对排名百分比
         *
         * 公式：(rank - 1) / (总行数 - 1)
         * 应用场景：成绩百分比排名、数据分布分析
         *
         * @param string|array|null $partitionBy 分区字段
         * @param string $orderBy 排序字段
         * @param string $direction 排序方向: asc|desc
         * @param string $alias 结果列别名，默认 'percent_rank_val'
         * @return Builder
         *
         * @example
         * // 计算学生在班级成绩的百分比排名
         * Student::query()->percentRank('class_id', 'exam_score', 'desc', 'percentile')->get();
         * // 结果: 0=最高, 0.5=中间, 1=最低
         */
        Builder::macro('percentRank', function (
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            string $alias = 'percent_rank_val'
        ): Builder {
            /** @var Builder $this */
            $direction = strtoupper($direction);
            $orderColumn = $orderBy ?: $this->getModel()->getKeyName();

            $partitionClause = self::buildPartitionClause($partitionBy);
            $orderClause = $orderColumn ? "ORDER BY `{$orderColumn}` {$direction}" : '';

            $windowExpr = "PERCENT_RANK() OVER ({$partitionClause} {$orderClause}) AS `{$alias}`";

            return $this->addSelect(DB::raw($windowExpr));
        });

        /**
         * 将数据分为N个桶（分位数），返回桶号
         *
         * 应用场景：四分位数、十分位数、百分位数分析
         *
         * @param int $buckets 桶的数量，必须大于0
         * @param string|array|null $partitionBy 分区字段
         * @param string $orderBy 排序字段
         * @param string $direction 排序方向: asc|desc
         * @param string $alias 结果列别名，默认 'bucket_num'
         * @return Builder
         *
         * @example
         * // 四分位数分析
         * Sales::query()->ntile(4, 'region', 'amount', 'desc', 'quartile')->get();
         * // 结果: 1=前25%, 2=25-50%, 3=50-75%, 4=后25%
         *
         * // 十分位数分析
         * Performance::query()->ntile(10, null, 'score', 'desc', 'decile')->get();
         */
        Builder::macro('ntile', function (
            int $buckets,
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            string $alias = 'bucket_num'
        ): Builder {
            /** @var Builder $this */
            if ($buckets < 1) {
                throw new \InvalidArgumentException('NTILE buckets must be greater than 0');
            }

            $direction = strtoupper($direction);
            $orderColumn = $orderBy ?: $this->getModel()->getKeyName();

            $partitionClause = self::buildPartitionClause($partitionBy);
            $orderClause = $orderColumn ? "ORDER BY `{$orderColumn}` {$direction}" : '';

            $windowExpr = "NTILE({$buckets}) OVER ({$partitionClause} {$orderClause}) AS `{$alias}`";

            return $this->addSelect(DB::raw($windowExpr));
        });
    }

    /**
     * 注册偏移函数
     *
     * 包含: lag, lead, firstValue, lastValue, nthValue
     */
    protected static function registerOffsetFunctions(): void
    {
        /**
         * 获取当前行之前第N行的值
         *
         * 应用场景：计算环比、与上期比较、趋势分析
         *
         * @param string $column 要获取值的列名
         * @param int $offset 偏移量，默认1（前一行）
         * @param mixed $default 当偏移行不存在时的默认值
         * @param string|array|null $partitionBy 分区字段
         * @param string $orderBy 排序字段
         * @param string $direction 排序方向
         * @param string $alias 结果列别名，默认为 {column}_lag
         * @return Builder
         *
         * @example
         * // 计算每日销售额与前一天比较
         * DailySales::query()
         *     ->lag('amount', 1, 0, null, 'sale_date', 'asc', 'prev_day_amount')
         *     ->selectRaw('amount - prev_day_amount as day_over_day')
         *     ->get();
         *
         * // 计算每月与去年同月比较（同比）
         * MonthlyData::query()
         *     ->lag('revenue', 12, 0, null, 'year_month', 'asc', 'last_year_same_month')
         *     ->get();
         */
        Builder::macro('lag', function (
            string $column,
            int $offset = 1,
            mixed $default = null,
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            ?string $alias = null
        ): Builder {
            /** @var Builder $this */
            $direction = strtoupper($direction);
            $orderColumn = $orderBy ?: $this->getModel()->getKeyName();
            $alias = $alias ?: "{$column}_lag";
            $defaultValue = $default === null ? 'NULL' : (is_string($default) ? "'{$default}'" : $default);

            $partitionClause = self::buildPartitionClause($partitionBy);
            $orderClause = $orderColumn ? "ORDER BY `{$orderColumn}` {$direction}" : '';

            $windowExpr = "LAG(`{$column}`, {$offset}, {$defaultValue}) OVER ({$partitionClause} {$orderClause}) AS `{$alias}`";

            return $this->addSelect(DB::raw($windowExpr));
        });

        /**
         * 获取当前行之后第N行的值
         *
         * 应用场景：预测下期、未来值比较、目标达成分析
         *
         * @param string $column 要获取值的列名
         * @param int $offset 偏移量，默认1（后一行）
         * @param mixed $default 当偏移行不存在时的默认值
         * @param string|array|null $partitionBy 分区字段
         * @param string $orderBy 排序字段
         * @param string $direction 排序方向
         * @param string $alias 结果列别名，默认为 {column}_lead
         * @return Builder
         *
         * @example
         * // 查看每个用户的下一次购买时间
         * Orders::query()
         *     ->lead('created_at', 1, null, 'user_id', 'created_at', 'asc', 'next_order_at')
         *     ->selectRaw('DATEDIFF(next_order_at, created_at) as days_until_next')
         *     ->get();
         */
        Builder::macro('lead', function (
            string $column,
            int $offset = 1,
            mixed $default = null,
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            ?string $alias = null
        ): Builder {
            /** @var Builder $this */
            $direction = strtoupper($direction);
            $orderColumn = $orderBy ?: $this->getModel()->getKeyName();
            $alias = $alias ?: "{$column}_lead";
            $defaultValue = $default === null ? 'NULL' : (is_string($default) ? "'{$default}'" : $default);

            $partitionClause = self::buildPartitionClause($partitionBy);
            $orderClause = $orderColumn ? "ORDER BY `{$orderColumn}` {$direction}" : '';

            $windowExpr = "LEAD(`{$column}`, {$offset}, {$defaultValue}) OVER ({$partitionClause} {$orderClause}) AS `{$alias}`";

            return $this->addSelect(DB::raw($windowExpr));
        });

        /**
         * 获取窗口框架中第一行的值
         *
         * 应用场景：计算与首行的差值、基准值比较
         *
         * @param string $column 要获取值的列名
         * @param string|array|null $partitionBy 分区字段
         * @param string $orderBy 排序字段
         * @param string $direction 排序方向
         * @param string $alias 结果列别名，默认为 {column}_first
         * @return Builder
         *
         * @example
         * // 计算每天股价与当月首日开盘价的差额
         * StockPrice::query()
         *     ->firstValue('open_price', 'stock_code', 'trade_date', 'asc', 'month_first_price')
         *     ->selectRaw('open_price - month_first_price as price_change')
         *     ->get();
         */
        Builder::macro('firstValue', function (
            string $column,
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            ?string $alias = null
        ): Builder {
            /** @var Builder $this */
            $direction = strtoupper($direction);
            $orderColumn = $orderBy ?: $this->getModel()->getKeyName();
            $alias = $alias ?: "{$column}_first";

            $partitionClause = self::buildPartitionClause($partitionBy);
            $orderClause = $orderColumn ? "ORDER BY `{$orderColumn}` {$direction}" : '';

            $windowExpr = "FIRST_VALUE(`{$column}`) OVER ({$partitionClause} {$orderClause}) AS `{$alias}`";

            return $this->addSelect(DB::raw($windowExpr));
        });

        /**
         * 获取窗口框架中最后一行的值
         *
         * 应用场景：计算与末行的差值、目标差距分析
         *
         * @param string $column 要获取值的列名
         * @param string|array|null $partitionBy 分区字段
         * @param string $orderBy 排序字段
         * @param string $direction 排序方向
         * @param string $alias 结果列别名，默认为 {column}_last
         * @return Builder
         *
         * @example
         * // 计算每个销售员与团队最佳业绩的差距
         * Sales::query()
         *     ->lastValue('amount', 'team_id', 'amount', 'asc', 'team_best')
         *     ->selectRaw('team_best - amount as gap_to_best')
         *     ->get();
         */
        Builder::macro('lastValue', function (
            string $column,
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            ?string $alias = null
        ): Builder {
            /** @var Builder $this */
            $direction = strtoupper($direction);
            $orderColumn = $orderBy ?: $this->getModel()->getKeyName();
            $alias = $alias ?: "{$column}_last";

            $partitionClause = self::buildPartitionClause($partitionBy);
            $orderClause = $orderColumn ? "ORDER BY `{$orderColumn}` {$direction}" : '';

            $windowExpr = "LAST_VALUE(`{$column}`) OVER ({$partitionClause} {$orderClause}) AS `{$alias}`";

            return $this->addSelect(DB::raw($windowExpr));
        });

        /**
         * 获取窗口框架中第N行的值
         *
         * 应用场景：获取特定排名的数据、目标位置分析
         *
         * @param string $column 要获取值的列名
         * @param int $n 行号，从1开始
         * @param string|array|null $partitionBy 分区字段
         * @param string $orderBy 排序字段
         * @param string $direction 排序方向
         * @param string $alias 结果列别名，默认为 {column}_nth
         * @return Builder
         *
         * @example
         * // 获取每个班级第3名的成绩作为基准线
         * ExamResult::query()
         *     ->nthValue('score', 3, 'class_id', 'score', 'desc', 'third_place_score')
         *     ->get();
         */
        Builder::macro('nthValue', function (
            string $column,
            int $n,
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            ?string $alias = null
        ): Builder {
            /** @var Builder $this */
            if ($n < 1) {
                throw new \InvalidArgumentException('NTH_VALUE n must be greater than 0');
            }

            $direction = strtoupper($direction);
            $orderColumn = $orderBy ?: $this->getModel()->getKeyName();
            $alias = $alias ?: "{$column}_nth";

            $partitionClause = self::buildPartitionClause($partitionBy);
            $orderClause = $orderColumn ? "ORDER BY `{$orderColumn}` {$direction}" : '';

            $windowExpr = "NTH_VALUE(`{$column}`, {$n}) OVER ({$partitionClause} {$orderClause}) AS `{$alias}`";

            return $this->addSelect(DB::raw($windowExpr));
        });
    }

    /**
     * 注册聚合窗口函数
     *
     * 包含: sumOver, avgOver, countOver, minOver, maxOver
     */
    protected static function registerAggregateWindowFunctions(): void
    {
        $aggregates = [
            'sumOver' => ['fn' => 'SUM', 'defaultAlias' => 'sum_val'],
            'avgOver' => ['fn' => 'AVG', 'defaultAlias' => 'avg_val'],
            'countOver' => ['fn' => 'COUNT', 'defaultAlias' => 'count_val'],
            'minOver' => ['fn' => 'MIN', 'defaultAlias' => 'min_val'],
            'maxOver' => ['fn' => 'MAX', 'defaultAlias' => 'max_val'],
        ];

        foreach ($aggregates as $macroName => $config) {
            /**
             * 聚合窗口函数宏
             *
             * 在保持原始行 detail 的同时，计算聚合统计值
             * 相比 GROUP BY，不会折叠行，每行都会保留并附带聚合值
             *
             * @param string $column 要聚合的列名
             * @param string|array|null $partitionBy 分区字段
             * @param string|null $orderBy 排序字段（用于有序聚合）
             * @param string $direction 排序方向
             * @param string|null $alias 结果列别名
             * @return Builder
             *
             * @example
             * // 计算每个员工销售额占部门总销售额的比例
             * Sales::query()
             *     ->sumOver('amount', 'department_id', null, 'asc', 'dept_total')
             *     ->selectRaw('amount / dept_total * 100 as percentage')
             *     ->get();
             *
             * // 计算每行数据的累计平均值（移动平均）
             * StockPrice::query()
             *     ->avgOver('close_price', 'stock_code', 'trade_date', 'asc', 'ma')
             *     ->get();
             */
            Builder::macro($macroName, function (
                string $column,
                string|array|null $partitionBy = null,
                ?string $orderBy = null,
                string $direction = 'asc',
                ?string $alias = null
            ) use ($config): Builder {
                /** @var Builder $this */
                $direction = strtoupper($direction);
                $alias = $alias ?: $config['defaultAlias'];

                $partitionClause = self::buildPartitionClause($partitionBy);
                $orderClause = $orderBy ? "ORDER BY `{$orderBy}` {$direction}" : '';
                $orderClause = $orderClause ? " {$orderClause}" : '';

                $windowExpr = "{$config['fn']}(`{$column}`) OVER ({$partitionClause}{$orderClause}) AS `{$alias}`";

                return $this->addSelect(DB::raw($windowExpr));
            });
        }
    }

    /**
     * 注册框架窗口函数
     *
     * 包含: rowsBetween, rangeBetween
     */
    protected static function registerFrameWindowFunctions(): void
    {
        /**
         * 使用 ROWS 框架指定窗口范围
         *
         * 基于物理行数定义窗口框架
         *
         * @param string $column 要计算的列
         * @param string $function 聚合函数: SUM|AVG|COUNT|MIN|MAX
         * @param string $start 框架起点: UNBOUNDED PRECEDING|N PRECEDING|CURRENT ROW
         * @param string $end 框架终点: UNBOUNDED FOLLOWING|N FOLLOWING|CURRENT ROW
         * @param string|array|null $partitionBy 分区字段
         * @param string $orderBy 排序字段
         * @param string $direction 排序方向
         * @param string $alias 结果列别名
         * @return Builder
         *
         * @example
         * // 计算3日移动平均（当前行及前2行）
         * StockPrice::query()
         *     ->rowsBetween('close_price', 'AVG', '2 PRECEDING', 'CURRENT ROW', 'stock_code', 'date', 'asc', 'ma3')
         *     ->get();
         *
         * // 计算从分区开始到当前行的累计和
         * Sales::query()
         *     ->rowsBetween('amount', 'SUM', 'UNBOUNDED PRECEDING', 'CURRENT ROW', 'region', 'date', 'asc', 'cumulative')
         *     ->get();
         */
        Builder::macro('rowsBetween', function (
            string $column,
            string $function,
            string $start,
            string $end,
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            string $alias = 'frame_result'
        ): Builder {
            /** @var Builder $this */
            $direction = strtoupper($direction);
            $function = strtoupper($function);
            $orderColumn = $orderBy ?: $this->getModel()->getKeyName();

            $partitionClause = self::buildPartitionClause($partitionBy);
            $orderClause = $orderColumn ? "ORDER BY `{$orderColumn}` {$direction}" : '';

            $windowExpr = "{$function}(`{$column}`) OVER ({$partitionClause} {$orderClause} ROWS BETWEEN {$start} AND {$end}) AS `{$alias}`";

            return $this->addSelect(DB::raw($windowExpr));
        });

        /**
         * 使用 RANGE 框架指定窗口范围
         *
         * 基于值范围定义窗口框架（需要 orderBy）
         *
         * @param string $column 要计算的列
         * @param string $function 聚合函数
         * @param string $start 框架起点
         * @param string $end 框架终点
         * @param string|array|null $partitionBy 分区字段
         * @param string $orderBy 排序字段（必须）
         * @param string $direction 排序方向
         * @param string $alias 结果列别名
         * @return Builder
         *
         * @example
         * // 计算数值范围内（±10）的平均值
         * Measurements::query()
         *     ->rangeBetween('value', 'AVG', '10 PRECEDING', '10 FOLLOWING', 'sensor_id', 'reading', 'asc', 'smoothed')
         *     ->get();
         */
        Builder::macro('rangeBetween', function (
            string $column,
            string $function,
            string $start,
            string $end,
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            string $alias = 'frame_result'
        ): Builder {
            /** @var Builder $this */
            if (empty($orderBy)) {
                throw new \InvalidArgumentException('RANGE frame requires orderBy parameter');
            }

            $direction = strtoupper($direction);
            $function = strtoupper($function);

            $partitionClause = self::buildPartitionClause($partitionBy);
            $orderClause = "ORDER BY `{$orderBy}` {$direction}";

            $windowExpr = "{$function}(`{$column}`) OVER ({$partitionClause} {$orderClause} RANGE BETWEEN {$start} AND {$end}) AS `{$alias}`";

            return $this->addSelect(DB::raw($windowExpr));
        });
    }

    /**
     * 注册统计函数
     *
     * 包含: cumulativeSum, movingAverage, runningTotal
     */
    protected static function registerStatisticalFunctions(): void
    {
        /**
         * 计算累计和（从分区开始到当前行）
         *
         * @param string $column 要计算的列
         * @param string|array|null $partitionBy 分区字段
         * @param string $orderBy 排序字段
         * @param string $direction 排序方向
         * @param string $alias 结果列别名，默认 'cumulative_sum'
         * @return Builder
         *
         * @example
         * // 计算累计销售额
         * Sales::query()->cumulativeSum('amount', 'region', 'date', 'asc', 'running_total')->get();
         */
        Builder::macro('cumulativeSum', function (
            string $column,
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            string $alias = 'cumulative_sum'
        ): Builder {
            /** @var Builder $this */
            return $this->rowsBetween(
                $column,
                'SUM',
                'UNBOUNDED PRECEDING',
                'CURRENT ROW',
                $partitionBy,
                $orderBy,
                $direction,
                $alias
            );
        });

        /**
         * 计算移动平均
         *
         * @param string $column 要计算的列
         * @param int $windowSize 窗口大小（每边行数）
         * @param string|array|null $partitionBy 分区字段
         * @param string $orderBy 排序字段
         * @param string $direction 排序方向
         * @param string $alias 结果列别名，默认 'moving_avg'
         * @return Builder
         *
         * @example
         * // 计算5日移动平均（当前行±2行）
         * StockPrice::query()->movingAverage('close_price', 2, 'stock_code', 'date', 'asc', 'ma5')->get();
         */
        Builder::macro('movingAverage', function (
            string $column,
            int $windowSize,
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            string $alias = 'moving_avg'
        ): Builder {
            /** @var Builder $this */
            return $this->rowsBetween(
                $column,
                'AVG',
                "{$windowSize} PRECEDING",
                "{$windowSize} FOLLOWING",
                $partitionBy,
                $orderBy,
                $direction,
                $alias
            );
        });

        /**
         * 计算运行总计（累计和的别名）
         *
         * @param string $column 要计算的列
         * @param string|array|null $partitionBy 分区字段
         * @param string $orderBy 排序字段
         * @param string $direction 排序方向
         * @param string $alias 结果列别名，默认 'running_total'
         * @return Builder
         *
         * @example
         * // 计算运行总计
         * Orders::query()->runningTotal('amount', 'customer_id', 'created_at', 'asc')->get();
         */
        Builder::macro('runningTotal', function (
            string $column,
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            string $alias = 'running_total'
        ): Builder {
            /** @var Builder $this */
            return $this->cumulativeSum($column, $partitionBy, $orderBy, $direction, $alias);
        });
    }

    /**
     * 构建 PARTITION BY 子句
     *
     * @param string|array|null $partitionBy 分区字段
     * @return string
     */
    public static function buildPartitionClause(string|array|null $partitionBy): string
    {
        if (empty($partitionBy)) {
            return '';
        }

        if (is_string($partitionBy)) {
            return "PARTITION BY `{$partitionBy}`";
        }

        if (is_array($partitionBy)) {
            $columns = array_map(fn ($col) => "`{$col}`", $partitionBy);
            return 'PARTITION BY ' . implode(', ', $columns);
        }

        return '';
    }
}
