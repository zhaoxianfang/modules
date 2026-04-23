<?php

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * MySQL 8.4+ 行列转换（PIVOT/UNPIVOT）宏
 *
 * 提供数据透视表功能：
 * - pivot: 行转列（将某列的唯一值转换为列）
 * - unpivot: 列转行（将多列转换为行）
 * - pivotCount/pivotSum/pivotAvg/pivotMax/pivotMin: 常用聚合透视
 *
 * @package zxf\Modules\BuilderQuery\WindowMacros
 * @version 1.0.0
 * @requires MySQL 8.4+
 */
class PivotMacro
{
    /**
     * 注册所有透视宏
     *
     * @return void
     */
    public static function register(): void
    {
        self::registerPivot();
        self::registerPivotAggregates();
        self::registerUnpivot();
        self::registerCrossTab();
    }

    /**
     * 注册通用 PIVOT 方法
     */
    protected static function registerPivot(): void
    {
        /**
         * PIVOT - 行转列（数据透视）
         *
         * 将某列的不同值旋转为多列，常用于报表生成
         *
         * @param string $pivotColumn 要旋转的列（如 status、category）
         * @param array $values 要转换为列的唯一值列表
         * @param string $aggregateColumn 要聚合的数值列
         * @param string $function 聚合函数: SUM|COUNT|AVG|MAX|MIN
         * @param string|array|null $groupBy 分组列
         * @return Builder
         *
         * @example
         * // 统计每个用户各状态订单的金额汇总
         * Order::query()
         *     ->pivot('status', ['pending', 'paid', 'shipped', 'completed'], 'amount', 'SUM', 'user_id')
         *     ->get();
         * // 结果: user_id | pending | paid | shipped | completed
         *
         * // 按月统计各产品类别销售额
         * Sales::query()
         *     ->pivot('category', ['electronics', 'clothing', 'food'], 'amount', 'SUM', 'month')
         *     ->get();
         */
        Builder::macro('pivot', function (
            string $pivotColumn,
            array $values,
            string $aggregateColumn,
            string $function = 'SUM',
            string|array|null $groupBy = null
        ): Builder {
            /** @var Builder $this */
            $function = strtoupper($function);
            $model = $this->getModel();
            $table = $model->getTable();

            // 确保分组列被选中
            $groupColumns = is_array($groupBy) ? $groupBy : ($groupBy ? [$groupBy] : []);
            $selectColumns = array_map(fn ($col) => "`{$table}`.`{$col}`", $groupColumns);
            $bindings = [];

            // 构建 CASE WHEN 聚合表达式（使用参数绑定防止SQL注入）
            foreach ($values as $value) {
                $safeAlias = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $value);
                $safeAlias = is_numeric($safeAlias[0] ?? '') ? '_' . $safeAlias : $safeAlias;
                if (is_null($value)) {
                    $selectColumns[] = "{$function}(CASE WHEN `{$pivotColumn}` IS NULL THEN `{$aggregateColumn}` ELSE NULL END) AS `{$safeAlias}`";
                } else {
                    $selectColumns[] = "{$function}(CASE WHEN `{$pivotColumn}` = ? THEN `{$aggregateColumn}` ELSE NULL END) AS `{$safeAlias}`";
                    $bindings[] = $value;
                }
            }

            // 构建查询
            $query = $this->getModel()->newQuery()
                ->selectRaw(implode(', ', $selectColumns), $bindings);

            // 添加分组
            foreach ($groupColumns as $col) {
                $query->groupByRaw("`{$table}`.`{$col}`");
            }

            return $query;
        });
    }

    /**
     * 注册常用聚合透视快捷方法
     */
    protected static function registerPivotAggregates(): void
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
             * @param string $pivotColumn 要旋转的列
             * @param array $values 要转换为列的唯一值
             * @param string $aggregateColumn 要聚合的列
             * @param string|array|null $groupBy 分组列
             * @return Builder
             *
             * @example
             * // 统计每个地区各状态订单数量
             * Order::query()->pivotCount('status', ['pending', 'paid', 'shipped'], 'id', 'region')->get();
             */
            Builder::macro($macroName, function (
                string $pivotColumn,
                array $values,
                string $aggregateColumn,
                string|array|null $groupBy = null
            ) use ($function): Builder {
                /** @var Builder $this */
                return $this->pivot($pivotColumn, $values, $aggregateColumn, $function, $groupBy);
            });
        }
    }

    /**
     * 注册 UNPIVOT 方法
     */
    protected static function registerUnpivot(): void
    {
        /**
         * UNPIVOT - 列转行
         *
         * 将多列转换为名称-值对的行格式
         *
         * @param array $columns 要转换的列 [['column' => 'jan_sales', 'alias' => '一月'], ...]
         * @param string $nameColumn 转换后的名称列
         * @param string $valueColumn 转换后的值列
         * @return Builder
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
        Builder::macro('unpivot', function (
            array $columns,
            string $nameColumn = 'attribute',
            string $valueColumn = 'value'
        ): Builder {
            /** @var Builder $this */
            $model = $this->getModel();
            $table = $model->getTable();
            $primaryKey = $model->getKeyName();

            // 构建 UNION ALL 查询
            $unionParts = [];
            $bindings = [];

            foreach ($columns as $index => $colConfig) {
                $column = $colConfig['column'];
                $alias = $colConfig['alias'] ?? $column;
                $bindings[] = $alias;

                $selectColumns = [
                    "`{$primaryKey}`",
                    "? AS `{$nameColumn}`",
                    "`{$column}` AS `{$valueColumn}`",
                ];

                $unionParts[] = "SELECT " . implode(', ', $selectColumns) . " FROM `{$table}`";
            }

            $unionSql = implode(' UNION ALL ', $unionParts);

            return $model->newQuery()
                ->fromRaw("({$unionSql}) as unpivoted", $bindings);
        });
    }

    /**
     * 注册交叉表（Cross Tabulation）
     */
    protected static function registerCrossTab(): void
    {
        /**
         * CROSS TAB - 交叉表查询（双维透视）
         *
         * 同时按行和列两个维度进行透视，生成矩阵式报表
         *
         * @param string $rowColumn 行维度列
         * @param string $colColumn 列维度列
         * @param string $aggregateColumn 聚合列
         * @param string $function 聚合函数
         * @param array $colValues 指定的列维度值（可选，自动探测时为null）
         * @return Builder
         *
         * @example
         * // 按地区和月份交叉统计销售额
         * Sales::query()
         *     ->crossTab('region', 'month', 'amount', 'SUM', ['01', '02', '03', '04'])
         *     ->get();
         * // 结果: region | jan | feb | mar | apr | total
         */
        Builder::macro('crossTab', function (
            string $rowColumn,
            string $colColumn,
            string $aggregateColumn,
            string $function = 'SUM',
            ?array $colValues = null
        ): Builder {
            /** @var Builder $this */
            $function = strtoupper($function);
            $model = $this->getModel();
            $table = $model->getTable();

            // 如果没有指定列值，使用 DISTINCT 查询（需要额外查询，这里简化处理）
            if ($colValues === null) {
                $colValues = $model->newQuery()
                    ->distinct()
                    ->pluck($colColumn)
                    ->toArray();
            }

            $selectColumns = ["`{$rowColumn}`"];
            $groupByColumns = ["`{$rowColumn}`"];
            $bindings = [];

            foreach ($colValues as $value) {
                $safeAlias = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $value);
                $safeAlias = is_numeric($safeAlias[0] ?? '') ? '_' . $safeAlias : $safeAlias;
                if (is_null($value)) {
                    $selectColumns[] = "{$function}(CASE WHEN `{$colColumn}` IS NULL THEN `{$aggregateColumn}` ELSE NULL END) AS `{$safeAlias}`";
                } else {
                    $selectColumns[] = "{$function}(CASE WHEN `{$colColumn}` = ? THEN `{$aggregateColumn}` ELSE NULL END) AS `{$safeAlias}`";
                    $bindings[] = $value;
                }
            }

            // 添加总计列
            $selectColumns[] = "{$function}(`{$aggregateColumn}`) AS `total`";

            return $model->newQuery()
                ->selectRaw(implode(', ', $selectColumns), $bindings)
                ->groupByRaw(implode(', ', $groupByColumns));
        });
    }
}
