<?php

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;

/**
 * MySQL 8.4+ 集合操作宏
 *
 * 提供 INTERSECT、EXCEPT 等集合操作支持：
 * - intersect: 返回两个查询的交集
 * - except: 返回第一个查询中存在但第二个查询中不存在的记录
 * - unionDistinct: UNION DISTINCT 显式去重
 *
 * @package zxf\Modules\BuilderQuery\WindowMacros
 * @version 1.0.0
 * @requires MySQL 8.0.31+
 */
class SetOperationsMacro
{
    /**
     * 注册所有集合操作宏
     *
     * @return void
     */
    public static function register(): void
    {
        self::registerIntersect();
        self::registerExcept();
        self::registerUnionDistinct();
    }

    /**
     * 注册 INTERSECT 交集操作
     */
    protected static function registerIntersect(): void
    {
        /**
         * 返回两个查询结果的交集（去重）
         *
         * 应用场景：查找同时满足两组条件的记录
         *
         * @param \Closure|Builder $query 第二个查询构建器或闭包
         * @param bool $all 是否保留重复行 true=INTERSECT ALL, false=INTERSECT DISTINCT
         * @return Builder
         *
         * @example
         * // 查找既购买了A商品又购买了B商品的用户
         * User::query()
         *     ->whereExists(function ($q) {
         *         $q->select('user_id')->from('orders')->where('product_id', 1);
         *     })
         *     ->intersect(function ($q) {
         *         $q->select('id')->from('users')
         *           ->whereExists(function ($sq) {
         *               $sq->select('user_id')->from('orders')->where('product_id', 2);
         *           });
         *     })
         *     ->get();
         *
         * // 使用 Builder 实例
         * $q1 = User::query()->select('id')->where('status', 1);
         * $q2 = User::query()->select('id')->where('vip', 1);
         * $q1->intersect($q2)->get();
         */
        Builder::macro('intersect', function ($query, bool $all = false): Builder {
            /** @var Builder $this */
            $sql = $this->toSql();
            $bindings = $this->getBindings();

            if ($query instanceof \Closure) {
                $callback = $query;
                $query = $this->getModel()->newQuery();
                $callback($query);
            }

            $secondSql = $query->toSql();
            $secondBindings = $query->getBindings();

            $allKeyword = $all ? 'ALL' : 'DISTINCT';
            $combinedSql = "({$sql}) INTERSECT {$allKeyword} ({$secondSql})";
            $combinedBindings = array_merge($bindings, $secondBindings);

            return $this->getModel()->newQuery()
                ->fromRaw("({$combinedSql}) as intersect_result", $combinedBindings);
        });
    }

    /**
     * 注册 EXCEPT 差集操作
     */
    protected static function registerExcept(): void
    {
        /**
         * 返回在第一个查询中存在但在第二个查询中不存在的记录
         *
         * 应用场景：查找满足某条件但不满足另一条件的记录
         *
         * @param \Closure|Builder $query 第二个查询构建器或闭包
         * @param bool $all 是否保留重复行 true=EXCEPT ALL, false=EXCEPT DISTINCT
         * @return Builder
         *
         * @example
         * // 查找活跃但未验证邮箱的用户
         * User::query()->select('id')->where('status', 'active')
         *     ->except(function ($q) {
         *         $q->select('id')->from('users')->whereNotNull('email_verified_at');
         *     })
         *     ->get();
         *
         * // 查找有订单但未评价的用户
         * $orderedUsers = User::query()->select('id')->whereHas('orders');
         * $reviewedUsers = User::query()->select('id')->whereHas('reviews');
         * $orderedUsers->except($reviewedUsers)->get();
         */
        Builder::macro('except', function ($query, bool $all = false): Builder {
            /** @var Builder $this */
            $sql = $this->toSql();
            $bindings = $this->getBindings();

            if ($query instanceof \Closure) {
                $callback = $query;
                $query = $this->getModel()->newQuery();
                $callback($query);
            }

            $secondSql = $query->toSql();
            $secondBindings = $query->getBindings();

            $allKeyword = $all ? 'ALL' : 'DISTINCT';
            $combinedSql = "({$sql}) EXCEPT {$allKeyword} ({$secondSql})";
            $combinedBindings = array_merge($bindings, $secondBindings);

            return $this->getModel()->newQuery()
                ->fromRaw("({$combinedSql}) as except_result", $combinedBindings);
        });
    }

    /**
     * 注册 UNION DISTINCT 显式去重
     */
    protected static function registerUnionDistinct(): void
    {
        /**
         * UNION DISTINCT - 显式去重的并集（默认行为，但显式声明更清晰）
         *
         * @param \Closure|Builder $query 第二个查询构建器或闭包
         * @return Builder
         *
         * @example
         * // 合并两个查询结果并去重
         * User::query()->where('role', 'admin')
         *     ->unionDistinct(function ($q) {
         *         $q->select('users.*')->from('users')
         *           ->join('permissions', 'users.id', '=', 'permissions.user_id')
         *           ->where('permissions.level', '>=', 5);
         *     })
         *     ->get();
         */
        Builder::macro('unionDistinct', function ($query): Builder {
            /** @var Builder $this */
            if ($query instanceof \Closure) {
                $callback = $query;
                $query = $this->getModel()->newQuery();
                $callback($query);
            }

            return $this->union($query);
        });
    }
}
