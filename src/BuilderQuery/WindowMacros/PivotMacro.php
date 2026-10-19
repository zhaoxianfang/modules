<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * 行列转换（PIVOT / UNPIVOT / CrossTab）宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 * 基于标准 SQL 的 CASE WHEN 条件聚合实现数据透视，无需数据库专属 PIVOT 语法，
 * 因此同一套宏在 MySQL 8.0+ 与 PostgreSQL 15+ 上均可直接使用：
 * - pivot / pivotCount / pivotSum / pivotAvg / pivotMax / pivotMin: 行转列（将某列唯一值旋转为多列）
 * - unpivot: 列转行（将多列展开为 名称-值 对，基于 UNION ALL）
 * - crossTab: 双维交叉表
 * 安全性：透视列 / 聚合列 / 分组列 / 别名经白名单校验并由 grammar wrap；透视值走参数绑定。
 *
 * @version 1.13.9
 *
 * @requires MySQL 8.0+ / PostgreSQL 15+
 */
class PivotMacro extends BaseMacro
{
    /**
     * 注册所有透视宏
     */
    public static function register(): void
    {
        PivotMacro::registerPivot();
        PivotMacro::registerPivotAggregates();
        PivotMacro::registerUnpivot();
        PivotMacro::registerCrossTab();
    }

    /**
     * 注册通用 PIVOT 方法
     */
    public static function registerPivot(): void
    {
        /**
         * PIVOT - 行转列（数据透视）
         * 将某列的不同值旋转为多列，常用于报表生成
         *
         * @param  string  $pivotColumn  要旋转的列（如 status、category）
         * @param  array  $values  要转换为列的唯一值列表
         * @param  string  $aggregateColumn  要聚合的数值列
         * @param  string  $function  聚合函数: SUM|COUNT|AVG|MAX|MIN
         * @param  string|array|null  $groupBy  分组列
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 统计每个用户各状态订单的金额汇总
         * Order::query()
         *     ->pivot('status', ['pending', 'paid', 'shipped', 'completed'], 'amount', 'SUM', 'user_id')
         *     ->get();
         * // 结果: user_id | pending | paid | shipped | completed
         * // 按月统计各产品类别销售额
         * Sales::query()
         *     ->pivot('category', ['electronics', 'clothing', 'food'], 'amount', 'SUM', 'month')
         *     ->get();
         */
        static::define('pivot', function (
            string $pivotColumn,
            array $values,
            string $aggregateColumn,
            string $function = 'SUM',
            string|array|null $groupBy = null
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            $function = BaseMacro::assertAggregateFunction($function);
            $pivotColumn = BaseMacro::assertValidIdentifier($pivotColumn, 'pivot');
            $aggregateColumn = BaseMacro::assertValidIdentifier($aggregateColumn, 'aggregate');
            $table = BaseMacro::resolveTable($this);
            $wrappedPivot = BaseMacro::wrapIdentifier($this, $table.'.'.$pivotColumn);
            $wrappedAggregate = BaseMacro::wrapIdentifier($this, $table.'.'.$aggregateColumn);

            // 确保分组列被选中
            $groupColumns = is_array($groupBy) ? $groupBy : ($groupBy ? [$groupBy] : []);
            $selectParts = [];
            foreach ($groupColumns as $col) {
                $col = BaseMacro::assertValidIdentifier($col, 'group');
                $selectParts[] = BaseMacro::wrapIdentifier($this, $table.'.'.$col);
            }

            // 构建 CASE WHEN 聚合表达式（值使用参数绑定防止 SQL 注入；列名/函数名已白名单校验）
            $bindings = [];
            foreach ($values as $value) {
                $safeAlias = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $value);
                $safeAlias = is_numeric($safeAlias[0] ?? '') ? '_'.$safeAlias : $safeAlias;
                $safeAlias = BaseMacro::assertValidIdentifier($safeAlias, 'alias');
                $wrappedAlias = BaseMacro::wrapIdentifier($this, $safeAlias);
                if (is_null($value)) {
                    $selectParts[] = "{$function}(CASE WHEN {$wrappedPivot} IS NULL THEN {$wrappedAggregate} ELSE NULL END) AS {$wrappedAlias}";
                } else {
                    $selectParts[] = "{$function}(CASE WHEN {$wrappedPivot} = ? THEN {$wrappedAggregate} ELSE NULL END) AS {$wrappedAlias}";
                    $bindings[] = $value;
                }
            }

            // 【原地改造当前构建器】
            //
            // 透视是一次完整的「列重塑」：结果集必然是「分组列 + N 个透视列」，
            // 因此原查询的 columns 与其绑定一并替换。
            //
            // 只清绑定不清 columns 是不够的：selectRaw() 在 columns 非 null 时是【追加】，
            // 调用方此前的 selectRaw('UPPER(?) as tag', ['x']) 会连同它的 ? 一起留在 SQL 里，
            // 而它的绑定已被我们清掉 → "Invalid parameter number"。
            // columns 与 bindings['select'] 必须成对处理。
            $query = BaseMacro::queryOf($this);
            $query->columns = null;
            $query->bindings['select'] = [];
            $query->selectRaw(implode(', ', $selectParts), $bindings);

            // 分组（透视的语义前提）。使用 grammar wrap 生成适配驱动的标识符引用。
            foreach ($groupColumns as $col) {
                $query->groupByRaw(BaseMacro::wrapIdentifier($this, $table.'.'.$col));
            }

            return $this;
        });
    }

    /**
     * 注册常用聚合透视快捷方法
     */
    public static function registerPivotAggregates(): void
    {
        $aggregates = [
            'pivotCount' => 'COUNT',
            'pivotSum' => 'SUM',
            'pivotAvg' => 'AVG',
            'pivotMax' => 'MAX',
            'pivotMin' => 'MIN',
        ];

        foreach ($aggregates as $macroName => $function) {
            /**
             * {$function} 聚合透视快捷方法
             *
             * @param  string  $pivotColumn  要旋转的列
             * @param  array  $values  要转换为列的唯一值
             * @param  string  $aggregateColumn  要聚合的列
             * @param  string|array|null  $groupBy  分组列
             * @return Builder|\Illuminate\Database\Query\Builder
             *
             * @example
             * // 统计每个地区各状态订单数量
             * Order::query()->pivotCount('status', ['pending', 'paid', 'shipped'], 'id', 'region')->get();
             */
            static::define($macroName, function (
                string $pivotColumn,
                array $values,
                string $aggregateColumn,
                string|array|null $groupBy = null
            ) use ($function): Builder|\Illuminate\Database\Query\Builder {
                /**
                 * @var Builder|\Illuminate\Database\Query\Builder $this
                 */

                return $this->pivot($pivotColumn, $values, $aggregateColumn, $function, $groupBy);
            });
        }
    }

    /**
     * 注册 UNPIVOT 方法
     */
    public static function registerUnpivot(): void
    {
        /**
         * UNPIVOT - 列转行
         * 将多列转换为名称-值对的行格式
         *
         * @param  array  $columns  要转换的列 [['column' => 'jan_sales', 'alias' => '一月'], ...]
         * @param  string  $nameColumn  转换后的名称列
         * @param  string  $valueColumn  转换后的值列
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 将月度销售列转换为行
         * MonthlySales::query()
         *     ->unpivot([
         *         ['column' => 'jan_sales', 'alias' => '一月'],
         *         ['column' => 'feb_sales', 'alias' => '二月'],
         *         ['column' => 'mar_sales', 'alias' => '三月'],
         *     ], 'month', 'sales_amount')
         *     ->get();
         * // 结果: product_id | month | sales_amount
         */
        static::define('unpivot', function (
            array $columns,
            string $nameColumn = 'attribute',
            string $valueColumn = 'value'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            $table = BaseMacro::resolveTable($this);
            $primaryKey = BaseMacro::assertValidIdentifier(BaseMacro::keyName($this), 'primaryKey');
            $wrappedPk = BaseMacro::wrapIdentifier($this, $primaryKey);
            $nameColumn = BaseMacro::assertValidIdentifier($nameColumn, 'name');
            $valueColumn = BaseMacro::assertValidIdentifier($valueColumn, 'value');
            $wrappedName = BaseMacro::wrapIdentifier($this, $nameColumn);
            $wrappedValue = BaseMacro::wrapIdentifier($this, $valueColumn);
            $wrappedTable = BaseMacro::wrapIdentifier($this, $table);

            // 构建 UNION ALL 查询
            $unionParts = [];
            $bindings = [];

            foreach ($columns as $index => $colConfig) {
                $column = BaseMacro::assertValidIdentifier((string) $colConfig['column'], 'column');
                $alias = $colConfig['alias'] ?? $colConfig['column'];
                $bindings[] = $alias;

                $selectColumns = [
                    $wrappedPk,
                    "? AS {$wrappedName}",
                    BaseMacro::wrapIdentifier($this, $column)." AS {$wrappedValue}",
                ];

                $unionParts[] = 'SELECT '.implode(', ', $selectColumns)." FROM {$wrappedTable}";
            }

            $unionSql = implode(' UNION ALL ', $unionParts);

            return BaseMacro::freshQuery($this)
                ->fromRaw("({$unionSql}) as unpivoted", $bindings);
        });
    }

    /**
     * 注册交叉表（Cross Tabulation）
     */
    public static function registerCrossTab(): void
    {
        /**
         * CROSS TAB - 交叉表查询（双维透视）
         * 同时按行和列两个维度进行透视，生成矩阵式报表
         *
         * @param  string  $rowColumn  行维度列
         * @param  string  $colColumn  列维度列
         * @param  string  $aggregateColumn  聚合列
         * @param  string  $function  聚合函数
         * @param  array  $colValues  指定的列维度值（可选，自动探测时为null）
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 按地区和月份交叉统计销售额
         * Sales::query()
         *     ->crossTab('region', 'month', 'amount', 'SUM', ['01', '02', '03', '04'])
         *     ->get();
         * // 结果: region | jan | feb | mar | apr | total
         */
        static::define('crossTab', function (
            string $rowColumn,
            string $colColumn,
            string $aggregateColumn,
            string $function = 'SUM',
            ?array $colValues = null
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            $function = BaseMacro::assertAggregateFunction($function);
            $rowColumn = BaseMacro::assertValidIdentifier($rowColumn, 'row');
            $colColumn = BaseMacro::assertValidIdentifier($colColumn, 'col');
            $aggregateColumn = BaseMacro::assertValidIdentifier($aggregateColumn, 'aggregate');
            $table = BaseMacro::resolveTable($this);
            $wrappedRow = BaseMacro::wrapIdentifier($this, $table.'.'.$rowColumn);
            $wrappedCol = BaseMacro::wrapIdentifier($this, $table.'.'.$colColumn);
            $wrappedAgg = BaseMacro::wrapIdentifier($this, $table.'.'.$aggregateColumn);

            // 如果没有指定列值，使用 DISTINCT 查询（需要额外查询，这里简化处理）
            //
            // 必须设上限，否则：
            //  1) 列值基数大时（例如把 user_id / 订单号当维度透视）
            //     一次把整列拉进 PHP 内存，几百万行直接 OOM；
            //  2) 每个值都会拼一条 CASE WHEN 到 SELECT，
            //     生成几十万列的巨型 SQL，数据库必然报「列数过多」甚至拖垮实例。
            // 因此：
            //  - limit 必须在【SQL 层】下发（见下），不能靠 pluck() 之后再 take()；
            //  - 超出上限时明确抛异常，而不是继续生成一条注定失败的巨型 SQL。
            if ($colValues === null) {
                // 注意：此处必须写 PivotMacro:: 而不能写 self::。
                // 宏闭包是被 Macroable 重新绑定到构建器实例上的，闭包内的 self
                // 解析为 Eloquent\Builder / Query\Builder，self::crossTabColumnLimit()
                // 会抛 "Call to undefined method"，使 crossTab 100% 不可用。
                $limit = PivotMacro::crossTabColumnLimit();

                $colValues = BaseMacro::freshQuery($this)
                    ->distinct()
                    // 先 limit 再 pluck：pluck() 会把结果集整体物化到 PHP，
                    // 「pluck()->take(n)」的 n 只是内存里的 take，防 OOM 完全无效。
                    ->limit($limit + 1)
                    ->pluck($colColumn)
                    ->toArray();

                if (count($colValues) > $limit) {
                    throw new \InvalidArgumentException(sprintf(
                        'crossTab 自动推断的列值超过上限 %d 个（列 [%s] 基数过高）。'
                        .'请改用 "新增列值过滤条件后再透视"，或显式传入 $colValues，'
                        .'或调高 modules.builder_query.pivot_cross_tab_column_limit 配置。',
                        $limit,
                        $colColumn
                    ));
                }
            }

            $selectColumns = [$wrappedRow];
            $groupByColumns = [$wrappedRow];
            $bindings = [];

            foreach ($colValues as $value) {
                $safeAlias = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $value);
                $safeAlias = is_numeric($safeAlias[0] ?? '') ? '_'.$safeAlias : $safeAlias;
                $safeAlias = BaseMacro::assertValidIdentifier($safeAlias, 'alias');
                $wrappedAlias = BaseMacro::wrapIdentifier($this, $safeAlias);
                if (is_null($value)) {
                    $selectColumns[] = "{$function}(CASE WHEN {$wrappedCol} IS NULL THEN {$wrappedAgg} ELSE NULL END) AS {$wrappedAlias}";
                } else {
                    $selectColumns[] = "{$function}(CASE WHEN {$wrappedCol} = ? THEN {$wrappedAgg} ELSE NULL END) AS {$wrappedAlias}";
                    $bindings[] = $value;
                }
            }

            // 添加总计列
            $selectColumns[] = "{$function}({$wrappedAgg}) AS ".BaseMacro::wrapIdentifier($this, 'total');

            // 复制原始 WHERE 条件
            $baseQuery = $this->clone();
            $query = BaseMacro::freshQuery($this)
                ->selectRaw(implode(', ', $selectColumns), $bindings)
                ->groupByRaw(implode(', ', $groupByColumns));

            // 复制原始查询的结构与绑定（详见 crossTab() 内的同名实现说明）
            $base = BaseMacro::queryOf($baseQuery);
            $target = BaseMacro::queryOf($query);

            if (! empty($base->wheres)) {
                $target->wheres = $base->wheres;
                $target->addBinding($base->bindings['where'] ?? [], 'where');
            }

            if (! empty($base->joins)) {
                $target->joins = $base->joins;
                $target->addBinding($base->bindings['join'] ?? [], 'join');
            }

            if (! empty($base->havings)) {
                $target->havings = $base->havings;
                $target->addBinding($base->bindings['having'] ?? [], 'having');
            }

            if (! empty($base->orders)) {
                $target->orders = $base->orders;
                $target->addBinding($base->bindings['order'] ?? [], 'order');
            }

            if (($base->limit ?? null) !== null) {
                $target->limit = $base->limit;
            }

            if (($base->offset ?? null) !== null) {
                $target->offset = $base->offset;
            }

            return $query;
        });
    }

    /**
     * crossTab 自动推断列值时的数量上限
     *
     * 用于给「无限 pluck」设一个硬边界，避免高基数列直接把内存和
     * 数据库的列数上限一起打爆。缺省 200，可在
     * config/modules.php 的 builder_query.pivot_cross_tab_column_limit 调整。
     */
    // 必须 public：crossTab 的宏闭包被 Macroable 重新绑定到构建器实例上，
    // 闭包内 `PivotMacro::crossTabColumnLimit()` 的调用方作用域是 Builder，
    // private 方法在那个作用域下不可访问（private 触发 "Call to private method ... from scope Builder"）。
    public static function crossTabColumnLimit(): int
    {
        try {
            if (function_exists('config') && function_exists('app') && app()->bound('config')) {
                $configured = (int) config('modules.builder_query.pivot_cross_tab_column_limit', 200);

                return $configured > 0 ? $configured : 200;
            }
        } catch (\Throwable) {
            // 容器不可用：使用默认值
        }

        return 200;
    }
}
