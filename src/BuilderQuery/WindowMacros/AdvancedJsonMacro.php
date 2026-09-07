<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use zxf\Modules\BuilderQuery\Concerns\SqlSecurity;

/**
 * MySQL 8.0+ JSON 高级操作宏
 *
 * 提供丰富的 JSON 字段查询和操作功能：
 * - JSON 路径查询和提取
 * - JSON 数组操作
 * - JSON 对象操作
 * - JSON 聚合
 * - JSON 搜索和过滤
 *
 * 安全性：列名经白名单校验，JSON Path 经格式校验，字面量与默认值的注入风险
 * 通过参数绑定（?）消除，不再裸拼字符串字面量。
 *
 * @package zxf\Modules\BuilderQuery\WindowMacros
 * @version 2.0.0
 * @requires MySQL 8.0+
 */
class AdvancedJsonMacro
{
    use SqlSecurity;

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
         * MySQL 8.0+ 支持 JSON Path 表达式，功能丰富
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
            AdvancedJsonMacro::assertMysql($this, 'jsonPath');
            $column = AdvancedJsonMacro::assertValidIdentifier($column, 'column');
            $path = AdvancedJsonMacro::assertJsonPath($path);
            $alias = AdvancedJsonMacro::assertValidIdentifier($alias, 'alias');
            $wrappedColumn = AdvancedJsonMacro::wrapIdentifier($this, $column);
            $wrappedAlias = AdvancedJsonMacro::wrapIdentifier($this, $alias);

            // 默认值走参数绑定，避免字符串字面量注入
            $expr = "JSON_UNQUOTE(JSON_EXTRACT({$wrappedColumn}, ?))";
            $bindings = [$path];

            if ($default !== null) {
                $expr = "COALESCE({$expr}, ?)";
                $bindings[] = $default;
            }

            return $this->selectRaw("{$expr} AS {$wrappedAlias}", $bindings);
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
            AdvancedJsonMacro::assertMysql($this, 'jsonExtract');
            $column = AdvancedJsonMacro::assertValidIdentifier($column, 'column');
            $path = AdvancedJsonMacro::assertJsonPath($path);
            $wrappedColumn = AdvancedJsonMacro::wrapIdentifier($this, $column);

            $alias = $alias ?: preg_replace('/[^a-zA-Z0-9_]/', '_', trim($path, '$.'));
            $alias = AdvancedJsonMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = AdvancedJsonMacro::wrapIdentifier($this, $alias);

            // 基础提取表达式（路径走参数绑定）
            $extractExpr = "JSON_UNQUOTE(JSON_EXTRACT({$wrappedColumn}, ?))";
            $bindings = [$path];

            // 根据类型添加转换
            $expr = match (strtolower($type)) {
                'int', 'integer' => "CAST({$extractExpr} AS SIGNED)",
                'float', 'double', 'decimal' => "CAST({$extractExpr} AS DECIMAL(15,4))",
                'bool', 'boolean' => "JSON_EXTRACT({$wrappedColumn}, ?) = true",
                'datetime' => "STR_TO_DATE({$extractExpr}, '%Y-%m-%dT%H:%i:%s')",
                'date' => "STR_TO_DATE({$extractExpr}, '%Y-%m-%d')",
                'time' => "STR_TO_DATE({$extractExpr}, '%H:%i:%s')",
                'json' => "JSON_EXTRACT({$wrappedColumn}, ?)",
                default => $extractExpr,
            };

            if ($default !== null) {
                $expr = "COALESCE({$expr}, ?)";
                $bindings[] = $default;
            }

            return $this->selectRaw("{$expr} AS {$wrappedAlias}", $bindings);
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
            AdvancedJsonMacro::assertMysql($this, 'whereJsonPathExists');
            $column = AdvancedJsonMacro::assertValidIdentifier($column, 'column');
            $path = AdvancedJsonMacro::assertJsonPath($path);
            $wrappedColumn = AdvancedJsonMacro::wrapIdentifier($this, $column);
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

            return $this->{$method}(
                "JSON_CONTAINS_PATH({$wrappedColumn}, 'one', ?)",
                [$path]
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
            AdvancedJsonMacro::assertMysql($this, 'whereJsonPathNotExists');
            $column = AdvancedJsonMacro::assertValidIdentifier($column, 'column');
            $path = AdvancedJsonMacro::assertJsonPath($path);
            $wrappedColumn = AdvancedJsonMacro::wrapIdentifier($this, $column);
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

            return $this->{$method}(
                "NOT JSON_CONTAINS_PATH({$wrappedColumn}, 'one', ?)",
                [$path]
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
            AdvancedJsonMacro::assertMysql($this, 'whereJsonArrayContains');
            $wrappedColumn = AdvancedJsonMacro::wrapIdentifier($this, AdvancedJsonMacro::assertValidIdentifier($column, 'column'));
            [$target, $bindings] = AdvancedJsonMacro::buildJsonTarget($wrappedColumn, $path);
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
            // 字面量走参数绑定（?），避免字符串拼接导致的注入
            $bindings[] = AdvancedJsonMacro::toJsonLiteral($value);

            return $this->{$method}(
                "JSON_CONTAINS({$target}, ?)",
                $bindings
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
            AdvancedJsonMacro::assertMysql($this, 'whereJsonArrayContainsAny');
            $wrappedColumn = AdvancedJsonMacro::wrapIdentifier($this, AdvancedJsonMacro::assertValidIdentifier($column, 'column'));
            [$target, $bindings] = AdvancedJsonMacro::buildJsonTarget($wrappedColumn, $path);
            $method = $boolean === 'or' ? 'orWhere' : 'where';

            $jsonBindings = array_map(
                fn ($value) => AdvancedJsonMacro::toJsonLiteral($value),
                $values
            );
            $placeholders = implode(', ', array_fill(0, count($values), '?'));
            $sql = "JSON_CONTAINS({$target}, JSON_ARRAY({$placeholders}))";

            return $this->{$method.'Raw'}($sql, [...$bindings, ...$jsonBindings]);
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
            AdvancedJsonMacro::assertMysql($this, 'whereJsonArrayContainsAll');
            $wrappedColumn = AdvancedJsonMacro::wrapIdentifier($this, AdvancedJsonMacro::assertValidIdentifier($column, 'column'));
            [$target, $bindings] = AdvancedJsonMacro::buildJsonTarget($wrappedColumn, $path);
            $method = $boolean === 'or' ? 'orWhere' : 'where';

            $jsonBindings = array_map(
                fn ($value) => AdvancedJsonMacro::toJsonLiteral($value),
                $values
            );
            $placeholders = implode(', ', array_fill(0, count($values), '?'));
            $sql = "JSON_CONTAINS({$target}, JSON_ARRAY({$placeholders}))";

            return $this->{$method.'Raw'}($sql, [...$bindings, ...$jsonBindings]);
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
            AdvancedJsonMacro::assertMysql($this, 'jsonArrayLength');
            $wrappedColumn = AdvancedJsonMacro::wrapIdentifier($this, AdvancedJsonMacro::assertValidIdentifier($column, 'column'));
            [$target, $bindings] = AdvancedJsonMacro::buildJsonTarget($wrappedColumn, $path);
            $alias = AdvancedJsonMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = AdvancedJsonMacro::wrapIdentifier($this, $alias);

            return $this->selectRaw("JSON_LENGTH({$target}) AS {$wrappedAlias}", $bindings);
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
            AdvancedJsonMacro::assertMysql($this, 'whereJsonArrayLength');
            $operator = AdvancedJsonMacro::assertOperator($operator);
            $wrappedColumn = AdvancedJsonMacro::wrapIdentifier($this, AdvancedJsonMacro::assertValidIdentifier($column, 'column'));
            [$target, $bindings] = AdvancedJsonMacro::buildJsonTarget($wrappedColumn, $path);
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

            return $this->{$method}(
                "JSON_LENGTH({$target}) {$operator} {$count}",
                $bindings
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
            AdvancedJsonMacro::assertMysql($this, 'appendToJsonArray');
            $wrappedColumn = AdvancedJsonMacro::wrapIdentifier($this, AdvancedJsonMacro::assertValidIdentifier($column, 'column'));
            $jsonValue = AdvancedJsonMacro::toJsonLiteral($value);

            if ($path !== null) {
                $path = AdvancedJsonMacro::assertJsonPath($path);
                $expr = "JSON_ARRAY_APPEND({$wrappedColumn}, ?, ?)";
                $bindings = [$path, $jsonValue];
            } else {
                $expr = "JSON_ARRAY_APPEND({$wrappedColumn}, '$', ?)";
                $bindings = [$jsonValue];
            }

            // UPDATE 语句编译后的绑定顺序为 values(SET 段非 Expression 值) → join → where，
            // 因此 SET 段 RAW 表达式的绑定须借道 join 通道，才能正确排在 where 绑定之前。
            $this->addBinding($bindings, 'join');

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
            AdvancedJsonMacro::assertMysql($this, 'removeFromJsonArray');
            $table = $this->getModel()->getTable();
            $primaryKey = $this->getModel()->getKeyName();
            $wrappedColumn = AdvancedJsonMacro::wrapIdentifier($this, AdvancedJsonMacro::assertValidIdentifier($column, 'column'));
            $wrappedTable = AdvancedJsonMacro::wrapIdentifier($this, $table);
            $wrappedTableInner = AdvancedJsonMacro::wrapIdentifier($this, $table);
            $wrappedPk = AdvancedJsonMacro::wrapIdentifier($this, AdvancedJsonMacro::assertValidIdentifier($primaryKey, 'primaryKey'));
            $jsonValue = AdvancedJsonMacro::toJsonLiteral($value);

            $target = $path !== null
                ? "JSON_EXTRACT({$wrappedColumn}, ?)"
                : $wrappedColumn;
            $pathBinding = $path !== null ? [AdvancedJsonMacro::assertJsonPath($path)] : [];

            // 使用关联子查询找到索引并删除，支持批量更新
            $expr = "(
                SELECT JSON_REMOVE({$wrappedTable}.{$wrappedColumn}, CONCAT('$[', idx, ']'))
                FROM (
                    SELECT JSON_SEARCH({$wrappedTable}.{$wrappedColumn}, 'one', ?) as idx_path,
                           SUBSTRING_INDEX(JSON_SEARCH({$wrappedTable}.{$wrappedColumn}, 'one', ?), '[', -1) as idx
                    FROM {$wrappedTableInner} AS inner_t
                    WHERE inner_t.{$wrappedPk} = {$wrappedTable}.{$wrappedPk}
                ) AS t
                WHERE t.idx_path IS NOT NULL
            )";

            // UPDATE 语句编译后的绑定顺序为 values(SET 段非 Expression 值) → join → where，
            // 因此 SET 段 RAW 表达式的绑定须借道 join 通道，才能正确排在 where 绑定之前。
            $this->addBinding([...$pathBinding, $jsonValue, $jsonValue], 'join');

            return $this->update([$column => DB::raw($expr)]);
        });
    }

    /**
     * 构建 JSON 提取目标片段（列名 + 可选路径）。
     *
     * @param string $wrappedColumn 已 wrap 的安全列名
     * @param string|null $path JSON Path（将用于参数绑定）
     * @return array{0: string, 1: array} [目标 SQL 片段, 绑定数组]
     */
    /**
     * 构建 JSON 查询目标片段
     *
     * 注意：本方法在 Builder::macro 闭包内通过 `AdvancedJsonMacro::buildJsonTarget(...)`
     * 显式类名调用，必须声明为 public（Laravel Macroable 会将闭包重绑到 Builder 作用域，
     * 匿名闭包不在本类内部，无法访问 protected/private 成员）。
     *
     * @param string $wrappedColumn 已 wrapIdentifier 的列名
     * @param string|null $path JSON Path（将用于参数绑定）
     * @return array{0: string, 1: array} [目标 SQL 片段, 绑定数组]
     */
    public static function buildJsonTarget(string $wrappedColumn, ?string $path): array
    {
        if ($path !== null) {
            $path = self::assertJsonPath($path);
            return ["JSON_EXTRACT({$wrappedColumn}, ?)", [$path]];
        }

        return [$wrappedColumn, []];
    }

    /**
     * 将 PHP 值编码为 JSON 字面量，用于 JSON 函数的参数绑定
     *
     * JSON_CONTAINS / JSON_ARRAY / JSON_SEARCH 等 MySQL JSON 函数的目标/搜索值
     * 必须是一个 JSON 文本（字符串需带引号），因此统一用 json_encode 生成合法字面量，
     * 并走参数绑定（?）以避免字符串拼接导致的注入。
     *
     * 该方法缺失会导致所有 JSON 宏调用抛「Call to undefined method」致命错误。
     *
     * @param mixed $value
     * @return string JSON 编码后的字面量
     * @throws \JsonException
     */
    public static function toJsonLiteral(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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
            AdvancedJsonMacro::assertMysql($this, 'setJsonValue');
            $wrappedColumn = AdvancedJsonMacro::wrapIdentifier($this, AdvancedJsonMacro::assertValidIdentifier($column, 'column'));
            $path = AdvancedJsonMacro::assertJsonPath($path);
            $jsonValue = AdvancedJsonMacro::toJsonLiteral($value);
            $function = $insert ? 'JSON_INSERT' : 'JSON_SET';

            $expr = "{$function}({$wrappedColumn}, ?, ?)";

            // UPDATE 语句编译后的绑定顺序为 values(SET 段非 Expression 值) → join → where，
            // 因此 SET 段 RAW 表达式的绑定须借道 join 通道，才能正确排在 where 绑定之前。
            $this->addBinding([$path, $jsonValue], 'join');

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
            AdvancedJsonMacro::assertMysql($this, 'removeJsonKey');
            $wrappedColumn = AdvancedJsonMacro::wrapIdentifier($this, AdvancedJsonMacro::assertValidIdentifier($column, 'column'));
            $paths = is_array($paths) ? $paths : [$paths];
            $pathBindings = [];
            foreach ($paths as &$path) {
                $path = AdvancedJsonMacro::assertJsonPath($path);
                $pathBindings[] = $path;
            }
            unset($path);

            $expr = $wrappedColumn;
            foreach ($paths as $path) {
                $expr = "JSON_REMOVE({$expr}, ?)";
            }

            // UPDATE 语句编译后的绑定顺序为 values(SET 段非 Expression 值) → join → where，
            // 因此 SET 段 RAW 表达式的绑定须借道 join 通道，才能正确排在 where 绑定之前。
            $this->addBinding($pathBindings, 'join');

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
            AdvancedJsonMacro::assertMysql($this, 'mergeJson');
            $wrappedColumn = AdvancedJsonMacro::wrapIdentifier($this, AdvancedJsonMacro::assertValidIdentifier($column, 'column'));
            $jsonData = AdvancedJsonMacro::toJsonLiteral($data);

            if ($path !== null) {
                // 更新嵌套路径的值（路径已校验）
                return $this->setJsonValue($column, $path, $data);
            }

            $expr = "JSON_MERGE_PATCH({$wrappedColumn}, ?)";

            // UPDATE 语句编译后的绑定顺序为 values(SET 段非 Expression 值) → join → where，
            // 因此 SET 段 RAW 表达式的绑定须借道 join 通道，才能正确排在 where 绑定之前。
            $this->addBinding([$jsonData], 'join');

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
            AdvancedJsonMacro::assertMysql($this, 'jsonKeys');
            $wrappedColumn = AdvancedJsonMacro::wrapIdentifier($this, AdvancedJsonMacro::assertValidIdentifier($column, 'column'));
            $alias = AdvancedJsonMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = AdvancedJsonMacro::wrapIdentifier($this, $alias);
            [$target, $bindings] = AdvancedJsonMacro::buildJsonTarget($wrappedColumn, $path);

            return $this->selectRaw("JSON_KEYS({$target}) AS {$wrappedAlias}", $bindings);
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
            AdvancedJsonMacro::assertMysql($this, 'jsonSearch');
            $wrappedColumn = AdvancedJsonMacro::wrapIdentifier($this, AdvancedJsonMacro::assertValidIdentifier($column, 'column'));
            if (! in_array($mode, ['one', 'all'], true)) {
                throw new \InvalidArgumentException("JSON_SEARCH mode 仅支持 'one' 或 'all'，收到 '{$mode}'");
            }
            $path = AdvancedJsonMacro::assertJsonPath($path);
            $alias = AdvancedJsonMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = AdvancedJsonMacro::wrapIdentifier($this, $alias);
            $searchValue = AdvancedJsonMacro::toJsonLiteral($search);

            return $this->selectRaw(
                "JSON_SEARCH({$wrappedColumn}, ?, ?, NULL, ?) AS {$wrappedAlias}",
                [$mode, $searchValue, $path]
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
            AdvancedJsonMacro::assertMysql($this, 'whereJsonLike');
            $wrappedColumn = AdvancedJsonMacro::wrapIdentifier($this, AdvancedJsonMacro::assertValidIdentifier($column, 'column'));
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
            $target = $path !== null
                ? "JSON_UNQUOTE(JSON_EXTRACT({$wrappedColumn}, ?))"
                : "JSON_UNQUOTE({$wrappedColumn})";
            $bindings = $path !== null ? [AdvancedJsonMacro::assertJsonPath($path)] : [];

            return $this->{$method}("{$target} LIKE ?", array_merge($bindings, [$pattern]));
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
            AdvancedJsonMacro::assertMysql($this, 'jsonArrayAgg');
            $column = AdvancedJsonMacro::assertValidIdentifier($column, 'column');
            $wrappedColumn = AdvancedJsonMacro::wrapIdentifier($this, $column);
            $alias = AdvancedJsonMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = AdvancedJsonMacro::wrapIdentifier($this, $alias);

            if ($orderBy) {
                $direction = AdvancedJsonMacro::assertDirection($direction);
                $orderColumn = AdvancedJsonMacro::wrapIdentifier($this, AdvancedJsonMacro::assertValidIdentifier($orderBy, 'order'));
                $expr = "JSON_ARRAYAGG({$wrappedColumn} ORDER BY {$orderColumn} {$direction})";
            } else {
                $expr = "JSON_ARRAYAGG({$wrappedColumn})";
            }

            return $this->selectRaw("{$expr} AS {$wrappedAlias}");
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
            AdvancedJsonMacro::assertMysql($this, 'jsonObjectAgg');
            $keyColumn = AdvancedJsonMacro::assertValidIdentifier($keyColumn, 'key');
            $valueColumn = AdvancedJsonMacro::assertValidIdentifier($valueColumn, 'value');
            $alias = AdvancedJsonMacro::assertValidIdentifier($alias, 'alias');
            $wrappedKey = AdvancedJsonMacro::wrapIdentifier($this, $keyColumn);
            $wrappedValue = AdvancedJsonMacro::wrapIdentifier($this, $valueColumn);
            $wrappedAlias = AdvancedJsonMacro::wrapIdentifier($this, $alias);

            $expr = "JSON_OBJECTAGG({$wrappedKey}, {$wrappedValue})";

            return $this->selectRaw("{$expr} AS {$wrappedAlias}");
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
            AdvancedJsonMacro::assertMysql($this, 'jsonRowAgg');
            $jsonObjectParts = [];
            foreach ($columns as $col) {
                $col = AdvancedJsonMacro::assertValidIdentifier($col, 'column');
                $jsonObjectParts[] = "'{$col}'";
                $jsonObjectParts[] = AdvancedJsonMacro::wrapIdentifier($this, $col);
            }
            $jsonObjectExpr = 'JSON_OBJECT(' . implode(', ', $jsonObjectParts) . ')';
            $alias = AdvancedJsonMacro::assertValidIdentifier($alias ?? 'json_rows', 'alias');
            $wrappedAlias = AdvancedJsonMacro::wrapIdentifier($this, $alias);

            $expr = "JSON_ARRAYAGG({$jsonObjectExpr})";

            return $this->selectRaw("{$expr} AS {$wrappedAlias}");
        });
    }
}
