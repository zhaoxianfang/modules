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
 * @version 3.0.0
 *
 * @requires MySQL 8.0+ / PostgreSQL 15+
 *
 * @scenario 行列转换（PIVOT / UNPIVOT / CrossTab）宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 */
class PivotMacro extends BaseMacro
{
    /**
     * 注册所有透视宏
     *
     * @scenario 注册所有透视宏
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
     *
     * @scenario 注册通用 PIVOT 方法
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
         *
         * @scenario PIVOT - 行转列（数据透视）
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
             *
             * @scenario 本类/成员说明。
             */
            $function = BaseMacro::assertAggregateFunction($function);
            $pivotColumn = BaseMacro::assertValidIdentifier($pivotColumn, 'pivot');
            $aggregateColumn = BaseMacro::assertValidIdentifier($aggregateColumn, 'aggregate');
            $model = BaseMacro::modelOf($this);
            $table = $model->getTable();
            $wrappedPivot = BaseMacro::wrapIdentifier($this, $pivotColumn);
            $wrappedAggregate = BaseMacro::wrapIdentifier($this, $aggregateColumn);

            // 确保分组列被选中
            $groupColumns = is_array($groupBy) ? $groupBy : ($groupBy ? [$groupBy] : []);
            $selectColumns = array_map(function ($col) use ($table, $model) {
                $col = BaseMacro::assertValidIdentifier($col, 'group');

                return BaseMacro::wrapIdentifier($model->newQuery(), "{$table}.{$col}");
            }, $groupColumns);
            $bindings = [];

            // 构建 CASE WHEN 聚合表达式（值使用参数绑定防止SQL注入；列名/函数名已白名单校验）
            foreach ($values as $value) {
                $safeAlias = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $value);
                $safeAlias = is_numeric($safeAlias[0] ?? '') ? '_'.$safeAlias : $safeAlias;
                $safeAlias = BaseMacro::assertValidIdentifier($safeAlias, 'alias');
                $wrappedAlias = BaseMacro::wrapIdentifier($this, $safeAlias);
                if (is_null($value)) {
                    $selectColumns[] = "{$function}(CASE WHEN {$wrappedPivot} IS NULL THEN {$wrappedAggregate} ELSE NULL END) AS {$wrappedAlias}";
                } else {
                    $selectColumns[] = "{$function}(CASE WHEN {$wrappedPivot} = ? THEN {$wrappedAggregate} ELSE NULL END) AS {$wrappedAlias}";
                    $bindings[] = $value;
                }
            }

            // 构建查询：复制原始查询的 WHERE 条件
            $baseQuery = $this->clone();
            $query = BaseMacro::freshQuery($this)
                ->selectRaw(implode(', ', $selectColumns), $bindings);

            // 复制原始 WHERE 条件到新查询
            if (! empty(BaseMacro::queryOf($baseQuery)->wheres)) {
                BaseMacro::queryOf($query)->wheres = BaseMacro::queryOf($baseQuery)->wheres;
                $query->addBinding(BaseMacro::queryOf($baseQuery)->bindings['where'] ?? [], 'where');
            }

            // 添加分组（使用 grammar wrap 生成适配驱动的标识符引用）
            foreach ($groupColumns as $col) {
                $wrappedGroupCol = BaseMacro::wrapIdentifier($this, "{$table}.{$col}");
                $query->groupByRaw($wrappedGroupCol);
            }

            return $query;
        });
    }

    /**
     * 注册常用聚合透视快捷方法
     *
     * @scenario 注册常用聚合透视快捷方法
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
             *
             * @scenario {$function} 聚合透视快捷方法
             */
            static::define($macroName, function (
                string $pivotColumn,
                array $values,
                string $aggregateColumn,
                string|array|null $groupBy = null
            ) use ($function): Builder|\Illuminate\Database\Query\Builder {
                /**
                 * @var Builder|\Illuminate\Database\Query\Builder $this
                 *
                 * @scenario 本类/成员说明。
                 */

                return $this->pivot($pivotColumn, $values, $aggregateColumn, $function, $groupBy);
            });
        }
    }

    /**
     * 注册 UNPIVOT 方法
     *
     * @scenario 注册 UNPIVOT 方法
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
         *
         * @scenario UNPIVOT - 列转行
         */
        static::define('unpivot', function (
            array $columns,
            string $nameColumn = 'attribute',
            string $valueColumn = 'value'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $model = BaseMacro::modelOf($this);
            $table = $model->getTable();
            $primaryKey = BaseMacro::assertValidIdentifier($model->getKeyName(), 'primaryKey');
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

            return $model->newQuery()
                ->fromRaw("({$unionSql}) as unpivoted", $bindings);
        });
    }

    /**
     * 注册交叉表（Cross Tabulation）
     *
     * @scenario 注册交叉表（Cross Tabulation）
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
         *
         * @scenario CROSS TAB - 交叉表查询（双维透视）
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
             *
             * @scenario 本类/成员说明。
             */
            $function = BaseMacro::assertAggregateFunction($function);
            $rowColumn = BaseMacro::assertValidIdentifier($rowColumn, 'row');
            $colColumn = BaseMacro::assertValidIdentifier($colColumn, 'col');
            $aggregateColumn = BaseMacro::assertValidIdentifier($aggregateColumn, 'aggregate');
            $model = BaseMacro::modelOf($this);
            $table = $model->getTable();
            $wrappedRow = BaseMacro::wrapIdentifier($this, $rowColumn);
            $wrappedCol = BaseMacro::wrapIdentifier($this, $colColumn);
            $wrappedAgg = BaseMacro::wrapIdentifier($this, $aggregateColumn);

            // 如果没有指定列值，使用 DISTINCT 查询（需要额外查询，这里简化处理）
            if ($colValues === null) {
                $colValues = $model->newQuery()
                    ->distinct()
                    ->pluck($colColumn)
                    ->toArray();
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
            $query = $model->newQuery()
                ->selectRaw(implode(', ', $selectColumns), $bindings)
                ->groupByRaw(implode(', ', $groupByColumns));

            // 复制原始 WHERE 条件到新查询
            if (! empty(BaseMacro::queryOf($baseQuery)->wheres)) {
                BaseMacro::queryOf($query)->wheres = BaseMacro::queryOf($baseQuery)->wheres;
                $query->addBinding(BaseMacro::queryOf($baseQuery)->bindings['where'] ?? [], 'where');
            }

            return $query;
        });
    }
}
