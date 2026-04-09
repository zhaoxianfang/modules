<?php

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;

/**
 * MySQL 8.4+ 正则表达式匹配宏
 *
 * 提供强大的正则表达式查询功能：
 * - REGEXP_LIKE: 正则匹配
 * - REGEXP_SUBSTR: 提取子串
 * - REGEXP_REPLACE: 替换文本
 * - REGEXP_INSTR: 定位匹配位置
 * - REGEXP_COUNT: 计数匹配
 *
 * @package zxf\Modules\BuilderQuery\WindowMacros
 * @version 1.0.0
 * @requires MySQL 8.4+
 */
class RegexMacro
{
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
         * MySQL 8.4+ 支持完整的 ICU 正则表达式语法
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
         * // 匹配中文字符
         * Article::query()->whereRegexp('content', '[\x{4e00}-\x{9fa5}]', 'u')->get();
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
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

            // 转义正则中的单引号
            $escapedPattern = str_replace("'", "''", $pattern);

            return $this->{$method}(
                "REGEXP_LIKE(`{$column}`, '{$escapedPattern}', '{$mode}')"
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
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

            $escapedPattern = str_replace("'", "''", $pattern);

            return $this->{$method}(
                "NOT REGEXP_LIKE(`{$column}`, '{$escapedPattern}', '{$mode}')"
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
            $method = $boolean === 'or' ? 'orWhere' : 'where';

            $conditions = array_map(function ($pattern) use ($column, $mode) {
                $escapedPattern = str_replace("'", "''", $pattern);
                return "REGEXP_LIKE(`{$column}`, '{$escapedPattern}', '{$mode}')";
            }, $patterns);

            $sql = '(' . implode(' OR ', $conditions) . ')';

            return $this->{$method}(\Illuminate\Support\Facades\DB::raw($sql));
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
         *
         * @example
         * // 提取邮箱域名
         * User::query()->regexpExtract('email', '@([^@]+)$', 1, 1, 'i', 'domain')->get();
         *
         * // 提取区号
         * User::query()->regexpExtract('phone', '^(\d{3,4})-', 1, 1, 'c', 'area_code')->get();
         *
         * // 提取所有数字
         * Order::query()->regexpExtract('order_no', '\d+', 0, 1, 'c', 'order_number')->get();
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
            $escapedPattern = str_replace("'", "''", $pattern);

            $expr = "REGEXP_SUBSTR(`{$column}`, '{$escapedPattern}', 1, {$occurrence}, '{$mode}', {$group})";

            return $this->selectRaw("{$expr} AS `{$alias}`");
        });

        /**
         * 提取所有匹配项为数组
         *
         * @param string $column 源列
         * @param string $pattern 正则模式
         * @param string $mode 匹配模式
         * @param string $alias 结果列别名
         * @return Builder
         *
         * @example
         * // 提取文本中所有邮箱
         * Article::query()->regexpExtractAll('content', '[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}', 'i', 'emails')->get();
         */
        Builder::macro('regexpExtractAll', function (
            string $column,
            string $pattern,
            string $mode = 'c',
            string $alias = 'all_matches'
        ): Builder {
            /** @var Builder $this */
            $escapedPattern = str_replace("'", "''", $pattern);

            // 使用 JSON_ARRAYAGG 收集所有匹配
            $expr = "(
                SELECT JSON_ARRAYAGG(m.match_text)
                FROM (
                    SELECT REGEXP_SUBSTR(`{$column}`, '{$escapedPattern}', 1, n.n, '{$mode}', 0) as match_text
                    FROM (
                        SELECT a.N + b.N * 10 + 1 n
                        FROM 
                            (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a,
                            (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b
                        ORDER BY n
                    ) n
                    WHERE REGEXP_SUBSTR(`{$column}`, '{$escapedPattern}', 1, n.n, '{$mode}', 0) IS NOT NULL
                      AND n.n <= 100
                ) m
                WHERE m.match_text IS NOT NULL
            )";

            return $this->selectRaw("{$expr} AS `{$alias}`");
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
         *
         * @example
         * // 隐藏手机号中间四位
         * User::query()->regexpReplace('phone', '(\d{3})\d{4}(\d{4})', '$1****$2', 0, 'c', 'masked_phone')->get();
         *
         * // 格式化日期
         * Log::query()->regexpReplace('raw_date', '(\d{4})(\d{2})(\d{2})', '$1-$2-$3', 0, 'c', 'formatted_date')->get();
         *
         * // 移除所有HTML标签
         * Article::query()->regexpReplace('content', '<[^>]+>', '', 0, 'i', 'plain_text')->get();
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
            $escapedPattern = str_replace("'", "''", $pattern);
            $escapedReplacement = str_replace("'", "''", $replacement);

            $expr = "REGEXP_REPLACE(`{$column}`, '{$escapedPattern}', '{$escapedReplacement}', 1, {$occurrence}, '{$mode}')";

            return $this->selectRaw("{$expr} AS `{$alias}`");
        });

        /**
         * 批量正则替换
         *
         * @param string $column 源列
         * @param array $replacements 替换规则 [['pattern' => '...', 'replacement' => '...'], ...]
         * @param string $mode 匹配模式
         * @param string $alias 结果列别名
         * @return Builder
         *
         * @example
         * // 多重替换
         * Article::query()->regexpReplaceBatch('content', [
         *     ['pattern' => '<script[^>]*>.*?</script>', 'replacement' => ''],
         *     ['pattern' => '<[^>]+>', 'replacement' => ''],
         *     ['pattern' => '\s+', 'replacement' => ' ']
         * ], 'i', 'clean_content')->get();
         */
        Builder::macro('regexpReplaceBatch', function (
            string $column,
            array $replacements,
            string $mode = 'c',
            string $alias = 'replaced'
        ): Builder {
            /** @var Builder $this */
            $expr = "`{$column}`";

            foreach ($replacements as $rule) {
                $pattern = str_replace("'", "''", $rule['pattern']);
                $replacement = str_replace("'", "''", $rule['replacement']);
                $expr = "REGEXP_REPLACE({$expr}, '{$pattern}', '{$replacement}', 1, 0, '{$mode}')";
            }

            return $this->selectRaw("{$expr} AS `{$alias}`");
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
         *
         * @example
         * // 查找第一个数字的位置
         * Product::query()->regexpPosition('sku', '\d', 1, 'c', 0, 'num_pos')->get();
         *
         * // 查找域名开始位置
         * User::query()->regexpPosition('email', '@', 1, 'c', 1, 'domain_start')->get();
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
            $escapedPattern = str_replace("'", "''", $pattern);

            $expr = "REGEXP_INSTR(`{$column}`, '{$escapedPattern}', 1, {$occurrence}, {$returnOption}, '{$mode}')";

            return $this->selectRaw("{$expr} AS `{$alias}`");
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
         *
         * @example
         * // 统计文章中的链接数量
         * Article::query()->regexpCount('content', 'https?://[^\s<>"\']+', 'i', 'link_count')->get();
         *
         * // 统计单词数量（简单估算）
         * Article::query()->regexpCount('content', '\b\w+\b', 'c', 'word_count')->get();
         *
         * // 统计换行次数
         * Document::query()->regexpCount('text', '\n', 'c', 'newline_count')->get();
         */
        Builder::macro('regexpCount', function (
            string $column,
            string $pattern,
            string $mode = 'c',
            string $alias = 'match_count'
        ): Builder {
            /** @var Builder $this */
            $escapedPattern = str_replace("'", "''", $pattern);

            $expr = "REGEXP_COUNT(`{$column}`, '{$escapedPattern}', 1, '{$mode}')";

            return $this->selectRaw("{$expr} AS `{$alias}`");
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
         *
         * @example
         * // 查找包含至少3个链接的文章
         * Article::query()->whereRegexpCount('content', 'https?://', 3, '>=', 'i')->get();
         *
         * // 查找没有邮箱的文本
         * Document::query()->whereRegexpCount('content', '[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}', 0, '=', 'i')->get();
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
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
            $escapedPattern = str_replace("'", "''", $pattern);

            return $this->{$method}(
                "REGEXP_COUNT(`{$column}`, '{$escapedPattern}', 1, '{$mode}') {$operator} {$count}"
            );
        });
    }
}
