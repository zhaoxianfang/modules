<?php

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * MySQL 8.4+ LATERAL JOIN 宏
 *
 * LATERAL 允许子查询引用主查询中的列，实现类似相关子查询但更高效的功能
 * MySQL 8.0.14+ 开始支持 LATERAL derived tables
 *
 * @package zxf\Modules\BuilderQuery\WindowMacros
 * @version 1.0.0
 * @requires MySQL 8.0.14+
 */
class LateralJoinMacro
{
    /**
     * 注册所有 LATERAL JOIN 宏
     *
     * @return void
     */
    public static function register(): void
    {
        self::registerLateralJoin();
        self::registerLateralLeftJoin();
        self::registerLateralLimit();
        self::registerLateralAggregate();
    }

    /**
     * 注册 LATERAL INNER JOIN
     */
    protected static function registerLateralJoin(): void
    {
        /**
         * LATERAL JOIN - 横向连接（INNER）
         *
         * 允许子查询引用主查询的列，常用于：Top-N 查询、行相关聚合、行相关过滤
         *
         * @param \Closure $callback 子查询闭包，接收主查询当前行的参数
         * @param string $alias 子查询别名
         * @return Builder
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
         *
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
        Builder::macro('lateralJoin', function (\Closure $callback, string $alias): Builder {
            /** @var Builder $this */
            $model = $this->getModel();
            $table = $model->getTable();

            // 构建子查询
            $subQuery = $model->newQuery();
            $callback($subQuery);

            $subSql = $subQuery->toSql();
            $subBindings = $subQuery->getBindings();

            // 构建 LATERAL JOIN
            $lateralSql = "LATERAL ({$subSql}) AS `{$alias}`";

            return $this->join(DB::raw($lateralSql), function ($join) {
                // LATERAL JOIN 不需要 ON 条件，子查询已通过 whereColumn 关联
                $join->onRaw('1=1');
            })->addBinding($subBindings, 'join');
        });
    }

    /**
     * 注册 LATERAL LEFT JOIN
     */
    protected static function registerLateralLeftJoin(): void
    {
        /**
         * LATERAL LEFT JOIN - 左横向连接
         *
         * 即使子查询没有匹配结果，也保留主查询的行
         *
         * @param \Closure $callback 子查询闭包
         * @param string $alias 子查询别名
         * @return Builder
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
        Builder::macro('lateralLeftJoin', function (\Closure $callback, string $alias): Builder {
            /** @var Builder $this */
            $model = $this->getModel();

            // 构建子查询
            $subQuery = $model->newQuery();
            $callback($subQuery);

            $subSql = $subQuery->toSql();
            $subBindings = $subQuery->getBindings();

            $lateralSql = "LATERAL ({$subSql}) AS `{$alias}`";

            return $this->leftJoin(DB::raw($lateralSql), function ($join) {
                $join->onRaw('1=1');
            })->addBinding($subBindings, 'join');
        });
    }

    /**
     * 注册 LATERAL LIMIT（Top-N 优化）
     */
    protected static function registerLateralLimit(): void
    {
        /**
         * 使用 LATERAL 实现高效的 Top-N 查询
         *
         * 为每个分组查找前N条记录，比窗口函数 + 子查询更高效
         *
         * @param string $partitionColumn 分组列（主表列）
         * @param string $orderColumn 排序列（子表列）
         * @param string $direction 排序方向
         * @param int $limit 每组返回记录数
         * @param string $alias 子查询别名
         * @return Builder
         *
         * @example
         * // 每个分类下销量最高的5个商品
         * Category::query()
         *     ->lateralLimit('id', 'sales_count', 'desc', 5, 'top_products')
         *     ->get();
         *
         * // 每个用户最近的10条消息
         * User::query()
         *     ->lateralLimit('id', 'created_at', 'desc', 10, 'recent_messages')
         *     ->get();
         */
        Builder::macro('lateralLimit', function (
            string $partitionColumn,
            string $orderColumn,
            string $direction = 'desc',
            int $limit = 5,
            string $alias = 'lateral_limit'
        ): Builder {
            /** @var Builder $this */
            $model = $this->getModel();
            $table = $model->getTable();
            $direction = strtoupper($direction);

            // 构建关联子查询
            $subQuery = $model->newQuery()
                ->from("{$table} as t2")
                ->select('t2.*')
                ->whereRaw("t2.{$partitionColumn} = {$table}.{$partitionColumn}")
                ->orderBy("t2.{$orderColumn}", $direction)
                ->limit($limit);

            $subSql = $subQuery->toSql();
            $subBindings = $subQuery->getBindings();

            $lateralSql = "LATERAL ({$subSql}) AS `{$alias}`";

            return $this->join(DB::raw($lateralSql), function ($join) {
                $join->onRaw('1=1');
            })->addBinding($subBindings, 'join');
        });
    }

    /**
     * 注册 LATERAL 聚合查询
     */
    protected static function registerLateralAggregate(): void
    {
        /**
         * 使用 LATERAL 实现行相关的聚合查询
         *
         * 计算每行与其相关行的聚合值
         *
         * @param string $relationColumn 关联列名
         * @param array $aggregates 聚合配置 [['column' => 'amount', 'function' => 'SUM', 'alias' => 'total'], ...]
         * @param string|null $whereColumn 额外过滤列
         * @param mixed $whereValue 额外过滤值
         * @param string $alias 子查询别名
         * @return Builder
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
        Builder::macro('lateralAggregate', function (
            string $relationColumn,
            array $aggregates,
            ?string $whereColumn = null,
            mixed $whereValue = null,
            string $alias = 'agg_result'
        ): Builder {
            /** @var Builder $this */
            $model = $this->getModel();
            $table = $model->getTable();

            $selectParts = [];
            foreach ($aggregates as $agg) {
                $function = strtoupper($agg['function']);
                $column = $agg['column'] === '*' ? '*' : "`{$agg['column']}`";
                $selectParts[] = "{$function}({$column}) AS `{$agg['alias']}`";
            }

            $subQuery = $model->newQuery()
                ->from("{$table} as t2")
                ->selectRaw(implode(', ', $selectParts))
                ->whereRaw("t2.{$relationColumn} = {$table}.{$relationColumn}");

            if ($whereColumn !== null && $whereValue !== null) {
                $subQuery->where("t2.{$whereColumn}", $whereValue);
            }

            $subSql = $subQuery->toSql();
            $subBindings = $subQuery->getBindings();

            $lateralSql = "LATERAL ({$subSql}) AS `{$alias}`";

            return $this->join(DB::raw($lateralSql), function ($join) {
                $join->onRaw('1=1');
            })->addBinding($subBindings, 'join');
        });
    }
}
