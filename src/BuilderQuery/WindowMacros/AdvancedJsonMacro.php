<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * JSON 高级操作宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 * 提供丰富的 JSON 字段查询与更新能力，所有数据库专属语法经 {@see Dialect} 抽象层生成，
 * 因此同一套宏在 MySQL 8.0+ 与 PostgreSQL 15+ 上均可直接使用：
 *  - 提取：MySQL JSON_UNQUOTE(JSON_EXTRACT) / PostgreSQL jsonb_path_query_first(#>> '{}')
 *  - 包含：MySQL JSON_CONTAINS / PostgreSQL @>
 *  - 设值/删除：MySQL JSON_SET/JSON_REMOVE / PostgreSQL jsonb_set/jsonb_delete
 *  - 聚合：MySQL JSON_ARRAYAGG / PostgreSQL jsonb_agg
 * 提供的宏：
 *  - jsonPath / jsonExtract：JSON Path 提取与类型转换
 *  - whereJsonPathExists / whereJsonPathNotExists：路径存在性筛选
 *  - whereJsonArrayContains / Any / All：数组包含筛选
 *  - jsonArrayLength / whereJsonArrayLength：数组长度
 *  - appendToJsonArray / removeFromJsonArray：数组元素追加 / 删除（UPDATE）
 *  - setJsonValue / removeJsonKey / mergeJson：对象设值 / 删除键 / 合并（UPDATE）
 *  - jsonKeys：获取对象键
 *  - jsonSearch / whereJsonLike：JSON 内搜索 / 模糊匹配
 *  - jsonArrayAgg / jsonObjectAgg / jsonRowAgg：多行聚合为 JSON
 * 安全性：列名 / 别名经白名单校验并由 grammar wrap；JSON Path 经格式校验；字面量与默认值
 * 一律走参数绑定（?），彻底消除原先将模式 / 字面量裸拼进 SQL 导致的注入风险。
 *
 * @version 3.0.0
 *
 * @requires MySQL 8.0+ / PostgreSQL 15+
 *
 * @scenario JSON 高级操作宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 */
class AdvancedJsonMacro extends BaseMacro
{
    /**
     * 注册所有 JSON 宏
     *
     * @scenario 注册所有 JSON 宏
     */
    public static function register(): void
    {
        AdvancedJsonMacro::registerJsonPathQueries();
        AdvancedJsonMacro::registerJsonArrayOperations();
        AdvancedJsonMacro::registerJsonObjectOperations();
        AdvancedJsonMacro::registerJsonSearchOperations();
        AdvancedJsonMacro::registerJsonAggregation();
    }

    /**
     * 注册 JSON 路径查询
     *
     * @scenario 注册 JSON 路径查询
     */
    public static function registerJsonPathQueries(): void
    {
        /**
         * 使用 JSON Path 提取嵌套 JSON 值（字符串形式）
         *
         * @param  string  $column  JSON 列名
         * @param  string  $path  JSON Path 表达式，例如: '$.name', '$.items[0].price'
         * @param  string  $alias  结果列别名
         * @param  mixed  $default  默认值（提取失败时返回，走参数绑定）
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 提取嵌套字段
         * User::query()->jsonPath('settings', '$.notifications.email', 'email_enabled', false)->get();
         * // 提取数组元素
         * Order::query()->jsonPath('items', '$[0].product_name', 'first_product')->get();
         * // 提取多级嵌套
         * Config::query()->jsonPath('data', '$.database.connections.mysql.host', 'db_host')->get();
         *
         * @scenario 使用 JSON Path 提取嵌套 JSON 值（字符串形式）
         */
        static::define('jsonPath', function (
            string $column,
            string $path,
            string $alias,
            mixed $default = null
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $column = BaseMacro::assertValidIdentifier($column, 'column');
            $path = BaseMacro::assertJsonPath($path);
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            $wrappedColumn = BaseMacro::wrapIdentifier($this, $column);
            $wrappedAlias = BaseMacro::wrapIdentifier($this, $alias);

            [$expr, $bindings] = BaseMacro::jsonExtractFragment($this, $wrappedColumn, $path, 'string');

            if ($default !== null) {
                $expr = "COALESCE({$expr}, ?)";
                $bindings[] = $default;
            }

            return $this->selectRaw("{$expr} AS {$wrappedAlias}", $bindings);
        });

        /**
         * 提取 JSON 值并转换为指定类型
         *
         * @param  string  $column  JSON 列名
         * @param  string  $path  JSON Path
         * @param  string  $type  目标类型: string|int|float|bool|datetime|date|time|json
         * @param  string  $alias  结果列别名
         * @param  mixed  $default  默认值
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 提取整数
         * Product::query()->jsonExtract('metadata', '$.stock', 'int', 'stock_qty', 0)->get();
         * // 提取日期时间
         * Event::query()->jsonExtract('schedule', '$.start_time', 'datetime', 'starts_at')->get();
         *
         * @scenario 提取 JSON 值并转换为指定类型
         */
        static::define('jsonExtract', function (
            string $column,
            string $path,
            string $type = 'string',
            string $alias = '',
            mixed $default = null
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $column = BaseMacro::assertValidIdentifier($column, 'column');
            $path = BaseMacro::assertJsonPath($path);
            $wrappedColumn = BaseMacro::wrapIdentifier($this, $column);

            $alias = $alias ?: preg_replace('/[^a-zA-Z0-9_]/', '_', trim($path, '$.'));
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = BaseMacro::wrapIdentifier($this, $alias);

            [$expr, $bindings] = BaseMacro::jsonExtractFragment($this, $wrappedColumn, $path, $type);

            if ($default !== null) {
                $expr = "COALESCE({$expr}, ?)";
                $bindings[] = $default;
            }

            return $this->selectRaw("{$expr} AS {$wrappedAlias}", $bindings);
        });

        /**
         * 检查 JSON Path 是否存在
         *
         * @param  string  $column  JSON 列名
         * @param  string  $path  JSON Path
         * @param  string  $boolean  连接条件: and|or
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 查找有邮箱设置的用户
         * User::query()->whereJsonPathExists('settings', '$.email')->get();
         * // 查找有嵌套配置的记录
         * Config::query()->whereJsonPathExists('data', '$.database.connections.mysql')->get();
         *
         * @scenario 检查 JSON Path 是否存在
         */
        static::define('whereJsonPathExists', function (
            string $column,
            string $path,
            string $boolean = 'and'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrappedColumn = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $path = BaseMacro::assertJsonPath($path);
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

            [$fragmentSql, $bindings] = BaseMacro::jsonPathExistsFragment($this, $wrappedColumn, $path);

            return $this->{$method}($fragmentSql, $bindings);
        });

        /**
         * 检查 JSON Path 是否不存在
         *
         * @param  string  $column  JSON 列名
         * @param  string  $path  JSON Path
         * @param  string  $boolean  连接条件
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 查找未配置邮箱的用户
         * User::query()->whereJsonPathNotExists('settings', '$.email')->get();
         * // 排除已设置嵌套数据库配置的记录
         * Config::query()->whereJsonPathNotExists('data', '$.database.connections.mysql')->get();
         *
         * @scenario 检查 JSON Path 是否不存在
         */
        static::define('whereJsonPathNotExists', function (
            string $column,
            string $path,
            string $boolean = 'and'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrappedColumn = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $path = BaseMacro::assertJsonPath($path);
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

            [$fragmentSql, $bindings] = BaseMacro::jsonPathExistsFragment($this, $wrappedColumn, $path);

            return $this->{$method}("NOT {$fragmentSql}", $bindings);
        });
    }

    /**
     * 注册 JSON 数组操作
     *
     * @scenario 注册 JSON 数组操作
     */
    public static function registerJsonArrayOperations(): void
    {
        /**
         * 检查 JSON 数组是否包含指定值
         *
         * @param  string  $column  JSON 列名
         * @param  mixed  $value  要查找的值
         * @param  string|null  $path  JSON Path（嵌套数组）
         * @param  string  $boolean  连接条件
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 查找标签包含 'php' 的文章
         * Article::query()->whereJsonArrayContains('tags', 'php')->get();
         * // 查找嵌套数组
         * Data::query()->whereJsonArrayContains('items', 123, '$.product_ids')->get();
         *
         * @scenario 检查 JSON 数组是否包含指定值
         */
        static::define('whereJsonArrayContains', function (
            string $column,
            mixed $value,
            ?string $path = null,
            string $boolean = 'and'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrappedColumn = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $path = $path !== null ? BaseMacro::assertJsonPath($path) : null;
            $jsonLiteral = AdvancedJsonMacro::toJsonLiteral($value);
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

            [$sql, $bindings] = BaseMacro::jsonContainsFragment($this, $wrappedColumn, $jsonLiteral, $path);

            return $this->{$method}($sql, $bindings);
        });

        /**
         * 检查 JSON 数组是否包含任意一个指定值
         *
         * @param  string  $column  JSON 列名
         * @param  array  $values  要查找的值数组
         * @param  string|null  $path  JSON Path
         * @param  string  $boolean  连接条件
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 查找包含指定标签组的文章（语义：数组整体包含该值集合）
         * Article::query()->whereJsonArrayContainsAny('tags', ['php', 'laravel', 'mysql'])->get();
         *
         * @scenario 检查 JSON 数组是否包含任意一个指定值
         */
        static::define('whereJsonArrayContainsAny', function (
            string $column,
            array $values,
            ?string $path = null,
            string $boolean = 'and'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */

            return AdvancedJsonMacro::buildArrayContainsMacro($this, $column, $values, $path, $boolean);
        });

        /**
         * 检查 JSON 数组是否包含所有指定值
         *
         * @param  string  $column  JSON 列名
         * @param  array  $values  要查找的值数组
         * @param  string|null  $path  JSON Path
         * @param  string  $boolean  连接条件
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 查找同时包含多个标签的文章
         * Article::query()->whereJsonArrayContainsAll('tags', ['php', 'mysql'])->get();
         *
         * @scenario 检查 JSON 数组是否包含所有指定值
         */
        static::define('whereJsonArrayContainsAll', function (
            string $column,
            array $values,
            ?string $path = null,
            string $boolean = 'and'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */

            return AdvancedJsonMacro::buildArrayContainsMacro($this, $column, $values, $path, $boolean);
        });

        /**
         * 获取 JSON 数组长度
         *
         * @param  string  $column  JSON 列名
         * @param  string|null  $path  JSON Path（嵌套数组）
         * @param  string  $alias  结果列别名
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 获取标签数量
         * Article::query()->jsonArrayLength('tags', null, 'tag_count')->get();
         * // 获取嵌套数组长度
         * Data::query()->jsonArrayLength('data', '$.items', 'item_count')->get();
         *
         * @scenario 获取 JSON 数组长度
         */
        static::define('jsonArrayLength', function (
            string $column,
            ?string $path = null,
            string $alias = 'array_length'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrappedColumn = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $path = $path !== null ? BaseMacro::assertJsonPath($path) : null;
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = BaseMacro::wrapIdentifier($this, $alias);

            [$expr, $bindings] = BaseMacro::jsonLengthFragment($this, $wrappedColumn, $path);

            return $this->selectRaw("{$expr} AS {$wrappedAlias}", $bindings);
        });

        /**
         * 按 JSON 数组长度筛选
         *
         * @param  string  $column  JSON 列名
         * @param  int  $count  数组长度
         * @param  string  $operator  比较运算符: =|>|<|>=|<=
         * @param  string|null  $path  JSON Path
         * @param  string  $boolean  连接条件
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 查找有3个标签的文章
         * Article::query()->whereJsonArrayLength('tags', 3)->get();
         * // 查找有多个标签的文章
         * Article::query()->whereJsonArrayLength('tags', 5, '>=')->get();
         *
         * @scenario 按 JSON 数组长度筛选
         */
        static::define('whereJsonArrayLength', function (
            string $column,
            int $count,
            string $operator = '=',
            ?string $path = null,
            string $boolean = 'and'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $operator = BaseMacro::assertOperator($operator);
            $wrappedColumn = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $path = $path !== null ? BaseMacro::assertJsonPath($path) : null;
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

            [$expr, $bindings] = BaseMacro::jsonLengthFragment($this, $wrappedColumn, $path);

            return $this->{$method}("{$expr} {$operator} {$count}", $bindings);
        });

        /**
         * 追加值到 JSON 数组
         *
         * @param  string  $column  JSON 列名
         * @param  mixed  $value  要追加的值
         * @param  string|null  $path  JSON Path（嵌套数组）
         * @return int 影响行数
         *
         * @example
         * // 添加标签
         * Article::query()->where('id', 1)->appendToJsonArray('tags', 'new-tag')->update();
         *
         * @scenario 追加值到 JSON 数组
         */
        static::define('appendToJsonArray', function (
            string $column,
            mixed $value,
            ?string $path = null
        ) {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrappedColumn = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $path = $path !== null ? BaseMacro::assertJsonPath($path) : null;
            $jsonValue = AdvancedJsonMacro::toJsonLiteral($value);

            [$expr, $bindings] = BaseMacro::jsonArrayAppendFragment($this, $wrappedColumn, $jsonValue, $path);

            // UPDATE 语句编译后的绑定顺序为 values(SET 段非 Expression 值) → join → where，
            // 因此 SET 段 RAW 表达式的绑定须借道 join 通道，才能正确排在 where 绑定之前。
            $this->addBinding($bindings, 'join');

            return $this->update([$column => DB::raw($expr)]);
        });

        /**
         * 从 JSON 数组中移除首个匹配值
         *
         * @param  string  $column  JSON 列名
         * @param  mixed  $value  要移除的值
         * @param  string|null  $path  JSON Path
         * @return int 影响行数
         *
         * @example
         * // 移除标签
         * Article::query()->where('id', 1)->removeFromJsonArray('tags', 'old-tag')->update();
         *
         * @scenario 从 JSON 数组中移除首个匹配值
         */
        static::define('removeFromJsonArray', function (
            string $column,
            mixed $value,
            ?string $path = null
        ) {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrappedColumn = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $path = $path !== null ? BaseMacro::assertJsonPath($path) : null;
            $jsonValue = AdvancedJsonMacro::toJsonLiteral($value);

            [$expr, $bindings] = BaseMacro::jsonRemoveValueFragment($this, $wrappedColumn, $jsonValue, $path);

            // UPDATE 语句编译后的绑定顺序为 values(SET 段非 Expression 值) → join → where，
            // 因此 SET 段 RAW 表达式的绑定须借道 join 通道，才能正确排在 where 绑定之前。
            $this->addBinding($bindings, 'join');

            return $this->update([$column => DB::raw($expr)]);
        });
    }

    /**
     * 将 PHP 值编码为 JSON 字面量，用于 JSON 函数的参数绑定
     * JSON_CONTAINS / JSON_ARRAY / JSON_SEARCH 等函数所需的目标 / 搜索值必须是一个合法的 JSON 文本
     * （字符串需带引号），因此统一用 json_encode 生成字面量并走参数绑定（?），避免拼接导致的注入。
     * 本方法为静态方法，可在 \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder::macro 闭包内通过 {@see AdvancedJsonMacro::toJsonLiteral()} 调用。
     *
     * @return string JSON 编码后的字面量
     *
     * @throws \JsonException
     *
     * @scenario 将 PHP 值编码为 JSON 字面量，用于 JSON 函数的参数绑定
     */
    public static function toJsonLiteral(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * whereJsonArrayContainsAny / All 的共用实现：将值集合编码为 JSON 数组字面量后走 CONTAINS/@> 语义。
     * 本方法为类级静态辅助方法，在 \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder::macro 闭包内需通过显式类名
     * {@see AdvancedJsonMacro::buildArrayContainsMacro()} 调用（宏闭包内 AdvancedJsonMacro:: 解析为 \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder）。
     *
     * @scenario 本类/成员说明。
     */
    public static function buildArrayContainsMacro(
        Builder|\Illuminate\Database\Query\Builder $builder,
        string $column,
        array $values,
        ?string $path,
        string $boolean
    ): Builder|\Illuminate\Database\Query\Builder {
        /**
         * @var Builder|\Illuminate\Database\Query\Builder $builder
         *
         * @scenario 本类/成员说明。
         */
        $wrappedColumn = BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($column, 'column'));
        $path = $path !== null ? BaseMacro::assertJsonPath($path) : null;
        $jsonLiteral = '['.implode(',', array_map(static fn ($v) => AdvancedJsonMacro::toJsonLiteral($v), $values)).']';
        $method = $boolean === 'or' ? 'orWhere' : 'where';

        [$sql, $bindings] = BaseMacro::jsonContainsFragment($builder, $wrappedColumn, $jsonLiteral, $path);

        return $builder->{$method.'Raw'}($sql, $bindings);
    }

    /**
     * 注册 JSON 对象操作
     *
     * @scenario 注册 JSON 对象操作
     */
    public static function registerJsonObjectOperations(): void
    {
        /**
         * 设置 JSON 对象的键值
         *
         * @param  string  $column  JSON 列名
         * @param  string  $path  JSON Path
         * @param  mixed  $value  要设置的值
         * @param  bool  $insert  键不存在时是否插入（true=插入，false=替换现有）
         * @return int 影响行数
         *
         * @example
         * // 更新设置
         * User::query()->where('id', 1)->setJsonValue('settings', '$.theme', 'dark')->update();
         * // 插入新键
         * User::query()->where('id', 1)->setJsonValue('settings', '$.notifications.push', true, true)->update();
         *
         * @scenario 设置 JSON 对象的键值
         */
        static::define('setJsonValue', function (
            string $column,
            string $path,
            mixed $value,
            bool $insert = false
        ) {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrappedColumn = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $path = BaseMacro::assertJsonPath($path);
            $jsonValue = AdvancedJsonMacro::toJsonLiteral($value);

            [$expr, $bindings] = BaseMacro::jsonSetFragment($this, $wrappedColumn, $path, $jsonValue, $insert);

            // UPDATE 语句编译后的绑定顺序为 values(SET 段非 Expression 值) → join → where，
            // 因此 SET 段 RAW 表达式的绑定须借道 join 通道，才能正确排在 where 绑定之前。
            $this->addBinding($bindings, 'join');

            return $this->update([$column => DB::raw($expr)]);
        });

        /**
         * 删除 JSON 对象的键
         *
         * @param  string  $column  JSON 列名
         * @param  string|array  $paths  要删除的路径或路径数组
         * @return int 影响行数
         *
         * @example
         * // 删除单个键
         * User::query()->where('id', 1)->removeJsonKey('settings', '$.temp_data')->update();
         * // 删除多个键
         * User::query()->where('id', 1)->removeJsonKey('settings', ['$.cache', '$.temp'])->update();
         *
         * @scenario 删除 JSON 对象的键
         */
        static::define('removeJsonKey', function (
            string $column,
            string|array $paths
        ) {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrappedColumn = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $paths = is_array($paths) ? $paths : [$paths];
            foreach ($paths as &$p) {
                $p = BaseMacro::assertJsonPath($p);
            }
            unset($p);

            [$expr, $bindings] = BaseMacro::jsonRemoveFragment($this, $wrappedColumn, $paths);

            // UPDATE 语句编译后的绑定顺序为 values(SET 段非 Expression 值) → join → where，
            // 因此 SET 段 RAW 表达式的绑定须借道 join 通道，才能正确排在 where 绑定之前。
            $this->addBinding($bindings, 'join');

            return $this->update([$column => DB::raw($expr)]);
        });

        /**
         * 合并 JSON 对象
         *
         * @param  string  $column  JSON 列名
         * @param  array  $data  要合并的数据
         * @param  string|null  $path  JSON Path（嵌套对象）
         * @return int 影响行数
         *
         * @example
         * // 合并设置
         * User::query()->where('id', 1)->mergeJson('settings', ['theme' => 'dark', 'lang' => 'zh'])->update();
         *
         * @scenario 合并 JSON 对象
         */
        static::define('mergeJson', function (
            string $column,
            array $data,
            ?string $path = null
        ) {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrappedColumn = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $jsonData = AdvancedJsonMacro::toJsonLiteral($data);

            if ($path !== null) {
                // 嵌套路径合并直接走 setJsonValue（路径已校验）。
                return $this->setJsonValue($column, $path, $data);
            }

            [$expr, $bindings] = BaseMacro::jsonMergeFragment($this, $wrappedColumn, $jsonData);

            // UPDATE 语句编译后的绑定顺序为 values(SET 段非 Expression 值) → join → where，
            // 因此 SET 段 RAW 表达式的绑定须借道 join 通道，才能正确排在 where 绑定之前。
            $this->addBinding($bindings, 'join');

            return $this->update([$column => DB::raw($expr)]);
        });

        /**
         * 获取 JSON 对象的所有键
         *
         * @param  string  $column  JSON 列名
         * @param  string|null  $path  JSON Path
         * @param  string  $alias  结果列别名
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 获取所有设置键
         * User::query()->jsonKeys('settings', null, 'setting_keys')->get();
         *
         * @scenario 获取 JSON 对象的所有键
         */
        static::define('jsonKeys', function (
            string $column,
            ?string $path = null,
            string $alias = 'json_keys'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrappedColumn = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $path = $path !== null ? BaseMacro::assertJsonPath($path) : null;
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = BaseMacro::wrapIdentifier($this, $alias);

            [$expr, $bindings] = BaseMacro::jsonKeysFragment($this, $wrappedColumn, $path);

            return $this->selectRaw("{$expr} AS {$wrappedAlias}", $bindings);
        });
    }

    /**
     * 注册 JSON 搜索操作
     *
     * @scenario 注册 JSON 搜索操作
     */
    public static function registerJsonSearchOperations(): void
    {
        /**
         * 在 JSON 中搜索值并返回首个匹配的 JSON Path
         *
         * @param  string  $column  JSON 列名
         * @param  string  $search  搜索的值
         * @param  string  $mode  搜索模式: 'one' 返回第一个匹配, 'all' 返回所有匹配
         * @param  string  $path  起始路径
         * @param  string  $alias  结果列别名
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 搜索值的位置
         * Data::query()->jsonSearch('content', 'target_value', 'all', '$', 'found_paths')->get();
         *
         * @scenario 在 JSON 中搜索值并返回首个匹配的 JSON Path
         */
        static::define('jsonSearch', function (
            string $column,
            string $search,
            string $mode = 'one',
            string $path = '$',
            string $alias = 'search_result'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrappedColumn = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            if (! in_array($mode, ['one', 'all'], true)) {
                throw new \InvalidArgumentException("JSON_SEARCH mode 仅支持 'one' 或 'all'，收到 '{$mode}'");
            }
            $path = BaseMacro::assertJsonPath($path);
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = BaseMacro::wrapIdentifier($this, $alias);

            [$expr, $bindings] = BaseMacro::jsonSearchFragment($this, $wrappedColumn, $search, $mode, $path);

            return $this->selectRaw("{$expr} AS {$wrappedAlias}", $bindings);
        });

        /**
         * 按 JSON 值模糊匹配（支持通配符）
         *
         * @param  string  $column  JSON 列名
         * @param  string  $pattern  搜索模式（支持 % 通配符）
         * @param  string|null  $path  JSON Path
         * @param  string  $boolean  连接条件
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 搜索包含特定文本的 JSON
         * Article::query()->whereJsonLike('metadata', '%keyword%', '$.description')->get();
         *
         * @scenario 按 JSON 值模糊匹配（支持通配符）
         */
        static::define('whereJsonLike', function (
            string $column,
            string $pattern,
            ?string $path = null,
            string $boolean = 'and'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrappedColumn = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
            $bindings = [];
            if ($path !== null) {
                $path = BaseMacro::assertJsonPath($path);
                $bindings[] = $path;
                $target = BaseMacro::isPostgres($this)
                    ? "jsonb_path_query_first({$wrappedColumn}::jsonb, ?) #>> '{}'"
                    : "JSON_UNQUOTE(JSON_EXTRACT({$wrappedColumn}, ?))";
            } else {
                $target = BaseMacro::isPostgres($this)
                    ? "{$wrappedColumn}::jsonb #>> '{}'"
                    : "JSON_UNQUOTE({$wrappedColumn})";
            }

            return $this->{$method}("{$target} LIKE ?", array_merge($bindings, [$pattern]));
        });
    }

    /**
     * 注册 JSON 聚合
     *
     * @scenario 注册 JSON 聚合
     */
    public static function registerJsonAggregation(): void
    {
        /**
         * 将多行聚合成 JSON 数组
         *
         * @param  string  $column  要聚合的列
         * @param  string  $alias  结果列别名
         * @param  string|null  $orderBy  排序字段
         * @param  string  $direction  排序方向
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 将商品ID聚合成数组
         * Order::query()->select('user_id')
         *     ->jsonArrayAgg('product_id', 'products')
         *     ->groupBy('user_id')
         *     ->get();
         *
         * @scenario 将多行聚合成 JSON 数组
         */
        static::define('jsonArrayAgg', function (
            string $column,
            string $alias = 'json_array',
            ?string $orderBy = null,
            string $direction = 'asc'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $column = BaseMacro::assertValidIdentifier($column, 'column');
            $wrappedColumn = BaseMacro::wrapIdentifier($this, $column);
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = BaseMacro::wrapIdentifier($this, $alias);
            $orderBy = $orderBy !== null ? BaseMacro::assertValidIdentifier($orderBy, 'order') : null;

            [$expr, $bindings] = BaseMacro::jsonArrayAggFragment($this, $wrappedColumn, $orderBy, $direction);

            return $this->selectRaw("{$expr} AS {$wrappedAlias}", $bindings);
        });

        /**
         * 将多行聚合成 JSON 对象
         *
         * @param  string  $keyColumn  作为键的列
         * @param  string  $valueColumn  作为值的列
         * @param  string  $alias  结果列别名
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 将配置聚合成对象
         * Config::query()->jsonObjectAgg('key', 'value', 'config_object')->first();
         *
         * @scenario 将多行聚合成 JSON 对象
         */
        static::define('jsonObjectAgg', function (
            string $keyColumn,
            string $valueColumn,
            string $alias = 'json_object'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $keyColumn = BaseMacro::assertValidIdentifier($keyColumn, 'key');
            $valueColumn = BaseMacro::assertValidIdentifier($valueColumn, 'value');
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            $wrappedKey = BaseMacro::wrapIdentifier($this, $keyColumn);
            $wrappedValue = BaseMacro::wrapIdentifier($this, $valueColumn);
            $wrappedAlias = BaseMacro::wrapIdentifier($this, $alias);

            [$expr, $bindings] = BaseMacro::jsonObjectAggFragment($this, $wrappedKey, $wrappedValue);

            return $this->selectRaw("{$expr} AS {$wrappedAlias}", $bindings);
        });

        /**
         * 将行聚合成 JSON 对象数组
         *
         * @param  array  $columns  要包含的列
         * @param  string  $alias  结果列别名
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 聚合完整行数据
         * Order::query()->select('user_id')
         *     ->jsonRowAgg(['id', 'product_name', 'price'], 'items')
         *     ->groupBy('user_id')
         *     ->get();
         *
         * @scenario 将行聚合成 JSON 对象数组
         */
        static::define('jsonRowAgg', function (
            array $columns,
            string $alias = 'json_rows'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $columnPairs = [];
            foreach ($columns as $col) {
                $col = BaseMacro::assertValidIdentifier($col, 'column');
                $columnPairs[$col] = BaseMacro::wrapIdentifier($this, $col);
            }
            $alias = BaseMacro::assertValidIdentifier($alias ?? 'json_rows', 'alias');
            $wrappedAlias = BaseMacro::wrapIdentifier($this, $alias);

            [$expr, $bindings] = BaseMacro::jsonRowAggFragment($this, $columnPairs);

            return $this->selectRaw("{$expr} AS {$wrappedAlias}", $bindings);
        });
    }
}
