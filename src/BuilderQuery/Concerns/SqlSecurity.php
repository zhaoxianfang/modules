<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\Concerns;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

/**
 * SQL 片段安全编译工具
 * 解决 BuilderQuery 宏体系最初「字符串裸插值」导致的系统性问题：
 *  1. SQL 注入风险 —— 列名 / 别名 / 排序方向 / 正则模式 / 字面值此前直接拼进 SQL；
 *     本工具提供标识符白名单校验与 grammar wrap，字面值一律走参数绑定。
 *  2. 硬编码 MySQL 反引号 —— 改用 {@see self::wrapIdentifier()} 用 grammar 生成适配
 *     当前数据库连接（MySQL / PostgreSQL / SQLite）的标识符引用。
 *  3. 驱动未判断 —— {@see self::assertMysql()} 在依赖 MySQL 专属语法的宏入口统一拦截，
 *     给出清晰异常而非静默生成非法 SQL。
 * 重要调用约定（Laravel Macroable 机制）：
 *   `Builder::macro()` 的闭包在调用时会被 `Closure::bind` 重绑到 `Builder` 作用域，
 *   因此宏闭包内 `self::` 解析到 `Builder` 而非宏类，**必须**用显式类名调用本 trait 方法：
 *   ```php
 *   WindowFunctionsMacro::assertValidIdentifier($column, 'column');
 *   ```
 * 本 trait 的方法全部声明为 `public static`，以保证跨作用域可调用。
 *
 * @internal 仅用于 BuilderQuery 内部宏实现
 */
trait SqlSecurity
{
    /**
     * 合法 SQL 标识符（列名 / 别名 / 字段名）白名单。
     * 允许：字母、数字、下划线，以及受限的点（跨表引用如 a.b）。
     */
    public const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/';

    /**
     * 合法 JSON Path 表达式。
     */
    public const JSON_PATH_PATTERN = '/^\$[A-Za-z0-9_\[\]\.\*"\x{4e00}-\x{9fa5}]*$/u';

    /**
     * 合法聚合函数白名单（用于 SUM/AVG/LAG 等聚合列）。
     */
    public const AGGREGATE_FUNCTIONS = [
        'SUM', 'AVG', 'MIN', 'MAX', 'COUNT', 'GROUP_CONCAT',
        'JSON_ARRAYAGG', 'JSON_OBJECTAGG', 'BIT_AND', 'BIT_OR', 'BIT_XOR',
        'STD', 'STDDEV', 'VARIANCE', 'VAR_POP', 'VAR_SAMP',
    ];

    /**
     * SQL 窗口函数名白名单。
     */
    public const WINDOW_FUNCTIONS = [
        'ROW_NUMBER', 'RANK', 'DENSE_RANK', 'PERCENT_RANK', 'CUME_DIST',
        'NTILE', 'LAG', 'LEAD', 'FIRST_VALUE', 'LAST_VALUE', 'NTH_VALUE',
    ];

    /**
     * 校验 SQL 标识符（列名 / 别名）合法性。
     *
     * @param  string  $identifier  待校验标识符
     * @param  string  $type  用于异常提示的语境，如 'column' / 'alias'
     * @return string 原值（校验通过）
     *
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
     * @param  string  $direction  用户输入的 asc / desc（大小写不敏感）
     * @return string 规范化的大写方向
     *
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
     * @return string 规范化后的模式串
     *
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
     * @return string 规范化运算符
     *
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
     * @param  string  $path  例如 '$.name' / '$.items[0].price'
     * @return string 原值（校验通过）
     *
     * @throws InvalidArgumentException
     */
    public static function assertJsonPath(string $path): string
    {
        $trimmed = trim($path);

        if ($trimmed === '' || ! preg_match(self::JSON_PATH_PATTERN, $trimmed)) {
            throw new InvalidArgumentException(
                sprintf('非法的 JSON Path 表达式: "%s"。', $path)
            );
        }

        return $trimmed;
    }

    /**
     * 校验聚合函数名（SUM / AVG / LAG 等）。
     *
     * @param  string  $function  用户输入的聚合函数名（大小写不敏感）
     * @return string 规范化大写函数名
     *
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
     * @return string 规范化大写函数名
     *
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
     * 允许白名单关键字：UNBOUNDED PRECEDING / UNBOUNDED FOLLOWING / CURRENT ROW，
     * 以及整数偏移（如 1 PRECEDING / 3 FOLLOWING）。
     *
     * @param  string  $bound  例如 'UNBOUNDED PRECEDING' / 'CURRENT ROW' / '1 PRECEDING' / '3 FOLLOWING'
     * @return string 规范化后的边界表达式
     *
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
     * 断言当前数据库连接受本扩展支持（MySQL 8.0+ 或 PostgreSQL 15+）。
     * 历史上本方法名为 assertMysql，仅允许 MySQL；在「MySQL + PostgreSQL 双数据库通用」重构后，
     * 它改为允许 mysql 与 pgsql 两种驱动，仅在遇到 SQLite 等未全面支持的驱动时抛出清晰异常。
     * 新代码应优先使用 {@see Dialect::assertSupportedOn()} 以精确表达受支持驱动列表。
     * 注意：本方法仅做「驱动是否受支持」的粗粒度判断，不保证后续生成的 SQL 一定适配当前驱动；
     * 真正的方言差异由 {@see Dialect} 的各片段方法在调用点按驱动分别生成 SQL。
     *
     * @param  string  $feature  功能名（用于异常提示）
     *
     * @throws InvalidArgumentException
     */
    public static function assertMysql(EloquentBuilder|QueryBuilder $builder, string $feature): void
    {
        self::assertSupportedOn($builder, $feature, ['mysql', 'pgsql']);
    }

    /**
     * 断言当前数据库连接受本扩展支持（MySQL 8.0+ 或 PostgreSQL 15+）。
     * 见 {@see self::assertMysql()} 的说明；本方法为语义更清晰的别名。
     *
     * @param  string  $feature  功能名（用于异常提示）
     *
     * @throws InvalidArgumentException
     */
    public static function assertSupported(EloquentBuilder|QueryBuilder $builder, string $feature): void
    {
        self::assertMysql($builder, $feature);
    }

    /**
     * 用 grammar 安全转义标识符（列名 / 别名 / 表名）。
     * 自动适配当前数据库连接（MySQL 反引号、PostgreSQL/SQLite 双引号），
     * 替代原先硬编码的 `{$column}` 反引号拼接。
     *
     * @param  string  $identifier  已通过 {@see self::assertValidIdentifier()} 的标识符
     * @return string grammar wrap 后的安全片段（如 `column` / "column"）
     */
    public static function wrapIdentifier(EloquentBuilder|QueryBuilder $builder, string $identifier): string
    {
        return $builder->getGrammar()->wrap($identifier);
    }

    /**
     * 构建窗口函数通用框架片段（带聚合列）。
     * 支持：{@code FUNC(aggregateExpr) OVER (PARTITION BY ... ORDER BY ...)}，
     * 适用于 SUM/AVG/LAG/LEAD/FIRST_VALUE/NTH_VALUE 等带聚合列的窗口函数。
     *
     * @param  string  $function  窗口函数名（经 {@see self::assertWindowFunction()}）
     * @param  string  $aggregateExpr  聚合表达式（如 `amount` 或 `LAG(`amount`, 1)`），已安全处理
     * @param  string|array|null  $partitionBy  分区字段（字符串或数组）
     * @param  string  $orderBy  排序列（空则使用模型主键，仅 Eloquent Builder 有效）
     * @param  string  $direction  排序方向
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
            ? 'ORDER BY '.self::wrapIdentifier($builder, $orderColumn).' '.$direction
            : '';

        return trim(sprintf('%s(%s) OVER (%s %s)', $function, $aggregateExpr, $partitionClause, $orderClause));
    }

    /**
     * 构建无参窗口函数框架片段（ROW_NUMBER / RANK / NTILE 等）。
     *
     * @param  string  $function  窗口函数名
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
            ? 'ORDER BY '.self::wrapIdentifier($builder, $orderColumn).' '.$direction
            : '';

        return trim(sprintf('%s() OVER (%s %s)', $function, $partitionClause, $orderClause));
    }

    /**
     * 构建 PARTITION BY 片段。
     *
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

        return 'PARTITION BY '.implode(', ', $wrapped);
    }
}
