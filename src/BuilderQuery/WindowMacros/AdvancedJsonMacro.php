<?php

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * MySQL 8.4+ JSON 高级操作宏
 *
 * 提供丰富的 JSON 字段查询和操作功能：
 * - JSON 路径查询和提取
 * - JSON 数组操作
 * - JSON 对象操作
 * - JSON 聚合
 * - JSON 搜索和过滤
 *
 * @package zxf\Modules\BuilderQuery\WindowMacros
 * @version 1.0.0
 * @requires MySQL 8.4+
 */
class AdvancedJsonMacro
{
    /**
     * 注册所有 JSON 宏
     *
     * @return void
     */
    public static function register(): void
    {
        self::registerJsonPathQueries();
        self::registerJsonArrayOperations();
        self::registerJsonObjectOperations();
        self::registerJsonSearchOperations();
        self::registerJsonAggregation();
    }

    /**
     * 注册 JSON 路径查询
     */
    protected static function registerJsonPathQueries(): void
    {
        /**
         * 使用 JSON Path 提取嵌套 JSON 值
         *
         * MySQL 8.4+ 支持 JSON Path 表达式，比 JSON_EXTRACT 更强大
         *
         * @param string $column JSON 列名
         * @param string $path JSON Path 表达式，例如: '$.name', '$.items[0].price'
         * @param string $alias 结果列别名
         * @param mixed $default 默认值（提取失败时返回）
         * @return Builder
         *
         * @example
         * // 提取嵌套字段
         * User::query()->jsonPath('settings', '$.notifications.email', 'email_enabled', false)->get();
         *
         * // 提取数组元素
         * Order::query()->jsonPath('items', '$[0].product_name', 'first_product')->get();
         *
         * // 提取多级嵌套
         * Config::query()->jsonPath('data', '$.database.connections.mysql.host', 'db_host')->get();
         */
        Builder::macro('jsonPath', function (
            string $column,
            string $path,
            string $alias,
            mixed $default = null
        ): Builder {
            /** @var Builder $this */
            $defaultExpr = $default !== null
                ? (is_string($default) ? "'{$default}'" : $default)
                : 'NULL';

            $expr = "JSON_UNQUOTE(JSON_EXTRACT(`{$column}`, '{$path}'))";

            if ($default !== null) {
                $expr = "COALESCE({$expr}, {$defaultExpr})";
            }

            return $this->selectRaw("{$expr} AS `{$alias}`");
        });

        /**
         * 提取 JSON 值并转换为指定类型
         *
         * @param string $column JSON 列名
         * @param string $path JSON Path
         * @param string $type 目标类型: string|int|float|bool|datetime|date|time
         * @param string $alias 结果列别名
         * @param mixed $default 默认值
         * @return Builder
         *
         * @example
         * // 提取整数
         * Product::query()->jsonExtract('metadata', '$.stock', 'int', 'stock_qty', 0)->get();
         *
         * // 提取日期时间
         * Event::query()->jsonExtract('schedule', '$.start_time', 'datetime', 'starts_at')->get();
         */
        Builder::macro('jsonExtract', function (
            string $column,
            string $path,
            string $type = 'string',
            string $alias = '',
            mixed $default = null
        ): Builder {
            /** @var Builder $this */
            $alias = $alias ?: str_replace(['.', '[', ']'], '_', trim($path, '$.'));

            // 基础提取表达式
            $extractExpr = "JSON_UNQUOTE(JSON_EXTRACT(`{$column}`, '{$path}'))";

            // 根据类型添加转换
            $expr = match (strtolower($type)) {
                'int', 'integer' => "CAST({$extractExpr} AS SIGNED)",
                'float', 'double', 'decimal' => "CAST({$extractExpr} AS DECIMAL(15,4))",
                'bool', 'boolean' => "JSON_EXTRACT(`{$column}`, '{$path}') = true",
                'datetime' => "STR_TO_DATE({$extractExpr}, '%Y-%m-%dT%H:%i:%s')",
                'date' => "STR_TO_DATE({$extractExpr}, '%Y-%m-%d')",
                'time' => "STR_TO_DATE({$extractExpr}, '%H:%i:%s')",
                'json' => "JSON_EXTRACT(`{$column}`, '{$path}')",
                default => $extractExpr,
            };

            if ($default !== null) {
                $defaultExpr = is_string($default) ? "'{$default}'" : $default;
                $expr = "COALESCE({$expr}, {$defaultExpr})";
            }

            return $this->selectRaw("{$expr} AS `{$alias}`");
        });

        /**
         * 检查 JSON Path 是否存在
         *
         * @param string $column JSON 列名
         * @param string $path JSON Path
         * @param string $boolean 连接条件: and|or
         * @return Builder
         *
         * @example
         * // 查找有邮箱设置的用户
         * User::query()->whereJsonPathExists('settings', '$.email')->get();
         *
         * // 查找有嵌套配置的记录
         * Config::query()->whereJsonPathExists('data', '$.database.connections.mysql')->get();
         */
        Builder::macro('whereJsonPathExists', function (
            string $column,
            string $path,
            string $boolean = 'and'
        ): Builder {
            /** @var Builder $this */
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

            return $this->{$method}(
                "JSON_CONTAINS_PATH(`{$column}`, 'one', '{$path}')"
            );
        });

        /**
         * 检查 JSON Path 是否不存在
         *
         * @param string $column JSON 列名
         * @param string $path JSON Path
         * @param string $boolean 连接条件
         * @return Builder
         */
        Builder::macro('whereJsonPathNotExists', function (
            string $column,
            string $path,
            string $boolean = 'and'
        ): Builder {
            /** @var Builder $this */
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

            return $this->{$method}(
                "NOT JSON_CONTAINS_PATH(`{$column}`, 'one', '{$path}')"
            );
        });
    }

    /**
     * 注册 JSON 数组操作
     */
    protected static function registerJsonArrayOperations(): void
    {
        /**
         * 检查 JSON 数组是否包含指定值
         *
         * @param string $column JSON 列名
         * @param mixed $value 要查找的值
         * @param string $path JSON Path（如果是嵌套数组）
         * @param string $boolean 连接条件
         * @return Builder
         *
         * @example
         * // 查找标签包含 'php' 的文章
         * Article::query()->whereJsonArrayContains('tags', 'php')->get();
         *
         * // 查找嵌套数组
         * Data::query()->whereJsonArrayContains('items', 123, '$.product_ids')->get();
         */
        Builder::macro('whereJsonArrayContains', function (
            string $column,
            mixed $value,
            ?string $path = null,
            string $boolean = 'and'
        ): Builder {
            /** @var Builder $this */
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
            $jsonValue = is_string($value) ? '"' . $value . '"' : json_encode($value);
            $target = $path ? "JSON_EXTRACT(`{$column}`, '{$path}')" : "`{$column}`";

            return $this->{$method}(
                "JSON_CONTAINS({$target}, {$jsonValue})"
            );
        });

        /**
         * 检查 JSON 数组是否包含任意一个指定值
         *
         * @param string $column JSON 列名
         * @param array $values 要查找的值数组
         * @param string $path JSON Path
         * @param string $boolean 连接条件
         * @return Builder
         *
         * @example
         * // 查找包含任意指定标签的文章
         * Article::query()->whereJsonArrayContainsAny('tags', ['php', 'laravel', 'mysql'])->get();
         */
        Builder::macro('whereJsonArrayContainsAny', function (
            string $column,
            array $values,
            ?string $path = null,
            string $boolean = 'and'
        ): Builder {
            /** @var Builder $this */
            $method = $boolean === 'or' ? 'orWhere' : 'where';
            $target = $path ? "JSON_EXTRACT(`{$column}`, '{$path}')" : "`{$column}`";

            $conditions = array_map(function ($value) use ($target) {
                $jsonValue = is_string($value) ? '"' . $value . '"' : json_encode($value);
                return "JSON_CONTAINS({$target}, {$jsonValue})";
            }, $values);

            $sql = '(' . implode(' OR ', $conditions) . ')';

            return $this->{$method}(DB::raw($sql));
        });

        /**
         * 检查 JSON 数组是否包含所有指定值
         *
         * @param string $column JSON 列名
         * @param array $values 要查找的值数组
         * @param string $path JSON Path
         * @param string $boolean 连接条件
         * @return Builder
         *
         * @example
         * // 查找同时包含多个标签的文章
         * Article::query()->whereJsonArrayContainsAll('tags', ['php', 'mysql'])->get();
         */
        Builder::macro('whereJsonArrayContainsAll', function (
            string $column,
            array $values,
            ?string $path = null,
            string $boolean = 'and'
        ): Builder {
            /** @var Builder $this */
            $method = $boolean === 'or' ? 'orWhere' : 'where';
            $target = $path ? "JSON_EXTRACT(`{$column}`, '{$path}')" : "`{$column}`";

            $conditions = array_map(function ($value) use ($target) {
                $jsonValue = is_string($value) ? '"' . $value . '"' : json_encode($value);
                return "JSON_CONTAINS({$target}, {$jsonValue})";
            }, $values);

            $sql = '(' . implode(' AND ', $conditions) . ')';

            return $this->{$method}(DB::raw($sql));
        });

        /**
         * 获取 JSON 数组长度
         *
         * @param string $column JSON 列名
         * @param string $path JSON Path（如果是嵌套数组）
         * @param string $alias 结果列别名
         * @return Builder
         *
         * @example
         * // 获取标签数量
         * Article::query()->jsonArrayLength('tags', null, 'tag_count')->get();
         *
         * // 获取嵌套数组长度
         * Data::query()->jsonArrayLength('data', '$.items', 'item_count')->get();
         */
        Builder::macro('jsonArrayLength', function (
            string $column,
            ?string $path = null,
            string $alias = 'array_length'
        ): Builder {
            /** @var Builder $this */
            $target = $path
                ? "JSON_EXTRACT(`{$column}`, '{$path}')"
                : "`{$column}`";

            return $this->selectRaw("JSON_LENGTH({$target}) AS `{$alias}`");
        });

        /**
         * 按 JSON 数组长度筛选
         *
         * @param string $column JSON 列名
         * @param int $count 数组长度
         * @param string $operator 比较运算符: =|>|<|>=|<=
         * @param string $path JSON Path
         * @param string $boolean 连接条件
         * @return Builder
         *
         * @example
         * // 查找有3个标签的文章
         * Article::query()->whereJsonArrayLength('tags', 3)->get();
         *
         * // 查找有多个标签的文章
         * Article::query()->whereJsonArrayLength('tags', 5, '>=')->get();
         */
        Builder::macro('whereJsonArrayLength', function (
            string $column,
            int $count,
            string $operator = '=',
            ?string $path = null,
            string $boolean = 'and'
        ): Builder {
            /** @var Builder $this */
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
            $target = $path
                ? "JSON_EXTRACT(`{$column}`, '{$path}')"
                : "`{$column}`";

            return $this->{$method}(
                "JSON_LENGTH({$target}) {$operator} {$count}"
            );
        });

        /**
         * 追加值到 JSON 数组
         *
         * @param string $column JSON 列名
         * @param mixed $value 要追加的值
         * @param string $path JSON Path（如果是嵌套数组）
         * @return int 影响行数
         *
         * @example
         * // 添加标签
         * Article::query()->where('id', 1)->appendToJsonArray('tags', 'new-tag')->update();
         */
        Builder::macro('appendToJsonArray', function (
            string $column,
            mixed $value,
            ?string $path = null
        ) {
            /** @var Builder $this */
            $jsonValue = is_string($value) ? '"' . $value . '"' : json_encode($value);

            if ($path) {
                $expr = "JSON_ARRAY_APPEND(`{$column}`, '{$path}', {$jsonValue})";
            } else {
                $expr = "JSON_ARRAY_APPEND(`{$column}`, '$', {$jsonValue})";
            }

            return $this->update([$column => DB::raw($expr)]);
        });

        /**
         * 从 JSON 数组中移除值
         *
         * @param string $column JSON 列名
         * @param mixed $value 要移除的值
         * @param string $path JSON Path
         * @return int 影响行数
         *
         * @example
         * // 移除标签
         * Article::query()->where('id', 1)->removeFromJsonArray('tags', 'old-tag')->update();
         */
        Builder::macro('removeFromJsonArray', function (
            string $column,
            mixed $value,
            ?string $path = null
        ) {
            /** @var Builder $this */
            $table = $this->getModel()->getTable();
            $primaryKey = $this->getModel()->getKeyName();

            // MySQL 8.4+ 不支持直接删除数组元素，需要使用复杂表达式
            // 这里使用 JSON_REMOVE 通过索引删除
            $jsonValue = is_string($value) ? '"' . $value . '"' : json_encode($value);
            $target = $path ? "JSON_EXTRACT(`{$column}`, '{$path}')" : "`{$column}`";

            // 使用子查询找到索引并删除
            $expr = "(
                SELECT JSON_REMOVE(`{$column}`, CONCAT('$[', idx, ']'))
                FROM (
                    SELECT JSON_SEARCH(`{$column}`, 'one', {$jsonValue}) as idx_path,
                           SUBSTRING_INDEX(JSON_SEARCH(`{$column}`, 'one', {$jsonValue}), '[', -1) as idx
                    FROM `{$table}`
                    WHERE {$primaryKey} = {$this->getModel()->getKey()}
                ) AS t
                WHERE idx_path IS NOT NULL
            )";

            return $this->update([$column => DB::raw($expr)]);
        });
    }

    /**
     * 注册 JSON 对象操作
     */
    protected static function registerJsonObjectOperations(): void
    {
        /**
         * 设置 JSON 对象的键值
         *
         * @param string $column JSON 列名
         * @param string $path JSON Path
         * @param mixed $value 要设置的值
         * @param bool $insert 如果键不存在是否插入（true=插入，false=替换现有）
         * @return int 影响行数
         *
         * @example
         * // 更新设置
         * User::query()->where('id', 1)->setJsonValue('settings', '$.theme', 'dark')->update();
         *
         * // 插入新键
         * User::query()->where('id', 1)->setJsonValue('settings', '$.notifications.push', true, true)->update();
         */
        Builder::macro('setJsonValue', function (
            string $column,
            string $path,
            mixed $value,
            bool $insert = false
        ) {
            /** @var Builder $this */
            $jsonValue = json_encode($value);
            $function = $insert ? 'JSON_INSERT' : 'JSON_SET';

            $expr = "{$function}(`{$column}`, '{$path}', {$jsonValue})";

            return $this->update([$column => DB::raw($expr)]);
        });

        /**
         * 删除 JSON 对象的键
         *
         * @param string $column JSON 列名
         * @param string|array $paths 要删除的路径或路径数组
         * @return int 影响行数
         *
         * @example
         * // 删除单个键
         * User::query()->where('id', 1)->removeJsonKey('settings', '$.temp_data')->update();
         *
         * // 删除多个键
         * User::query()->where('id', 1)->removeJsonKey('settings', ['$.cache', '$.temp'])->update();
         */
        Builder::macro('removeJsonKey', function (
            string $column,
            string|array $paths
        ) {
            /** @var Builder $this */
            $paths = is_array($paths) ? $paths : [$paths];

            $expr = "`{$column}`";
            foreach ($paths as $path) {
                $expr = "JSON_REMOVE({$expr}, '{$path}')";
            }

            return $this->update([$column => DB::raw($expr)]);
        });

        /**
         * 合并 JSON 对象
         *
         * @param string $column JSON 列名
         * @param array $data 要合并的数据
         * @param string|null $path JSON Path（如果是嵌套对象）
         * @return int 影响行数
         *
         * @example
         * // 合并设置
         * User::query()->where('id', 1)->mergeJson('settings', ['theme' => 'dark', 'lang' => 'zh'])->update();
         */
        Builder::macro('mergeJson', function (
            string $column,
            array $data,
            ?string $path = null
        ) {
            /** @var Builder $this */
            $jsonData = json_encode($data);

            if ($path) {
                $expr = "JSON_MERGE_PATCH(JSON_EXTRACT(`{$column}`, '{$path}'), {$jsonData})";
                // 需要更新嵌套路径的值
                return $this->setJsonValue($column, $path, $data);
            }

            $expr = "JSON_MERGE_PATCH(`{$column}`, {$jsonData})";

            return $this->update([$column => DB::raw($expr)]);
        });

        /**
         * 获取 JSON 对象的所有键
         *
         * @param string $column JSON 列名
         * @param string $path JSON Path
         * @param string $alias 结果列别名
         * @return Builder
         *
         * @example
         * // 获取所有设置键
         * User::query()->jsonKeys('settings', null, 'setting_keys')->get();
         */
        Builder::macro('jsonKeys', function (
            string $column,
            ?string $path = null,
            string $alias = 'json_keys'
        ): Builder {
            /** @var Builder $this */
            $target = $path
                ? "JSON_EXTRACT(`{$column}`, '{$path}')"
                : "`{$column}`";

            return $this->selectRaw("JSON_KEYS({$target}) AS `{$alias}`");
        });
    }

    /**
     * 注册 JSON 搜索操作
     */
    protected static function registerJsonSearchOperations(): void
    {
        /**
         * 在 JSON 中搜索值并返回路径
         *
         * @param string $column JSON 列名
         * @param string $search 搜索的值
         * @param string $mode 搜索模式: 'one' 返回第一个匹配, 'all' 返回所有匹配
         * @param string $path 起始路径
         * @param string $alias 结果列别名
         * @return Builder
         *
         * @example
         * // 搜索值的位置
         * Data::query()->jsonSearch('content', 'target_value', 'all', '$', 'found_paths')->get();
         */
        Builder::macro('jsonSearch', function (
            string $column,
            string $search,
            string $mode = 'one',
            string $path = '$',
            string $alias = 'search_result'
        ): Builder {
            /** @var Builder $this */
            $searchValue = json_encode($search);

            return $this->selectRaw(
                "JSON_SEARCH(`{$column}`, '{$mode}', {$searchValue}, NULL, '{$path}') AS `{$alias}`"
            );
        });

        /**
         * 按 JSON 值筛选（支持通配符）
         *
         * @param string $column JSON 列名
         * @param string $pattern 搜索模式（支持 % 通配符）
         * @param string $path JSON Path
         * @param string $boolean 连接条件
         * @return Builder
         *
         * @example
         * // 搜索包含特定文本的 JSON
         * Article::query()->whereJsonLike('metadata', '%keyword%', '$.description')->get();
         */
        Builder::macro('whereJsonLike', function (
            string $column,
            string $pattern,
            ?string $path = null,
            string $boolean = 'and'
        ): Builder {
            /** @var Builder $this */
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
            $target = $path
                ? "JSON_UNQUOTE(JSON_EXTRACT(`{$column}`, '{$path}'))"
                : "JSON_UNQUOTE(`{$column}`)";

            return $this->{$method}("{$target} LIKE '{$pattern}'");
        });
    }

    /**
     * 注册 JSON 聚合
     */
    protected static function registerJsonAggregation(): void
    {
        /**
         * 将多行聚合成 JSON 数组
         *
         * @param string $column 要聚合的列
         * @param string $alias 结果列别名
         * @param string $orderBy 排序字段
         * @param string $direction 排序方向
         * @return Builder
         *
         * @example
         * // 将商品ID聚合成数组
         * Order::query()->select('user_id')
         *     ->jsonArrayAgg('product_id', 'products')
         *     ->groupBy('user_id')
         *     ->get();
         */
        Builder::macro('jsonArrayAgg', function (
            string $column,
            string $alias = 'json_array',
            ?string $orderBy = null,
            string $direction = 'asc'
        ): Builder {
            /** @var Builder $this */
            if ($orderBy) {
                $direction = strtoupper($direction);
                $expr = "JSON_ARRAYAGG(`{$column}` ORDER BY `{$orderBy}` {$direction})";
            } else {
                $expr = "JSON_ARRAYAGG(`{$column}`)";
            }

            return $this->selectRaw("{$expr} AS `{$alias}`");
        });

        /**
         * 将多行聚合成 JSON 对象
         *
         * @param string $keyColumn 作为键的列
         * @param string $valueColumn 作为值的列
         * @param string $alias 结果列别名
         * @return Builder
         *
         * @example
         * // 将配置聚合成对象
         * Config::query()->jsonObjectAgg('key', 'value', 'config_object')->first();
         */
        Builder::macro('jsonObjectAgg', function (
            string $keyColumn,
            string $valueColumn,
            string $alias = 'json_object'
        ): Builder {
            /** @var Builder $this */
            $expr = "JSON_OBJECTAGG(`{$keyColumn}`, `{$valueColumn}`)";

            return $this->selectRaw("{$expr} AS `{$alias}`");
        });

        /**
         * 将行聚合成 JSON 对象数组
         *
         * @param array $columns 要包含的列
         * @param string $alias 结果列别名
         * @return Builder
         *
         * @example
         * // 聚合完整行数据
         * Order::query()->select('user_id')
         *     ->jsonRowAgg(['id', 'product_name', 'price'], 'items')
         *     ->groupBy('user_id')
         *     ->get();
         */
        Builder::macro('jsonRowAgg', function (
            array $columns,
            string $alias = 'json_rows'
        ): Builder {
            /** @var Builder $this */
            $jsonObjectParts = [];
            foreach ($columns as $col) {
                $jsonObjectParts[] = "'{$col}'";
                $jsonObjectParts[] = "`{$col}`";
            }
            $jsonObjectExpr = 'JSON_OBJECT(' . implode(', ', $jsonObjectParts) . ')';

            $expr = "JSON_ARRAYAGG({$jsonObjectExpr})";

            return $this->selectRaw("{$expr} AS `{$alias}`");
        });
    }
}
