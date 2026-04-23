<?php

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;

/**
 * MySQL 8.4+ QUALIFY 子句宏
 *
 * QUALIFY 用于过滤窗口函数的结果，类似于 HAVING 过滤聚合函数
 * MySQL 8.0.33+ 开始支持 QUALIFY 子句
 *
 * @package zxf\Modules\BuilderQuery\WindowMacros
 * @version 1.0.0
 * @requires MySQL 8.0.33+
 */
class QualifyMacro
{
    /**
     * 注册所有 QUALIFY 宏
     *
     * @return void
     */
    public static function register(): void
    {
        self::registerQualify();
        self::registerQualifyRaw();
    }

    /**
     * 注册 QUALIFY 基础方法
     */
    protected static function registerQualify(): void
    {
        /**
         * QUALIFY 子句 - 过滤窗口函数结果
         *
         * 作用：对窗口函数计算后的结果进行过滤，无需子查询或 CTE
         *
         * @param string $column 窗口函数结果列名或表达式
         * @param mixed $operator 运算符或值（当只有两个参数时）
         * @param mixed $value 比较值
         * @param string $boolean 连接条件: and|or
         * @return Builder
         *
         * @example
         * // 查找每个部门工资排名前3的员工（无需子查询）
         * Employee::query()
         *     ->select('*')
         *     ->selectRaw('RANK() OVER (PARTITION BY department_id ORDER BY salary DESC) as dept_rank')
         *     ->qualify('dept_rank', '<=', 3)
         *     ->get();
         *
         * // 查找销售额超过部门平均的员工
         * Sales::query()
         *     ->select('*')
         *     ->selectRaw('amount - AVG(amount) OVER (PARTITION BY department_id) as above_avg')
         *     ->qualify('above_avg', '>', 0)
         *     ->get();
         *
         * // 使用 orQualify
         * Employee::query()
         *     ->select('*')
         *     ->selectRaw('ROW_NUMBER() OVER (PARTITION BY dept_id ORDER BY score DESC) as rn')
         *     ->where('status', 'active')
         *     ->qualify('rn', '=', 1)
         *     ->orQualify('rn', '=', 2)
         *     ->get();
         */
        Builder::macro('qualify', function (
            string $column,
            mixed $operator = null,
            mixed $value = null,
            string $boolean = 'and'
        ): Builder {
            /** @var Builder $this */
            if (func_num_args() === 2) {
                $value = $operator;
                $operator = '=';
            }

            $method = $boolean === 'or' ? 'orHavingRaw' : 'havingRaw';

            // 使用 HAVING 模拟 QUALIFY（MySQL 8.0.33+ 原生支持 QUALIFY）
            // 这里使用 HAVING 以兼容更多版本，同时语义上更接近 QUALIFY
            return $this->{$method}("`{$column}` {$operator} ?", [$value]);
        });

        /**
         * OR QUALIFY 子句
         *
         * @param string $column 窗口函数结果列名
         * @param mixed $operator 运算符或值
         * @param mixed $value 比较值
         * @return Builder
         *
         * @example
         * // 查找排名第一或第三的员工
         * Employee::query()
         *     ->selectRaw('ROW_NUMBER() OVER (ORDER BY score DESC) as rn')
         *     ->qualify('rn', '=', 1)
         *     ->orQualify('rn', '=', 3)
         *     ->get();
         */
        Builder::macro('orQualify', function (
            string $column,
            mixed $operator = null,
            mixed $value = null
        ): Builder {
            /** @var Builder $this */
            if (func_num_args() === 2) {
                $value = $operator;
                $operator = '=';
            }

            return $this->qualify($column, $operator, $value, 'or');
        });
    }

    /**
     * 注册 QUALIFY RAW 方法
     */
    protected static function registerQualifyRaw(): void
    {
        /**
         * 使用原始 SQL 的 QUALIFY 子句
         *
         * @param string $sql 原始 SQL 条件
         * @param array $bindings 绑定参数
         * @param string $boolean 连接条件: and|or
         * @return Builder
         *
         * @example
         * // 复杂条件过滤
         * Employee::query()
         *     ->select('*')
         *     ->selectRaw('DENSE_RANK() OVER (PARTITION BY dept_id ORDER BY salary DESC) as dr')
         *     ->qualifyRaw('dr BETWEEN ? AND ?', [1, 5])
         *     ->get();
         *
         * // 多窗口函数条件
         * Sales::query()
         *     ->select('*')
         *     ->selectRaw('RANK() OVER (ORDER BY amount DESC) as sales_rank')
         *     ->selectRaw('NTILE(4) OVER (ORDER BY amount DESC) as quartile')
         *     ->qualifyRaw('sales_rank <= 10 AND quartile = 1')
         *     ->get();
         */
        Builder::macro('qualifyRaw', function (
            string $sql,
            array $bindings = [],
            string $boolean = 'and'
        ): Builder {
            /** @var Builder $this */
            $method = $boolean === 'or' ? 'orHavingRaw' : 'havingRaw';

            return $this->{$method}($sql, $bindings);
        });

        /**
         * OR QUALIFY RAW 子句
         *
         * @param string $sql 原始 SQL 条件
         * @param array $bindings 绑定参数
         * @return Builder
         */
        Builder::macro('orQualifyRaw', function (
            string $sql,
            array $bindings = []
        ): Builder {
            /** @var Builder $this */
            return $this->qualifyRaw($sql, $bindings, 'or');
        });
    }
}
