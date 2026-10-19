<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * 字符串高效处理宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 * 基于数据库内置字符串函数封装的高性能查询能力：
 * - LOCATE / position：查找子字符串在另一个字符串中首次出现的位置（分词查询匹配）
 * - CHAR_LENGTH：按字符数（多字节安全）排序与筛选
 * - FIELD / array_position：按指定值顺序自定义排序
 * - FIND_IN_SET / ANY(string_to_array)：逗号分隔列表成员查找
 * - CONCAT_WS：多列拼接模糊搜索
 * - SUBSTRING_INDEX / split_part：按分隔符提取子串
 * - GROUP_CONCAT / string_agg：行转字符串聚合
 * - SOUNDEX：发音相似匹配
 * - REPLACE / TRIM / LOWER / UPPER / REVERSE / LPAD / RPAD：字符串变换
 * - 自然排序：字符串内嵌数字按数值大小排序
 * 安全性：列名 / 别名均经白名单校验并由 grammar wrap；所有字面值走参数绑定。
 * 方言差异（LOCATE、FIND_IN_SET、SUBSTRING_INDEX、GROUP_CONCAT、FIELD、
 * 自然排序、SOUNDEX）统一交由 Dialect 方言抽象层生成。
 *
 * @version 1.13.9
 *
 * @requires MySQL 8.0+ / PostgreSQL 15+
 */
class StringFunctionsMacro extends BaseMacro
{
    /**
     * 注册所有字符串宏
     */
    public static function register(): void
    {
        StringFunctionsMacro::registerLocateSearch();      // LOCATE / position 分词查询匹配
        StringFunctionsMacro::registerCharLengthSorting(); // CHAR_LENGTH 排序/筛选 + 自然排序
        StringFunctionsMacro::registerFieldOrdering();     // FIELD / array_position 自定义排序
        StringFunctionsMacro::registerFindInSet();         // FIND_IN_SET / ANY(string_to_array) 列表查找
        StringFunctionsMacro::registerConcatSearch();      // CONCAT_WS 多列拼接搜索
        StringFunctionsMacro::registerStringExtraction();  // SUBSTRING_INDEX / split_part 提取
        StringFunctionsMacro::registerStringAggregation(); // GROUP_CONCAT / string_agg 聚合
        StringFunctionsMacro::registerSoundexSearch();     // SOUNDEX 发音匹配
        StringFunctionsMacro::registerStringTransform();   // 字符串变换
    }

    /**
     * 注册 LOCATE / position 分词查询匹配系列
     */
    public static function registerLocateSearch(): void
    {
        /**
         * whereLocate - 子字符串位置查找（分词查询匹配）
         * 使用 LOCATE(substr, str)（PostgreSQL 为 position(substr in str)）查找子串在字段中
         * 首次出现的位置，位置 > 0 即表示命中。相比 LIKE '%keyword%' 在部分场景下
         * 可利用索引前缀匹配，且天然支持以空格分隔的多词查询（分词匹配）。
         *
         * @param  string  $column  查询字段名
         * @param  string  $search  要查找的子字符串（可为空格分隔的多词分词）
         * @param  string  $boolean  连接条件: and|or
         *
         * @example
         * // 查找描述中包含 "Laravel" 的记录
         * Post::query()->whereLocate('description', 'Laravel')->get();
         * // 分词查询：包含 "高级" 或 "查询" 任意一词
         * Article::query()->whereLocate('content', '高级 查询')->get();
         */
        static::define('whereLocate', function (string $column, string $search, string $boolean = 'and'): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */
            return StringFunctionsMacro::whereLocate($this, $column, $search, $boolean);
        });

        /**
         * orWhereLocate - OR 条件的子字符串位置查找
         *
         * @example
         * Model::query()->orWhereLocate('col', 'val')->get();
         */
        static::define('orWhereLocate', function (string $column, string $search): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */
            return StringFunctionsMacro::whereLocate($this, $column, $search, 'or');
        });

        /**
         * whereLocateAll - 多关键词全命中查询（AND 连接）
         *
         * @param  string  $column  查询字段名
         * @param  array<int, string>  $keywords  关键词数组
         *
         * @example
         * Model::query()->whereLocateAll('col', 'kw')->get();
         */
        static::define('whereLocateAll', function (string $column, array $keywords): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */

            return StringFunctionsMacro::whereLocateAll($this, $column, $keywords);
        });

        /**
         * whereLocateAny - 多关键词任一命中查询（OR 连接）
         *
         * @param  string  $column  查询字段名
         * @param  array<int, string>  $keywords  关键词数组
         *
         * @example
         * Model::query()->whereLocateAny('col', 'kw')->get();
         */
        static::define('whereLocateAny', function (string $column, array $keywords): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */

            return StringFunctionsMacro::whereLocateAny($this, $column, $keywords);
        });
    }

    /**
     * 注册 CHAR_LENGTH 排序/筛选与自然排序系列
     */
    public static function registerCharLengthSorting(): void
    {
        /**
         * orderByCharLength - 按字符长度排序
         * 使用 CHAR_LENGTH()（PostgreSQL 为 char_length()）计算字段的字符数
         * （多字节安全，中英文均按 1 个字符计）。
         *
         * @param  string  $column  排序字段名
         * @param  string  $direction  排序方向: asc|desc
         *
         * @example
         * Model::query()->orderByCharLength('col', 'desc')->get();
         */
        static::define('orderByCharLength', function (string $column, string $direction = 'asc'): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */

            return StringFunctionsMacro::orderByCharLength($this, $column, $direction);
        });

        /**
         * orderByDescCharLength - 按字符长度降序排序
         *
         * @example
         * Model::query()->orderByDescCharLength('col', 'val')->get();
         */
        static::define('orderByDescCharLength', function (string $column): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */

            return StringFunctionsMacro::orderByCharLength($this, $column, 'desc');
        });

        /**
         * whereCharLength - 按字符长度筛选
         *
         * @param  string  $column  查询字段名
         * @param  string  $operator  比较运算符: =|>|<|>=|<=|<>|!=
         * @param  int  $length  字符长度
         * @param  string  $boolean  连接条件: and|or
         *
         * @example
         * Model::query()->whereCharLength('col', $o, $l, 'and')->get();
         */
        static::define('whereCharLength', function (string $column, string $operator, int $length, string $boolean = 'and'): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */

            return StringFunctionsMacro::whereCharLength($this, $column, $operator, $length, $boolean);
        });

        /**
         * orWhereCharLength - OR 条件的字符长度筛选
         *
         * @example
         * Model::query()->orWhereCharLength('col', 'val')->get();
         */
        static::define('orWhereCharLength', function (string $column, string $operator, int $length): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */

            return StringFunctionsMacro::whereCharLength($this, $column, $operator, $length, 'or');
        });

        /**
         * orderByNatural - 自然排序（字符串中内嵌数字按数值大小排序）
         * 提取字段中所有数字转为整数作为主排序键，原字段作为次排序键，
         * 实现 "V1, V2, V10" 而非 "V1, V10, V2" 的自然序。
         *
         * @param  string  $column  排序字段名
         * @param  string  $direction  排序方向: asc|desc
         *
         * @example
         * Model::query()->orderByNatural('col', 'desc')->get();
         */
        static::define('orderByNatural', function (string $column, string $direction = 'asc'): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */

            return StringFunctionsMacro::orderByNatural($this, $column, $direction);
        });
    }

    /**
     * 注册 FIELD / array_position 自定义排序系列
     */
    public static function registerFieldOrdering(): void
    {
        /**
         * fieldOrderBy - 按指定值顺序自定义排序
         * 将字段值映射为其在给定列表中的下标作为排序键，实现"枚举状态优先序"等业务排序。
         * MySQL 使用 FIELD()，PostgreSQL 使用 array_position()。
         * 未出现在列表中的值排在最前（下标 0）。
         *
         * @param  string  $column  排序字段名
         * @param  array<int, mixed>  $order  自定义顺序的值列表（按期望的排序顺序）
         * @param  string  $direction  排序方向: asc|desc（作用于下标）
         *
         * @example
         * Model::query()->fieldOrderBy('col', $o, 'desc')->get();
         */
        static::define('fieldOrderBy', function (string $column, array $order, string $direction = 'asc'): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */

            return StringFunctionsMacro::fieldOrderBy($this, $column, $order, $direction);
        });
    }

    /**
     * 注册 FIND_IN_SET / ANY(string_to_array) 列表查找系列
     */
    public static function registerFindInSet(): void
    {
        /**
         * whereFindInSet - 逗号分隔列表成员查找
         * 判断字段（逗号分隔字符串）是否包含指定值。MySQL 使用 FIND_IN_SET()，
         * PostgreSQL 使用 ? = ANY(string_to_array(col, ','))。
         *
         * @param  string  $column  查询字段名（逗号分隔的字符串列）
         * @param  mixed  $value  要查找的值
         * @param  string  $boolean  连接条件: and|or
         *
         * @example
         * Model::query()->whereFindInSet('col', 'val', 'and')->get();
         */
        static::define('whereFindInSet', function (string $column, mixed $value, string $boolean = 'and'): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */

            return StringFunctionsMacro::whereFindInSet($this, $column, $value, $boolean);
        });

        /**
         * orWhereFindInSet - OR 条件的列表成员查找
         *
         * @example
         * Model::query()->orWhereFindInSet('col', 'val')->get();
         */
        static::define('orWhereFindInSet', function (string $column, mixed $value): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */

            return StringFunctionsMacro::whereFindInSet($this, $column, $value, 'or');
        });

        /**
         * whereFindInSetAny - 列表字段包含任意指定值
         *
         * @param  string  $column  查询字段名
         * @param  array<int, mixed>  $values  候选值数组
         *
         * @example
         * Model::query()->whereFindInSetAny('col', 'val')->get();
         */
        static::define('whereFindInSetAny', function (string $column, array $values): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */

            return StringFunctionsMacro::whereFindInSetAny($this, $column, $values);
        });

        /**
         * whereFindInSetAll - 列表字段包含全部指定值
         *
         * @param  string  $column  查询字段名
         * @param  array<int, mixed>  $values  必须全部包含的值数组
         *
         * @example
         * Model::query()->whereFindInSetAll('col', 'val')->get();
         */
        static::define('whereFindInSetAll', function (string $column, array $values): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */

            return StringFunctionsMacro::whereFindInSetAll($this, $column, $values);
        });
    }

    /**
     * 注册 CONCAT_WS 多列拼接搜索系列
     */
    public static function registerConcatSearch(): void
    {
        /**
         * whereConcatLike - 多列拼接模糊搜索
         * 使用 CONCAT_WS（两库通用）将多个字段以空格拼接后做 LIKE 模糊匹配，
         * 实现"跨字段全文关键词检索"（如姓名+手机号+邮箱 一站式搜索）。
         *
         * @param  array<int, string>  $columns  参与拼接的字段数组
         * @param  string  $keyword  搜索关键词
         * @param  string  $boolean  连接条件: and|or
         *
         * @example
         * Model::query()->whereConcatLike('col', 'kw', 'and')->get();
         */
        static::define('whereConcatLike', function (array $columns, string $keyword, string $boolean = 'and'): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */

            return StringFunctionsMacro::whereConcatLike($this, $columns, $keyword, $boolean);
        });

        /**
         * orWhereConcatLike - OR 条件的多列拼接搜索
         *
         * @example
         * Model::query()->orWhereConcatLike('col', 'val')->get();
         */
        static::define('orWhereConcatLike', function (array $columns, string $keyword): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */

            return StringFunctionsMacro::whereConcatLike($this, $columns, $keyword, 'or');
        });
    }

    /**
     * 注册 SUBSTRING_INDEX / split_part 提取系列
     */
    public static function registerStringExtraction(): void
    {
        /**
         * substringIndex - 按分隔符提取子串（SELECT 场景）
         * 提取分隔字段中的一段。MySQL 使用 SUBSTRING_INDEX()，
         * PostgreSQL 使用 split_part()（正负计数语义对齐）。
         *
         * @param  string  $column  字段名
         * @param  string  $delimiter  分隔符
         * @param  int  $count  提取方向与个数（正数从左、负数从右）
         * @param  string  $alias  结果别名
         *
         * @example
         * Model::query()->substringIndex('col', 10, 10, $a)->get();
         */
        static::define('substringIndex', function (string $column, string $delimiter, int $count, string $alias = 'part'): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */

            return StringFunctionsMacro::substringIndex($this, $column, $delimiter, $count, $alias);
        });
    }

    /**
     * 注册 GROUP_CONCAT / string_agg 聚合系列
     */
    public static function registerStringAggregation(): void
    {
        /**
         * groupConcat - 行转字符串聚合
         * 将分组内的多行字段值拼接为一个字符串，配合 groupBy 使用。
         * MySQL 使用 GROUP_CONCAT()，PostgreSQL 使用 string_agg()。
         *
         * @param  string  $column  要聚合的字段名
         * @param  string  $alias  结果别名
         * @param  string  $separator  分隔符（默认英文逗号）
         * @param  bool  $distinct  是否去重
         *
         * @example
         * Model::query()->groupConcat('col', $a, $s, $d)->get();
         */
        static::define('groupConcat', function (string $column, string $alias = 'items', string $separator = ',', bool $distinct = false): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */

            return StringFunctionsMacro::groupConcat($this, $column, $alias, $separator, $distinct);
        });
    }

    /**
     * 注册 SOUNDEX 发音匹配系列
     */
    public static function registerSoundexSearch(): void
    {
        /**
         * whereSoundex - 发音相似匹配
         * 比较两个字符串的发音编码是否一致，适用于英文姓名、公司名的模糊发音匹配。
         * 注：PostgreSQL 需在目标库启用 fuzzystrmatch 扩展后才有 soundex()。
         *
         * @param  string  $column  查询字段名
         * @param  string  $value  要匹配发音的值
         * @param  string  $boolean  连接条件: and|or
         *
         * @example
         * Model::query()->whereSoundex('col', 'val', 'and')->get();
         */
        static::define('whereSoundex', function (string $column, string $value, string $boolean = 'and'): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */

            return StringFunctionsMacro::whereSoundex($this, $column, $value, $boolean);
        });

        /**
         * orWhereSoundex - OR 条件的发音相似匹配
         *
         * @example
         * Model::query()->orWhereSoundex('col', 'val')->get();
         */
        static::define('orWhereSoundex', function (string $column, string $value): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */

            return StringFunctionsMacro::whereSoundex($this, $column, $value, 'or');
        });
    }

    /**
     * 注册字符串变换系列
     */
    public static function registerStringTransform(): void
    {
        /**
         * replaceString - 字符串替换（SELECT 场景）
         * 使用 REPLACE(str, from, to)（两库通用）将字段中所有出现的 from 替换为 to。
         *
         * @example
         * Model::query()->replaceString('col', 'val')->get();
         */
        static::define('replaceString', function (string $column, string $search, string $replace, string $alias = 'replaced'): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */

            return StringFunctionsMacro::replaceString($this, $column, $search, $replace, $alias);
        });

        /**
         * trimString - 去除首尾空格（SELECT 场景）
         *
         * @example
         * Model::query()->trimString('col', 'val')->get();
         */
        static::define('trimString', function (string $column, string $alias = 'trimmed'): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */

            return StringFunctionsMacro::trimString($this, $column, $alias);
        });

        /**
         * lowerString - 转小写（SELECT 场景）
         *
         * @example
         * Model::query()->lowerString('col', 'val')->get();
         */
        static::define('lowerString', function (string $column, string $alias = 'lower_val'): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */

            return StringFunctionsMacro::lowerString($this, $column, $alias);
        });

        /**
         * upperString - 转大写（SELECT 场景）
         *
         * @example
         * Model::query()->upperString('col', 'val')->get();
         */
        static::define('upperString', function (string $column, string $alias = 'upper_val'): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */

            return StringFunctionsMacro::upperString($this, $column, $alias);
        });

        /**
         * reverseString - 字符串反转（SELECT 场景）
         *
         * @example
         * Model::query()->reverseString('col', 'val')->get();
         */
        static::define('reverseString', function (string $column, string $alias = 'reversed'): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */

            return StringFunctionsMacro::reverseString($this, $column, $alias);
        });

        /**
         * padString - 填充字符串到指定长度（SELECT 场景）
         * 使用 LPAD/RPAD（PostgreSQL 为 lpad/rpad）将字段填充到指定长度。
         *
         * @param  string  $column  字段名
         * @param  int  $length  目标总长度
         * @param  string  $padString  用于填充的字符串
         * @param  string  $position  填充位置: left|right
         * @param  string  $alias  结果别名
         *
         * @example
         * Model::query()->padString('col', $l, $p, $p, $a)->get();
         */
        static::define('padString', function (string $column, int $length, string $padString, string $position = 'left', string $alias = 'padded'): Builder|QueryBuilder {
            /**
             * @var EloquentBuilder|QueryBuilder $this
             */

            return StringFunctionsMacro::padString($this, $column, $length, $padString, $position, $alias);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | 静态实现（宏闭包内 StringFunctionsMacro:: 会解析到 \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder，故必须经显式类名调用）
    |--------------------------------------------------------------------------
    */

    /**
     * 实现 whereLocate / orWhereLocate
     */
    public static function whereLocate(EloquentBuilder|QueryBuilder $builder, string $column, string $search, string $boolean = 'and'): EloquentBuilder|QueryBuilder
    {
        $wrapped = BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($column));
        [$sql, $bindings] = BaseMacro::locateFragment($builder, $wrapped, $search);

        return $builder->whereRaw($sql, $bindings, $boolean);
    }

    /**
     * 实现 whereLocateAll（AND 全命中）
     */
    public static function whereLocateAll(EloquentBuilder|QueryBuilder $builder, string $column, array $keywords): EloquentBuilder|QueryBuilder
    {
        $wrapped = BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($column));

        foreach ($keywords as $keyword) {
            [$sql, $bindings] = BaseMacro::locateFragment($builder, $wrapped, (string) $keyword);
            $builder->whereRaw($sql, $bindings);
        }

        return $builder;
    }

    /**
     * 实现 whereLocateAny（OR 任一命中，嵌套分组避免布尔优先级陷阱）
     */
    public static function whereLocateAny(EloquentBuilder|QueryBuilder $builder, string $column, array $keywords): EloquentBuilder|QueryBuilder
    {
        $wrapped = BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($column));

        return $builder->where(function ($query) use ($wrapped, $builder, $keywords) {
            foreach ($keywords as $index => $keyword) {
                [$sql, $bindings] = BaseMacro::locateFragment($builder, $wrapped, (string) $keyword);
                $query->whereRaw($sql, $bindings, $index === 0 ? 'and' : 'or');
            }
        });
    }

    /**
     * 实现 orderByCharLength / orderByDescCharLength
     */
    public static function orderByCharLength(EloquentBuilder|QueryBuilder $builder, string $column, string $direction = 'asc'): EloquentBuilder|QueryBuilder
    {
        $wrapped = BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($column));
        $direction = BaseMacro::assertDirection($direction);

        return $builder->orderByRaw(BaseMacro::charLengthExpr($builder, $wrapped)." {$direction}");
    }

    /**
     * 实现 whereCharLength / orWhereCharLength
     */
    public static function whereCharLength(EloquentBuilder|QueryBuilder $builder, string $column, string $operator, int $length, string $boolean = 'and'): EloquentBuilder|QueryBuilder
    {
        $wrapped = BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($column));
        $operator = BaseMacro::assertOperator($operator);

        return $builder->whereRaw(BaseMacro::charLengthExpr($builder, $wrapped)." {$operator} ?", [$length], $boolean);
    }

    /**
     * 实现 orderByNatural（提取数字的自然排序，双库通用）
     */
    public static function orderByNatural(EloquentBuilder|QueryBuilder $builder, string $column, string $direction = 'asc'): EloquentBuilder|QueryBuilder
    {
        $wrapped = BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($column));
        $direction = BaseMacro::assertDirection($direction);

        return $builder->orderByRaw(BaseMacro::naturalSortExpr($builder, $wrapped)." {$direction}, {$wrapped} {$direction}");
    }

    /**
     * 实现 fieldOrderBy（FIELD / array_position 自定义排序）
     */
    public static function fieldOrderBy(EloquentBuilder|QueryBuilder $builder, string $column, array $order, string $direction = 'asc'): EloquentBuilder|QueryBuilder
    {
        $wrapped = BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($column));
        $direction = BaseMacro::assertDirection($direction);

        [$sql, $bindings] = BaseMacro::fieldOrderFragment($builder, $wrapped, $order);

        return $builder->orderByRaw("{$sql} {$direction}", $bindings);
    }

    /**
     * 实现 whereFindInSet / orWhereFindInSet
     */
    public static function whereFindInSet(EloquentBuilder|QueryBuilder $builder, string $column, mixed $value, string $boolean = 'and'): EloquentBuilder|QueryBuilder
    {
        $wrapped = BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($column));
        [$sql, $bindings] = BaseMacro::findInSetFragment($builder, $wrapped, (string) $value);

        return $builder->whereRaw($sql, $bindings, $boolean);
    }

    /**
     * 实现 whereFindInSetAny（OR 任一命中）
     */
    public static function whereFindInSetAny(EloquentBuilder|QueryBuilder $builder, string $column, array $values): EloquentBuilder|QueryBuilder
    {
        $wrapped = BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($column));

        return $builder->where(function ($query) use ($wrapped, $builder, $values) {
            foreach ($values as $index => $value) {
                [$sql, $bindings] = BaseMacro::findInSetFragment($builder, $wrapped, (string) $value);
                $query->whereRaw($sql, $bindings, $index === 0 ? 'and' : 'or');
            }
        });
    }

    /**
     * 实现 whereFindInSetAll（AND 全命中）
     */
    public static function whereFindInSetAll(EloquentBuilder|QueryBuilder $builder, string $column, array $values): EloquentBuilder|QueryBuilder
    {
        $wrapped = BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($column));

        foreach ($values as $value) {
            [$sql, $bindings] = BaseMacro::findInSetFragment($builder, $wrapped, (string) $value);
            $builder->whereRaw($sql, $bindings);
        }

        return $builder;
    }

    /**
     * 实现 whereConcatLike / orWhereConcatLike
     */
    public static function whereConcatLike(EloquentBuilder|QueryBuilder $builder, array $columns, string $keyword, string $boolean = 'and'): EloquentBuilder|QueryBuilder
    {
        $wrappedColumns = [];
        foreach ($columns as $column) {
            $wrappedColumns[] = BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($column));
        }
        $concatExpr = BaseMacro::concatWsFragment($builder, implode(', ', $wrappedColumns));

        return $builder->whereRaw("{$concatExpr} LIKE ?", ['%'.$keyword.'%'], $boolean);
    }

    /**
     * 实现 substringIndex（SUBSTRING_INDEX / split_part 提取）
     */
    public static function substringIndex(EloquentBuilder|QueryBuilder $builder, string $column, string $delimiter, int $count, string $alias = 'part'): EloquentBuilder|QueryBuilder
    {
        $wrapped = BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($column));
        $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
        [$sql, $bindings] = BaseMacro::substringIndexFragment($builder, $wrapped, $delimiter, $count);

        return $builder->selectRaw("{$sql} AS ".BaseMacro::wrapIdentifier($builder, $alias), $bindings);
    }

    /**
     * 实现 groupConcat（GROUP_CONCAT / string_agg 聚合）
     */
    public static function groupConcat(EloquentBuilder|QueryBuilder $builder, string $column, string $alias = 'items', string $separator = ',', bool $distinct = false): EloquentBuilder|QueryBuilder
    {
        $wrapped = BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($column));
        $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
        [$sql, $bindings] = BaseMacro::groupConcatFragment($builder, $wrapped, $separator, $distinct);

        return $builder->selectRaw("{$sql} AS ".BaseMacro::wrapIdentifier($builder, $alias), $bindings);
    }

    /**
     * 实现 replaceString（REPLACE 两库通用）
     */
    public static function replaceString(EloquentBuilder|QueryBuilder $builder, string $column, string $search, string $replace, string $alias = 'replaced'): EloquentBuilder|QueryBuilder
    {
        $wrapped = BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($column));
        $alias = BaseMacro::assertValidIdentifier($alias, 'alias');

        return $builder->selectRaw("REPLACE({$wrapped}, ?, ?) AS ".BaseMacro::wrapIdentifier($builder, $alias), [$search, $replace]);
    }

    /**
     * 实现 trimString（TRIM 两库通用）
     */
    public static function trimString(EloquentBuilder|QueryBuilder $builder, string $column, string $alias = 'trimmed'): EloquentBuilder|QueryBuilder
    {
        $wrapped = BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($column));
        $alias = BaseMacro::assertValidIdentifier($alias, 'alias');

        return $builder->selectRaw("TRIM({$wrapped}) AS ".BaseMacro::wrapIdentifier($builder, $alias));
    }

    /**
     * 实现 lowerString（LOWER 两库通用）
     */
    public static function lowerString(EloquentBuilder|QueryBuilder $builder, string $column, string $alias = 'lower_val'): EloquentBuilder|QueryBuilder
    {
        $wrapped = BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($column));
        $alias = BaseMacro::assertValidIdentifier($alias, 'alias');

        return $builder->selectRaw("LOWER({$wrapped}) AS ".BaseMacro::wrapIdentifier($builder, $alias));
    }

    /**
     * 实现 upperString（UPPER 两库通用）
     */
    public static function upperString(EloquentBuilder|QueryBuilder $builder, string $column, string $alias = 'upper_val'): EloquentBuilder|QueryBuilder
    {
        $wrapped = BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($column));
        $alias = BaseMacro::assertValidIdentifier($alias, 'alias');

        return $builder->selectRaw("UPPER({$wrapped}) AS ".BaseMacro::wrapIdentifier($builder, $alias));
    }

    /**
     * 实现 reverseString（REVERSE 两库通用）
     */
    public static function reverseString(EloquentBuilder|QueryBuilder $builder, string $column, string $alias = 'reversed'): EloquentBuilder|QueryBuilder
    {
        $wrapped = BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($column));
        $alias = BaseMacro::assertValidIdentifier($alias, 'alias');

        return $builder->selectRaw("REVERSE({$wrapped}) AS ".BaseMacro::wrapIdentifier($builder, $alias));
    }

    /**
     * 实现 padString（LPAD / RPAD 两库通用）
     */
    public static function padString(EloquentBuilder|QueryBuilder $builder, string $column, int $length, string $padString, string $position = 'left', string $alias = 'padded'): EloquentBuilder|QueryBuilder
    {
        $wrapped = BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($column));
        $alias = BaseMacro::assertValidIdentifier($alias, 'alias');

        $position = strtolower(trim($position));
        if (! in_array($position, ['left', 'right'], true)) {
            throw new \InvalidArgumentException('padString 填充位置必须为 left 或 right');
        }
        $function = $position === 'left' ? 'LPAD' : 'RPAD';

        return $builder->selectRaw("{$function}({$wrapped}, ?, ?) AS ".BaseMacro::wrapIdentifier($builder, $alias), [$length, $padString]);
    }

    /**
     * 实现 whereSoundex / orWhereSoundex
     */
    public static function whereSoundex(EloquentBuilder|QueryBuilder $builder, string $column, string $value, string $boolean = 'and'): EloquentBuilder|QueryBuilder
    {
        $wrapped = BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($column));
        $fn = BaseMacro::isPostgres($builder) ? 'soundex' : 'SOUNDEX';

        return $builder->whereRaw("{$fn}({$wrapped}) = {$fn}(?)", [$value], $boolean);
    }
}
