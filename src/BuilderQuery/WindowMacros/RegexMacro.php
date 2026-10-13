<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * 正则表达式匹配宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 * 提供强大的正则表达式查询能力，所有数据库专属语法经 {@see Dialect} 抽象层生成，
 * 因此同一套宏在 MySQL 8.0+ 与 PostgreSQL 15+ 上均可直接使用：
 *  - MySQL 走 REGEXP_LIKE / REGEXP_SUBSTR / REGEXP_REPLACE / REGEXP_INSTR / REGEXP_COUNT
 *  - PostgreSQL 走 ~ / ~* / regexp_replace / regexp_matches / regexp_count
 * 提供的宏：
 *  - whereRegexp / whereNotRegexp / whereRegexpAny：正则匹配筛选
 *  - regexpExtract / regexpExtractAll：提取子串 / 全量提取为数组
 *  - regexpReplace / regexpReplaceBatch：正则替换
 *  - regexpPosition：定位匹配位置
 *  - regexpCount / whereRegexpCount：计数匹配
 * 安全性：列名 / 别名经 {@see BaseMacro::assertValidIdentifier()} 白名单校验并由 grammar wrap；
 * 正则模式、替换文本、匹配模式一律走参数绑定（?），彻底消除原先将模式裸拼进 SQL 导致的注入风险。
 *
 * @version 3.0.0
 *
 * @requires MySQL 8.0+ / PostgreSQL 15+
 *
 * @scenario 正则表达式匹配宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 */
class RegexMacro extends BaseMacro
{
    /**
     * 注册所有正则表达式宏
     *
     * @scenario 注册所有正则表达式宏
     */
    public static function register(): void
    {
        RegexMacro::registerRegexpMatch();
        RegexMacro::registerRegexpExtract();
        RegexMacro::registerRegexpReplace();
        RegexMacro::registerRegexpPosition();
        RegexMacro::registerRegexpCount();
    }

    /**
     * 注册正则匹配筛选宏
     *
     * @scenario 注册正则匹配筛选宏
     */
    public static function registerRegexpMatch(): void
    {
        /**
         * 正则表达式匹配筛选
         * MySQL 8.0+ 与 PostgreSQL 15+ 均支持完整的正则语法；模式字符 c/i/m/n 会被自动
         * 映射为对应数据库的内联修饰符（PostgreSQL 无 n，以 s 等价）。
         *
         * @param  string  $column  要匹配的列
         * @param  string  $pattern  正则表达式模式
         * @param  string  $mode  匹配模式: 'c'(区分大小写)|'i'(不区分)|'m'(多行)|'n'(点匹配换行)
         * @param  string  $boolean  连接条件: and|or
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 匹配邮箱格式
         * User::query()->whereRegexp('email', '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$')->get();
         * // 匹配手机号（不区分大小写）
         * User::query()->whereRegexp('phone', '^1[3-9]\d{9}$', 'i')->get();
         * // 不匹配正则
         * User::query()->whereNotRegexp('username', '^admin', 'i')->get();
         *
         * @scenario 正则表达式匹配筛选
         */
        static::define('whereRegexp', function (
            string $column,
            string $pattern,
            string $mode = 'c',
            string $boolean = 'and'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrappedColumn = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $mode = BaseMacro::assertRegexMode($mode);
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

            [$sql, $bindings] = BaseMacro::regexMatchFragment($this, $wrappedColumn, $pattern, $mode, false);

            return $this->{$method}($sql, $bindings);
        });

        /**
         * 正则表达式不匹配筛选
         *
         * @param  string  $column  要匹配的列
         * @param  string  $pattern  正则表达式模式
         * @param  string  $mode  匹配模式
         * @param  string  $boolean  连接条件
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * Model::query()->whereNotRegexp('col', $p, 'natural', 'and')->get();
         *
         * @scenario 正则表达式不匹配筛选
         */
        static::define('whereNotRegexp', function (
            string $column,
            string $pattern,
            string $mode = 'c',
            string $boolean = 'and'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrappedColumn = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $mode = BaseMacro::assertRegexMode($mode);
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

            [$sql, $bindings] = BaseMacro::regexMatchFragment($this, $wrappedColumn, $pattern, $mode, true);

            return $this->{$method}($sql, $bindings);
        });

        /**
         * 匹配任意一个正则模式（OR 组合）
         *
         * @param  string  $column  要匹配的列
         * @param  array  $patterns  正则模式数组
         * @param  string  $mode  匹配模式
         * @param  string  $boolean  连接条件
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 匹配多种邮箱域名
         * User::query()->whereRegexpAny('email', [
         *     '@gmail\.com$',
         *     '@yahoo\.com$',
         *     '@outlook\.com$'
         * ])->get();
         *
         * @scenario 匹配任意一个正则模式（OR 组合）
         */
        static::define('whereRegexpAny', function (
            string $column,
            array $patterns,
            string $mode = 'c',
            string $boolean = 'and'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrappedColumn = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $mode = BaseMacro::assertRegexMode($mode);

            $conditions = [];
            $bindings = [];
            foreach ($patterns as $pattern) {
                [$cond, $b] = BaseMacro::regexMatchFragment($this, $wrappedColumn, (string) $pattern, $mode, false);
                $conditions[] = $cond;
                $bindings = array_merge($bindings, $b);
            }

            $sql = '('.implode(' OR ', $conditions).')';
            $method = $boolean === 'or' ? 'orWhere' : 'where';

            return $this->{$method.'Raw'}($sql, $bindings);
        });
    }

    /**
     * 注册正则提取宏
     *
     * @scenario 注册正则提取宏
     */
    public static function registerRegexpExtract(): void
    {
        /**
         * 使用正则提取子串
         *
         * @param  string  $column  源列
         * @param  string  $pattern  正则模式（需包含捕获组）
         * @param  int  $group  捕获组索引（0=完整匹配，1=第一个捕获组）
         * @param  int  $occurrence  匹配出现次数（1=第一个）
         * @param  string  $mode  匹配模式
         * @param  string  $alias  结果列别名
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * Model::query()->regexpExtract('col', $p, $g, $o, 'natural', $a)->get();
         *
         * @scenario 使用正则提取子串
         */
        static::define('regexpExtract', function (
            string $column,
            string $pattern,
            int $group = 0,
            int $occurrence = 1,
            string $mode = 'c',
            string $alias = 'extracted'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrappedColumn = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $mode = BaseMacro::assertRegexMode($mode);
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = BaseMacro::wrapIdentifier($this, $alias);

            [$expr, $bindings] = BaseMacro::regexExtractFragment($this, $wrappedColumn, $pattern, $group, $occurrence, $mode);

            return $this->selectRaw("{$expr} AS {$wrappedAlias}", $bindings);
        });

        /**
         * 提取所有匹配项为 JSON 数组
         *
         * @param  string  $column  源列
         * @param  string  $pattern  正则模式
         * @param  string  $mode  匹配模式
         * @param  string  $alias  结果列别名
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * Model::query()->regexpExtractAll('col', $p, 'natural', $a)->get();
         *
         * @scenario 提取所有匹配项为 JSON 数组
         */
        static::define('regexpExtractAll', function (
            string $column,
            string $pattern,
            string $mode = 'c',
            string $alias = 'all_matches'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrappedColumn = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $mode = BaseMacro::assertRegexMode($mode);
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = BaseMacro::wrapIdentifier($this, $alias);

            [$expr, $bindings] = BaseMacro::regexExtractAllFragment($this, $wrappedColumn, $pattern, $mode);

            return $this->selectRaw("{$expr} AS {$wrappedAlias}", $bindings);
        });
    }

    /**
     * 注册正则替换宏
     *
     * @scenario 注册正则替换宏
     */
    public static function registerRegexpReplace(): void
    {
        /**
         * 使用正则替换文本
         *
         * @param  string  $column  源列
         * @param  string  $pattern  匹配模式
         * @param  string  $replacement  替换文本（MySQL 支持 \1、\2 引用捕获组）
         * @param  int  $occurrence  替换第几次匹配（0=全部替换）
         * @param  string  $mode  匹配模式
         * @param  string  $alias  结果列别名
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * Model::query()->regexpReplace('col', $p, $r, $o, 'natural', $a)->get();
         *
         * @scenario 使用正则替换文本
         */
        static::define('regexpReplace', function (
            string $column,
            string $pattern,
            string $replacement,
            int $occurrence = 0,
            string $mode = 'c',
            string $alias = 'replaced'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrappedColumn = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $mode = BaseMacro::assertRegexMode($mode);
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = BaseMacro::wrapIdentifier($this, $alias);

            [$expr, $bindings] = BaseMacro::regexReplaceFragment($this, $wrappedColumn, $pattern, $replacement, $occurrence, $mode);

            return $this->selectRaw("{$expr} AS {$wrappedAlias}", $bindings);
        });

        /**
         * 批量正则替换（依次应用多条规则）
         *
         * @param  string  $column  源列
         * @param  array  $replacements  替换规则 [['pattern' => '...', 'replacement' => '...'], ...]
         * @param  string  $mode  匹配模式
         * @param  string  $alias  结果列别名
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * Model::query()->regexpReplaceBatch('col', $r, 'natural', $a)->get();
         *
         * @scenario 批量正则替换（依次应用多条规则）
         */
        static::define('regexpReplaceBatch', function (
            string $column,
            array $replacements,
            string $mode = 'c',
            string $alias = 'replaced'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrappedColumn = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $mode = BaseMacro::assertRegexMode($mode);
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = BaseMacro::wrapIdentifier($this, $alias);

            $expr = $wrappedColumn;
            $bindings = [];
            foreach ($replacements as $rule) {
                [$expr, $b] = BaseMacro::regexReplaceFragment(
                    $this,
                    $expr,
                    (string) ($rule['pattern'] ?? ''),
                    (string) ($rule['replacement'] ?? ''),
                    0,
                    $mode
                );
                $bindings = array_merge($bindings, $b);
            }

            return $this->selectRaw("{$expr} AS {$wrappedAlias}", $bindings);
        });
    }

    /**
     * 注册正则位置宏
     *
     * @scenario 注册正则位置宏
     */
    public static function registerRegexpPosition(): void
    {
        /**
         * 查找正则匹配位置
         *
         * @param  string  $column  源列
         * @param  string  $pattern  正则模式
         * @param  int  $occurrence  第几次匹配
         * @param  string  $mode  匹配模式
         * @param  int  $returnOption  返回选项: 0=匹配起始位置, 1=匹配结束位置
         * @param  string  $alias  结果列别名
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * Model::query()->regexpPosition('col', $p, $o, 'natural', $r, $a)->get();
         *
         * @scenario 查找正则匹配位置
         */
        static::define('regexpPosition', function (
            string $column,
            string $pattern,
            int $occurrence = 1,
            string $mode = 'c',
            int $returnOption = 0,
            string $alias = 'position'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrappedColumn = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $mode = BaseMacro::assertRegexMode($mode);
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = BaseMacro::wrapIdentifier($this, $alias);

            [$expr, $bindings] = BaseMacro::regexPositionFragment($this, $wrappedColumn, $pattern, $occurrence, $mode, $returnOption);

            return $this->selectRaw("{$expr} AS {$wrappedAlias}", $bindings);
        });
    }

    /**
     * 注册正则计数宏
     *
     * @scenario 注册正则计数宏
     */
    public static function registerRegexpCount(): void
    {
        /**
         * 统计正则匹配次数
         *
         * @param  string  $column  源列
         * @param  string  $pattern  正则模式
         * @param  string  $mode  匹配模式
         * @param  string  $alias  结果列别名
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * Model::query()->regexpCount('col', $p, 'natural', $a)->get();
         *
         * @scenario 统计正则匹配次数
         */
        static::define('regexpCount', function (
            string $column,
            string $pattern,
            string $mode = 'c',
            string $alias = 'match_count'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrappedColumn = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $mode = BaseMacro::assertRegexMode($mode);
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = BaseMacro::wrapIdentifier($this, $alias);

            [$expr, $bindings] = BaseMacro::regexCountFragment($this, $wrappedColumn, $pattern, $mode);

            return $this->selectRaw("{$expr} AS {$wrappedAlias}", $bindings);
        });

        /**
         * 按匹配次数筛选
         *
         * @param  string  $column  源列
         * @param  string  $pattern  正则模式
         * @param  int  $count  匹配次数
         * @param  string  $operator  比较运算符: =|>|<|>=|<=
         * @param  string  $mode  匹配模式
         * @param  string  $boolean  连接条件
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * Model::query()->whereRegexpCount('col', $p, 10, $o, 'natural', 'and')->get();
         *
         * @scenario 按匹配次数筛选
         */
        static::define('whereRegexpCount', function (
            string $column,
            string $pattern,
            int $count,
            string $operator = '=',
            string $mode = 'c',
            string $boolean = 'and'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrappedColumn = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $operator = BaseMacro::assertOperator($operator);
            $mode = BaseMacro::assertRegexMode($mode);
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

            [$expr, $bindings] = BaseMacro::regexCountFragment($this, $wrappedColumn, $pattern, $mode);

            return $this->{$method}("{$expr} {$operator} {$count}", $bindings);
        });
    }
}
