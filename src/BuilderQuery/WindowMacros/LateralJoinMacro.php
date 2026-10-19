<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * LATERAL JOIN 宏（MySQL 8.0.14+ / PostgreSQL 15+ 通用）
 * LATERAL 允许子查询引用主查询中的列，实现「每个主表行关联一个相关子查询」的高效查询
 * （Top-N、行相关聚合、行相关过滤等）。LATERAL 为标准 SQL，在 MySQL 8.0.14+ 与 PostgreSQL 15+ 上均可直接使用。
 * 安全性：用户传入的表别名 / 分组列 / 排序列 / 聚合列经白名单校验并由 grammar wrap；
 * 关联条件通过 whereColumn / 参数化子查询实现，不裸拼用户输入。
 *
 * @version 1.13.9
 *
 * @requires MySQL 8.0.14+ / PostgreSQL 15+
 */
class LateralJoinMacro extends BaseMacro
{
    /**
     * 归一化 LATERAL 子查询：兼容两种回调写法。
     *
     * 原实现一律把「主模型的新查询」交给回调、并丢弃返回值，于是本宏自己的文档示例
     *   ->lateralJoin(fn () => Order::query()->whereColumn('user_id', 'users.id'), 'recent_orders')
     * 实际生成的是 `LATERAL (select * from users ...) AS recent_orders` —— 主表与子查询
     * **同表**，语义完全跑偏且不会报错，属于最难排查的一类缺陷。
     *
     * 现在：
     *   - 回调返回构建器 → 以返回值为准（支持 Order::query() 这类"关联另一个模型"的写法）；
     *   - 回调返回 null   → 视为"就地改造传入的构建器"（完全向后兼容原有用法）。
     *
     * @param  \Closure  $callback  回调
     * @param  Builder|QueryBuilder  $main  主查询构建器
     * @return Builder|QueryBuilder 子查询构建器
     */
    private static function resolveLateralSubQuery(\Closure $callback, $main)
    {
        $seed = BaseMacro::freshQuery($main);
        $returned = $callback($seed);

        return ($returned instanceof Builder || $returned instanceof QueryBuilder)
            ? $returned
            : $seed;
    }

    /**
     * 注册所有 LATERAL JOIN 宏
     */
    public static function register(): void
    {
        LateralJoinMacro::registerLateralJoin();
        LateralJoinMacro::registerLateralLeftJoin();
        LateralJoinMacro::registerLateralLimit();
        LateralJoinMacro::registerLateralAggregate();
    }

    /**
     * 注册 LATERAL INNER JOIN
     */
    public static function registerLateralJoin(): void
    {
        /**
         * LATERAL JOIN - 横向连接（INNER）
         * 允许子查询引用主查询的列，常用于：Top-N 查询、行相关聚合、行相关过滤
         *
         * @param  \Closure  $callback  子查询闭包，接收主查询当前行的参数
         * @param  string  $alias  子查询别名
         *
         * @example
         * // 查找每个用户最近的3条订单
         * User::query()
         *     ->lateralJoin(function ($userId) {
         *         return Order::query()
         *             ->select('*')
         *             ->whereColumn('user_id', 'users.id')
         *             ->orderBy('created_at', 'desc')
         *             ->limit(3);
         *     }, 'recent_orders')
         *     ->get();
         * // 查找每个部门工资最高的员工
         * Employee::query()
         *     ->lateralJoin(function ($deptId) {
         *         return Employee::query()
         *             ->select('*')
         *             ->whereColumn('department_id', 'employees.department_id')
         *             ->orderBy('salary', 'desc')
         *             ->limit(1);
         *     }, 'top_earner')
         *     ->get();
         */
        static::define('lateralJoin', function (\Closure $callback, string $alias): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            $table = BaseMacro::resolveTable($this);
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = BaseMacro::wrapIdentifier($this, $alias);

            // 构建子查询
            $subQuery = LateralJoinMacro::resolveLateralSubQuery($callback, $this);

            $subSql = $subQuery->toSql();
            $subBindings = $subQuery->getBindings();

            // 构建 LATERAL JOIN
            $lateralSql = "LATERAL ({$subSql}) AS {$wrappedAlias}";

            return $this->join(DB::raw($lateralSql), function ($join) {
                // LATERAL JOIN 不需要 ON 条件，子查询已通过 whereColumn 关联
                $join->onRaw('1=1');
            })->addBinding($subBindings, 'join');
        });
    }

    /**
     * 注册 LATERAL LEFT JOIN
     */
    public static function registerLateralLeftJoin(): void
    {
        /**
         * LATERAL LEFT JOIN - 左横向连接
         * 即使子查询没有匹配结果，也保留主查询的行
         *
         * @param  \Closure  $callback  子查询闭包
         * @param  string  $alias  子查询别名
         *
         * @example
         * // 查找所有用户及其最新订单（包括没有订单的用户）
         * User::query()
         *     ->lateralLeftJoin(function ($query) {
         *         return $query->select('*')->from('orders')
         *             ->whereColumn('orders.user_id', 'users.id')
         *             ->orderBy('created_at', 'desc')
         *             ->limit(1);
         *     }, 'latest_order')
         *     ->get();
         */
        static::define('lateralLeftJoin', function (\Closure $callback, string $alias): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = BaseMacro::wrapIdentifier($this, $alias);

            // 构建子查询
            $subQuery = LateralJoinMacro::resolveLateralSubQuery($callback, $this);

            $subSql = $subQuery->toSql();
            $subBindings = $subQuery->getBindings();

            $lateralSql = "LATERAL ({$subSql}) AS {$wrappedAlias}";

            return $this->leftJoin(DB::raw($lateralSql), function ($join) {
                $join->onRaw('1=1');
            })->addBinding($subBindings, 'join');
        });
    }

    /**
     * 注册 LATERAL LIMIT（Top-N 优化）
     */
    public static function registerLateralLimit(): void
    {
        /**
         * 使用 LATERAL 实现高效的 Top-N 查询
         * 为每个分组查找前N条记录，比窗口函数 + 子查询更高效
         *
         * @param  string  $partitionColumn  分组列（主表列）
         * @param  string  $orderColumn  排序列（子表列）
         * @param  string  $direction  排序方向
         * @param  int  $limit  每组返回记录数
         * @param  string  $alias  子查询别名
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 每个分类下销量最高的5个商品
         * Category::query()
         *     ->lateralLimit('id', 'sales_count', 'desc', 5, 'top_products')
         *     ->get();
         * // 每个用户最近的10条消息
         * User::query()
         *     ->lateralLimit('id', 'created_at', 'desc', 10, 'recent_messages')
         *     ->get();
         */
        static::define('lateralLimit', function (
            string $partitionColumn,
            string $orderColumn,
            string $direction = 'desc',
            int $limit = 5,
            string $alias = 'lateral_limit'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            $table = BaseMacro::resolveTable($this);
            $direction = BaseMacro::assertDirection($direction);
            $partitionColumn = BaseMacro::assertValidIdentifier($partitionColumn, 'partition');
            $orderColumn = BaseMacro::assertValidIdentifier($orderColumn, 'order');
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = BaseMacro::wrapIdentifier($this, $alias);

            // 构建关联子查询
            $subQuery = BaseMacro::freshQuery($this)
                ->from("{$table} as t2")
                ->select('t2.*')
                ->whereRaw("t2.{$partitionColumn} = {$table}.{$partitionColumn}")
                ->orderBy("t2.{$orderColumn}", $direction)
                ->limit($limit);

            $subSql = $subQuery->toSql();
            $subBindings = $subQuery->getBindings();

            $lateralSql = "LATERAL ({$subSql}) AS {$wrappedAlias}";

            return $this->join(DB::raw($lateralSql), function ($join) {
                $join->onRaw('1=1');
            })->addBinding($subBindings, 'join');
        });
    }

    /**
     * 注册 LATERAL 聚合查询
     */
    public static function registerLateralAggregate(): void
    {
        /**
         * 使用 LATERAL 实现行相关的聚合查询
         * 计算每行与其相关行的聚合值
         *
         * @param  string  $relationColumn  关联列名
         * @param  array  $aggregates  聚合配置 [['column' => 'amount', 'function' => 'SUM', 'alias' => 'total'], ...]
         * @param  string|null  $whereColumn  额外过滤列
         * @param  mixed  $whereValue  额外过滤值
         * @param  string  $alias  子查询别名
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 计算每个订单的客户历史总消费和平均消费
         * Order::query()
         *     ->lateralAggregate('user_id', [
         *         ['column' => 'amount', 'function' => 'SUM', 'alias' => 'customer_total'],
         *         ['column' => 'amount', 'function' => 'AVG', 'alias' => 'customer_avg'],
         *         ['column' => '*', 'function' => 'COUNT', 'alias' => 'customer_orders'],
         *     ], 'status', 'completed', 'customer_stats')
         *     ->get();
         */
        static::define('lateralAggregate', function (
            string $relationColumn,
            array $aggregates,
            ?string $whereColumn = null,
            mixed $whereValue = null,
            string $alias = 'agg_result'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            $table = BaseMacro::resolveTable($this);
            $relationColumn = BaseMacro::assertValidIdentifier($relationColumn, 'relation');
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = BaseMacro::wrapIdentifier($this, $alias);

            $selectParts = [];
            foreach ($aggregates as $agg) {
                $function = BaseMacro::assertAggregateFunction((string) $agg['function']);
                $column = $agg['column'] === '*' ? '*' : BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier((string) $agg['column'], 'column'));
                $aggAlias = BaseMacro::assertValidIdentifier((string) $agg['alias'], 'alias');
                $selectParts[] = "{$function}({$column}) AS ".BaseMacro::wrapIdentifier($this, $aggAlias);
            }

            $subQuery = BaseMacro::freshQuery($this)
                ->from("{$table} as t2")
                ->selectRaw(implode(', ', $selectParts))
                ->whereRaw("t2.{$relationColumn} = {$table}.{$relationColumn}");

            if ($whereColumn !== null && $whereValue !== null) {
                $whereColumn = BaseMacro::assertValidIdentifier($whereColumn, 'where');
                $subQuery->where("t2.{$whereColumn}", $whereValue);
            }

            $subSql = $subQuery->toSql();
            $subBindings = $subQuery->getBindings();

            $lateralSql = "LATERAL ({$subSql}) AS {$wrappedAlias}";

            return $this->join(DB::raw($lateralSql), function ($join) {
                $join->onRaw('1=1');
            })->addBinding($subBindings, 'join');
        });
    }
}
