<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use zxf\Modules\BuilderQuery\Concerns\SqlSecurity;

/**
 * MySQL 8.0+ 正则表达式匹配宏
 *
 * 提供强大的正则表达式查询功能：
 * - REGEXP_LIKE: 正则匹配
 * - REGEXP_SUBSTR: 提取子串
 * - REGEXP_REPLACE: 替换文本
 * - REGEXP_INSTR: 定位匹配位置
 * - REGEXP_COUNT: 计数匹配
 *
 * 安全性：列名 / 别名经白名单校验并由 grammar wrap；正则模式、替换文本、
 * 匹配模式一律走参数绑定（?），彻底消除原先将模式裸拼进 SQL 导致的注入风险。
 *
 * @package zxf\Modules\BuilderQuery\WindowMacros
 * @version 2.0.0
 * @requires MySQL 8.0+
 */
class RegexMacro
{
    use SqlSecurity;

    /**
     * 注册所有正则表达式宏
     *
     * @return void
     */
    public static function register(): void
    {
        self::registerRegexpMatch();
        self::registerRegexpExtract();
        self::registerRegexpReplace();
        self::registerRegexpPosition();
        self::registerRegexpCount();
    }

    /**
     * 注册正则匹配函数
     */
    protected static function registerRegexpMatch(): void
    {
        /**
         * 正则表达式匹配筛选
         *
         * MySQL 8.0+ 支持完整的 ICU 正则表达式语法
         *
         * @param string $column 要匹配的列
         * @param string $pattern 正则表达式模式
         * @param string $mode 匹配模式: 'c'(区分大小写)|'i'(不区分)|'m'(多行)|'n'(点匹配换行)
         * @param string $boolean 连接条件: and|or
         * @return Builder
         *
         * @example
         * // 匹配邮箱格式
         * User::query()->whereRegexp('email', '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$')->get();
         *
         * // 匹配手机号（不区分大小写）
         * User::query()->whereRegexp('phone', '^1[3-9]\d{9}$', 'i')->get();
         *
         * // 不匹配正则
         * User::query()->whereNotRegexp('username', '^admin', 'i')->get();
         */
        Builder::macro('whereRegexp', function (
            string $column,
            string $pattern,
            string $mode = 'c',
            string $boolean = 'and'
        ): Builder {
            /** @var Builder $this */
            RegexMacro::assertMysql($this, 'whereRegexp');
            $wrappedColumn = RegexMacro::wrapIdentifier($this, RegexMacro::assertValidIdentifier($column, 'column'));
            $mode = RegexMacro::assertRegexMode($mode);
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

            return $this->{$method}(
                "REGEXP_LIKE({$wrappedColumn}, ?, ?)",
                [$pattern, $mode]
            );
        });

        /**
         * 正则表达式不匹配筛选
         *
         * @param string $column 要匹配的列
         * @param string $pattern 正则表达式模式
         * @param string $mode 匹配模式
         * @param string $boolean 连接条件
         * @return Builder
         */
        Builder::macro('whereNotRegexp', function (
            string $column,
            string $pattern,
            string $mode = 'c',
            string $boolean = 'and'
        ): Builder {
            /** @var Builder $this */
            RegexMacro::assertMysql($this, 'whereNotRegexp');
            $wrappedColumn = RegexMacro::wrapIdentifier($this, RegexMacro::assertValidIdentifier($column, 'column'));
            $mode = RegexMacro::assertRegexMode($mode);
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

            return $this->{$method}(
                "NOT REGEXP_LIKE({$wrappedColumn}, ?, ?)",
                [$pattern, $mode]
            );
        });

        /**
         * 匹配任意一个正则模式
         *
         * @param string $column 要匹配的列
         * @param array $patterns 正则模式数组
         * @param string $mode 匹配模式
         * @param string $boolean 连接条件
         * @return Builder
         *
         * @example
         * // 匹配多种邮箱域名
         * User::query()->whereRegexpAny('email', [
         *     '@gmail\.com$',
         *     '@yahoo\.com$',
         *     '@outlook\.com$'
         * ])->get();
         */
        Builder::macro('whereRegexpAny', function (
            string $column,
            array $patterns,
            string $mode = 'c',
            string $boolean = 'and'
        ): Builder {
            /** @var Builder $this */
            RegexMacro::assertMysql($this, 'whereRegexpAny');
            $wrappedColumn = RegexMacro::wrapIdentifier($this, RegexMacro::assertValidIdentifier($column, 'column'));
            $mode = RegexMacro::assertRegexMode($mode);
            $method = $boolean === 'or' ? 'orWhere' : 'where';

            $conditions = array_map(function ($pattern) use ($wrappedColumn, $mode) {
                return "REGEXP_LIKE({$wrappedColumn}, ?, ?)";
            }, $patterns);

            $sql = '(' . implode(' OR ', $conditions) . ')';
            $bindings = [];
            foreach ($patterns as $pattern) {
                $bindings[] = $pattern;
                $bindings[] = $mode;
            }

            return $this->{$method.'Raw'}($sql, $bindings);
        });
    }

    /**
     * 注册正则提取函数
     */
    protected static function registerRegexpExtract(): void
    {
        /**
         * 使用正则提取子串
         *
         * @param string $column 源列
         * @param string $pattern 正则模式（需包含捕获组）
         * @param int $group 捕获组索引（0=完整匹配，1=第一个捕获组）
         * @param int $occurrence 匹配出现次数（1=第一个）
         * @param string $mode 匹配模式
         * @param string $alias 结果列别名
         * @return Builder
         */
        Builder::macro('regexpExtract', function (
            string $column,
            string $pattern,
            int $group = 0,
            int $occurrence = 1,
            string $mode = 'c',
            string $alias = 'extracted'
        ): Builder {
            /** @var Builder $this */
            RegexMacro::assertMysql($this, 'regexpExtract');
            $wrappedColumn = RegexMacro::wrapIdentifier($this, RegexMacro::assertValidIdentifier($column, 'column'));
            $mode = RegexMacro::assertRegexMode($mode);
            $alias = RegexMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = RegexMacro::wrapIdentifier($this, $alias);

            $expr = "REGEXP_SUBSTR({$wrappedColumn}, ?, 1, ?, ?, ?)";

            return $this->selectRaw("{$expr} AS {$wrappedAlias}", [$pattern, $occurrence, $mode, $group]);
        });

        /**
         * 提取所有匹配项为数组
         *
         * @param string $column 源列
         * @param string $pattern 正则模式
         * @param string $mode 匹配模式
         * @param string $alias 结果列别名
         * @return Builder
         */
        Builder::macro('regexpExtractAll', function (
            string $column,
            string $pattern,
            string $mode = 'c',
            string $alias = 'all_matches'
        ): Builder {
            /** @var Builder $this */
            RegexMacro::assertMysql($this, 'regexpExtractAll');
            $wrappedColumn = RegexMacro::wrapIdentifier($this, RegexMacro::assertValidIdentifier($column, 'column'));
            $mode = RegexMacro::assertRegexMode($mode);
            $alias = RegexMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = RegexMacro::wrapIdentifier($this, $alias);

            $expr = "(
                SELECT JSON_ARRAYAGG(m.match_text)
                FROM (
                    SELECT REGEXP_SUBSTR({$wrappedColumn}, ?, 1, n.n, ?, 0) as match_text
                    FROM (
                        SELECT a.N + b.N * 10 + 1 n
                        FROM 
                            (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a,
                            (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b
                        ORDER BY n
                    ) n
                    WHERE REGEXP_SUBSTR({$wrappedColumn}, ?, 1, n.n, ?, 0) IS NOT NULL
                      AND n.n <= 100
                ) m
                WHERE m.match_text IS NOT NULL
            )";

            return $this->selectRaw("{$expr} AS {$wrappedAlias}", [$pattern, $mode, $pattern, $mode]);
        });
    }

    /**
     * 注册正则替换函数
     */
    protected static function registerRegexpReplace(): void
    {
        /**
         * 使用正则替换文本
         *
         * @param string $column 源列
         * @param string $pattern 匹配模式
         * @param string $replacement 替换文本（支持 $1, $2 引用捕获组）
         * @param int $occurrence 替换第几次匹配（0=全部替换）
         * @param string $mode 匹配模式
         * @param string $alias 结果列别名
         * @return Builder
         */
        Builder::macro('regexpReplace', function (
            string $column,
            string $pattern,
            string $replacement,
            int $occurrence = 0,
            string $mode = 'c',
            string $alias = 'replaced'
        ): Builder {
            /** @var Builder $this */
            RegexMacro::assertMysql($this, 'regexpReplace');
            $wrappedColumn = RegexMacro::wrapIdentifier($this, RegexMacro::assertValidIdentifier($column, 'column'));
            $mode = RegexMacro::assertRegexMode($mode);
            $alias = RegexMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = RegexMacro::wrapIdentifier($this, $alias);

            $expr = "REGEXP_REPLACE({$wrappedColumn}, ?, ?, 1, ?, ?)";

            return $this->selectRaw("{$expr} AS {$wrappedAlias}", [$pattern, $replacement, $occurrence, $mode]);
        });

        /**
         * 批量正则替换
         *
         * @param string $column 源列
         * @param array $replacements 替换规则 [['pattern' => '...', 'replacement' => '...'], ...]
         * @param string $mode 匹配模式
         * @param string $alias 结果列别名
         * @return Builder
         */
        Builder::macro('regexpReplaceBatch', function (
            string $column,
            array $replacements,
            string $mode = 'c',
            string $alias = 'replaced'
        ): Builder {
            /** @var Builder $this */
            RegexMacro::assertMysql($this, 'regexpReplaceBatch');
            $wrappedColumn = RegexMacro::wrapIdentifier($this, RegexMacro::assertValidIdentifier($column, 'column'));
            $mode = RegexMacro::assertRegexMode($mode);
            $alias = RegexMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = RegexMacro::wrapIdentifier($this, $alias);

            $expr = $wrappedColumn;
            $bindings = [];

            foreach ($replacements as $rule) {
                $expr = "REGEXP_REPLACE({$expr}, ?, ?, 1, 0, ?)";
                $bindings[] = $rule['pattern'];
                $bindings[] = $rule['replacement'];
                $bindings[] = $mode;
            }

            return $this->selectRaw("{$expr} AS {$wrappedAlias}", $bindings);
        });
    }

    /**
     * 注册正则位置函数
     */
    protected static function registerRegexpPosition(): void
    {
        /**
         * 查找正则匹配位置
         *
         * @param string $column 源列
         * @param string $pattern 正则模式
         * @param int $occurrence 第几次匹配
         * @param string $mode 匹配模式
         * @param int $returnOption 返回选项: 0=位置, 1=匹配后位置
         * @param string $alias 结果列别名
         * @return Builder
         */
        Builder::macro('regexpPosition', function (
            string $column,
            string $pattern,
            int $occurrence = 1,
            string $mode = 'c',
            int $returnOption = 0,
            string $alias = 'position'
        ): Builder {
            /** @var Builder $this */
            RegexMacro::assertMysql($this, 'regexpPosition');
            $wrappedColumn = RegexMacro::wrapIdentifier($this, RegexMacro::assertValidIdentifier($column, 'column'));
            $mode = RegexMacro::assertRegexMode($mode);
            $alias = RegexMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = RegexMacro::wrapIdentifier($this, $alias);

            $expr = "REGEXP_INSTR({$wrappedColumn}, ?, 1, ?, ?, ?)";

            return $this->selectRaw("{$expr} AS {$wrappedAlias}", [$pattern, $occurrence, $returnOption, $mode]);
        });
    }

    /**
     * 注册正则计数函数
     */
    protected static function registerRegexpCount(): void
    {
        /**
         * 统计正则匹配次数
         *
         * @param string $column 源列
         * @param string $pattern 正则模式
         * @param string $mode 匹配模式
         * @param string $alias 结果列别名
         * @return Builder
         */
        Builder::macro('regexpCount', function (
            string $column,
            string $pattern,
            string $mode = 'c',
            string $alias = 'match_count'
        ): Builder {
            /** @var Builder $this */
            RegexMacro::assertMysql($this, 'regexpCount');
            $wrappedColumn = RegexMacro::wrapIdentifier($this, RegexMacro::assertValidIdentifier($column, 'column'));
            $mode = RegexMacro::assertRegexMode($mode);
            $alias = RegexMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = RegexMacro::wrapIdentifier($this, $alias);

            $expr = "REGEXP_COUNT({$wrappedColumn}, ?, 1, ?)";

            return $this->selectRaw("{$expr} AS {$wrappedAlias}", [$pattern, $mode]);
        });

        /**
         * 按匹配次数筛选
         *
         * @param string $column 源列
         * @param string $pattern 正则模式
         * @param int $count 匹配次数
         * @param string $operator 比较运算符: =|>|<|>=|<=
         * @param string $mode 匹配模式
         * @param string $boolean 连接条件
         * @return Builder
         */
        Builder::macro('whereRegexpCount', function (
            string $column,
            string $pattern,
            int $count,
            string $operator = '=',
            string $mode = 'c',
            string $boolean = 'and'
        ): Builder {
            /** @var Builder $this */
            RegexMacro::assertMysql($this, 'whereRegexpCount');
            $wrappedColumn = RegexMacro::wrapIdentifier($this, RegexMacro::assertValidIdentifier($column, 'column'));
            $operator = RegexMacro::assertOperator($operator);
            $mode = RegexMacro::assertRegexMode($mode);
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

            return $this->{$method}(
                "REGEXP_COUNT({$wrappedColumn}, ?, 1, ?) {$operator} {$count}",
                [$pattern, $mode]
            );
        });
    }
}
