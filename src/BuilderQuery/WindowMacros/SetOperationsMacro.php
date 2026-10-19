<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * 集合操作宏（MySQL 8.0.31+ / PostgreSQL 15+ 通用）
 * 基于标准 SQL 集合操作，同一套宏在 MySQL 8.0.31+ 与 PostgreSQL 15+ 上均可直接使用：
 * - intersect: 返回两个查询的交集（INTERSECT）
 * - except: 返回第一个查询中存在但第二个查询中不存在的记录（EXCEPT）
 * - unionDistinct: UNION DISTINCT 显式去重
 * 安全性：子查询通过 grammar 编译，绑定参数沿用 Laravel 绑定合并机制，不存在字符串裸拼注入；
 * 衍生结果表别名（intersect_result / except_result）为固定字面量，非用户输入。
 *
 * @version 1.13.9
 *
 * @requires MySQL 8.0.31+ / PostgreSQL 15+
 */
class SetOperationsMacro extends BaseMacro
{
    /**
     * 注册所有集合操作宏
     */
    public static function register(): void
    {
        SetOperationsMacro::registerIntersect();
        SetOperationsMacro::registerExcept();
        SetOperationsMacro::registerUnionDistinct();
    }

    /**
     * 注册 INTERSECT 交集操作
     */
    public static function registerIntersect(): void
    {
        /**
         * 返回两个查询结果的交集（去重）
         * 应用场景：查找同时满足两组条件的记录
         *
         * @param  \Closure|Builder|\Illuminate\Database\Query\Builder  $query  第二个查询构建器或闭包
         * @param  bool  $all  是否保留重复行 true=INTERSECT ALL, false=INTERSECT DISTINCT
         *
         * @example
         * // 查找既是 VIP、又已激活的用户
         * $activeVips = User::query()->select('id')
         *     ->where('status', 1)
         *     ->where('vip', 1);
         * $recentBuyers = User::query()->select('id')
         *     ->whereHasIn('orders', fn ($q) => $q->where('paid_at', '>=', now()->subDays(30)));
         * $activeVips->intersect($recentBuyers)->get();   // 两个集合的交集
         *
         * // 也可直接传闭包（内部会新建同源查询并应用条件）
         * User::query()->select('id')->where('status', 1)->intersect(function ($q) {
         *     $q->where('vip', 1);
         * })->get();
         *
         * @scenario 需要「同时满足两组条件」的记录，例如「近 30 天有订单 且 是 VIP」。
         *           注意：两个查询的 SELECT 列必须一致（都取 id 或都取业务主键），
         *           否则 SQL 层的 INTERSECT 会因列数/类型不匹配报错。
         */
        static::define('intersect', function ($query, bool $all = false): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            $sql = $this->toSql();
            $bindings = $this->getBindings();

            if ($query instanceof \Closure) {
                $callback = $query;
                $query = BaseMacro::freshQuery($this);
                $callback($query);
            }

            $secondSql = $query->toSql();
            $secondBindings = $query->getBindings();

            $allKeyword = $all ? 'ALL' : 'DISTINCT';
            $combinedSql = "({$sql}) INTERSECT {$allKeyword} ({$secondSql})";
            $combinedBindings = array_merge($bindings, $secondBindings);

            return BaseMacro::freshQuery($this)
                ->fromRaw("({$combinedSql}) as intersect_result", $combinedBindings);
        });
    }

    /**
     * 注册 EXCEPT 差集操作
     */
    public static function registerExcept(): void
    {
        /**
         * 返回在第一个查询中存在但在第二个查询中不存在的记录
         * 应用场景：查找满足某条件但不满足另一条件的记录
         *
         * @param  \Closure|Builder|\Illuminate\Database\Query\Builder  $query  第二个查询构建器或闭包
         * @param  bool  $all  是否保留重复行 true=EXCEPT ALL, false=EXCEPT DISTINCT
         *
         * @example
         * // 查找「已激活但还没验证邮箱」的用户
         * User::query()->select('id')
         *     ->where('status', 'active')
         *     ->except(function ($q) {
         *         $q->whereNotNull('email_verified_at');
         *     })
         *     ->get();
         *
         * // 查找「有订单但从未评价」的用户
         * $orderedUsers  = User::query()->select('id')->whereHasIn('orders');
         * $reviewedUsers = User::query()->select('id')->whereHasIn('reviews');
         * $orderedUsers->except($reviewedUsers)->get();
         *
         * @scenario 需要「满足 A 但不满足 B」的记录，例如「激活了却没验证邮箱」
         *           「下单了却没评价」「有退款记录但已结案」等差集场景。
         *           注意：两个查询的 SELECT 列必须一致，否则 SQL 层的 EXCEPT 会报错。
         *
         * ★ **Eloquent Builder 上请用别名 {@see exceptSet}**：框架原生 `Eloquent\Builder::except()`
         *   是「按主键排除模型」（whereKeyNot），与本宏的 SQL 集合差集**同名异义**。
         *   define() 会让原生方法优先，因此 Eloquent 侧本宏不注册 `except`，改由 `exceptSet` 出口；
         *   Query\Builder 上的原生 `except()` 本身就是 SQL 集合差集，语义一致，可直接使用。
         */
        static::define('except', function ($query, bool $all = false): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            $sql = $this->toSql();
            $bindings = $this->getBindings();

            if ($query instanceof \Closure) {
                $callback = $query;
                $query = BaseMacro::freshQuery($this);
                $callback($query);
            }

            $secondSql = $query->toSql();
            $secondBindings = $query->getBindings();

            $allKeyword = $all ? 'ALL' : 'DISTINCT';
            $combinedSql = "({$sql}) EXCEPT {$allKeyword} ({$secondSql})";
            $combinedBindings = array_merge($bindings, $secondBindings);

            return BaseMacro::freshQuery($this)
                ->fromRaw("({$combinedSql}) as except_result", $combinedBindings);
        }, 'exceptSet');
    }

    /**
     * 注册 UNION DISTINCT 显式去重
     */
    public static function registerUnionDistinct(): void
    {
        /**
         * UNION DISTINCT - 显式去重的并集（默认行为，但显式声明更清晰）
         *
         * @param  \Closure|Builder|\Illuminate\Database\Query\Builder  $query  第二个查询构建器或闭包
         *
         * @example
         * // 合并「管理员」与「高权限用户」两个集合并去重
         * User::query()->select('id')->where('role', 'admin')
         *     ->unionDistinct(function ($q) {
         *         $q->join('permissions', 'users.id', '=', 'permissions.user_id')
         *             ->where('permissions.level', '>=', 5);
         *     })
         *     ->get();
         *
         * @scenario 需要合并两个来源的记录集合并自动去重（UNION 的默认语义）。
         *           显式写 unionDistinct 相比直接用 Laravel 的 union() 更能表达意图，
         *           便于与 unionAll() 区分。
         */
        static::define('unionDistinct', function ($query): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            if ($query instanceof \Closure) {
                $callback = $query;
                $query = BaseMacro::freshQuery($this);
                $callback($query);
            }

            return $this->union($query);
        });
    }
}
