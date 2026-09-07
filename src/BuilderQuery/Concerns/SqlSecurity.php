<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\Concerns;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

/**
 * SQL 片段安全编译工具
 *
 * 解决 BuilderQuery 宏体系最初「字符串裸插值」导致的系统性问题：
 *
 *  1. SQL 注入风险 —— 列名 / 别名 / 排序方向 / 正则模式 / 字面值此前直接拼进 SQL；
 *     本工具提供标识符白名单校验与 grammar wrap，字面值一律走参数绑定。
 *  2. 硬编码 MySQL 反引号 —— 改用 {@see self::wrapIdentifier()} 用 grammar 生成适配
 *     当前数据库连接（MySQL / PostgreSQL / SQLite）的标识符引用。
 *  3. 驱动未判断 —— {@see self::assertMysql()} 在依赖 MySQL 专属语法的宏入口统一拦截，
 *     给出清晰异常而非静默生成非法 SQL。
 *
 * 重要调用约定（Laravel Macroable 机制）：
 *   `Builder::macro()` 的闭包在调用时会被 `Closure::bind` 重绑到 `Builder` 作用域，
 *   因此宏闭包内 `self::` 解析到 `Builder` 而非宏类，**必须**用显式类名调用本 trait 方法：
 *
 *   ```php
 *   WindowFunctionsMacro::assertValidIdentifier($column, 'column');
 *   ```
 *
 * 本 trait 的方法全部声明为 `public static`，以保证跨作用域可调用。
 *
 * @package zxf\Modules\BuilderQuery\Concerns
 * @internal 仅用于 BuilderQuery 内部宏实现
 */
trait SqlSecurity
{
    /**
     * 合法 SQL 标识符（列名 / 别名 / 字段名）白名单。
     *
     * 允许：字母、数字、下划线，以及受限的点（跨表引用如 a.b）。
     */
    private const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/';

    /**
     * 合法 JSON Path 表达式。
     */
    private const JSON_PATH_PATTERN = '/^\$[A-Za-z0-9_\[\]\.\*"\x{4e00}-\x{9fa5}]*$/u';

    /**
     * 合法聚合函数白名单（用于 SUM/AVG/LAG 等聚合列）。
     */
    private const AGGREGATE_FUNCTIONS = [
        'SUM', 'AVG', 'MIN', 'MAX', 'COUNT', 'GROUP_CONCAT',
        'JSON_ARRAYAGG', 'JSON_OBJECTAGG', 'BIT_AND', 'BIT_OR', 'BIT_XOR',
        'STD', 'STDDEV', 'VARIANCE', 'VAR_POP', 'VAR_SAMP',
    ];

    /**
     * SQL 窗口函数名白名单。
     */
    private const WINDOW_FUNCTIONS = [
        'ROW_NUMBER', 'RANK', 'DENSE_RANK', 'PERCENT_RANK', 'CUME_DIST',
        'NTILE', 'LAG', 'LEAD', 'FIRST_VALUE', 'LAST_VALUE', 'NTH_VALUE',
    ];

    /**
     * 校验 SQL 标识符（列名 / 别名）合法性。
     *
     * @param string $identifier 待校验标识符
     * @param string $type 用于异常提示的语境，如 'column' / 'alias'
     * @return string 原值（校验通过）
     * @throws InvalidArgumentException
     */
    public static function assertValidIdentifier(string $identifier, string $type = 'column'): string
    {
        $raw = trim($identifier, '`"[]');

        if ($raw === '' || ! preg_match(self::IDENTIFIER_PATTERN, $raw)) {
            throw new InvalidArgumentException(
                sprintf('非法的 SQL %s标识符: "%s"。标识符仅允许字母、数字、下划线与受限的点分隔。', $type, $identifier)
            );
        }

        return $identifier;
    }

    /**
     * 校验排序方向。
     *
     * @param string $direction 用户输入的 asc / desc（大小写不敏感）
     * @return string 规范化的大写方向
     * @throws InvalidArgumentException
     */
    public static function assertDirection(string $direction): string
    {
        $direction = strtoupper(trim($direction));

        if (! in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException(
                sprintf('非法的排序方向: "%s"。仅允许 asc 或 desc。', $direction)
            );
        }

        return $direction;
    }

    /**
     * 校验 MySQL 正则匹配模式字符（c/i/m/n/s/u 的子集）。
     *
     * @param string $mode
     * @return string 规范化后的模式串
     * @throws InvalidArgumentException
     */
    public static function assertRegexMode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        if ($mode !== '' && ! preg_match('/^[cimsnu]+$/', $mode)) {
            throw new InvalidArgumentException(
                sprintf('非法的正则模式: "%s"。仅允许 c/i/m/n/s/u 的组合。', $mode)
            );
        }

        return $mode;
    }

    /**
     * 校验比较运算符。
     *
     * @param string $operator
     * @return string 规范化运算符
     * @throws InvalidArgumentException
     */
    public static function assertOperator(string $operator): string
    {
        $allowed = ['=', '>', '<', '>=', '<=', '<>', '!=', 'like', 'not like', 'ilike'];

        $operator = strtolower(trim($operator));

        if (! in_array($operator, $allowed, true)) {
            throw new InvalidArgumentException(
                sprintf('非法的比较运算符: "%s"。', $operator)
            );
        }

        return $operator;
    }

    /**
     * 校验 JSON Path 表达式合法性。
     *
     * @param string $path 例如 '$.name' / '$.items[0].price'
     * @return string 原值（校验通过）
     * @throws InvalidArgumentException
     */
    public static function assertJsonPath(string $path): string
    {
        $trimmed = trim($path);

        if ($trimmed === '' || ! preg_match(self::JSON_PATH_PATTERN, $trimmed)) {
            throw new \InvalidArgumentException(
                sprintf('非法的 JSON Path 表达式: "%s"。', $path)
            );
        }

        return $trimmed;
    }

    /**
     * 校验聚合函数名（SUM / AVG / LAG 等）。
     *
     * @param string $function 用户输入的聚合函数名（大小写不敏感）
     * @return string 规范化大写函数名
     * @throws InvalidArgumentException
     */
    public static function assertAggregateFunction(string $function): string
    {
        $uppercase = strtoupper(trim($function));

        if (! in_array($uppercase, self::AGGREGATE_FUNCTIONS, true)) {
            throw new InvalidArgumentException(
                sprintf('非法的聚合函数: "%s"。仅允许: %s。', $function, implode(', ', self::AGGREGATE_FUNCTIONS))
            );
        }

        return $uppercase;
    }

    /**
     * 校验窗口函数名（ROW_NUMBER / RANK / LAG 等）。
     *
     * @param string $function
     * @return string 规范化大写函数名
     * @throws InvalidArgumentException
     */
    public static function assertWindowFunction(string $function): string
    {
        $uppercase = strtoupper(trim($function));

        if (! in_array($uppercase, self::WINDOW_FUNCTIONS, true)) {
            throw new InvalidArgumentException(
                sprintf('非法的窗口函数: "%s"。仅允许: %s。', $function, implode(', ', self::WINDOW_FUNCTIONS))
            );
        }

        return $uppercase;
    }

    /**
     * 校验窗口框架边界（ROWS/RANGE BETWEEN 的 bound 表达式）。
     *
     * 允许白名单关键字：UNBOUNDED PRECEDING / UNBOUNDED FOLLOWING / CURRENT ROW，
     * 以及整数偏移（如 1 PRECEDING / 3 FOLLOWING）。
     *
     * @param string $bound 例如 'UNBOUNDED PRECEDING' / 'CURRENT ROW' / '1 PRECEDING' / '3 FOLLOWING'
     * @return string 规范化后的边界表达式
     * @throws InvalidArgumentException
     */
    public static function assertFrameBound(string $bound): string
    {
        $bound = strtoupper(trim($bound));

        if (in_array($bound, ['UNBOUNDED PRECEDING', 'UNBOUNDED FOLLOWING', 'CURRENT ROW'], true)) {
            return $bound;
        }

        if (preg_match('/^\d+\s+(PRECEDING|FOLLOWING)$/', $bound)) {
            return $bound;
        }

        throw new InvalidArgumentException(
            sprintf('非法的窗口框架边界: "%s"。', $bound)
        );
    }

    /**
     * 断言当前数据库连接为 MySQL。
     *
     * 大量宏依赖 MySQL 8.0+ 专属语法（REGEXP_LIKE / JSON_EXTRACT / VALUES ROW /
     * ON DUPLICATE KEY UPDATE / LATERAL 等）。在非 MySQL 驱动上调用应给出清晰异常。
     *
     * @param EloquentBuilder|QueryBuilder $builder
     * @param string $feature 功能名（用于异常提示）
     * @return void
     * @throws InvalidArgumentException
     */
    public static function assertMysql(EloquentBuilder|QueryBuilder $builder, string $feature): void
    {
        $driver = $builder->getConnection()->getDriverName();

        if ($driver !== 'mysql') {
            throw new InvalidArgumentException(
                sprintf('宏 [%s] 仅支持 MySQL 8.0+ 数据库，当前驱动为 [%s]。', $feature, $driver)
            );
        }
    }

    /**
     * 用 grammar 安全转义标识符（列名 / 别名 / 表名）。
     *
     * 自动适配当前数据库连接（MySQL 反引号、PostgreSQL/SQLite 双引号），
     * 替代原先硬编码的 `{$column}` 反引号拼接。
     *
     * @param EloquentBuilder|QueryBuilder $builder
     * @param string $identifier 已通过 {@see self::assertValidIdentifier()} 的标识符
     * @return string grammar wrap 后的安全片段（如 `column` / "column"）
     */
    public static function wrapIdentifier(EloquentBuilder|QueryBuilder $builder, string $identifier): string
    {
        return $builder->getGrammar()->wrap($identifier);
    }

    /**
     * 构建窗口函数通用框架片段（带聚合列）。
     *
     * 支持：{@code FUNC(aggregateExpr) OVER (PARTITION BY ... ORDER BY ...)}，
     * 适用于 SUM/AVG/LAG/LEAD/FIRST_VALUE/NTH_VALUE 等带聚合列的窗口函数。
     *
     * @param EloquentBuilder|QueryBuilder $builder
     * @param string $function 窗口函数名（经 {@see self::assertWindowFunction()}）
     * @param string $aggregateExpr 聚合表达式（如 `amount` 或 `LAG(`amount`, 1)`），已安全处理
     * @param string|array|null $partitionBy 分区字段（字符串或数组）
     * @param string $orderBy 排序列（空则使用模型主键，仅 Eloquent Builder 有效）
     * @param string $direction 排序方向
     * @return string 完整窗口函数片段（不含 AS 别名）
     */
    public static function buildAggregateWindowClause(
        EloquentBuilder|QueryBuilder $builder,
        string $function,
        string $aggregateExpr,
        string|array|null $partitionBy,
        string $orderBy,
        string $direction
    ): string {
        $function = self::assertWindowFunction($function);
        $direction = self::assertDirection($direction);

        $orderColumn = $orderBy !== ''
            ? $orderBy
            : ($builder instanceof EloquentBuilder ? $builder->getModel()->getKeyName() : '');

        if ($orderColumn !== '') {
            $orderColumn = self::assertValidIdentifier($orderColumn, 'order');
        }

        $partitionClause = self::buildPartitionClause($builder, $partitionBy);
        $orderClause = $orderColumn !== ''
            ? 'ORDER BY ' . self::wrapIdentifier($builder, $orderColumn) . ' ' . $direction
            : '';

        return trim(sprintf('%s(%s) OVER (%s %s)', $function, $aggregateExpr, $partitionClause, $orderClause));
    }

    /**
     * 构建无参窗口函数框架片段（ROW_NUMBER / RANK / NTILE 等）。
     *
     * @param EloquentBuilder|QueryBuilder $builder
     * @param string $function 窗口函数名
     * @param string|array|null $partitionBy
     * @param string $orderBy
     * @param string $direction
     * @return string
     */
    public static function buildWindowClause(
        EloquentBuilder|QueryBuilder $builder,
        string $function,
        string|array|null $partitionBy,
        string $orderBy,
        string $direction
    ): string {
        $function = self::assertWindowFunction($function);
        $direction = self::assertDirection($direction);

        $orderColumn = $orderBy !== ''
            ? $orderBy
            : ($builder instanceof EloquentBuilder ? $builder->getModel()->getKeyName() : '');

        if ($orderColumn !== '') {
            $orderColumn = self::assertValidIdentifier($orderColumn, 'order');
        }

        $partitionClause = self::buildPartitionClause($builder, $partitionBy);
        $orderClause = $orderColumn !== ''
            ? 'ORDER BY ' . self::wrapIdentifier($builder, $orderColumn) . ' ' . $direction
            : '';

        return trim(sprintf('%s() OVER (%s %s)', $function, $partitionClause, $orderClause));
    }

    /**
     * 构建 PARTITION BY 片段。
     *
     * @param EloquentBuilder|QueryBuilder $builder
     * @param string|array|null $partitionBy
     * @return string 例如 'PARTITION BY `dept_id`' 或 ''（无分区）
     */
    public static function buildPartitionClause(EloquentBuilder|QueryBuilder $builder, string|array|null $partitionBy): string
    {
        if ($partitionBy === null || $partitionBy === '' || $partitionBy === []) {
            return '';
        }

        $columns = is_array($partitionBy) ? $partitionBy : [$partitionBy];
        $wrapped = [];

        foreach ($columns as $column) {
            self::assertValidIdentifier((string) $column, 'partition');
            $wrapped[] = self::wrapIdentifier($builder, (string) $column);
        }

        return 'PARTITION BY ' . implode(', ', $wrapped);
    }
}
