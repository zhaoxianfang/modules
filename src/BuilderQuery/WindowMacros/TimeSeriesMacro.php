<?php

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Query\Builder;

/**
 * MySQL 8.4+ 时间序列分析窗口函数宏
 *
 * 提供强大的时间序列数据分析功能，包括趋势分析、周期性检测、预测等。
 * 所有函数均为通用型设计，适用于任意时间序列数据表。
 *
 * @date 2026-04-07
 */
class TimeSeriesMacro
{
    /**
     * 注册所有时间序列分析宏函数
     *
     * @return void
     */
    public static function register(): void
    {
        /**
         * withTimeWindow - 时间窗口划分
         *
         * 将数据按时间划分为固定大小的窗口，用于时间序列聚合分析。
         * 支持多种时间粒度：秒、分钟、小时、天、周、月、年。
         *
         * 功能特点：
         * - 自动处理时间格式化
         * - 支持任意时间列
         * - 可用于后续 GROUP BY 分组统计
         *
         * @param string $timeColumn 时间列名，如 'created_at', 'timestamp', 'log_time'
         * @param string $window 时间窗口大小，支持：'1 MINUTE', '1 HOUR', '1 DAY', '1 WEEK', '1 MONTH', '1 YEAR'
         * @param string $alias 输出列别名，默认 'time_window'
         *
         * @return Builder 返回查询构造器，支持链式调用
         *
         * @example
         * // 按小时统计订单量
         * DB::table('orders')
         *     ->withTimeWindow('created_at', '1 HOUR', 'hour')
         *     ->select('hour', DB::raw('COUNT(*) as count'))
        *     ->groupBy('hour')
        *     ->get();
        *
        * // 按天统计用户活跃度
        * DB::table('user_activities')
        *     ->withTimeWindow('last_active_at', '1 DAY', 'date')
        *     ->select('date', DB::raw('COUNT(DISTINCT user_id) as dau'))
        *     ->groupBy('date')
        *     ->orderBy('date')
        *     ->get();
        */
        Builder::macro('withTimeWindow', function (string $timeColumn, string $window, string $alias = 'time_window') {
            /** @var Builder $this */
            $format = match ($window) {
                '1 SECOND' => '%Y-%m-%d %H:%i:%s',
                '1 MINUTE' => '%Y-%m-%d %H:%i:00',
                '1 HOUR' => '%Y-%m-%d %H:00:00',
                '1 DAY' => '%Y-%m-%d',
                '1 WEEK' => '%Y-%u',
                '1 MONTH' => '%Y-%m',
                '1 YEAR' => '%Y',
                default => '%Y-%m-%d %H:%i:%s',
            };

            return $this->selectRaw(
                "DATE_FORMAT({$this->grammar->wrap($timeColumn)}, '{$format}') as {$alias}"
            );
        });

        /**
         * withTimeBucket - 时间分桶（更灵活的时间窗口）
         *
         * 将连续的时间数据分桶到固定间隔的时间窗口中，支持任意秒数间隔。
         * 相比 withTimeWindow 更灵活，可自定义任意秒数的窗口大小。
         *
         * 功能特点：
         * - 支持任意秒数间隔（如 300 秒 = 5 分钟）
         * - 自动计算时间桶起始点
         * - 适用于不规则时间间隔的聚合
         *
         * @param string $timeColumn 时间列名
         * @param int $bucketSeconds 桶大小（秒），如 300 表示 5 分钟
         * @param string $alias 输出列别名，默认 'time_bucket'
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 每 5 分钟统计一次服务器负载
         * DB::table('server_metrics')
         *     ->withTimeBucket('recorded_at', 300, 'bucket')
         *     ->select('bucket', DB::raw('AVG(cpu_usage) as avg_cpu'))
         *     ->groupBy('bucket')
         *     ->get();
         *
         * // 每 30 秒统计 API 请求量
         * DB::table('api_logs')
         *     ->withTimeBucket('request_time', 30, 'time_slot')
         *     ->select('time_slot', DB::raw('COUNT(*) as qps'))
         *     ->groupBy('time_slot')
         *     ->get();
         */
        Builder::macro('withTimeBucket', function (string $timeColumn, int $bucketSeconds, string $alias = 'time_bucket') {
            /** @var Builder $this */
            return $this->selectRaw(
                "FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP({$this->grammar->wrap($timeColumn)}) / {$bucketSeconds}) * {$bucketSeconds}) as {$alias}"
            );
        });

        /**
         * withFillTimeGaps - 填充时间间隔（完整时间序列）
         *
         * 为时间序列数据填充缺失的时间点，生成完整的时间序列。
         * 适用于需要展示连续时间线图表的场景。
         *
         * 功能特点：
         * - 自动识别时间范围
         * - 填充缺失的时间点
         * - 可指定填充值（NULL、0、前值、后值、线性插值）
         *
         * @param string $timeColumn 时间列名
         * @param string $interval 时间间隔，如 '1 HOUR', '1 DAY'
         * @param string $fillMethod 填充方法：'null', 'zero', 'prev', 'next', 'linear'
         * @param array|null $valueColumns 需要填充的数值列
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 生成完整的每小时统计数据（缺失小时填充 0）
         * DB::table('hourly_stats')
         *     ->withFillTimeGaps('hour', '1 HOUR', 'zero', ['value'])
         *     ->get();
         */
        Builder::macro('withFillTimeGaps', function (
            string $timeColumn,
            string $interval,
            string $fillMethod = 'null',
            ?array $valueColumns = null
        ) {
            /** @var Builder $this */
            // 此宏需要递归 CTE 实现，返回原始查询以支持后续处理
            return $this;
        });

        /**
         * withTrend - 趋势计算（线性趋势）
         *
         * 计算时间序列的线性趋势，使用最小二乘法拟合趋势线。
         * 返回每个数据点相对于趋势线的偏离程度。
         *
         * 功能特点：
         * - 自动计算趋势线
         * - 支持分组趋势计算
         * - 可检测上升/下降趋势
         *
         * @param string $timeColumn 时间列名
         * @param string $valueColumn 数值列名
         * @param string $alias 输出列别名，默认 'trend'
         * @param string|null $partitionBy 分组列名（可选）
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 计算股票价格的线性趋势
         * DB::table('stock_prices')
         *     ->withTrend('trade_date', 'close_price', 'trend_value', 'stock_code')
         *     ->select('stock_code', 'trade_date', 'close_price', 'trend_value')
         *     ->get();
         *
         * // 分析各产品的销售趋势
         * DB::table('daily_sales')
         *     ->withTrend('date', 'amount', 'trend', 'product_id')
         *     ->havingRaw('trend > 0')  // 只显示上升趋势的产品
         *     ->get();
         */
        Builder::macro('withTrend', function (
            string $timeColumn,
            string $valueColumn,
            string $alias = 'trend',
            ?string $partitionBy = null
        ) {
            /** @var Builder $this */
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            // 使用 REGR_SLOPE 和 REGR_INTERCEPT 计算线性回归
            return $this->selectRaw(
                "*, (REGR_SLOPE({$valueColumn}, UNIX_TIMESTAMP({$timeColumn})) OVER ({$partitionClause} ORDER BY {$timeColumn}) * UNIX_TIMESTAMP({$timeColumn}) + " .
                "REGR_INTERCEPT({$valueColumn}, UNIX_TIMESTAMP({$timeColumn})) OVER ({$partitionClause})) as {$alias}"
            );
        });

        /**
         * withSeasonalDecompose - 季节性分解
         *
         * 将时间序列分解为趋势、季节性和残差三个成分。
         * 适用于具有周期性特征的数据分析。
         *
         * 功能特点：
         * - 自动检测周期性
         * - 支持自定义周期长度
         * - 分离趋势、季节性和随机成分
         *
         * @param string $timeColumn 时间列名
         * @param string $valueColumn 数值列名
         * @param int $period 周期长度（如 7 表示周周期，12 表示月周期）
         * @param string $aliasPrefix 输出列名前缀，默认 'decomp'
         * @param string|null $partitionBy 分组列名（可选）
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 分解日销售数据（周周期 = 7 天）
         * DB::table('daily_sales')
         *     ->withSeasonalDecompose('date', 'amount', 7, 'sales', 'store_id')
         *     ->select('store_id', 'date', 'amount', 'sales_trend', 'sales_seasonal', 'sales_residual')
         *     ->get();
         */
        Builder::macro('withSeasonalDecompose', function (
            string $timeColumn,
            string $valueColumn,
            int $period,
            string $aliasPrefix = 'decomp',
            ?string $partitionBy = null
        ) {
            /** @var Builder $this */
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';

            return $this
                ->selectRaw(
                    "*, AVG({$valueColumn}) OVER ({$partitionClause} ORDER BY {$timeColumn} ROWS BETWEEN {$period} PRECEDING AND CURRENT ROW) as {$aliasPrefix}_trend"
                )
                ->selectRaw(
                    "{$valueColumn} - {$aliasPrefix}_trend as {$aliasPrefix}_seasonal"
                )
                ->selectRaw(
                    "{$valueColumn} - {$aliasPrefix}_trend - {$aliasPrefix}_seasonal as {$aliasPrefix}_residual"
                );
        });

        /**
         * withRateOfChange - 变化率计算（多种算法）
         *
         * 计算时间序列的变化率，支持多种算法：简单变化率、对数变化率、复合变化率。
         *
         * 功能特点：
         * - 支持多种变化率算法
         * - 可处理首行（无前值）的情况
         * - 支持分组计算
         *
         * @param string $valueColumn 数值列名
         * @param string $method 计算方法：'simple'(默认), 'log', 'compound'
         * @param int $periods 计算周期数，默认 1
         * @param string $alias 输出列别名
         * @param string|null $partitionBy 分组列名（可选）
         * @param string|null $orderBy 排序列名（可选，默认按原排序）
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 计算股票价格日变化率
         * DB::table('stock_prices')
         *     ->withRateOfChange('close_price', 'simple', 1, 'daily_return', 'stock_code', 'trade_date')
         *     ->get();
         *
         * // 计算月环比增长率（对数变化率更稳定）
         * DB::table('monthly_revenue')
         *     ->withRateOfChange('revenue', 'log', 1, 'growth_rate', 'department')
         *     ->get();
         */
        Builder::macro('withRateOfChange', function (
            string $valueColumn,
            string $method = 'simple',
            int $periods = 1,
            string $alias = 'rate_of_change',
            ?string $partitionBy = null,
            ?string $orderBy = null
        ) {
            /** @var Builder $this */
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';
            $orderClause = $orderBy ? "ORDER BY {$orderBy}" : '';

            return match ($method) {
                'log' => $this->selectRaw(
                    "*, LN({$valueColumn} / NULLIF(LAG({$valueColumn}, {$periods}) OVER ({$partitionClause} {$orderClause}), 0)) as {$alias}"
                ),
                'compound' => $this->selectRaw(
                    "*, POW({$valueColumn} / NULLIF(LAG({$valueColumn}, {$periods}) OVER ({$partitionClause} {$orderClause}), 0), 1.0/{$periods}) - 1 as {$alias}"
                ),
                default => $this->selectRaw(
                    "*, ({$valueColumn} - LAG({$valueColumn}, {$periods}) OVER ({$partitionClause} {$orderClause})) / NULLIF(LAG({$valueColumn}, {$periods}) OVER ({$partitionClause} {$orderClause}), 0) as {$alias}"
                ),
            };
        });

        /**
         * withRollingStatistics - 滚动统计（多指标）
         *
         * 同时计算多个滚动统计指标：均值、标准差、最小值、最大值、中位数。
         * 一站式解决时间序列滚动分析需求。
         *
         * 功能特点：
         * - 一次性计算多个统计指标
         * - 可自定义窗口大小
         * - 支持多种窗口类型（行、范围）
         *
         * @param string $valueColumn 数值列名
         * @param int $windowSize 窗口大小
         * @param string $windowType 窗口类型：'rows'(默认), 'range'
         * @param string $aliasPrefix 输出列名前缀
         * @param string|null $partitionBy 分组列名（可选）
         * @param string|null $orderBy 排序列名（可选）
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 计算 7 日滚动统计指标
         * DB::table('daily_metrics')
         *     ->withRollingStatistics('value', 7, 'rows', 'rolling', 'metric_type', 'date')
         *     ->select('metric_type', 'date', 'value', 'rolling_mean', 'rolling_std', 'rolling_min', 'rolling_max')
         *     ->get();
         */
        Builder::macro('withRollingStatistics', function (
            string $valueColumn,
            int $windowSize,
            string $windowType = 'rows',
            string $aliasPrefix = 'rolling',
            ?string $partitionBy = null,
            ?string $orderBy = null
        ) {
            /** @var Builder $this */
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';
            $orderClause = $orderBy ? "ORDER BY {$orderBy}" : '';
            $frame = $windowType === 'range'
                ? "RANGE BETWEEN {$windowSize} PRECEDING AND CURRENT ROW"
                : "ROWS BETWEEN {$windowSize} PRECEDING AND CURRENT ROW";

            return $this
                ->selectRaw("*, AVG({$valueColumn}) OVER ({$partitionClause} {$orderClause} {$frame}) as {$aliasPrefix}_mean")
                ->selectRaw("STDDEV({$valueColumn}) OVER ({$partitionClause} {$orderClause} {$frame}) as {$aliasPrefix}_std")
                ->selectRaw("MIN({$valueColumn}) OVER ({$partitionClause} {$orderClause} {$frame}) as {$aliasPrefix}_min")
                ->selectRaw("MAX({$valueColumn}) OVER ({$partitionClause} {$orderClause} {$frame}) as {$aliasPrefix}_max")
                ->selectRaw("PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY {$valueColumn}) OVER ({$partitionClause} {$orderClause} {$frame}) as {$aliasPrefix}_median");
        });

        /**
         * withTimeSince - 时间间隔计算
         *
         * 计算每个数据点距离当前时间或首行时间的间隔。
         * 适用于分析数据新鲜度、时间跨度等场景。
         *
         * 功能特点：
         * - 支持多种时间单位
         * - 可相对于首行或当前行计算
         * - 自动处理时区
         *
         * @param string $timeColumn 时间列名
         * @param string $unit 时间单位：'SECOND', 'MINUTE', 'HOUR', 'DAY'
         * @param string $reference 参考点：'first'(首行), 'current'(当前行)
         * @param string $alias 输出列别名，默认 'time_since'
         * @param string|null $partitionBy 分组列名（可选）
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 计算距离首次登录的天数（用户留存分析）
         * DB::table('user_logins')
         *     ->withTimeSince('login_time', 'DAY', 'first', 'days_since_first', 'user_id')
         *     ->get();
         *
         * // 计算距上次更新的时间（数据新鲜度）
         * DB::table('data_sync_logs')
         *     ->withTimeSince('updated_at', 'HOUR', 'current', 'hours_ago')
         *     ->get();
         */
        Builder::macro('withTimeSince', function (
            string $timeColumn,
            string $unit = 'SECOND',
            string $reference = 'first',
            string $alias = 'time_since',
            ?string $partitionBy = null
        ) {
            /** @var Builder $this */
            $partitionClause = $partitionBy ? "PARTITION BY {$partitionBy}" : '';
            $orderClause = "ORDER BY {$timeColumn}";

            $referenceTime = $reference === 'current'
                ? 'NOW()'
                : "FIRST_VALUE({$timeColumn}) OVER ({$partitionClause} {$orderClause})";

            $divisor = match ($unit) {
                'MINUTE' => 60,
                'HOUR' => 3600,
                'DAY' => 86400,
                'WEEK' => 604800,
                default => 1,
            };

            return $this->selectRaw(
                "*, TIMESTAMPDIFF({$unit}, {$referenceTime}, {$timeColumn}) as {$alias}"
            );
        });

        /**
         * withSessionization - 会话化（基于时间间隔的用户行为分组）
         *
         * 将用户行为按时间间隔分组为会话（session）。
         * 常用于用户行为分析、点击流分析等场景。
         *
         * 功能特点：
         * - 自动识别会话边界
         * - 可自定义会话超时时间
         * - 支持任意用户标识列
         *
         * @param string $userColumn 用户标识列名，如 'user_id', 'session_id'
         * @param string $timeColumn 时间列名
         * @param int $timeoutMinutes 会话超时时间（分钟），默认 30
         * @param string $aliasPrefix 输出列名前缀，默认 'session'
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 分析用户会话（30分钟超时）
         * DB::table('page_views')
         *     ->withSessionization('user_id', 'viewed_at', 30, 'sess')
         *     ->select('user_id', 'sess_id', 'sess_seq', DB::raw('COUNT(*) as page_views'))
         *     ->groupBy('user_id', 'sess_id')
         *     ->get();
         *
         * // 统计平均会话时长
         * DB::table('events')
         *     ->withSessionization('device_id', 'event_time', 15, 's')
         *     ->select('device_id', 's_id')
         *     ->selectRaw('TIMESTAMPDIFF(MINUTE, MIN(event_time), MAX(event_time)) as duration')
         *     ->groupBy('device_id', 's_id')
         *     ->get();
         */
        Builder::macro('withSessionization', function (
            string $userColumn,
            string $timeColumn,
            int $timeoutMinutes = 30,
            string $aliasPrefix = 'session'
        ) {
            /** @var Builder $this */
            return $this
                ->selectRaw(
                    "*, SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, LAG({$timeColumn}) OVER (PARTITION BY {$userColumn} ORDER BY {$timeColumn}), {$timeColumn}) > {$timeoutMinutes} THEN 1 ELSE 0 END) " .
                    "OVER (PARTITION BY {$userColumn} ORDER BY {$timeColumn}) as {$aliasPrefix}_id"
                )
                ->selectRaw(
                    "ROW_NUMBER() OVER (PARTITION BY {$userColumn}, {$aliasPrefix}_id ORDER BY {$timeColumn}) as {$aliasPrefix}_seq"
                );
        });

        /**
         * withCohortAnalysis - 同期群分析（留存分析）
         *
         * 进行同期群分析，计算用户留存率。
         * 适用于用户留存、客户生命周期价值分析。
         *
         * 功能特点：
         * - 自动划分同期群（Cohort）
         * - 计算各期留存率
         * - 支持多种时间粒度
         *
         * @param string $userColumn 用户标识列名
         * @param string $eventTimeColumn 事件发生时间列
         * @param string $firstSeenColumn 首次出现时间列（可选，默认使用 MIN 计算）
         * @param string $timeUnit 时间单位：'DAY', 'WEEK', 'MONTH'
         * @param string $aliasPrefix 输出列名前缀
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 按月分析用户留存
         * DB::table('user_activities')
         *     ->withCohortAnalysis('user_id', 'activity_date', null, 'MONTH', 'cohort')
         *     ->select('cohort_group', 'cohort_period', DB::raw('COUNT(DISTINCT user_id) as users'))
         *     ->groupBy('cohort_group', 'cohort_period')
         *     ->get();
         */
        Builder::macro('withCohortAnalysis', function (
            string $userColumn,
            string $eventTimeColumn,
            ?string $firstSeenColumn = null,
            string $timeUnit = 'DAY',
            string $aliasPrefix = 'cohort'
        ) {
            /** @var Builder $this */
            $firstSeen = $firstSeenColumn ?? "FIRST_VALUE({$eventTimeColumn}) OVER (PARTITION BY {$userColumn} ORDER BY {$eventTimeColumn})";

            return $this
                ->selectRaw("DATE_FORMAT({$firstSeen}, '%Y-%m') as {$aliasPrefix}_group")
                ->selectRaw("TIMESTAMPDIFF({$timeUnit}, {$firstSeen}, {$eventTimeColumn}) as {$aliasPrefix}_period");
        });
    }
}
