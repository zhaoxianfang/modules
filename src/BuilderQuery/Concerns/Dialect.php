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
            // （手动单引号转义在含引号文本下会破坏 SQL 字符串边界，存在语法/注入隐患）。
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
     * 驱动守卫：MySQL 走 LOCATE()、PostgreSQL 走 position()，SQLite 等其它驱动
     * 既无 LOCATE() 也无 position()，若不在此处拦截，运行期会抛出
     * "no such function: LOCATE" 这类极难定位的数据库原始错误。
     * 这里统一前置 assertSupportedOn，让调用方拿到明确的「仅支持驱动 mysql/pgsql」提示。
     *
     * @return array{0: string, 1: array}
     */
    public static function locateFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        string $search
    ): array {
        self::assertSupportedOn($builder, 'whereLocate');

        if (self::isPostgres($builder)) {
            return ["position(? in {$wrappedColumn}) > 0", [$search]];
        }

        return ["LOCATE(?, {$wrappedColumn}) > 0", [$search]];
    }

    /**
     * CHAR_LENGTH 表达式（纯片段，无绑定）。
     *
     * 驱动守卫：同 locateFragment —— SQLite 无 char_length() 函数，
     * 不拦截就会在运行期报出难以定位的原始数据库错误。
     */
    public static function charLengthExpr(EloquentBuilder|QueryBuilder $builder, string $wrappedColumn): string
    {
        self::assertSupportedOn($builder, 'whereCharLength');

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
     * @param  array|string  $mode  模式：数组 ['mode' => 'boolean'|'natural', 'expanded' => bool]
     *                              或字符串 'natural'|'boolean'|'expansion'
     * @return array{0: string, 1: array} [sql 片段, 绑定]
     */
    public static function fullTextMatchFragment(
        EloquentBuilder|QueryBuilder $builder,
        array $wrappedColumns,
        string $keyword,
        array|string $mode
    ): array {
        $mode = self::normalizeFullTextMode($mode);
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

        // 追加 `> 0`：MATCH...AGAINST 对「只含停用词」的记录会返回 0 相关度，
        // 若不比较，这些零命中行会被一并返回，造成搜索结果里出现明显不相关的条目。
        return ["MATCH({$columnsSql}) AGAINST (? {$modeSql}) > 0", [$keyword]];
    }

    /**
     * 归一化全文检索模式入参。
     *
     * 同时兼容两种写法（Laravel 13.32 起原生 whereFullText 的第三参是
     * array $options，本宏第三参是字符串 $mode）：
     *  - 数组：['mode' => 'boolean', 'expanded' => true]
     *  - 字符串：'boolean' / 'expansion' / 'natural'
     *  - 空数组：等价于 'natural'
     *
     * @param  array<string, mixed>|string  $mode
     */
    public static function normalizeFullTextMode(array|string $mode): string
    {
        if (is_array($mode)) {
            $inner = $mode['mode'] ?? 'natural';
            $mode = (is_string($inner) && $inner !== '')
                ? $inner
                : (! empty($mode['expanded']) ? 'expansion' : 'natural');
        }

        return strtolower(trim($mode));
    }

    /** 全文检索相关度表达式（ORDER BY 用）。 */
    public static function fullTextRelevanceExpr(
        EloquentBuilder|QueryBuilder $builder,
        array $wrappedColumns,
        array|string $mode,
        string $direction = 'desc'
    ): string {
        $mode = self::normalizeFullTextMode($mode);
        if (! in_array($mode, ['natural', 'boolean', 'expansion'], true)) {
            throw new InvalidArgumentException(
                sprintf('非法的全文检索模式: "%s"。仅允许 natural / boolean / expansion。', $mode)
            );
        }
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


    /* =====================================================================
     * 7. 向量操作（PostgreSQL pgvector · MariaDB 11.7+ VECTOR · MySQL HeatWave VECTOR）
     * ===================================================================== */

    /**
     * 支持向量距离计算的度量方式（归一化后的小写键）。
     *
     * 别名设计是为了让调用方能用业务习惯的写法：
     *  - cosine    ← cosine / cos / similarity
     *  - l2        ← l2 / euclidean
     *  - inner     ← inner / ip / dot / inner_product / innerproduct
     *  - manhattan ← manhattan / l1 / cityblock
     *
     * @var array<string, string>
     */
    private const VECTOR_METRIC_ALIASES = [
        'cosine' => 'cosine',
        'cos' => 'cosine',
        'similarity' => 'cosine',
        'l2' => 'l2',
        'euclidean' => 'l2',
        'inner' => 'inner',
        'ip' => 'inner',
        'dot' => 'inner',
        'inner_product' => 'inner',
        'innerproduct' => 'inner',
        'manhattan' => 'manhattan',
        'l1' => 'manhattan',
        'cityblock' => 'manhattan',
    ];

    /**
     * 向量后端类型。
     *
     * MySQL 系内部差异极大，必须区分（详见 {@see self::vectorBackend()}）：
     *  - pgsql     ：PostgreSQL + pgvector 扩展，距离用 `<=>` `<->` `<#>` `<+>` 运算符
     *  - mariadb   ：MariaDB 11.7+，距离用 VEC_DISTANCE_COSINE / VEC_DISTANCE_EUCLIDEAN
     *  - heatwave  ：MySQL（HeatWave / MySQL AI 版），距离用 DISTANCE(v1,v2,'METRIC')
     *  - community ：MySQL 社区版 —— 只能存 VECTOR、**没有距离函数**，无法做相似度检索
     *
     * @var array<string, string>
     */
    public const VECTOR_BACKEND_PGSQL = 'pgsql';
    public const VECTOR_BACKEND_MARIADB = 'mariadb';
    public const VECTOR_BACKEND_HEATWAVE = 'heatwave';
    public const VECTOR_BACKEND_COMMUNITY = 'community';
    public const VECTOR_BACKEND_NONE = 'none';

    /**
     * 向量后端能力探测结果缓存。
     *
     * 探测需要真实执行一次函数调用（information_schema.ROUTINES 里查不到内建函数，
     * 本次实测确认 MySQL 9.3 的 VEC_* 与 DISTANCE 都不在 ROUTINES 中），因此按
     * 「驱动 + 服务端版本」缓存结果，避免每次查询都探测一次。
     *
     * @var array<string, string>
     */
    private static array $vectorBackendCache = [];

    /**
     * 归一化向量度量方式。
     *
     * 该值决定生成的 SQL 片段与「应该建哪种索引」，二者强绑定，因此必须白名单化：
     * 度量名无法参数绑定，放任任意字符串就等于允许注入到函数名位置。
     *
     * @throws InvalidArgumentException
     */
    public static function normalizeVectorMetric(string $metric): string
    {
        $key = strtolower(trim($metric));
        $normalized = self::VECTOR_METRIC_ALIASES[$key] ?? null;

        if ($normalized === null) {
            throw new InvalidArgumentException(sprintf(
                '非法的向量度量方式: "%s"。允许值：cosine / l2(euclidean) / inner(ip,dot) / manhattan(l1)。',
                $metric
            ));
        }

        return $normalized;
    }

    /**
     * 探测当前连接的向量后端类型（带进程内缓存）。
     *
     * ## 为什么必须分三档，而不是「MySQL 走 VEC_*、PG 走运算符」
     *
     * `VEC_DISTANCE_COSINE` / `VEC_FromText` / `VECTOR INDEX` 是 **MariaDB 11.7+** 的函数，
     * **Oracle MySQL 并不存在**；Oracle MySQL 9.0+ 的对应函数是 `STRING_TO_VECTOR()` /
     * `VECTOR_TO_STRING()` / `VECTOR_DIM()`，而距离函数 `DISTANCE(v1, v2, 'COSINE')`
     * **只在 HeatWave / MySQL AI 版提供**。于是 Oracle MySQL 社区版形成了一个尴尬局面：
     * `VECTOR` 列能建、向量能存能读，但**没有距离函数**，无法在库内做相似度检索。
     *
     * 若按「MySQL 一律用 VEC_*」生成 SQL，社区版上会在运行期报出
     * `FUNCTION xxx.VEC_DISTANCE_COSINE does not exist` —— 用户完全无从判断是版本问题
     * 还是写法问题。因此这里主动探测并**给出可执行的指引**。
     *
     * @return self::VECTOR_BACKEND_* 之一
     */
    public static function vectorBackend(EloquentBuilder|QueryBuilder $builder): string
    {
        $driver = self::driver($builder);

        if ($driver === 'pgsql') {
            return self::VECTOR_BACKEND_PGSQL;
        }

        if ($driver !== 'mysql') {
            return self::VECTOR_BACKEND_NONE;
        }

        $connection = $builder->getConnection();
        $version = (string) $connection->getServerVersion();
        $cacheKey = $driver.'@'.$version;

        if (isset(self::$vectorBackendCache[$cacheKey])) {
            return self::$vectorBackendCache[$cacheKey];
        }

        $backend = self::VECTOR_BACKEND_COMMUNITY;

        try {
            // MariaDB：VEC_DISTANCE_COSINE 是其 11.7+ 独有函数
            $connection->select('SELECT VEC_DISTANCE_COSINE(VEC_FromText(?), VEC_FromText(?))', ['[1,0]', '[0,1]']);
            $backend = self::VECTOR_BACKEND_MARIADB;
        } catch (\Throwable) {
            try {
                // Oracle MySQL HeatWave / MySQL AI：距离用 DISTANCE(v1, v2, 'METRIC')
                $connection->select('SELECT DISTANCE(STRING_TO_VECTOR(?), STRING_TO_VECTOR(?), \'COSINE\')', ['[1,0]', '[0,1]']);
                $backend = self::VECTOR_BACKEND_HEATWAVE;
            } catch (\Throwable) {
                // 两者都没有：社区版只能存向量、不能算距离
                $backend = self::VECTOR_BACKEND_COMMUNITY;
            }
        }

        self::$vectorBackendCache[$cacheKey] = $backend;

        return $backend;
    }

    /**
     * 断言当前连接可执行向量距离计算，否则抛出带**可执行修复建议**的异常。
     *
     * @throws InvalidArgumentException
     */
    public static function assertVectorSupport(EloquentBuilder|QueryBuilder $builder): void
    {
        $backend = self::vectorBackend($builder);

        if ($backend === self::VECTOR_BACKEND_PGSQL || $backend === self::VECTOR_BACKEND_MARIADB) {
            return;
        }

        if ($backend === self::VECTOR_BACKEND_HEATWAVE) {
            return;
        }

        if ($backend === self::VECTOR_BACKEND_COMMUNITY) {
            throw new InvalidArgumentException(
                '当前是 MySQL 社区版（Oracle MySQL），可以创建并读写 VECTOR 列，'
                .'但**不提供任何向量距离函数**（DISTANCE() 仅 HeatWave / MySQL AI 版有）。'
                .'因此无法在库内做相似度检索。可选方案：'
                .'① 迁移到 PostgreSQL + pgvector（生态最完整，支持 HNSW/IVFFlat 近似索引）；'
                .'② 迁移到 MariaDB 11.7+（有 VEC_DISTANCE_* 函数与 VECTOR 近似索引）；'
                .'③ 改用 MySQL HeatWave / MySQL AI 版；'
                .'④ 在应用侧把向量算好距离后按普通数值列存储与检索。'
            );
        }

        throw new InvalidArgumentException(sprintf(
            '向量操作仅支持 PostgreSQL（pgvector 扩展）/ MariaDB 11.7+ / MySQL HeatWave；当前驱动为 [%s]。',
            self::driver($builder)
        ));
    }

    /**
     * 断言当前连接具备**向量类型**能力（能建列、能读写向量）。
     *
     * 与 {@see self::assertVectorSupport()}（要求「能算距离」）刻意区分：
     * Oracle MySQL 社区版**有** VECTOR 列与 `STRING_TO_VECTOR()` / `VECTOR_TO_STRING()` /
     * `VECTOR_DIM()`（实测 MySQL 9.3 均可用），只是**没有距离函数**。
     * 因此「查向量维度」「把向量导出成文本」「写入向量」这些操作在社区版上完全合法，
     * 不应该被距离能力的限制误伤 —— 否则会白白挡掉一批本来能跑的用法。
     *
     * @throws InvalidArgumentException
     */
    public static function assertVectorTypeSupport(EloquentBuilder|QueryBuilder $builder): void
    {
        if (self::vectorBackend($builder) !== self::VECTOR_BACKEND_NONE) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            '向量类型仅支持 PostgreSQL（pgvector 扩展）/ MariaDB 11.7+ / MySQL 9.0+（VECTOR 列）；当前驱动为 [%s]。',
            self::driver($builder)
        ));
    }

    /**
     * 返回该度量方式在**当前后端**下的底层实现名（供文档与异常提示使用）。
     *
     * @throws InvalidArgumentException
     */
    public static function vectorMetricImplementation(
        EloquentBuilder|QueryBuilder $builder,
        string $metric
    ): string {
        $metric = self::normalizeVectorMetric($metric);
        $backend = self::vectorBackend($builder);

        if ($backend === self::VECTOR_BACKEND_PGSQL) {
            return match ($metric) {
                'cosine' => '<=>（pgvector 余弦距离运算符）',
                'l2' => '<->（pgvector L2 / 欧氏距离运算符）',
                'inner' => '<#>（pgvector 负内积运算符）',
                'manhattan' => '<+>（pgvector L1，仅 sparsevec 类型可用）',
            };
        }

        if ($backend === self::VECTOR_BACKEND_MARIADB) {
            return match ($metric) {
                'cosine' => 'VEC_DISTANCE_COSINE()（MariaDB 11.7+）',
                'l2' => 'VEC_DISTANCE_EUCLIDEAN()（MariaDB 11.7+）',
                'inner' => throw new InvalidArgumentException(
                    'MariaDB 11.7+ 的 VECTOR 未提供内积距离函数（只有 VEC_DISTANCE_COSINE / VEC_DISTANCE_EUCLIDEAN）；'
                    .'请改用 cosine 或 l2，或迁移到 PostgreSQL + pgvector 使用 <#>。'
                ),
                'manhattan' => throw new InvalidArgumentException(
                    'MariaDB 11.7+ 的 VECTOR 未提供 L1（曼哈顿）距离函数；请改用 cosine 或 l2。'
                ),
            };
        }

        if ($backend === self::VECTOR_BACKEND_HEATWAVE) {
            return match ($metric) {
                'cosine' => "DISTANCE(a, b, 'COSINE')（MySQL HeatWave / MySQL AI）",
                'l2' => "DISTANCE(a, b, 'EUCLIDEAN')（MySQL HeatWave / MySQL AI）",
                'inner' => "DISTANCE(a, b, 'DOT_PRODUCT')（MySQL HeatWave / MySQL AI）",
                'manhattan' => throw new InvalidArgumentException(
                    "MySQL HeatWave 的 DISTANCE() 仅支持 COSINE / EUCLIDEAN / DOT_PRODUCT；"
                    .'没有 L1（曼哈顿）度量。'
                ),
            };
        }

        self::assertVectorSupport($builder);

        return '';
    }

    /**
     * 返回该度量方式在当前后端下**应配套创建的向量索引** DDL 片段与注意事项。
     *
     * 这是向量检索最容易被忽略、也最难排查的一点：
     * - PostgreSQL 的近似索引（IVFFlat / HNSW）**只有在 ORDER BY 使用的运算符与索引
     *   opclass 完全一致时才会被采用**。用 `<->`（L2）排序却只建了 `vector_cosine_ops`
     *   索引时，查询会**静默退化为全表暴力扫描** —— 结果完全正确、不报任何错，但百万行下
     *   耗时从毫秒变成分钟。
     * - MariaDB 的 `VECTOR INDEX` 同样按建索引时声明的 metric 决定能否命中。
     * - Oracle MySQL 社区版没有任何向量索引能力。
     *
     * @param  string  $column  向量列名（标识符，用于生成可读的建表语句）
     * @return array{ddl: string, note: string}
     *
     * @throws InvalidArgumentException
     */
    public static function vectorIndexAdvice(
        EloquentBuilder|QueryBuilder $builder,
        string $metric,
        string $column = 'embedding'
    ): array {
        $metric = self::normalizeVectorMetric($metric);
        $column = self::assertValidIdentifier($column, 'column');
        $backend = self::vectorBackend($builder);

        if ($backend === self::VECTOR_BACKEND_PGSQL) {
            [$opclass, $algo] = match ($metric) {
                'cosine' => ['vector_cosine_ops', 'hnsw'],
                'l2' => ['vector_l2_ops', 'hnsw'],
                'inner' => ['vector_ip_ops', 'hnsw'],
                'manhattan' => ['(sparsevec 无近似索引 opclass)', '(暴力检索)'],
            };

            return [
                'ddl' => sprintf('CREATE INDEX ON %s USING %s (%s %s);', $column, $algo, $column, $opclass),
                'note' => sprintf(
                    'ORDER BY 必须使用与 %s 配套的运算符，索引才会被采用；'
                    .'召回不足时可用 SET LOCAL hnsw.ef_search / ivfflat.probes 提升（见 vectorSearchParams 宏）。',
                    $opclass
                ),
            ];
        }

        if ($backend === self::VECTOR_BACKEND_MARIADB) {
            [$metricName, $supported] = match ($metric) {
                'cosine' => ['COSINE', true],
                'l2' => ['EUCLIDEAN', true],
                'inner' => ['', false],
                'manhattan' => ['', false],
            };

            if (! $supported) {
                return [
                    'ddl' => '(MariaDB 11.7+ 的 VECTOR 近似索引仅支持 COSINE / EUCLIDEAN)',
                    'note' => sprintf(
                        '当前度量 [%s] 无法使用 MariaDB 向量索引，查询将退化为全量向量扫描。'
                        .'请改用 cosine 或 l2；如必须用内积，请迁移到 PostgreSQL + pgvector（<#> + vector_ip_ops）。',
                        $metric
                    ),
                ];
            }

            return [
                'ddl' => sprintf(
                    "CREATE VECTOR INDEX %s_vec_idx ON %s (%s) USING HNSW DISTANCE_METRIC=%s;",
                    $column,
                    $column,
                    $column,
                    $metricName
                ),
                'note' => 'ORDER BY 使用的距离函数必须与索引声明的 DISTANCE_METRIC 一致，否则索引不会被采用。',
            ];
        }

        if ($backend === self::VECTOR_BACKEND_HEATWAVE) {
            $metricName = match ($metric) {
                'cosine' => 'COSINE',
                'l2' => 'EUCLIDEAN',
                'inner' => 'DOT_PRODUCT',
            };

            return [
                'ddl' => sprintf(
                    "CREATE VECTOR INDEX %s_vec_idx ON %s (%s) USING VECTOR INDEX WITH (metric = '%s');",
                    $column,
                    $column,
                    $column,
                    $metricName
                ),
                'note' => 'HeatWave 向量索引需与距离度量一致；可用 EXPLAIN ANALYZE 确认是否命中近似索引。',
            ];
        }

        // 社区版 / 无向量类型：诊断接口不应该抛异常。
        //
        // 调用 indexAdvice() 的人正是想知道「这台机器上的向量检索该怎么建索引」，
        // 抛异常等于把答案换成一句「不支持」—— 反而不如直接告诉他没有索引可用。
        // 因此这里返回明确的空 DDL + 可执行结论（这与 assertVectorSupport() 的职责区分：
        // 后者是给执行类宏用的前置守卫）。
        return [
            'ddl' => '',
            'note' => self::VECTOR_BACKEND_COMMUNITY === $backend
                ? '当前是 MySQL 社区版（Oracle MySQL）：没有距离函数，也没有任何向量索引能力，'
                    .'无法做库内相似度检索。可选方案：① 迁移到 PostgreSQL + pgvector（支持 HNSW/IVFFlat）；'
                    .'② 迁移到 MariaDB 11.7+（VEC_DISTANCE_* + VECTOR 索引）；'
                    .'③ 改用 MySQL HeatWave / MySQL AI 版；'
                    .'④ 在应用侧算好距离后按普通数值列存储与检索。'
                : sprintf('当前驱动 [%s] 无向量能力，无法建向量索引。', self::driver($builder)),
        ];
    }

    /**
     * 把数组 / Arrayable 向量规范化为 pgvector 与 MySQL 系都接受的文本字面量：`[1,2,3]`。
     *
     * 该文本随后作为**参数绑定**下传（PG 走 `?::vector`，MySQL 走 `STRING_TO_VECTOR(?)`
     * 或 `VEC_FromText(?)`），本身不存在注入面；但这里仍逐元素校验为有限数值，目的是把
     * 「维度元素含 NaN / 非数字」这类问题在应用层就报成清晰异常，而不是等数据库抛出
     * 一句含糊的解析错误。
     *
     * @param  array|\Illuminate\Contracts\Support\Arrayable|string  $vector
     * @return string
     *
     * @throws InvalidArgumentException
     */
    public static function normalizeVectorLiteral(array|\Illuminate\Contracts\Support\Arrayable|string $vector): string
    {
        if (is_string($vector)) {
            $trimmed = trim($vector);

            if ($trimmed === '' || ! str_starts_with($trimmed, '[') || ! str_ends_with($trimmed, ']')) {
                throw new InvalidArgumentException(
                    '向量字面量必须是 [x,y,z] 形式；收到空值或不含首尾方括号的字符串。'
                );
            }

            return $trimmed;
        }

        $items = is_array($vector) ? $vector : $vector->toArray();

        if ($items === []) {
            throw new InvalidArgumentException('向量不能为空数组：距离计算至少需要 1 个维度。');
        }

        $parts = [];
        foreach ($items as $index => $item) {
            if (! is_int($item) && ! is_float($item) && ! (is_string($item) && is_numeric($item))) {
                throw new InvalidArgumentException(sprintf(
                    '向量第 %s 个维度不是数值：%s（%s）。',
                    (string) $index,
                    var_export($item, true),
                    get_debug_type($item)
                ));
            }

            $number = (float) $item;

            // is_finite 覆盖 INF 与 NAN：它们能通过上面的数值类型判断，
            // 但会让距离计算在数据库侧返回 NULL 或溢出，结果静默错误。
            if (! is_finite($number)) {
                throw new InvalidArgumentException(sprintf(
                    '向量第 %s 个维度不是有限数值（INF / NAN）。',
                    (string) $index
                ));
            }

            $parts[] = self::formatVectorElement($number);
        }

        return '['.implode(',', $parts).']';
    }

    /**
     * 格式化单个向量元素为**不带指数形式**的短小数串。
     *
     * 必须避免 PHP 默认的浮点字符串输出产生 '1.0E-5' 这类科学计数法 ——
     * pgvector 与 MySQL 的向量解析器只接受普通十进制小数，遇到 'E' 会直接报
     * 解析错误。（实测 Oracle MySQL 的 VECTOR_TO_STRING 输出形如
     * `[1.00000e+00,2.00000e+00]`，说明其内部正是二进制浮点表示，
     * 写入时必须显式规避指数形式。）
     */
    private static function formatVectorElement(float $number): string
    {
        if ($number === floor($number) && abs($number) < 1.0e15) {
            return (string) (int) $number;
        }

        // rtrim 去掉尾随 0，避免 '0.500000' 这类无意义精度膨胀。
        $formatted = rtrim(rtrim(number_format($number, 10, '.', ''), '0'), '.');

        return ($formatted === '' || $formatted === '-') ? '0' : $formatted;
    }

    /**
     * 向量距离表达式片段（SELECT 列 / ORDER BY / WHERE 条件三处通用）。
     *
     * - PostgreSQL（pgvector）：用距离运算符，能被近似索引识别
     *     cosine `(col <=> ?::vector)`、l2 `(col <-> ?::vector)`、
     *     inner `(col <#> ?::vector)`、manhattan `(col <+> ?::vector)`
     * - MariaDB 11.7+：`VEC_DISTANCE_COSINE(col, VEC_FromText(?))` 等
     * - MySQL HeatWave / MySQL AI：`DISTANCE(col, STRING_TO_VECTOR(?), 'COSINE')` 等
     * - Oracle MySQL 社区版：**无距离函数**，调用即抛带修复方案的异常
     *
     * @param  string  $wrappedColumn  已 wrap 的向量列名
     * @param  array|\Illuminate\Contracts\Support\Arrayable|string  $vector  查询向量
     * @param  string  $metric  度量方式（别名见 normalizeVectorMetric）
     * @return array{0: string, 1: array} [sql 片段, 绑定]
     *
     * @throws InvalidArgumentException
     */
    public static function vectorDistanceFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        array|\Illuminate\Contracts\Support\Arrayable|string $vector,
        string $metric = 'cosine'
    ): array {
        $metric = self::normalizeVectorMetric($metric);
        $literal = self::normalizeVectorLiteral($vector);
        $backend = self::vectorBackend($builder);

        if ($backend === self::VECTOR_BACKEND_PGSQL) {
            $operator = match ($metric) {
                'cosine' => '<=>',
                'l2' => '<->',
                'inner' => '<#>',
                'manhattan' => '<+>',
            };

            // PG 必须显式 cast：绑定参数的类型推断结果是 unknown，
            // 写成 `col <=> ?` 会报 "operator does not exist: vector <=> unknown"。
            return ["({$wrappedColumn} {$operator} ?::vector)", [$literal]];
        }

        if ($backend === self::VECTOR_BACKEND_MARIADB) {
            $function = match ($metric) {
                'cosine' => 'VEC_DISTANCE_COSINE',
                'l2' => 'VEC_DISTANCE_EUCLIDEAN',
                'inner' => throw new InvalidArgumentException(
                    'MariaDB 11.7+ 的 VECTOR 未提供内积距离函数；请改用 cosine 或 l2。'
                ),
                'manhattan' => throw new InvalidArgumentException(
                    'MariaDB 11.7+ 的 VECTOR 未提供 L1（曼哈顿）距离函数；请改用 cosine 或 l2。'
                ),
            };

            return ["{$function}({$wrappedColumn}, VEC_FromText(?))", [$literal]];
        }

        if ($backend === self::VECTOR_BACKEND_HEATWAVE) {
            $metricName = match ($metric) {
                'cosine' => 'COSINE',
                'l2' => 'EUCLIDEAN',
                'inner' => 'DOT_PRODUCT',
                'manhattan' => throw new InvalidArgumentException(
                    "MySQL HeatWave 的 DISTANCE() 仅支持 COSINE / EUCLIDEAN / DOT_PRODUCT，没有 L1 度量。"
                ),
            };

            return [
                "DISTANCE({$wrappedColumn}, STRING_TO_VECTOR(?), '{$metricName}')",
                [$literal],
            ];
        }

        self::assertVectorSupport($builder);

        return ['', []];
    }

    /**
     * 向量相似度表达式片段（SELECT 列用）。
     *
     * 距离与相似度的换算关系随度量方式而不同，不能一律用 `1 - distance`：
     *  - cosine：距离取值 [0,2]，`1 - d` 落在 [-1,1]（完全反向为 -1）。
     *    这里保持该原始语义而**不额外折半到 [0,1]**，避免「相似度 0.5」从「正交」
     *    悄悄变成「无关」，让既有阈值失效。需要 [0,1] 请调用方自行 clamp。
     *  - l2 / manhattan：距离无上界，`1 / (1 + distance)` 才能保证落在 (0,1]。
     *  - inner：pgvector 的 `<#>` 返回**负**内积，`-(col <#> ?)` 即真实内积；
     *    MariaDB 无内积函数（直接抛异常）。内积可为负，能表达「方向相反」，
     *    这是它相对余弦/欧氏的优势，故不压缩到 (0,1]。
     *
     * @return array{0: string, 1: array}
     */
    public static function vectorSimilarityFragment(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn,
        array|\Illuminate\Contracts\Support\Arrayable|string $vector,
        string $metric = 'cosine'
    ): array {
        $metric = self::normalizeVectorMetric($metric);
        $literal = self::normalizeVectorLiteral($vector);
        $backend = self::vectorBackend($builder);

        if ($metric === 'inner') {
            if ($backend === self::VECTOR_BACKEND_PGSQL) {
                return ["-({$wrappedColumn} <#> ?::vector)", [$literal]];
            }

            if ($backend === self::VECTOR_BACKEND_HEATWAVE) {
                return [
                    "DISTANCE({$wrappedColumn}, STRING_TO_VECTOR(?), 'DOT_PRODUCT')",
                    [$literal],
                ];
            }

            // MariaDB / 社区版：无内积函数，落到 assertVectorSupport 给出明确提示
            [$sql, $bindings] = self::vectorDistanceFragment($builder, $wrappedColumn, $vector, $metric);

            return [$sql, $bindings];
        }

        [$sql, $bindings] = self::vectorDistanceFragment($builder, $wrappedColumn, $vector, $metric);

        return [$metric === 'cosine' ? '(1 - '.$sql.')' : '(1.0 / (1 + '.$sql.'))', $bindings];
    }

    /**
     * 向量维度表达式（无绑定）。
     * - PostgreSQL：`vector_dims(col)`
     * - MariaDB / Oracle MySQL 9.0+：`VECTOR_DIM(col)`（实测 MySQL 9.3 社区版可用）
     */
    public static function vectorDimensionExpr(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn
    ): string {
        $backend = self::vectorBackend($builder);

        if ($backend === self::VECTOR_BACKEND_PGSQL) {
            return "vector_dims({$wrappedColumn})";
        }

        // 维度查询不需要距离能力：MariaDB 与 Oracle MySQL 9.0+（含社区版）都有 VECTOR_DIM
        self::assertVectorTypeSupport($builder);

        return "VECTOR_DIM({$wrappedColumn})";
    }

    /**
     * 向量转文本表达式（无绑定），用于把向量列导出成可读 / 可比较的字符串。
     * - PostgreSQL：`col::text`（pgvector 的 text 输出即 [1,2,3] 形式）
     * - MariaDB：`VEC_ToText(col)`
     * - Oracle MySQL 9.0+：`VECTOR_TO_STRING(col)`
     */
    public static function vectorToTextExpr(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn
    ): string {
        $backend = self::vectorBackend($builder);

        if ($backend === self::VECTOR_BACKEND_PGSQL) {
            return "({$wrappedColumn})::text";
        }

        // 文本导出同样不需要距离能力（Oracle MySQL 社区版的 VECTOR_TO_STRING 可用）
        self::assertVectorTypeSupport($builder);

        return $backend === self::VECTOR_BACKEND_MARIADB
            ? "VEC_ToText({$wrappedColumn})"
            : "VECTOR_TO_STRING({$wrappedColumn})";
    }

    /**
     * 文本转向量表达式（供 INSERT / UPDATE 的 SET 段或 VALUES 使用）。
     *
     * - PostgreSQL：`?::vector`
     * - MariaDB：`VEC_FromText(?)`
     * - Oracle MySQL 9.0+：`STRING_TO_VECTOR(?)`
     *
     * @return array{0: string, 1: array}
     */
    public static function vectorFromLiteralFragment(
        EloquentBuilder|QueryBuilder $builder,
        array|\Illuminate\Contracts\Support\Arrayable|string $vector
    ): array {
        $literal = self::normalizeVectorLiteral($vector);
        $backend = self::vectorBackend($builder);

        if ($backend === self::VECTOR_BACKEND_PGSQL) {
            return ['?::vector', [$literal]];
        }

        // 写入向量只需要向量类型能力（社区版的 STRING_TO_VECTOR 可用）
        self::assertVectorTypeSupport($builder);

        return $backend === self::VECTOR_BACKEND_MARIADB
            ? ['VEC_FromText(?)', [$literal]]
            : ['STRING_TO_VECTOR(?)', [$literal]];
    }

    /**
     * 向量 L2 归一化表达式（无绑定）。
     *
     * 为什么需要归一化：内积度量下，模长差异会主导结果 —— 「方向高度相似但模长差
     * 10 倍」的两个向量会被排得很远，这是内积检索最常见的误用。归一化后内积 ≡
     * 余弦相似度，且 HNSW / IVFFlat 的聚类质心才具有几何意义（否则簇中心被长向量拉偏）。
     *
     * - PostgreSQL：`l2_normalize(col)`（pgvector 0.7.0+ 内置函数）
     * - MySQL 系（MariaDB / Oracle MySQL）：**没有内置范数函数**。VEC_* / DISTANCE 系列
     *   只有距离与维度，且向量分量不可拆出（没有 VEC_INDEX / VEC_SLICE 之类），
     *   因此无法在 SQL 内等价表达归一化。这里明确抛异常并指引在应用层归一化后写入 ——
     *   绝不生成一个看似能跑、实则语义错误的表达式。
     *
     * @throws InvalidArgumentException
     */
    public static function vectorNormalizeExpr(
        EloquentBuilder|QueryBuilder $builder,
        string $wrappedColumn
    ): string {
        if (self::vectorBackend($builder) === self::VECTOR_BACKEND_PGSQL) {
            return "l2_normalize({$wrappedColumn})";
        }

        throw new InvalidArgumentException(
            'MySQL / MariaDB 的 VECTOR 没有内置范数函数，无法在 SQL 内做 L2 归一化。'
            .'请在应用层对向量归一化（x / sqrt(sum(x^2))）后再写入数据库，'
            .'或改用 cosine 度量 —— 余弦距离本身已对模长不敏感，无需归一化。'
        );
    }

    /**
     * 清空向量后端探测缓存（连接版本变更、或测试需要强制重探时使用）。
     */
    public static function clearVectorBackendCache(): void
    {
        self::$vectorBackendCache = [];
    }
}
