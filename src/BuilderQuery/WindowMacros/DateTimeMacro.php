<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * 日期时间处理宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 * 基于 study_mysql「日期时间函数 / 时段重叠 / 性能优化」章节系统化封装。所有语法经
 * {@see Dialect} 抽象层生成，双库通用。重点解决常见开发瓶颈：
 *  - 时间分桶统计（按 天/周/月/季度/年 或 固定 N 分钟）进行 GROUP BY 报表；
 *  - 「用 DATE(col) 包裹列导致索引失效」的反模式，改为列不包裹的范围查询；
 *  - 预约 / 排期 / 库存占用等「时段重叠」冲突检测。
 * 提供的宏：
 *  - dateBucket：按日/周/月/季/年分桶（自动 select + groupBy）
 *  - timeBucket：按固定 N 分钟分桶（如 15 分钟粒度报表）
 *  - whereDateRange：索引友好的日期范围筛选（列不被函数包裹）
 *  - whereTimeOverlap：时段重叠冲突检测（a.start < b.end AND a.end > b.start）
 * 安全性：列名 / 别名经白名单校验并由 grammar wrap；范围值与时段值一律参数绑定。
 *
 * @version 3.1.0
 *
 * @requires MySQL 8.0+ / PostgreSQL 15+
 *
 * @scenario 日期时间处理宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 */
class DateTimeMacro extends BaseMacro
{
    /**
     *  注册所有日期时间宏。
     *
     * @scenario 注册所有日期时间宏。
     */
    public static function register(): void
    {
        self::registerBuckets();
        self::registerDateRange();
        self::registerOverlap();
    }

    /**
     * 注册分桶宏（日/周/月/季/年 + N 分钟）。
     *
     * @scenario 注册分桶宏（日/周/月/季/年 + N 分钟）。
     */
    public static function registerBuckets(): void
    {
        /**
         * 按时间粒度分桶（自动添加 select 桶字段并 groupBy）。
         * 适用于「每个月的订单量」「每日活跃用户」等时间维度报表。
         *
         * @param  string  $column  时间列
         * @param  string  $unit  day / week / month / quarter / year
         * @param  string  $alias  桶字段别名（默认 bucket）
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 统计近一年每月的订单数
         * Order::query()
         *     ->dateBucket('created_at', 'month', 'month_bucket')
         *     ->selectRaw('COUNT(*) AS cnt')
         *     ->whereDateRange('created_at', '2025-01-01', '2025-12-31')
         *     ->get();
         *
         * @scenario 时间维度聚合报表；相比在应用层循环遍历，数据库分桶一次扫描即可完成。
         */
        static::define('dateBucket', function (
            string $column,
            string $unit = 'month',
            string $alias = 'bucket'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrapped = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            $expr = BaseMacro::dateBucketFragment($this, $wrapped, $unit);

            return $this->selectRaw("{$expr} AS {$alias}")->groupByRaw($expr);
        });

        /**
         * 按固定 N 分钟分桶（如 15 分钟 / 30 分钟粒度报表）。
         *
         * @param  string  $column  时间列
         * @param  int  $minutes  步长（分钟，须 > 0），如 15
         * @param  string  $alias  桶字段别名（默认 bucket）
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 每 15 分钟的请求数
         * RequestLog::query()
         *     ->timeBucket('created_at', 15, 'slot')
         *     ->selectRaw('COUNT(*) AS cnt')
         *     ->get();
         *
         * @scenario 监控 / 流量分析中的固定步长时间序列聚合（MySQL 无原生 TIME_BUCKET，此处用 epoch 整除模拟）。
         */
        static::define('timeBucket', function (
            string $column,
            int $minutes = 15,
            string $alias = 'bucket'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrapped = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            [$expr, $bindings] = BaseMacro::timeBucketFragment($this, $wrapped, $minutes);

            return $this->selectRaw("{$expr} AS {$alias}", $bindings)->groupByRaw($expr, $bindings);
        });
    }

    /**
     * 注册索引友好的日期范围筛选宏。
     *
     * @scenario 注册索引友好的日期范围筛选宏。
     */
    public static function registerDateRange(): void
    {
        /**
         * 索引友好的日期/时间范围筛选（列不被函数包裹，可命中索引）。
         *
         * @param  string  $column  时间列
         * @param  mixed  $start  起始值（含），如 '2025-01-01'
         * @param  mixed  $end  结束值；$endInclusive=true 时为 `<=`，否则为 `<`（排他对次日 00:00 更友好）
         * @param  bool  $endInclusive  结束是否包含
         * @param  string  $boolean  连接条件 and|or
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 错误写法（索引失效）：whereRaw('DATE(created_at) BETWEEN ? AND ?')
         * // 本宏正确写法（走索引）：
         * Order::query()->whereDateRange('created_at', '2025-01-01', '2025-01-31')->get();
         *
         * @scenario 大数据量时间范围查询；避免对列套用 DATE()/函数导致全表扫描。
         */
        static::define('whereDateRange', function (
            string $column,
            mixed $start,
            mixed $end,
            bool $endInclusive = true,
            string $boolean = 'and'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrapped = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $method = $boolean === 'or' ? 'orWhere' : 'where';
            $operator = $endInclusive ? '<=' : '<';

            return $this->{$method}($wrapped, '>=', $start)
                ->{$method}($wrapped, $operator, $end);
        });
    }

    /**
     * 注册时段重叠冲突检测宏。
     *
     * @scenario 注册时段重叠冲突检测宏。
     */
    public static function registerOverlap(): void
    {
        /**
         * 时段重叠冲突检测（预约 / 排期 / 库存占用等）。
         * 两时段 [a.start, a.end] 与 [b.start, b.end] 重叠的充要条件是：
         * `a.start < b.end AND a.end > b.start`。本宏以候选区间 [startVal, endVal]
         * 检测与表中 [startCol, endCol] 是否存在重叠。
         *
         * @param  string  $startCol  表中时段起始列
         * @param  string  $endCol  表中时段结束列
         * @param  mixed  $startVal  候选起始值
         * @param  mixed  $endVal  候选结束值
         * @param  string  $boolean  连接条件 and|or
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 查询 2025-06-01 09:00~10:00 已被占用的会议室
         * RoomBooking::query()
         *     ->whereTimeOverlap('start_at', 'end_at', '2025-06-01 09:00:00', '2025-06-01 10:00:00')
         *     ->exists();
         *
         * @scenario 预约系统、排班系统、库存占用、工单时段互斥等冲突校验。
         */
        static::define('whereTimeOverlap', function (
            string $startCol,
            string $endCol,
            mixed $startVal,
            mixed $endVal,
            string $boolean = 'and'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $sc = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($startCol, 'column'));
            $ec = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($endCol, 'column'));
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

            return $this->{$method}("{$sc} < ? AND {$ec} > ?", [$endVal, $startVal]);
        });
    }
}
