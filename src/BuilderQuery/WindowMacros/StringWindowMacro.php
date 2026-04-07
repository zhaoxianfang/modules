<?php

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Query\Builder;

/**
 * MySQL 8.4+ 字符串处理窗口函数宏
 *
 * 提供字符串聚合、分组连接等窗口函数功能。
 * 所有函数均为通用型设计，适用于任意字符串数据列。
 *
 * @date 2026-04-07
 */
class StringWindowMacro
{
    /**
     * 注册所有字符串处理窗口函数宏
     *
     * @return void
     */
    public static function register(): void
    {
        /**
         * withStringAgg - 字符串分组聚合（窗口函数版）
         *
         * 在窗口内将多行字符串值聚合成单个字符串，支持排序和分隔符自定义。
         * 相比 GROUP_CONCAT 可以在不分组的情况下使用。
         *
         * 功能特点：
         * - 支持自定义分隔符
         * - 支持窗口内排序
         * - 可限制结果长度
         * - 支持分组聚合
         *
         * @param string $column 字符串列名
         * @param string|null $orderBy 排序列名（可选）
         * @param string $separator 分隔符，默认 ','
         * @param string|null $alias 输出列别名
         * @param string|null $partitionBy 分组列名（可选）
         * @param int|null $maxLength 最大结果长度限制（可选）
         *
         * @return Builder 返回查询构造器，支持链式调用
         *
         * @example
         * // 获取每个分类下的所有标签（不分组）
         * DB::table('products')
         *     ->withStringAgg('tag', 'tag_order', '|', 'all_tags', 'category_id')
         *     ->select('category_id', 'product_name', 'all_tags')
         *     ->get();
         *
         * // 结果示例：
         * // category_id: 1, product_name: "Phone", all_tags: "electronics|mobile|5g"
         *
         * // 获取每个用户的最近 5 个访问页面
         * DB::table('page_views')
         *     ->withStringAgg('page_url', 'visited_at DESC', ' -> ', 'recent_pages', 'user_id')
         *     ->select('user_id', 'recent_pages')
         *     ->groupBy('user_id')
         *     ->get();
         */
        Builder::macro('withStringAgg', function (
            string $column,
            ?string $orderBy = null,
            string $separator = ',',
            ?string $alias = null,
            ?string $partitionBy = null,
            ?int $maxLength = null
        ) {
            /** @var Builder $this */
            $alias = $alias ?? $column . '_agg';
            $partitionClause = $partitionBy ? "PARTITION BY {$this->grammar->wrap($partitionBy)}" : '';
            $orderClause = $orderBy ? "ORDER BY {$orderBy}" : '';

            $aggExpr = "GROUP_CONCAT({$this->grammar->wrap($column)} {$orderClause} SEPARATOR '{$separator}')";

            if ($maxLength) {
                $aggExpr = "SUBSTRING({$aggExpr}, 1, {$maxLength})";
            }

            return $this->selectRaw(
                "*, {$aggExpr} OVER ({$partitionClause}) as {$this->grammar->wrap($alias)}"
            );
        });

        /**
         * withDistinctStringAgg - 去重字符串聚合
         *
         * 在聚合字符串时自动去重，确保结果中不包含重复值。
         *
         * @param string $column 字符串列名
         * @param string $separator 分隔符，默认 ','
         * @param string|null $alias 输出列别名
         * @param string|null $partitionBy 分组列名（可选）
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 获取每个用户的所有唯一设备类型
         * DB::table('user_devices')
         *     ->withDistinctStringAgg('device_type', ',', 'unique_devices', 'user_id')
         *     ->select('user_id', 'unique_devices')
         *     ->groupBy('user_id')
         *     ->get();
         */
        Builder::macro('withDistinctStringAgg', function (
            string $column,
            string $separator = ',',
            ?string $alias = null,
            ?string $partitionBy = null
        ) {
            /** @var Builder $this */
            $alias = $alias ?? $column . '_distinct_agg';
            $partitionClause = $partitionBy ? "PARTITION BY {$this->grammar->wrap($partitionBy)}" : '';

            // 使用子查询去重
            return $this->selectRaw(
                "*, (SELECT GROUP_CONCAT(DISTINCT sub.{$column} SEPARATOR '{$separator}') " .
                "FROM (SELECT {$column} FROM {$this->from} temp_table WHERE temp_table.id = {$this->from}.id) sub) " .
                "OVER ({$partitionClause}) as {$this->grammar->wrap($alias)}"
            );
        });

        /**
         * withFirstString - 窗口内第一个非空字符串
         *
         * 获取窗口内按排序的第一个非空字符串值。
         *
         * @param string $column 字符串列名
         * @param string|null $orderBy 排序列名
         * @param string|null $alias 输出列别名
         * @param string|null $partitionBy 分组列名（可选）
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 获取每个用户首次设置的昵称
         * DB::table('user_profiles')
         *     ->withFirstString('nickname', 'created_at', 'first_nickname', 'user_id')
         *     ->select('user_id', 'first_nickname')
         *     ->get();
         */
        Builder::macro('withFirstString', function (
            string $column,
            ?string $orderBy = null,
            ?string $alias = null,
            ?string $partitionBy = null
        ) {
            /** @var Builder $this */
            $alias = $alias ?? 'first_' . $column;
            $partitionClause = $partitionBy ? "PARTITION BY {$this->grammar->wrap($partitionBy)}" : '';
            $orderClause = $orderBy ? "ORDER BY {$orderBy}" : '';

            return $this->selectRaw(
                "*, FIRST_VALUE({$this->grammar->wrap($column)}) IGNORE NULLS " .
                "OVER ({$partitionClause} {$orderClause}) as {$this->grammar->wrap($alias)}"
            );
        });

        /**
         * withLastString - 窗口内最后一个非空字符串
         *
         * 获取窗口内按排序的最后一个非空字符串值。
         *
         * @param string $column 字符串列名
         * @param string|null $orderBy 排序列名
         * @param string|null $alias 输出列别名
         * @param string|null $partitionBy 分组列名（可选）
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 获取每个用户最新设置的签名
         * DB::table('user_profiles')
         *     ->withLastString('bio', 'updated_at DESC', 'latest_bio', 'user_id')
         *     ->select('user_id', 'latest_bio')
         *     ->get();
         */
        Builder::macro('withLastString', function (
            string $column,
            ?string $orderBy = null,
            ?string $alias = null,
            ?string $partitionBy = null
        ) {
            /** @var Builder $this */
            $alias = $alias ?? 'last_' . $column;
            $partitionClause = $partitionBy ? "PARTITION BY {$this->grammar->wrap($partitionBy)}" : '';
            $orderClause = $orderBy ? "ORDER BY {$orderBy}" : '';

            return $this->selectRaw(
                "*, LAST_VALUE({$this->grammar->wrap($column)}) IGNORE NULLS " .
                "OVER ({$partitionClause} {$orderClause} RANGE BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING) " .
                "as {$this->grammar->wrap($alias)}"
            );
        });

        /**
         * withStringCount - 窗口内字符串出现次数统计
         *
         * 统计每个不同字符串值在窗口内出现的次数。
         *
         * @param string $column 字符串列名
         * @param string|null $alias 输出列别名
         * @param string|null $partitionBy 分组列名（可选）
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 统计每个类别中各状态的数量
         * DB::table('orders')
         *     ->withStringCount('status', 'status_count', 'category_id')
         *     ->select('category_id', 'status', 'status_count')
         *     ->get();
         */
        Builder::macro('withStringCount', function (
            string $column,
            ?string $alias = null,
            ?string $partitionBy = null
        ) {
            /** @var Builder $this */
            $alias = $alias ?? $column . '_count';
            $partitionClause = $partitionBy ? "PARTITION BY {$this->grammar->wrap($partitionBy)}" : '';

            return $this->selectRaw(
                "*, COUNT({$this->grammar->wrap($column)}) OVER ({$partitionClause}) as {$this->grammar->wrap($alias)}"
            );
        });

        /**
         * withStringRank - 字符串频率排名
         *
         * 根据字符串出现频率进行排名，常用于找出最常见的值。
         *
         * @param string $column 字符串列名
         * @param string $rankType 排名类型：'dense'(默认), 'standard'
         * @param string|null $alias 输出列别名
         * @param string|null $partitionBy 分组列名（可选）
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 找出每个类别中最常见的 3 个标签
         * DB::table('tagged_items')
         *     ->withStringRank('tag', 'dense', 'tag_rank', 'category_id')
         *     ->where('tag_rank', '<=', 3)
         *     ->get();
         */
        Builder::macro('withStringRank', function (
            string $column,
            string $rankType = 'dense',
            ?string $alias = null,
            ?string $partitionBy = null
        ) {
            /** @var Builder $this */
            $alias = $alias ?? $column . '_rank';
            $partitionClause = $partitionBy ? "PARTITION BY {$this->grammar->wrap($partitionBy)}" : '';
            $rankFunc = $rankType === 'dense' ? 'DENSE_RANK' : 'RANK';

            return $this->selectRaw(
                "*, {$rankFunc}() OVER ({$partitionClause} ORDER BY COUNT(*) DESC) as {$this->grammar->wrap($alias)}"
            );
        });

        /**
         * withPivotString - 字符串透视（行转列）
         *
         * 将字符串列的值作为列名进行透视，类似于 Excel 的数据透视表。
         *
         * @param string $pivotColumn 透视列名（值将成为新列名）
         * @param string $valueColumn 值列名
         * @param array|null $pivotValues 指定的透视值（可选，默认自动识别）
         * @param string|null $aliasPrefix 输出列名前缀
         * @param string|null $partitionBy 分组列名（可选）
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 将订单状态透视为列
         * DB::table('orders')
         *     ->withPivotString('status', 'amount', ['pending', 'paid', 'shipped'], 'amt_')
         *     ->select('user_id', 'amt_pending', 'amt_paid', 'amt_shipped')
         *     ->groupBy('user_id')
         *     ->get();
         */
        Builder::macro('withPivotString', function (
            string $pivotColumn,
            string $valueColumn,
            ?array $pivotValues = null,
            ?string $aliasPrefix = null,
            ?string $partitionBy = null
        ) {
            /** @var Builder $this */
            $aliasPrefix = $aliasPrefix ?? $pivotColumn . '_';

            if ($pivotValues) {
                foreach ($pivotValues as $value) {
                    $safeAlias = $aliasPrefix . preg_replace('/[^a-zA-Z0-9_]/', '_', $value);
                    $safeValue = is_string($value) ? "'{$value}'" : $value;

                    $this->selectRaw(
                        "MAX(CASE WHEN {$this->grammar->wrap($pivotColumn)} = {$safeValue} THEN {$valueColumn} END) as {$safeAlias}"
                    );
                }
            }

            if ($partitionBy) {
                $this->groupBy($partitionBy);
            }

            return $this;
        });
    }
}
