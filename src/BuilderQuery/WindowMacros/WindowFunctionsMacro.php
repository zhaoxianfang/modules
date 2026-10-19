<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * 窗口函数宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 * 提供丰富的窗口函数封装，包括：
 * - 排序函数：rowNumber, rank, denseRank, ntile, percentRank, cumeDist, rankOver
 * - 值访问函数：lag, lead, firstValue, lastValue, nthValue
 * - 聚合窗口函数：sumOver, avgOver, countOver, minOver, maxOver
 * - 框架窗口函数：rowsBetween, rangeBetween
 * - 统计函数：cumulativeSum, movingAverage, runningTotal
 * 上述窗口函数均为 SQL 标准，MySQL 8.0+ 与 PostgreSQL 15+ 原生支持，无需方言分支。
 * 仅标识符引用（引号风格）交由 Dialect 方言抽象层按驱动生成，杜绝硬编码反引号。
 * 安全性：所有列名 / 别名 / 排序方向 / 聚合函数 / 框架边界均经白名单校验，
 * 标识符由 grammar wrap 生成（适配 MySQL / PostgreSQL / SQLite），字面值走参数绑定，
 * 杜绝 `{$column}` 裸插值造成的 SQL 注入风险。
 *
 * @version 1.13.9
 *
 * @requires MySQL 8.0+ / PostgreSQL 15+
 */
class WindowFunctionsMacro extends BaseMacro
{
    /**
     * 注册所有窗口函数宏
     */
    public static function register(): void
    {
        WindowFunctionsMacro::registerRankingFunctions();
        WindowFunctionsMacro::registerValueFunctions();
        WindowFunctionsMacro::registerAggregateWindowFunctions();
        WindowFunctionsMacro::registerFrameWindowFunctions();
        WindowFunctionsMacro::registerStatisticalFunctions();
    }

    /**
     * 注册排名窗口函数
     */
    public static function registerRankingFunctions(): void
    {
        /**
         * 为查询结果添加行号（ROW_NUMBER）
         * 应用场景：分页、排名、去重
         *
         * @param  string|array|null  $partitionBy  分区字段，如 'department_id' 或 ['dept','year']
         * @param  string  $orderBy  排序字段
         * @param  string  $direction  排序方向（asc/desc）
         * @param  string|null  $alias  结果列别名，默认 'row_num'
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 按部门分区，按工资降序排名
         * Employee::query()->rowNumber('department_id', 'salary', 'desc', 'rn')->get();
         */
        static::define('rowNumber', function (
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            ?string $alias = null
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            $direction = WindowFunctionsMacro::assertDirection($direction);
            $alias = WindowFunctionsMacro::assertValidIdentifier($alias ?: 'row_num', 'alias');
            $wrappedAlias = WindowFunctionsMacro::wrapIdentifier($this, $alias);

            $partitionClause = WindowFunctionsMacro::buildPartitionClause($this, $partitionBy);
            $orderColumn = $orderBy ?: BaseMacro::keyName($this);
            $wrappedOrder = WindowFunctionsMacro::wrapIdentifier($this, WindowFunctionsMacro::assertValidIdentifier($orderColumn, 'order'));
            $orderClause = "ORDER BY {$wrappedOrder} {$direction}";

            $windowExpr = "ROW_NUMBER() OVER ({$partitionClause} {$orderClause}) AS {$wrappedAlias}";

            return BaseMacro::addWindowSelect($this, $windowExpr);
        });

        /**
         * 添加排名（RANK）
         * 相同值获得相同排名，后续排名会跳过
         *
         * @param  string|array|null  $partitionBy  分区字段
         * @param  string  $orderBy  排序字段
         * @param  string  $direction  排序方向
         * @param  string|null  $alias  结果列别名，默认 'rank_val'
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 按分数排名（并列会跳过名次）
         * Student::query()->rank('class_id', 'score', 'desc', 'rank_pos')->get();
         */
        static::define('rank', function (
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            ?string $alias = null
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            $direction = WindowFunctionsMacro::assertDirection($direction);
            $alias = WindowFunctionsMacro::assertValidIdentifier($alias ?: 'rank_val', 'alias');
            $wrappedAlias = WindowFunctionsMacro::wrapIdentifier($this, $alias);

            $partitionClause = WindowFunctionsMacro::buildPartitionClause($this, $partitionBy);
            $orderColumn = $orderBy ?: BaseMacro::keyName($this);
            $wrappedOrder = WindowFunctionsMacro::wrapIdentifier($this, WindowFunctionsMacro::assertValidIdentifier($orderColumn, 'order'));
            $orderClause = "ORDER BY {$wrappedOrder} {$direction}";

            $windowExpr = "RANK() OVER ({$partitionClause} {$orderClause}) AS {$wrappedAlias}";

            return BaseMacro::addWindowSelect($this, $windowExpr);
        });

        /**
         * 添加密集排名（DENSE_RANK）
         * 相同值获得相同排名，后续排名不跳过
         *
         * @param  string|array|null  $partitionBy  分区字段
         * @param  string  $orderBy  排序字段
         * @param  string  $direction  排序方向
         * @param  string|null  $alias  结果列别名，默认 'dense_rank_val'
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 按销售额密集排名（并列不跳过名次）
         * Sales::query()->denseRank('region', 'amount', 'desc', 'dense_rank_pos')->get();
         */
        static::define('denseRank', function (
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            ?string $alias = null
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            $direction = WindowFunctionsMacro::assertDirection($direction);
            $alias = WindowFunctionsMacro::assertValidIdentifier($alias ?: 'dense_rank_val', 'alias');
            $wrappedAlias = WindowFunctionsMacro::wrapIdentifier($this, $alias);

            $partitionClause = WindowFunctionsMacro::buildPartitionClause($this, $partitionBy);
            $orderColumn = $orderBy ?: BaseMacro::keyName($this);
            $wrappedOrder = WindowFunctionsMacro::wrapIdentifier($this, WindowFunctionsMacro::assertValidIdentifier($orderColumn, 'order'));
            $orderClause = "ORDER BY {$wrappedOrder} {$direction}";

            $windowExpr = "DENSE_RANK() OVER ({$partitionClause} {$orderClause}) AS {$wrappedAlias}";

            return BaseMacro::addWindowSelect($this, $windowExpr);
        });

        /**
         * 将分区内的行分为指定数量的桶（NTILE）
         *
         * @param  int  $buckets  桶的数量
         * @param  string|array|null  $partitionBy  分区字段
         * @param  string  $orderBy  排序字段
         * @param  string  $direction  排序方向
         * @param  string|null  $alias  结果列别名，默认 'ntile_val'
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 将员工按工资分为4个等级
         * Employee::query()->ntile(4, 'department_id', 'salary', 'desc', 'salary_grade')->get();
         */
        static::define('ntile', function (
            int $buckets = 4,
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            ?string $alias = null
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            if ($buckets < 1) {
                throw new \InvalidArgumentException('NTILE buckets must be greater than 0');
            }
            $direction = WindowFunctionsMacro::assertDirection($direction);
            $alias = WindowFunctionsMacro::assertValidIdentifier($alias ?: 'ntile_val', 'alias');
            $wrappedAlias = WindowFunctionsMacro::wrapIdentifier($this, $alias);

            $partitionClause = WindowFunctionsMacro::buildPartitionClause($this, $partitionBy);
            $orderColumn = $orderBy ?: BaseMacro::keyName($this);
            $wrappedOrder = WindowFunctionsMacro::wrapIdentifier($this, WindowFunctionsMacro::assertValidIdentifier($orderColumn, 'order'));
            $orderClause = "ORDER BY {$wrappedOrder} {$direction}";

            $windowExpr = "NTILE({$buckets}) OVER ({$partitionClause} {$orderClause}) AS {$wrappedAlias}";

            return BaseMacro::addWindowSelect($this, $windowExpr);
        });

        /**
         * 计算当前行在分区中的百分比排名（PERCENT_RANK）
         * 取值范围 0~1，(rank-1)/(rows-1)
         *
         * @param  string|array|null  $partitionBy  分区字段
         * @param  string  $orderBy  排序字段
         * @param  string  $direction  排序方向
         * @param  string|null  $alias  结果列别名，默认 'percent_rank_val'
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 计算员工工资的百分比排名
         * Employee::query()->percentRank('department_id', 'salary', 'desc', 'pct_rank')->get();
         */
        static::define('percentRank', function (
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            ?string $alias = null
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            $direction = WindowFunctionsMacro::assertDirection($direction);
            $alias = WindowFunctionsMacro::assertValidIdentifier($alias ?: 'percent_rank_val', 'alias');
            $wrappedAlias = WindowFunctionsMacro::wrapIdentifier($this, $alias);

            $partitionClause = WindowFunctionsMacro::buildPartitionClause($this, $partitionBy);
            $orderColumn = $orderBy ?: BaseMacro::keyName($this);
            $wrappedOrder = WindowFunctionsMacro::wrapIdentifier($this, WindowFunctionsMacro::assertValidIdentifier($orderColumn, 'order'));
            $orderClause = "ORDER BY {$wrappedOrder} {$direction}";

            $windowExpr = "PERCENT_RANK() OVER ({$partitionClause} {$orderClause}) AS {$wrappedAlias}";

            return BaseMacro::addWindowSelect($this, $windowExpr);
        });

        /**
         * 计算当前行在分区中的累积分布（CUME_DIST）
         * 取值范围 0~1，(小于等于当前值的行数)/(总行数)
         *
         * @param  string|array|null  $partitionBy  分区字段
         * @param  string  $orderBy  排序字段
         * @param  string  $direction  排序方向
         * @param  string|null  $alias  结果列别名，默认 'cume_dist_val'
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 计算产品价格的累积分布
         * Product::query()->cumeDist('category_id', 'price', 'asc', 'cum_dist')->get();
         */
        static::define('cumeDist', function (
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            ?string $alias = null
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            $direction = WindowFunctionsMacro::assertDirection($direction);
            $alias = WindowFunctionsMacro::assertValidIdentifier($alias ?: 'cume_dist_val', 'alias');
            $wrappedAlias = WindowFunctionsMacro::wrapIdentifier($this, $alias);

            $partitionClause = WindowFunctionsMacro::buildPartitionClause($this, $partitionBy);
            $orderColumn = $orderBy ?: BaseMacro::keyName($this);
            $wrappedOrder = WindowFunctionsMacro::wrapIdentifier($this, WindowFunctionsMacro::assertValidIdentifier($orderColumn, 'order'));
            $orderClause = "ORDER BY {$wrappedOrder} {$direction}";

            $windowExpr = "CUME_DIST() OVER ({$partitionClause} {$orderClause}) AS {$wrappedAlias}";

            return BaseMacro::addWindowSelect($this, $windowExpr);
        });

        /**
         * 自定义排名函数（RANK OVER）
         * 允许自定义聚合函数和框架的排名
         *
         * @param  string  $function  排名函数（RANK/DENSE_RANK/ROW_NUMBER）
         * @param  string|array|null  $partitionBy  分区字段
         * @param  string  $orderBy  排序字段
         * @param  string  $direction  排序方向
         * @param  string|null  $alias  结果列别名，默认 'custom_rank'
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 自定义排名
         * Employee::query()->rankOver('DENSE_RANK', 'department_id', 'salary', 'desc')->get();
         */
        static::define('rankOver', function (
            string $function = 'RANK',
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            ?string $alias = null
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            $function = WindowFunctionsMacro::assertWindowFunction($function);

            // rankOver 生成的是 `FUNC() OVER (...)`（无实参形式）。
            // 通用窗口函数白名单里还包含 LAG / LEAD / NTH_VALUE / FIRST_VALUE 等
            // 【必须带实参】的函数，一旦放行就会生成 `LAG() OVER (...)` 这类非法 SQL，
            // 由数据库报错。这里额外收敛到"排名类"专属白名单。
            if (! in_array(strtoupper($function), ['ROW_NUMBER', 'RANK', 'DENSE_RANK', 'PERCENT_RANK', 'CUME_DIST'], true)) {
                throw new \InvalidArgumentException(sprintf(
                    'rankOver 只支持排名类窗口函数（ROW_NUMBER / RANK / DENSE_RANK / PERCENT_RANK / CUME_DIST），'
                    .'不支持 [%s] —— 该函数需要实参，无法用于 `FUNC() OVER (...)` 形式。',
                    $function
                ));
            }

            $direction = WindowFunctionsMacro::assertDirection($direction);
            $alias = WindowFunctionsMacro::assertValidIdentifier($alias ?: 'custom_rank', 'alias');
            $wrappedAlias = WindowFunctionsMacro::wrapIdentifier($this, $alias);

            $partitionClause = WindowFunctionsMacro::buildPartitionClause($this, $partitionBy);
            $orderColumn = $orderBy ?: BaseMacro::keyName($this);
            $wrappedOrder = WindowFunctionsMacro::wrapIdentifier($this, WindowFunctionsMacro::assertValidIdentifier($orderColumn, 'order'));
            $orderClause = "ORDER BY {$wrappedOrder} {$direction}";

            $windowExpr = "{$function}() OVER ({$partitionClause} {$orderClause}) AS {$wrappedAlias}";

            return BaseMacro::addWindowSelect($this, $windowExpr);
        });
    }

    /**
     * 注册值访问窗口函数
     */
    public static function registerValueFunctions(): void
    {
        /**
         * 访问当前行之前的第N行数据（LAG）
         *
         * @param  string  $column  要访问的列名
         * @param  int  $offset  偏移量，默认1
         * @param  mixed  $default  当无前序行时返回的默认值
         * @param  string|array|null  $partitionBy  分区字段
         * @param  string  $orderBy  排序字段
         * @param  string  $direction  排序方向
         * @param  string|null  $alias  结果列别名，默认 {column}_lag
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 计算月度销售额环比变化
         * Sales::query()
         *     ->lag('amount', 1, 0, 'product_id', 'month', 'asc', 'prev_amount')
         *     ->selectRaw('amount - prev_amount as growth')
         *     ->get();
         */
        static::define('lag', function (
            string $column,
            int $offset = 1,
            mixed $default = null,
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            ?string $alias = null
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            $direction = WindowFunctionsMacro::assertDirection($direction);
            $column = WindowFunctionsMacro::assertValidIdentifier($column, 'column');
            $wrappedColumn = WindowFunctionsMacro::wrapIdentifier($this, $column);
            $alias = WindowFunctionsMacro::assertValidIdentifier($alias ?: "{$column}_lag", 'alias');
            $wrappedAlias = WindowFunctionsMacro::wrapIdentifier($this, $alias);

            $partitionClause = WindowFunctionsMacro::buildPartitionClause($this, $partitionBy);
            $orderColumn = $orderBy ?: BaseMacro::keyName($this);
            $wrappedOrder = WindowFunctionsMacro::wrapIdentifier($this, WindowFunctionsMacro::assertValidIdentifier($orderColumn, 'order'));
            $orderClause = "ORDER BY {$wrappedOrder} {$direction}";

            // 默认值走参数绑定，避免字符串字面量注入
            $defaultSql = $default === null ? 'NULL' : '?';
            $bindings = $default === null ? [] : [$default];

            $windowExpr = "LAG({$wrappedColumn}, {$offset}, {$defaultSql}) OVER ({$partitionClause} {$orderClause}) AS {$wrappedAlias}";

            return $this->selectRaw($windowExpr, $bindings);
        });

        /**
         * 访问当前行之后的第N行数据（LEAD）
         *
         * @param  string  $column  要访问的列名
         * @param  int  $offset  偏移量，默认1
         * @param  mixed  $default  当无后序行时返回的默认值
         * @param  string|array|null  $partitionBy  分区字段
         * @param  string  $orderBy  排序字段
         * @param  string  $direction  排序方向
         * @param  string|null  $alias  结果列别名，默认 {column}_lead
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 计算下个月的预测销售额
         * Sales::query()
         *     ->lead('amount', 1, 0, 'product_id', 'month', 'asc', 'next_amount')
         *     ->selectRaw('next_amount - amount as forecast')
         *     ->get();
         */
        static::define('lead', function (
            string $column,
            int $offset = 1,
            mixed $default = null,
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            ?string $alias = null
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            $direction = WindowFunctionsMacro::assertDirection($direction);
            $column = WindowFunctionsMacro::assertValidIdentifier($column, 'column');
            $wrappedColumn = WindowFunctionsMacro::wrapIdentifier($this, $column);
            $alias = WindowFunctionsMacro::assertValidIdentifier($alias ?: "{$column}_lead", 'alias');
            $wrappedAlias = WindowFunctionsMacro::wrapIdentifier($this, $alias);

            $partitionClause = WindowFunctionsMacro::buildPartitionClause($this, $partitionBy);
            $orderColumn = $orderBy ?: BaseMacro::keyName($this);
            $wrappedOrder = WindowFunctionsMacro::wrapIdentifier($this, WindowFunctionsMacro::assertValidIdentifier($orderColumn, 'order'));
            $orderClause = "ORDER BY {$wrappedOrder} {$direction}";

            $defaultSql = $default === null ? 'NULL' : '?';
            $bindings = $default === null ? [] : [$default];

            $windowExpr = "LEAD({$wrappedColumn}, {$offset}, {$defaultSql}) OVER ({$partitionClause} {$orderClause}) AS {$wrappedAlias}";

            return $this->selectRaw($windowExpr, $bindings);
        });

        /**
         * 获取窗口框架中第一行的值（FIRST_VALUE）
         *
         * @param  string  $column  要获取值的列名
         * @param  string|array|null  $partitionBy  分区字段
         * @param  string  $orderBy  排序字段
         * @param  string  $direction  排序方向
         * @param  string|null  $alias  结果列别名，默认 {column}_first
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 计算每天股价与当月首日开盘价的差额
         * StockPrice::query()
         *     ->firstValue('open_price', 'stock_code', 'trade_date', 'asc', 'month_first_price')
         *     ->selectRaw('open_price - month_first_price as price_change')
         *     ->get();
         */
        static::define('firstValue', function (
            string $column,
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            ?string $alias = null
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            $direction = WindowFunctionsMacro::assertDirection($direction);
            $column = WindowFunctionsMacro::assertValidIdentifier($column, 'column');
            $wrappedColumn = WindowFunctionsMacro::wrapIdentifier($this, $column);
            $alias = WindowFunctionsMacro::assertValidIdentifier($alias ?: "{$column}_first", 'alias');
            $wrappedAlias = WindowFunctionsMacro::wrapIdentifier($this, $alias);

            $partitionClause = WindowFunctionsMacro::buildPartitionClause($this, $partitionBy);
            $orderColumn = $orderBy ?: BaseMacro::keyName($this);
            $wrappedOrder = WindowFunctionsMacro::wrapIdentifier($this, WindowFunctionsMacro::assertValidIdentifier($orderColumn, 'order'));
            $orderClause = "ORDER BY {$wrappedOrder} {$direction}";

            $windowExpr = "FIRST_VALUE({$wrappedColumn}) OVER ({$partitionClause} {$orderClause}) AS {$wrappedAlias}";

            return BaseMacro::addWindowSelect($this, $windowExpr);
        });

        /**
         * 获取窗口框架中最后一行的值（LAST_VALUE）
         *
         * @param  string  $column  要获取值的列名
         * @param  string|array|null  $partitionBy  分区字段
         * @param  string  $orderBy  排序字段
         * @param  string  $direction  排序方向
         * @param  string|null  $alias  结果列别名，默认 {column}_last
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 计算每个销售员与团队最佳业绩的差距
         * Sales::query()
         *     ->lastValue('amount', 'team_id', 'amount', 'asc', 'team_best')
         *     ->selectRaw('team_best - amount as gap_to_best')
         *     ->get();
         */
        static::define('lastValue', function (
            string $column,
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            ?string $alias = null
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            $direction = WindowFunctionsMacro::assertDirection($direction);
            $column = WindowFunctionsMacro::assertValidIdentifier($column, 'column');
            $wrappedColumn = WindowFunctionsMacro::wrapIdentifier($this, $column);
            $alias = WindowFunctionsMacro::assertValidIdentifier($alias ?: "{$column}_last", 'alias');
            $wrappedAlias = WindowFunctionsMacro::wrapIdentifier($this, $alias);

            $partitionClause = WindowFunctionsMacro::buildPartitionClause($this, $partitionBy);
            $orderColumn = $orderBy ?: BaseMacro::keyName($this);
            $wrappedOrder = WindowFunctionsMacro::wrapIdentifier($this, WindowFunctionsMacro::assertValidIdentifier($orderColumn, 'order'));
            $orderClause = "ORDER BY {$wrappedOrder} {$direction}";

            // LAST_VALUE 的默认窗口帧是 RANGE BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW，
            // 即"从分区开头到当前行"—— 不显式指定帧时 LAST_VALUE(x) 恒等于 x 本身，
            // 与"取分区最后一行"的语义完全相反（且不会报错，静默给错结果）。
            // ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING 才是整个分区。
            $windowExpr = "LAST_VALUE({$wrappedColumn}) OVER ({$partitionClause} {$orderClause}"
                .' ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING) AS '.$wrappedAlias;

            return BaseMacro::addWindowSelect($this, $windowExpr);
        });

        /**
         * 获取窗口框架中第N行的值（NTH_VALUE）
         *
         * @param  string  $column  要获取值的列名
         * @param  int  $n  行号，从1开始
         * @param  string|array|null  $partitionBy  分区字段
         * @param  string  $orderBy  排序字段
         * @param  string  $direction  排序方向
         * @param  string|null  $alias  结果列别名，默认 {column}_nth
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 获取每个班级第3名的成绩作为基准线
         * ExamResult::query()
         *     ->nthValue('score', 3, 'class_id', 'score', 'desc', 'third_place_score')
         *     ->get();
         */
        static::define('nthValue', function (
            string $column,
            int $n,
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            ?string $alias = null
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            if ($n < 1) {
                throw new \InvalidArgumentException('NTH_VALUE n must be greater than 0');
            }
            $direction = WindowFunctionsMacro::assertDirection($direction);
            $column = WindowFunctionsMacro::assertValidIdentifier($column, 'column');
            $wrappedColumn = WindowFunctionsMacro::wrapIdentifier($this, $column);
            $alias = WindowFunctionsMacro::assertValidIdentifier($alias ?: "{$column}_nth", 'alias');
            $wrappedAlias = WindowFunctionsMacro::wrapIdentifier($this, $alias);

            $partitionClause = WindowFunctionsMacro::buildPartitionClause($this, $partitionBy);
            $orderColumn = $orderBy ?: BaseMacro::keyName($this);
            $wrappedOrder = WindowFunctionsMacro::wrapIdentifier($this, WindowFunctionsMacro::assertValidIdentifier($orderColumn, 'order'));
            $orderClause = "ORDER BY {$wrappedOrder} {$direction}";

            // 同 lastValue：默认帧只到 CURRENT ROW，NTH_VALUE(x, n>1) 会恒返回当前行。
            $windowExpr = "NTH_VALUE({$wrappedColumn}, {$n}) OVER ({$partitionClause} {$orderClause}"
                .' ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING) AS '.$wrappedAlias;

            return BaseMacro::addWindowSelect($this, $windowExpr);
        });
    }

    /**
     * 注册聚合窗口函数
     * 包含: sumOver, avgOver, countOver, minOver, maxOver
     */
    public static function registerAggregateWindowFunctions(): void
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
             * 在保持原始行 detail 的同时，计算聚合统计值
             * 相比 GROUP BY，不会折叠行，每行都会保留并附带聚合值
             *
             * @param  string  $column  要聚合的列名
             * @param  string|array|null  $partitionBy  分区字段
             * @param  string|null  $orderBy  排序字段（用于有序聚合）
             * @param  string  $direction  排序方向
             * @param  string|null  $alias  结果列别名
             * @return Builder|\Illuminate\Database\Query\Builder
             *
             * @example
             * // 计算每个员工销售额占部门总销售额的比例
             * Sales::query()
             *     ->sumOver('amount', 'department_id', null, 'asc', 'dept_total')
             *     ->selectRaw('amount / dept_total * 100 as percentage')
             *     ->get();
             * // 计算每行数据的累计平均值（移动平均）
             * StockPrice::query()
             *     ->avgOver('close_price', 'stock_code', 'trade_date', 'asc', 'ma')
             *     ->get();
             */
            static::define($macroName, function (
                string $column,
                string|array|null $partitionBy = null,
                ?string $orderBy = null,
                string $direction = 'asc',
                ?string $alias = null
            ) use ($config): Builder|\Illuminate\Database\Query\Builder {
                /**
                 * @var Builder|\Illuminate\Database\Query\Builder $this
                 */
                $direction = WindowFunctionsMacro::assertDirection($direction);
                $function = WindowFunctionsMacro::assertAggregateFunction($config['fn']);
                $column = WindowFunctionsMacro::assertValidIdentifier($column, 'column');
                $wrappedColumn = WindowFunctionsMacro::wrapIdentifier($this, $column);
                $alias = WindowFunctionsMacro::assertValidIdentifier($alias ?: $config['defaultAlias'], 'alias');
                $wrappedAlias = WindowFunctionsMacro::wrapIdentifier($this, $alias);

                $partitionClause = WindowFunctionsMacro::buildPartitionClause($this, $partitionBy);
                $orderClause = '';
                if ($orderBy) {
                    $wrappedOrder = WindowFunctionsMacro::wrapIdentifier($this, WindowFunctionsMacro::assertValidIdentifier($orderBy, 'order'));
                    $orderClause = " ORDER BY {$wrappedOrder} {$direction}";
                }

                $windowExpr = "{$function}({$wrappedColumn}) OVER ({$partitionClause}{$orderClause}) AS {$wrappedAlias}";

                return BaseMacro::addWindowSelect($this, $windowExpr);
            });
        }
    }

    /**
     * 注册框架窗口函数
     * 包含: rowsBetween, rangeBetween
     */
    public static function registerFrameWindowFunctions(): void
    {
        /**
         * 使用 ROWS 框架指定窗口范围
         * 基于物理行数定义窗口框架
         *
         * @param  string  $column  要计算的列
         * @param  string  $function  聚合函数: SUM|AVG|COUNT|MIN|MAX
         * @param  string  $start  框架起点: UNBOUNDED PRECEDING|N PRECEDING|CURRENT ROW
         * @param  string  $end  框架终点: UNBOUNDED FOLLOWING|N FOLLOWING|CURRENT ROW
         * @param  string|array|null  $partitionBy  分区字段
         * @param  string  $orderBy  排序字段
         * @param  string  $direction  排序方向
         * @param  string  $alias  结果列别名
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 计算3日移动平均（当前行及前2行）
         * StockPrice::query()
         *     ->rowsBetween('close_price', 'AVG', '2 PRECEDING', 'CURRENT ROW', 'stock_code', 'date', 'asc', 'ma3')
         *     ->get();
         * // 计算从分区开始到当前行的累计和
         * Sales::query()
         *     ->rowsBetween('amount', 'SUM', 'UNBOUNDED PRECEDING', 'CURRENT ROW', 'region', 'date', 'asc', 'cumulative')
         *     ->get();
         */
        static::define('rowsBetween', function (
            string $column,
            string $function,
            string $start,
            string $end,
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            string $alias = 'frame_result'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            $direction = WindowFunctionsMacro::assertDirection($direction);
            $function = WindowFunctionsMacro::assertAggregateFunction($function);
            $column = WindowFunctionsMacro::assertValidIdentifier($column, 'column');
            $wrappedColumn = WindowFunctionsMacro::wrapIdentifier($this, $column);
            $alias = WindowFunctionsMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = WindowFunctionsMacro::wrapIdentifier($this, $alias);
            $start = WindowFunctionsMacro::assertFrameBound($start);
            $end = WindowFunctionsMacro::assertFrameBound($end);

            $partitionClause = WindowFunctionsMacro::buildPartitionClause($this, $partitionBy);
            $orderColumn = $orderBy ?: BaseMacro::keyName($this);
            $wrappedOrder = WindowFunctionsMacro::wrapIdentifier($this, WindowFunctionsMacro::assertValidIdentifier($orderColumn, 'order'));
            $orderClause = "ORDER BY {$wrappedOrder} {$direction}";

            $windowExpr = "{$function}({$wrappedColumn}) OVER ({$partitionClause} {$orderClause} ROWS BETWEEN {$start} AND {$end}) AS {$wrappedAlias}";

            return BaseMacro::addWindowSelect($this, $windowExpr);
        });

        /**
         * 使用 RANGE 框架指定窗口范围
         * 基于值范围定义窗口框架（需要 orderBy）
         *
         * @param  string  $column  要计算的列
         * @param  string  $function  聚合函数
         * @param  string  $start  框架起点
         * @param  string  $end  框架终点
         * @param  string|array|null  $partitionBy  分区字段
         * @param  string  $orderBy  排序字段（必须）
         * @param  string  $direction  排序方向
         * @param  string  $alias  结果列别名
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 计算数值范围内（±10）的平均值
         * Measurements::query()
         *     ->rangeBetween('value', 'AVG', '10 PRECEDING', '10 FOLLOWING', 'sensor_id', 'reading', 'asc', 'smoothed')
         *     ->get();
         */
        static::define('rangeBetween', function (
            string $column,
            string $function,
            string $start,
            string $end,
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            string $alias = 'frame_result'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            if (empty($orderBy)) {
                throw new \InvalidArgumentException('RANGE frame requires orderBy parameter');
            }

            $direction = WindowFunctionsMacro::assertDirection($direction);
            $function = WindowFunctionsMacro::assertAggregateFunction($function);
            $column = WindowFunctionsMacro::assertValidIdentifier($column, 'column');
            $wrappedColumn = WindowFunctionsMacro::wrapIdentifier($this, $column);
            $alias = WindowFunctionsMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = WindowFunctionsMacro::wrapIdentifier($this, $alias);
            $start = WindowFunctionsMacro::assertFrameBound($start);
            $end = WindowFunctionsMacro::assertFrameBound($end);

            $partitionClause = WindowFunctionsMacro::buildPartitionClause($this, $partitionBy);
            $wrappedOrder = WindowFunctionsMacro::wrapIdentifier($this, WindowFunctionsMacro::assertValidIdentifier($orderBy, 'order'));
            $orderClause = "ORDER BY {$wrappedOrder} {$direction}";

            $windowExpr = "{$function}({$wrappedColumn}) OVER ({$partitionClause} {$orderClause} RANGE BETWEEN {$start} AND {$end}) AS {$wrappedAlias}";

            return BaseMacro::addWindowSelect($this, $windowExpr);
        });
    }

    /**
     * 注册统计函数
     * 包含: cumulativeSum, movingAverage, runningTotal
     */
    public static function registerStatisticalFunctions(): void
    {
        /**
         * 计算累计和（从分区开始到当前行）
         *
         * @param  string  $column  要计算的列
         * @param  string|array|null  $partitionBy  分区字段
         * @param  string  $orderBy  排序字段
         * @param  string  $direction  排序方向
         * @param  string  $alias  结果列别名，默认 'cumulative_sum'
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 计算累计销售额
         * Sales::query()->cumulativeSum('amount', 'region', 'date', 'asc', 'running_total')->get();
         */
        static::define('cumulativeSum', function (
            string $column,
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            string $alias = 'cumulative_sum'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */

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
         * @param  string  $column  要计算的列
         * @param  int  $windowSize  窗口大小（每边行数）
         * @param  string|array|null  $partitionBy  分区字段
         * @param  string  $orderBy  排序字段
         * @param  string  $direction  排序方向
         * @param  string  $alias  结果列别名，默认 'moving_avg'
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 计算5日移动平均（当前行±2行）
         * StockPrice::query()->movingAverage('close_price', 2, 'stock_code', 'date', 'asc', 'ma5')->get();
         */
        static::define('movingAverage', function (
            string $column,
            int $windowSize,
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            string $alias = 'moving_avg'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */

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
         * @param  string  $column  要计算的列
         * @param  string|array|null  $partitionBy  分区字段
         * @param  string  $orderBy  排序字段
         * @param  string  $direction  排序方向
         * @param  string  $alias  结果列别名，默认 'running_total'
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 计算运行总计
         * Orders::query()->runningTotal('amount', 'customer_id', 'created_at', 'asc')->get();
         */
        static::define('runningTotal', function (
            string $column,
            string|array|null $partitionBy = null,
            string $orderBy = '',
            string $direction = 'asc',
            string $alias = 'running_total'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */

            return $this->cumulativeSum($column, $partitionBy, $orderBy, $direction, $alias);
        });
    }
}
