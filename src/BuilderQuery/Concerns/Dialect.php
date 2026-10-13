<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\Concerns;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

/**
 * 方言抽象层（Dialect Abstraction）
 * 本 trait 是「BuilderQuery 宏扩展」实现 MySQL 8.0+ 与 PostgreSQL 15+ 双数据库通用的核心。
 * 所有依赖数据库专属语法的宏（正则、JSON、字符串函数、全文检索、抽样、批量写、随机数等）
 * 都必须经由此层生成 SQL 片段，绝不在宏类内硬编码 `REGEXP_LIKE` / `JSON_EXTRACT` / 反引号等
 * 某一数据库的专属写法。
 * 设计原则：
 *  1. 驱动识别 —— {@see self::driver()} 返回当前连接驱动名（mysql / pgsql / sqlite …），
 *     宏据此走对应分支；{@see self::assertSupportedOn()} 在功能不支持当前驱动时抛出清晰异常。
 *  2. 片段即契约 —— 每个方法返回「SQL 片段 + 绑定数组」的二元组 `[string $sql, array $bindings]`，
 *     片段中的字面值一律用 `?` 占位、随绑定数组下传，杜绝字符串拼接导致的注入。
 *  3. 标识符安全 —— 列名 / 别名经 {@see SqlSecurity::assertValidIdentifier()} 白名单校验，
 *     再用 grammar::wrap()（{@see SqlSecurity::wrapIdentifier()}）生成适配当前驱动的引用，
 *     MySQL 得到反引号、PostgreSQL/SQLite 得到双引号，无需任何硬编码。
 *  4. 等价优先 —— 优先使用两种数据库语义一致的标准写法（窗口函数、CTE、LATERAL、INTERSECT/EXCEPT、
 *     GROUP BY 条件透视等）；仅在函数名不一致时做分支（如 FIND_IN_SET→ANY(string_to_array)）。
 * 覆盖范围（结合 Laravel 11+ / MySQL 8.0+ / PostgreSQL 15+ 的能力）：
 *  - 正则：匹配 / 提取 / 替换 / 位置 / 计数 / 全量提取
 *  - JSON：提取（含类型转换）/ 路径存在 / 包含 / 长度 / 键 / 搜索 / 数组聚合 / 对象聚合 / 行聚合
 *          / 设值 / 删除键 / 合并 / 数组追加 / 数组移除
 *  - 字符串：LOCATE / CHAR_LENGTH / FIELD / FIND_IN_SET / CONCAT_WS / SUBSTRING_INDEX /
 *           GROUP_CONCAT(string_agg) / SOUNDEX / 自然排序 / 全文检索(MATCH vs tsvector)
 *  - 抽样 / 批量写（VALUES、UPSERT）/ 随机函数
 *
 * @internal 仅用于 BuilderQuery 内部宏实现；所有方法均声明为 public static 以便跨 Macroable 作用域调用
 */
trait Dialect
{
    use SqlSecurity;

    /* =====================================================================
     * 1. 驱动识别
     * ===================================================================== */

    /**
     * 获取当前查询连接使用的数据库驱动名。
     *
     * @return string 小写驱动名，如 'mysql' / 'pgsql' / 'sqlite'
     */
    public static function driver(EloquentBuilder|QueryBuilder $builder): string
    {
        return strtolower((string) $builder->getConnection()->getDriverName());
    }

    /** 当前是否为 MySQL 8.0+ 驱动。 */
    public static function isMySql(EloquentBuilder|QueryBuilder $builder): bool
    {
        return self::driver($builder) === 'mysql';
    }

    /** 当前是否为 PostgreSQL 15+ 驱动。 */
    public static function isPostgres(EloquentBuilder|QueryBuilder $builder): bool
    {
        return self::driver($builder) === 'pgsql';
    }

    /**
     * 断言当前驱动在受支持列表内，否则抛出清晰异常。
     *
     * @param  string  $feature  功能名（用于异常提示）
     * @param  string[]  $drivers  受支持驱动白名单，默认 ['mysql', 'pgsql']
     *
     * @throws InvalidArgumentException
     */
    public static function assertSupportedOn(
        EloquentBuilder|QueryBuilder $builder,
        string $feature,
        array $drivers = ['mysql', 'pgsql']
    ): void {
        $current = self::driver($builder);
        if (! in_array($current, $drivers, true)) {
            throw new InvalidArgumentException(
                sprintf('宏 [%s] 仅支持驱动: %s；当前驱动为 [%s]。', $feature, implode('/', $drivers), $current)
            );
        }
    }

    /* =====================================================================
     * 2. 正则表达式
     * ===================================================================== */

    /**
     * 将 MySQL 正则 match type（c/i/m/n）映射为 PostgreSQL 内联修饰符。
     * MySQL: c=区分大小写(默认) / i=不区分 / m=多行 / n=.匹配换行
     * PG:    i=不区分 / m=多行 / s=.匹配换行（PG 无 n，用 s 等价）
     *
     * @return string 内联修饰符串，如 'im'；空串表示默认
     */
    public static function pgRegexInlineFlags(string $mode): string
    {
        $mode = strtolower(trim($mode));
        $flags = '';
        if (str_contains($mode, 'i')) {
            $flags .= 'i';
        }
        if (str_contains($mode, 'm')) {
            $flags .= 'm';
        }
        if (str_contains($mode, 'n')) {
            $flags .= 's';
        }

        return $flags;
    }

    /**
     * 正则匹配片段（WHERE 条件用）。
     *
     * @param  string  $wrappedColumn  已 wrap 的安全列名
     * @param  string  $pattern  正则模式（字面值，走绑定）
     * @param  string  $mode  c/i/m/n 子集
     * @param  bool  $negate  是否取反（NOT / !~）
     * @return array{0: string, 1: array} [sql 片段, 绑定]
     */
    public static function regexMatchFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        string $pattern,
        string $mode,
        bool $negate = false
    ): array {
        if (self::isPostgres($builder)) {
            $flags = self::pgRegexInlineFlags($mode);
            $finalPattern = $flags !== '' ? '(?'.$flags.')'.$pattern : $pattern;
            $operator = $negate ? '!~' : '~';

            return ["{$wrappedColumn} {$operator} ?", [$finalPattern]];
        }

        self::assertSupportedOn($builder, 'whereRegexp');
        $sql = $negate
            ? "NOT REGEXP_LIKE({$wrappedColumn}, ?, ?)"
            : "REGEXP_LIKE({$wrappedColumn}, ?, ?)";

        return [$sql, [$pattern, $mode]];
    }

    /**
     * 正则替换片段（SELECT 表达式用）。
     *
     * @param  int  $occurrence  0=全部，>0=第 N 次
     * @return array{0: string, 1: array}
     */
    public static function regexReplaceFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        string $pattern,
        string $replacement,
        int $occurrence,
        string $mode
    ): array {
        if (self::isPostgres($builder)) {
            $flags = self::pgRegexInlineFlags($mode);
            if ($occurrence <= 0) {
                $occFlag = 'g';

                return ["regexp_replace({$wrappedColumn}, ?, ?, ?)", [$pattern, $replacement, $occFlag]];
            }

            return ["regexp_replace({$wrappedColumn}, ?, ?, 1, ?, ?)", [$pattern, $replacement, $occurrence, $flags]];
        }

        self::assertSupportedOn($builder, 'regexpReplace');

        return ["REGEXP_REPLACE({$wrappedColumn}, ?, ?, 1, ?, ?)", [$pattern, $replacement, $occurrence, $mode]];
    }

    /**
     * 正则提取片段（SELECT 表达式用）。
     *
     * @param  int  $group  捕获组索引（0=完整匹配，1=第一组）
     * @param  int  $occurrence  第几次匹配（1=第一次）
     * @return array{0: string, 1: array}
     */
    public static function regexExtractFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        string $pattern,
        int $group,
        int $occurrence,
        string $mode
    ): array {
        if (self::isPostgres($builder)) {
            // PG regexp_match 返回 text[]，元素 1 为完整匹配，故组 N 取 [N+1]；
            // regexp_matches(..., 'g') 返回所有匹配行，配 WITH ORDINALITY 取第 occurrence 次。
            $sql = sprintf(
                '(SELECT (rm.match)[?] FROM regexp_matches(%s, ?, \'g\') WITH ORDINALITY AS rm(match, ord) WHERE rm.ord = ?)',
                $wrappedColumn
            );

            return [$sql, [$group + 1, $pattern, $occurrence]];
        }

        self::assertSupportedOn($builder, 'regexpExtract');

        // MySQL：REGEXP_SUBSTR(expr, pattern, position, occurrence, match_type, capture_group)
        // 第 3 个参数 position（起始位置）固定为 1（字面量），故共 4 个占位符、4 个绑定值。
        return ["REGEXP_SUBSTR({$wrappedColumn}, ?, 1, ?, ?, ?)", [$pattern, $occurrence, $mode, $group]];
    }

    /**
     * 正则匹配位置片段（SELECT 表达式用）。
     *
     * @param  int  $returnOption  0=匹配起始位置，1=匹配结束位置
     * @return array{0: string, 1: array}
     */
    public static function regexPositionFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        string $pattern,
        int $occurrence,
        string $mode,
        int $returnOption
    ): array {
        if (self::isPostgres($builder)) {
            // PostgreSQL：regexp_matches(..., 'g') WITH ORDINALITY 取得第 occurrence 次匹配，
            // strpos 取其在原串中的 1 基起始位置；returnOption=1 时返回结束位置（起始 + 长度 - 1）。
            // 无匹配时 COALESCE 回退为 0，与 MySQL REGEXP_INSTR 的语义对齐。
            $sql = sprintf(
                'COALESCE((SELECT CASE WHEN rm.match IS NULL THEN 0
                       ELSE strpos(%1$s, rm.match[1]) + CASE WHEN ? = 1 THEN length(rm.match[1]) - 1 ELSE 0 END END
                  FROM regexp_matches(%1$s, ?, \'g\') WITH ORDINALITY AS rm(match, ord)
                  WHERE rm.ord = ?), 0)',
                $wrappedColumn
            );

            return [$sql, [$returnOption, $pattern, $occurrence]];
        }

        self::assertSupportedOn($builder, 'regexpPosition');

        // MySQL：REGEXP_INSTR(expr, pat, pos, occurrence, return_option, match_type)
        // 第 3 个参数 pos（起始位置）固定为 1（字面量），故共 4 个占位符、4 个绑定值。
        return ["REGEXP_INSTR({$wrappedColumn}, ?, 1, ?, ?, ?)", [$pattern, $occurrence, $returnOption, $mode]];
    }

    /**
     * 正则计数片段（SELECT 表达式用）。
     *
     * @return array{0: string, 1: array}
     */
    public static function regexCountFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        string $pattern,
        string $mode
    ): array {
        if (self::isPostgres($builder)) {
            // PostgreSQL 15+ 提供 regexp_count。
            return ["regexp_count({$wrappedColumn}, ?)", [$pattern]];
        }

        self::assertSupportedOn($builder, 'regexpCount');

        return ["REGEXP_COUNT({$wrappedColumn}, ?, 1, ?)", [$pattern, $mode]];
    }

    /**
     * 正则全量提取为 JSON 数组片段（SELECT 表达式用）。
     *
     * @return array{0: string, 1: array}
     */
    public static function regexExtractAllFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        string $pattern,
        string $mode
    ): array {
        if (self::isPostgres($builder)) {
            $sql = "COALESCE((SELECT jsonb_agg(m.m) FROM regexp_matches({$wrappedColumn}, ?, 'g') AS t(m)), '[]'::jsonb)";

            return [$sql, [$pattern]];
        }

        self::assertSupportedOn($builder, 'regexpExtractAll');

        return [self::mysqlRegexExtractAllSql($wrappedColumn), [$pattern, $mode, $pattern, $mode]];
    }

    /**
     * MySQL 正则全量提取的 SQL（含 0-99 数字序列表，提取前 100 个匹配）。
     */
    protected static function mysqlRegexExtractAllSql(string $wrappedColumn): string
    {
        return sprintf(
            '(SELECT JSON_ARRAYAGG(m.match_text)
              FROM (SELECT REGEXP_SUBSTR(%1$s, ?, 1, n.n, ?, 0) AS match_text
                    FROM (SELECT a.N + b.N * 10 + 1 n
                          FROM (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
                                UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a,
                               (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
                                UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b
                          ORDER BY n) n
                    WHERE REGEXP_SUBSTR(%1$s, ?, 1, n.n, ?, 0) IS NOT NULL AND n.n <= 100) m
              WHERE m.match_text IS NOT NULL)',
            $wrappedColumn
        );
    }

    /* =====================================================================
     * 3. JSON 操作
     * ===================================================================== */

    /**
     * 将 MySQL 风格 JSON Path（$.a.b[0]）直接作为 PostgreSQL jsonpath 使用。
     * 两种数据库 jsonpath 语法高度一致（均 0 基下标、点号导航），可直接透传。
     */
    public static function jsonPathForPg(string $path): string
    {
        return $path;
    }

    /**
     * JSON 提取值并可选类型转换片段。
     *
     * @param  string  $path  已校验的 JSON Path
     * @param  string  $type  string|int|float|bool|datetime|date|time|json
     * @return array{0: string, 1: array}
     */
    public static function jsonExtractFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        string $path,
        string $type = 'string'
    ): array {
        if (self::isPostgres($builder)) {
            $base = "jsonb_path_query_first({$wrappedColumn}::jsonb, ?)";
            // 使用 #>> '{}' 取出标量文本（去除 JSON 引号），与 MySQL 的 JSON_UNQUOTE 语义对齐。
            $sql = match (strtolower($type)) {
                'int', 'integer' => "({$base} #>> '{}')::int",
                'float', 'double', 'decimal' => "({$base} #>> '{}')::numeric",
                'bool', 'boolean' => "({$base} #>> '{}')::boolean",
                'datetime' => "to_timestamp({$base} #>> '{}', 'YYYY-MM-DD\"T\"HH24:MI:SS')",
                'date' => "to_date({$base} #>> '{}', 'YYYY-MM-DD')",
                'time' => "(to_timestamp({$base} #>> '{}', 'HH24:MI:SS'))::time",
                'json' => $base,
                default => "{$base} #>> '{}'",
            };

            return [$sql, [$path]];
        }

        self::assertSupportedOn($builder, 'jsonExtract');
        $base = "JSON_UNQUOTE(JSON_EXTRACT({$wrappedColumn}, ?))";
        $sql = match (strtolower($type)) {
            'int', 'integer' => "CAST({$base} AS SIGNED)",
            'float', 'double', 'decimal' => "CAST({$base} AS DECIMAL(15,4))",
            'bool', 'boolean' => "JSON_EXTRACT({$wrappedColumn}, ?) = true",
            'datetime' => "STR_TO_DATE({$base}, '%Y-%m-%dT%H:%i:%s')",
            'date' => "STR_TO_DATE({$base}, '%Y-%m-%d')",
            'time' => "STR_TO_DATE({$base}, '%H:%i:%s')",
            'json' => "JSON_EXTRACT({$wrappedColumn}, ?)",
            default => $base,
        };

        // 所有类型分支的 SQL 均只含一个路径占位符，绑定统一为 [$path]。
        $bindings = [$path];

        return [$sql, $bindings];
    }

    /**
     * JSON 路径存在片段。
     *
     * @return array{0: string, 1: array}
     */
    public static function jsonPathExistsFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        string $path
    ): array {
        if (self::isPostgres($builder)) {
            return ["jsonb_path_exists({$wrappedColumn}::jsonb, ?)", [$path]];
        }

        self::assertSupportedOn($builder, 'whereJsonPathExists');

        return ["JSON_CONTAINS_PATH({$wrappedColumn}, 'one', ?)", [$path]];
    }

    /**
     * JSON 包含片段（数组/对象包含某值）。
     *
     * @param  string  $jsonLiteral  已 json_encode 的字面量（走绑定）
     * @param  string|null  $path  嵌套路径（可空）
     * @return array{0: string, 1: array}
     */
    public static function jsonContainsFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        string $jsonLiteral,
        ?string $path = null
    ): array {
        if (self::isPostgres($builder)) {
            if ($path !== null) {
                return ["jsonb_path_query_first({$wrappedColumn}::jsonb, ?) @> ?::jsonb", [$path, $jsonLiteral]];
            }

            return ["{$wrappedColumn}::jsonb @> ?::jsonb", [$jsonLiteral]];
        }

        self::assertSupportedOn($builder, 'whereJsonArrayContains');
        $target = $path !== null ? "JSON_EXTRACT({$wrappedColumn}, ?)" : $wrappedColumn;
        $bindings = $path !== null ? [$path, $jsonLiteral] : [$jsonLiteral];

        return ["JSON_CONTAINS({$target}, ?)", $bindings];
    }

    /**
     * JSON 长度片段。
     *
     * @return array{0: string, 1: array}
     */
    public static function jsonLengthFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        ?string $path = null
    ): array {
        if (self::isPostgres($builder)) {
            if ($path !== null) {
                return ["jsonb_array_length(jsonb_path_query_first({$wrappedColumn}::jsonb, ?))", [$path]];
            }

            return ["jsonb_array_length({$wrappedColumn}::jsonb)", []];
        }

        self::assertSupportedOn($builder, 'jsonArrayLength');
        $target = $path !== null ? "JSON_EXTRACT({$wrappedColumn}, ?)" : $wrappedColumn;
        $bindings = $path !== null ? [$path] : [];

        return ["JSON_LENGTH({$target})", $bindings];
    }

    /**
     * JSON 键片段。
     *
     * @return array{0: string, 1: array}
     */
    public static function jsonKeysFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        ?string $path = null
    ): array {
        if (self::isPostgres($builder)) {
            if ($path !== null) {
                return ["to_jsonb(ARRAY(SELECT jsonb_object_keys(jsonb_path_query_first({$wrappedColumn}::jsonb, ?))))", [$path]];
            }

            return ["to_jsonb(ARRAY(SELECT jsonb_object_keys({$wrappedColumn}::jsonb)))", []];
        }

        self::assertSupportedOn($builder, 'jsonKeys');
        $target = $path !== null ? "JSON_EXTRACT({$wrappedColumn}, ?)" : $wrappedColumn;
        $bindings = $path !== null ? [$path] : [];

        return ["JSON_KEYS({$target})", $bindings];
    }

    /**
     * JSON 搜索片段（PG 返回首个匹配值，MySQL 返回路径；语义近似）。
     *
     * @return array{0: string, 1: array}
     */
    public static function jsonSearchFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        string $search,
        string $mode,
        string $path
    ): array {
        if (self::isPostgres($builder)) {
            // 使用 jsonpath 变量 $search 承载搜索值，避免把用户输入插值进 SQL 字面量
            // （原先的手动单引号转义在含引号文本下会破坏 SQL 字符串边界，存在语法/注入隐患）。
            return [
                "jsonb_path_query_first({$wrappedColumn}::jsonb, '\$.** ? (@ == \$search)', jsonb_build_object('search', ?))",
                [$search],
            ];
        }

        self::assertSupportedOn($builder, 'jsonSearch');
        if (! in_array($mode, ['one', 'all'], true)) {
            throw new InvalidArgumentException("JSON_SEARCH mode 仅支持 'one' 或 'all'，收到 '{$mode}'");
        }
        $searchValue = json_encode($search, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return ["JSON_SEARCH({$wrappedColumn}, ?, ?, NULL, ?)", [$mode, $searchValue, $path]];
    }

    /**
     * JSON 数组聚合片段（jsonb_agg / JSON_ARRAYAGG）。
     *
     * @return array{0: string, 1: array}
     */
    public static function jsonArrayAggFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        ?string $orderColumn = null,
        ?string $orderDirection = null
    ): array {
        if (self::isPostgres($builder)) {
            $expr = $orderColumn !== null
                ? "jsonb_agg({$wrappedColumn} ORDER BY ".self::wrapIdentifier($builder, $orderColumn).' '.self::assertDirection((string) $orderDirection)
                : "jsonb_agg({$wrappedColumn})";

            return [$expr, []];
        }

        self::assertSupportedOn($builder, 'jsonArrayAgg');
        $expr = $orderColumn !== null
            ? "JSON_ARRAYAGG({$wrappedColumn} ORDER BY ".self::wrapIdentifier($builder, $orderColumn).' '.self::assertDirection((string) $orderDirection).')'
            : "JSON_ARRAYAGG({$wrappedColumn})";

        return [$expr, []];
    }

    /**
     * JSON 对象聚合片段。
     *
     * @return array{0: string, 1: array}
     */
    public static function jsonObjectAggFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedKey,
        string $wrappedValue
    ): array {
        if (self::isPostgres($builder)) {
            return ["jsonb_object_agg({$wrappedKey}, {$wrappedValue})", []];
        }

        self::assertSupportedOn($builder, 'jsonObjectAgg');

        return ["JSON_OBJECTAGG({$wrappedKey}, {$wrappedValue})", []];
    }

    /**
     * JSON 行聚合片段（多列合成为对象数组）。
     *
     * @param  array<string,string>  $columnPairs  ['别名' => 已wrap列名, ...]
     * @return array{0: string, 1: array}
     */
    public static function jsonRowAggFragment(
        EloquentBuilder|QueryBuilder $builder,
        array $columnPairs
    ): array {
        $parts = [];
        foreach ($columnPairs as $alias => $wrappedColumn) {
            $parts[] = "'".self::assertValidIdentifier((string) $alias, 'alias')."'";
            $parts[] = $wrappedColumn;
        }
        $objectExpr = 'JSON_OBJECT('.implode(', ', $parts).')';

        if (self::isPostgres($builder)) {
            return ['jsonb_agg('.$objectExpr.')', []];
        }

        self::assertSupportedOn($builder, 'jsonRowAgg');

        return ['JSON_ARRAYAGG('.$objectExpr.')', []];
    }

    /**
     * JSON 设值（UPDATE 表达式，SET 段用）。
     *
     * @return array{0: string, 1: array}
     */
    public static function jsonSetFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        string $path,
        string $jsonValue,
        bool $insert
    ): array {
        if (self::isPostgres($builder)) {
            $func = $insert ? 'jsonb_insert' : 'jsonb_set';

            return ["{$func}({$wrappedColumn}::jsonb, ?, ?::jsonb)", [$path, $jsonValue]];
        }

        self::assertSupportedOn($builder, 'setJsonValue');
        $function = $insert ? 'JSON_INSERT' : 'JSON_SET';

        return ["{$function}({$wrappedColumn}, ?, ?)", [$path, $jsonValue]];
    }

    /**
     * JSON 删除键（UPDATE 表达式）。
     *
     * @param  string[]  $paths
     * @return array{0: string, 1: array}
     */
    public static function jsonRemoveFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        array $paths
    ): array {
        if (self::isPostgres($builder)) {
            $expr = $wrappedColumn.'::jsonb';
            foreach ($paths as $path) {
                $expr = "jsonb_delete({$expr}, ?)";
            }

            return [$expr, $paths];
        }

        self::assertSupportedOn($builder, 'removeJsonKey');
        $expr = $wrappedColumn;
        foreach ($paths as $path) {
            $expr = "JSON_REMOVE({$expr}, ?)";
        }

        return [$expr, $paths];
    }

    /**
     * JSON 合并（UPDATE 表达式）。
     *
     * @return array{0: string, 1: array}
     */
    public static function jsonMergeFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        string $jsonData
    ): array {
        if (self::isPostgres($builder)) {
            return ["{$wrappedColumn}::jsonb || ?::jsonb", [$jsonData]];
        }

        self::assertSupportedOn($builder, 'mergeJson');

        return ["JSON_MERGE_PATCH({$wrappedColumn}, ?)", [$jsonData]];
    }

    /**
     * JSON 数组追加（UPDATE 表达式）。
     *
     * @return array{0: string, 1: array}
     */
    public static function jsonArrayAppendFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        string $jsonValue,
        ?string $path = null
    ): array {
        if (self::isPostgres($builder)) {
            $base = $path !== null
                ? "jsonb_path_query_first({$wrappedColumn}::jsonb, ?)"
                : "{$wrappedColumn}::jsonb";
            $bindings = $path !== null ? [$path] : [];
            $bindings[] = $jsonValue;

            return ["jsonb_set({$wrappedColumn}::jsonb, COALESCE(jsonb_path_query_first({$base}, '\$')::text, '[]')::jsonpath, ?::jsonb, true)", $bindings];
        }

        self::assertSupportedOn($builder, 'appendToJsonArray');
        if ($path !== null) {
            return ["JSON_ARRAY_APPEND({$wrappedColumn}, ?, ?)", [$path, $jsonValue]];
        }

        return ["JSON_ARRAY_APPEND({$wrappedColumn}, '\$', ?)", [$jsonValue]];
    }

    /**
     * JSON 数组中按值删除元素（UPDATE 表达式，SET 段用）。
     * MySQL:   JSON_REMOVE(col, JSON_SEARCH(col, 'one', ?[, NULL, ?]))
     *          JSON_SEARCH 返回首个匹配的路径，JSON_REMOVE 据此删除。
     * PostgreSQL: col::jsonb - (SELECT (ord-1)::int ... FROM jsonb_array_elements(...) WHERE elem = ?::jsonb LIMIT 1)
     *          按值在数组中的下标删除（0 基）。
     *
     * @param  string  $jsonValue  已 json_encode 的字面量（走绑定）
     * @param  string|null  $path  嵌套数组路径（可空）
     * @return array{0: string, 1: array}
     */
    public static function jsonRemoveValueFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        string $jsonValue,
        ?string $path = null
    ): array {
        if (self::isPostgres($builder)) {
            if ($path !== null) {
                $expr = "jsonb_set({$wrappedColumn}::jsonb, ?, (
                    SELECT (jsonb_path_query_first({$wrappedColumn}::jsonb, ?)::jsonb - ((ord - 1)::int))
                    FROM jsonb_array_elements(jsonb_path_query_first({$wrappedColumn}::jsonb, ?)::jsonb) WITH ORDINALITY AS e(elem, ord)
                    WHERE e.elem = ?::jsonb
                    LIMIT 1
                )::jsonb)";

                return [$expr, [$path, $path, $path, $jsonValue]];
            }

            $expr = "{$wrappedColumn}::jsonb - (
                SELECT (ord - 1)::int
                FROM jsonb_array_elements({$wrappedColumn}::jsonb) WITH ORDINALITY AS e(elem, ord)
                WHERE e.elem = ?::jsonb
                LIMIT 1
            )";

            return [$expr, [$jsonValue]];
        }

        self::assertSupportedOn($builder, 'removeFromJsonArray');
        if ($path !== null) {
            return ["JSON_REMOVE({$wrappedColumn}, JSON_SEARCH({$wrappedColumn}, 'one', ?, NULL, ?))", [$jsonValue, $path]];
        }

        return ["JSON_REMOVE({$wrappedColumn}, JSON_SEARCH({$wrappedColumn}, 'one', ?))", [$jsonValue]];
    }

    /* =====================================================================
     * 4. 字符串函数
     * ===================================================================== */

    /**
     * LOCATE 子串匹配片段（字段是否包含子串）。
     *
     * @return array{0: string, 1: array}
     */
    public static function locateFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        string $search
    ): array {
        if (self::isPostgres($builder)) {
            return ["position(? in {$wrappedColumn}) > 0", [$search]];
        }

        return ["LOCATE(?, {$wrappedColumn}) > 0", [$search]];
    }

    /** CHAR_LENGTH 表达式（纯片段，无绑定）。 */
    public static function charLengthExpr(EloquentBuilder|QueryBuilder $builder, string $wrappedColumn): string
    {
        return self::isPostgres($builder) ? "char_length({$wrappedColumn})" : "CHAR_LENGTH({$wrappedColumn})";
    }

    /**
     * FIELD 自定义排序片段（PG 用 array_position 等价实现）。
     *
     * @param  mixed[]  $order
     * @return array{0: string, 1: array}
     */
    public static function fieldOrderFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        array $order
    ): array {
        if (self::isPostgres($builder)) {
            $placeholders = implode(', ', array_fill(0, count($order), '?'));

            return ["array_position(ARRAY[{$placeholders}], {$wrappedColumn})", array_values($order)];
        }

        $placeholders = implode(', ', array_fill(0, count($order), '?'));

        return ["FIELD({$wrappedColumn}, {$placeholders})", array_values($order)];
    }

    /**
     * FIND_IN_SET 片段（逗号分隔列表成员查找）。
     *
     * @return array{0: string, 1: array}
     */
    public static function findInSetFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        string $value
    ): array {
        if (self::isPostgres($builder)) {
            return ["? = ANY(string_to_array({$wrappedColumn}, ','))", [(string) $value]];
        }

        return ["FIND_IN_SET(?, {$wrappedColumn}) > 0", [(string) $value]];
    }

    /** CONCAT_WS 多列拼接表达式（纯片段，无绑定）。 */
    public static function concatWsFragment(EloquentBuilder|QueryBuilder $builder, string $wrappedColumnsCsv): string
    {
        return "CONCAT_WS(' ', {$wrappedColumnsCsv})";
    }

    /**
     * SUBSTRING_INDEX 片段（PG 用 split_part 等价，支持正负计数）。
     *
     * @return array{0: string, 1: array}
     */
    public static function substringIndexFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        string $delimiter,
        int $count
    ): array {
        if (self::isPostgres($builder)) {
            if ($count >= 0) {
                return ["split_part({$wrappedColumn}, ?, ?)", [$delimiter, $count]];
            }
            // 负计数：反转字符串取分段再反转
            $sql = "reverse(split_part(reverse({$wrappedColumn}), ?, ?))";

            return [$sql, [$delimiter, abs($count)]];
        }

        return ["SUBSTRING_INDEX({$wrappedColumn}, ?, ?)", [$delimiter, $count]];
    }

    /**
     * GROUP_CONCAT / string_agg 片段。
     *
     * @return array{0: string, 1: array}
     */
    public static function groupConcatFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        string $separator,
        bool $distinct
    ): array {
        if (self::isPostgres($builder)) {
            $inner = $distinct ? "DISTINCT {$wrappedColumn}" : $wrappedColumn;

            return ["string_agg({$inner}, ?)", [$separator]];
        }

        $distinctSql = $distinct ? 'DISTINCT ' : '';

        return ["GROUP_CONCAT({$distinctSql}{$wrappedColumn} SEPARATOR ?)", [$separator]];
    }

    /** SOUNDEX 表达式（纯片段）。 */
    public static function soundexExpr(EloquentBuilder|QueryBuilder $builder, string $wrappedColumn): string
    {
        // PostgreSQL 需在目标库启用 fuzzystrmatch 扩展后方可用 SOUNDEX()。
        return self::isPostgres($builder) ? "soundex({$wrappedColumn})" : "SOUNDEX({$wrappedColumn})";
    }

    /** 自然排序表达式（提取数字作为主排序键）。 */
    public static function naturalSortExpr(EloquentBuilder|QueryBuilder $builder, string $wrappedColumn): string
    {
        if (self::isPostgres($builder)) {
            return "CAST(regexp_replace({$wrappedColumn}, '\\D', '', 'g') AS bigint)";
        }

        return "CAST(REGEXP_REPLACE({$wrappedColumn}, '[^0-9]', '') AS UNSIGNED)";
    }

    /**
     * 全文检索 MATCH 片段（MySQL MATCH...AGAINST / PostgreSQL tsvector）。
     * 关键词始终走参数绑定（绝不拼接），调用方须传入 $keyword。
     *
     * @param  string[]  $wrappedColumns
     * @param  string  $keyword  检索关键词（走绑定，杜绝注入）
     * @param  string  $mode  natural|boolean|expansion
     * @return array{0: string, 1: array} [sql 片段, 绑定]
     */
    public static function fullTextMatchFragment(
        EloquentBuilder|QueryBuilder $builder,
        array $wrappedColumns,
        string $keyword,
        string $mode
    ): array {
        $mode = strtolower(trim($mode));
        if (! in_array($mode, ['natural', 'boolean', 'expansion'], true)) {
            throw new InvalidArgumentException(
                sprintf('非法的全文检索模式: "%s"。仅允许 natural / boolean / expansion。', $mode)
            );
        }

        if (self::isPostgres($builder)) {
            $tsvector = implode(' || ', array_map(static fn ($c) => "to_tsvector('simple', COALESCE({$c}, ''))", $wrappedColumns));
            $tsqueryFn = $mode === 'boolean' ? 'to_tsquery' : 'plainto_tsquery';

            return ["({$tsvector}) @@ {$tsqueryFn}('simple', ?)", [$keyword]];
        }

        self::assertSupportedOn($builder, 'whereFullText');
        $modeSql = match ($mode) {
            'natural' => 'IN NATURAL LANGUAGE MODE',
            'boolean' => 'IN BOOLEAN MODE',
            'expansion' => 'WITH QUERY EXPANSION',
        };
        $columnsSql = implode(', ', $wrappedColumns);

        return ["MATCH({$columnsSql}) AGAINST (? {$modeSql})", [$keyword]];
    }

    /** 全文检索相关度表达式（ORDER BY 用）。 */
    public static function fullTextRelevanceExpr(
        EloquentBuilder|QueryBuilder $builder,
        array $wrappedColumns,
        string $mode,
        string $direction = 'desc'
    ): string {
        $mode = strtolower(trim($mode));
        $direction = self::assertDirection($direction);

        if (self::isPostgres($builder)) {
            $tsvector = implode(' || ', array_map(static fn ($c) => "to_tsvector('simple', COALESCE({$c}, ''))", $wrappedColumns));
            $tsqueryFn = $mode === 'boolean' ? 'to_tsquery' : 'plainto_tsquery';

            return "ts_rank(({$tsvector}), {$tsqueryFn}('simple', ?)) {$direction}";
        }

        $modeSql = match ($mode) {
            'natural' => 'IN NATURAL LANGUAGE MODE',
            'boolean' => 'IN BOOLEAN MODE',
            'expansion' => 'WITH QUERY EXPANSION',
        };
        $columnsSql = implode(', ', $wrappedColumns);

        return "MATCH({$columnsSql}) AGAINST (? {$modeSql}) {$direction}";
    }

    /* =====================================================================
     * 5. 抽样 / 随机 / 批量写
     * ===================================================================== */

    /** 随机函数片段（无绑定）：MySQL RAND() / PostgreSQL random()。 */
    public static function randomFunction(EloquentBuilder|QueryBuilder $builder): string
    {
        return self::isPostgres($builder) ? 'random()' : 'RAND()';
    }

    /**
     * VALUES 行语法片段：MySQL `ROW(?, ?, ...)`，PostgreSQL `(?, ?, ...)`。
     *
     * @param  int  $count  占位符数量
     */
    public static function valuesRowSyntax(EloquentBuilder|QueryBuilder $builder, int $count): string
    {
        $placeholders = implode(', ', array_fill(0, $count, '?'));

        return self::isPostgres($builder) ? "({$placeholders})" : "ROW({$placeholders})";
    }

    /**
     * UPSERT 后缀片段（用于批量 INSERT）。
     * MySQL: `ON DUPLICATE KEY UPDATE col = new_values.col ...`
     * PG:    `ON CONFLICT (unique) DO UPDATE SET col = EXCLUDED.col ...`
     *
     * @param  string[]  $updateColumns  已 wrap 的更新列
     * @param  string[]  $uniqueColumns  已 wrap 的唯一键列（PG 必需）
     * @return array{0: string, 1: array}
     */
    public static function upsertSuffix(
        EloquentBuilder|QueryBuilder $builder,
        array $updateColumns,
        array $uniqueColumns
    ): array {
        if (self::isPostgres($builder)) {
            $uniqueList = implode(', ', $uniqueColumns);
            $setParts = [];
            foreach ($updateColumns as $col) {
                $setParts[] = "{$col} = EXCLUDED.{$col}";
            }
            $suffix = empty($setParts) ? "ON CONFLICT ({$uniqueList}) DO NOTHING" : 'ON CONFLICT ('.$uniqueList.') DO UPDATE SET '.implode(', ', $setParts);

            return [$suffix, []];
        }

        self::assertSupportedOn($builder, 'batchUpsert');
        $setParts = [];
        foreach ($updateColumns as $col) {
            $setParts[] = "{$col} = new_values.{$col}";
        }
        $suffix = empty($setParts) ? '' : 'ON DUPLICATE KEY UPDATE '.implode(', ', $setParts);

        return [$suffix, []];
    }

    /**
     * 表级抽样片段（WHERE 可下推的跨库抽样条件）。
     * 生成可在 `whereRaw` 中直接使用的抽样条件片段（双库一致语义）：
     *  - system：基于主键等距取模 `MOD(pk, ceil(100/pct)) = 0`，适合按主键有序的大表；
     *  - random：基于数据库随机函数（MySQL RAND() / PostgreSQL random()）按比例命中。
     * 注：PostgreSQL 原生 `TABLESAMPLE` 语法须置于 FROM 子句、无法作为 WHERE 条件，
     * 故此处统一采用与 MySQL 一致的 WHERE 方案，保证双库行为等价、可被索引下推。
     *
     * @param  string  $wrappedPrimaryKey  已 wrap 的安全主键列名
     * @param  float  $percentage  抽样百分比 (0, 100]
     * @param  string  $method  'system' | 'random'
     * @return array{0: string, 1: array} [sql 片段, 绑定]
     */
    public static function tableSampleFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedPrimaryKey,
        float $percentage,
        string $method
    ): array {
        $method = strtolower($method);

        if ($method === 'system') {
            $mod = (int) ceil(100 / $percentage);

            return ["MOD({$wrappedPrimaryKey}, ?) = 0", [$mod]];
        }

        $randomFn = self::randomFunction($builder);

        return ["{$randomFn} * 100 <= ?", [$percentage]];
    }

    /* =====================================================================
     * 6. 日期 / 数值 / 排序辅助（双库通用片段）
     * ===================================================================== */

    /**
     * 日期分桶表达式（按时间粒度把时间戳归一到桶起点，便于 GROUP BY 统计）。
     * 支持粒度：day / week / month / quarter / year。
     * - MySQL：DATE_FORMAT 系列（quarter 用 CONCAT+YEAR+QUARTER 模拟）
     * - PostgreSQL：date_trunc 系列
     *
     * @param  string  $wrappedColumn  已 wrap 的安全列名
     * @param  string  $unit  分桶粒度
     * @return string SQL 表达式（无绑定）
     *
     * @throws InvalidArgumentException 非法粒度
     */
    public static function dateBucketFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        string $unit
    ): string {
        $unit = strtolower(trim($unit));
        if (! in_array($unit, ['day', 'week', 'month', 'quarter', 'year'], true)) {
            throw new InvalidArgumentException(
                sprintf('非法的日期分桶粒度: "%s"。仅允许 day / week / month / quarter / year。', $unit)
            );
        }

        if (self::isPostgres($builder)) {
            return "date_trunc('{$unit}', {$wrappedColumn})";
        }

        return match ($unit) {
            'day' => "DATE_FORMAT({$wrappedColumn}, '%Y-%m-%d')",
            'week' => "DATE_FORMAT({$wrappedColumn}, '%Y-%u')",
            'month' => "DATE_FORMAT({$wrappedColumn}, '%Y-%m')",
            'quarter' => "CONCAT(DATE_FORMAT({$wrappedColumn}, '%Y'), '-Q', QUARTER({$wrappedColumn}))",
            'year' => "DATE_FORMAT({$wrappedColumn}, '%Y')",
        };
    }

    /**
     * 时间分桶表达式（按 N 分钟归一时间，用于 15 分钟 / 30 分钟等固定步长统计）。
     * 两种库均基于 epoch 秒做整除归桶，语义一致：
     * - MySQL：`FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(col)/?) * ?)`
     * - PostgreSQL：`to_timestamp(floor(extract(epoch from col)/?) * ?)`
     *
     * @param  string  $wrappedColumn  已 wrap 的安全列名
     * @param  int  $minutes  步长（分钟），须 > 0
     * @return array{0: string, 1: array} [sql 片段, 绑定]
     *
     * @throws InvalidArgumentException
     */
    public static function timeBucketFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        int $minutes
    ): array {
        if ($minutes <= 0) {
            throw new InvalidArgumentException('时间分桶步长 minutes 必须 > 0。');
        }
        $seconds = $minutes * 60;

        if (self::isPostgres($builder)) {
            return ["to_timestamp(floor(extract(epoch from {$wrappedColumn}) / ?) * ?)", [$seconds, $seconds]];
        }

        return ["FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP({$wrappedColumn}) / ?) * ?)", [$seconds, $seconds]];
    }

    /**
     * NULL 安全等值比较表达式（不区分 NULL 与值）。
     * - MySQL：`col <=> ?`
     * - PostgreSQL：`col IS NOT DISTINCT FROM ?`
     *
     * @param  string  $wrappedColumn  已 wrap 的安全列名
     * @param  mixed  $value  比较值（走绑定）
     * @return array{0: string, 1: array}
     */
    public static function nullSafeEqualsExpr(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        mixed $value
    ): array {
        if (self::isPostgres($builder)) {
            return ["{$wrappedColumn} IS NOT DISTINCT FROM ?", [$value]];
        }

        return ["{$wrappedColumn} <=> ?", [$value]];
    }

    /**
     * 无全文索引时的两阶段相关度表达式（LOCATE + 长度归一化）。
     * 相关度 = 命中位置（越靠前分越高）× 长度归一因子（文本越短越精准），
     * 与 {@see self::fullTextRelevanceExpr()} 可组合成「先全文粗筛、再 LOCATE 精排」的两阶段检索。
     * - MySQL：`LOCATE(?, col) * (1.0 / NULLIF(CHAR_LENGTH(col), 0))`
     * - PostgreSQL：`strpos(col, ?) * (1.0 / NULLIF(length(col), 0))`
     *
     * @param  string  $wrappedColumn  已 wrap 的安全列名
     * @param  string  $keyword  关键词（走绑定）
     * @return array{0: string, 1: array}
     */
    public static function likeRelevanceExpr(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        string $keyword
    ): array {
        if (self::isPostgres($builder)) {
            return ["strpos({$wrappedColumn}, ?) * (1.0 / NULLIF(length({$wrappedColumn}), 0))", [$keyword]];
        }

        return ["LOCATE(?, {$wrappedColumn}) * (1.0 / NULLIF(CHAR_LENGTH({$wrappedColumn}), 0))", [$keyword]];
    }

    /**
     * 加权排序表达式（多列按权重线性组合，常用于搜索结果综合打分）。
     *
     * @param  array<int,array{column:string,weight:float}>  $items  每项含已 wrap 的 column 与权重
     * @return array{0: string, 1: array} 如 `(col1 * ? + col2 * ?)`，绑定为权重
     *
     * @throws InvalidArgumentException
     */
    public static function weightedOrderFragment(
        EloquentBuilder|QueryBuilder $builder,
        array $items
    ): array {
        if (empty($items)) {
            throw new InvalidArgumentException('weightedOrderFragment 至少需要一个排序项。');
        }

        $parts = [];
        $bindings = [];
        foreach ($items as $item) {
            $weight = (float) ($item['weight'] ?? 1.0);
            $parts[] = "{$item['column']} * ?";
            $bindings[] = $weight;
        }

        return ['('.implode(' + ', $parts).')', $bindings];
    }

    /**
     * 多级兜底表达式（COALESCE 链，取首个非 NULL 值，末位为默认值）。
     * 双库通用：`COALESCE(c1, c2, ..., ?)`。
     *
     * @param  string[]  $wrappedColumns  已 wrap 的安全列名（至少 1 个）
     * @param  mixed  $default  最终默认值（走绑定）
     * @return array{0: string, 1: array}
     *
     * @throws InvalidArgumentException
     */
    public static function coalesceChainExpr(
        EloquentBuilder|QueryBuilder $builder,
        array $wrappedColumns,
        mixed $default
    ): array {
        if (empty($wrappedColumns)) {
            throw new InvalidArgumentException('coalesceChainExpr 至少需要一个列。');
        }

        return ['COALESCE('.implode(', ', $wrappedColumns).', ?)', [$default]];
    }
}
