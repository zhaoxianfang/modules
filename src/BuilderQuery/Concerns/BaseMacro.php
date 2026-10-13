<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\Concerns;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * 宏模块标准化基类（Standardized Macro Base）
 * 本基类统一了「BuilderQuery 宏扩展」的架构规范，所有宏模块（WindowMacros 命名空间下的类）
 * 均须 `extends BaseMacro`。它解决了历史版本中以下不规范/缺陷问题：
 * 1. **ORM 与 DB 双友好**：{@see self::define()} 会同时将同一个宏注册到
 *    `Illuminate\Database\Eloquent\Builder`（ORM 模型查询）与
 *    `Illuminate\Database\Query\Builder`（`DB::table()` 原生查询）上，
 *    因此 `User::query()->whereRegexp(...)` 与 `DB::table('users')->whereRegexp(...)`
 *    均可直接使用，满足「必须友好支持 Laravel 11+ 的 ORM 和 DB」要求。
 * 2. **闭包内 self:: 陷阱修复**：Laravel Macroable 在绑定宏闭包时，会将 `static::` 重绑到
 *    Builder 类；宏闭包内部若写 `self::helper()` 会在运行时解析为 `Builder::helper()` 而致命报错。
 *    因此所有助手方法务必使用**显式类名**（如 `RegexMacro::assertXxx`）调用，禁止在闭包内用 `self::`。
 * 3. **跨构建器助手**：{@see self::resolveTable()} / {@see self::freshQuery()} / {@see self::resetSubQuery()}
 *    等可在 Eloquent 与 Query 两种构建器上安全取得表名、构建同源子查询、重置子查询状态，
 *    使宏逻辑与具体构建器解耦，提升扩展性与可维护性。
 * 4. **统一的注册与文档约定**：模块统一暴露 `public static function register(): void`，
 *    内部调用 `self::registerXxx()` 分组注册；每个宏闭包头部须含 `@example` 与 `@scenario`
 *    （场景）注释，参数须声明类型并经白名单校验，链式查询宏一律返回 `$this`（Builder）。
 *
 * @version 3.1.0
 *
 * @requires PHP 8.3+, Laravel 11+ / 12+ / 13+, MySQL 8.0+ / PostgreSQL 15+
 *
 * @internal 仅用于 BuilderQuery 内部宏模块继承；不直接实例化。
 */
abstract class BaseMacro
{
    use Dialect;

    /**
     * SELECT 前缀中间状态存储（前缀串 + 干净列集合）。
     * 以 spl_object_id 为键、WeakReference 做身份校验，避免：
     *  - 在 Builder 上写入动态属性（PHP 8.2+ 触发 E_DEPRECATED）；
     *  - 强引用导致的跨请求内存泄漏；
     *  - 对象 id 复用造成的状态串号。
     *
     * @var array<int, array{ref: \WeakReference, prefix: string, base: array|null}>
     */
    private static array $xbqState = [];

    /* =====================================================================
     * 1. 双构建器注册（ORM + DB 友好）
     * ===================================================================== */

    /**
     * 将宏同时注册到 Eloquent Builder（ORM）与 Query Builder（DB）。
     * 注意：闭包内 `$this` 在运行时会被重绑到被调用的构建器实例，
     * 因此闭包体必须兼容两种构建器（详见类注释第 2 点）。
     *
     * @param  string  $name  宏名称（小驼峰，语义化、场景化）
     * @param  \Closure  $closure  宏实现闭包
     */
    public static function define(string $name, \Closure $closure): void
    {
        EloquentBuilder::macro($name, $closure);
        QueryBuilder::macro($name, $closure);
    }

    /* =====================================================================
     * 2. 跨构建器安全助手
     * ===================================================================== */

    /**
     * 解析当前构建器对应的物理表名。
     * - Eloquent\Builder：取模型的 `getTable()`；
     * - Query\Builder：取 `from`（字符串或数组别名取首键）。
     *
     * @param  EloquentBuilder|QueryBuilder  $builder
     */
    public static function resolveTable($builder): string
    {
        if ($builder instanceof EloquentBuilder) {
            return $builder->getModel()->getTable();
        }

        $from = $builder->from;
        if (is_string($from)) {
            return $from;
        }
        if (is_array($from)) {
            return (string) array_key_first($from);
        }

        return (string) $from;
    }

    /**
     * 取得当前构建器对应的模型（仅 Eloquent 可用，其余返回 null）。
     *
     * @param  EloquentBuilder|QueryBuilder  $builder
     */
    public static function modelOf($builder): ?Model
    {
        return $builder instanceof EloquentBuilder ? $builder->getModel() : null;
    }

    /**
     * 取得当前构建器的主键列名。
     * - Eloquent\Builder：取模型的 `getKeyName()`（默认 id）；
     * - Query\Builder：无主键概念，返回默认 'id'（双库通用占位）。
     *
     * @param  EloquentBuilder|QueryBuilder  $builder
     */
    public static function keyName($builder): string
    {
        return $builder instanceof EloquentBuilder ? $builder->getModel()->getKeyName() : 'id';
    }

    /**
     * 取得底层 Query Builder。
     * - Eloquent\Builder：`$builder->getQuery()`；
     * - Query\Builder：自身。
     *
     * @param  EloquentBuilder|QueryBuilder  $builder
     */
    public static function queryOf($builder): QueryBuilder
    {
        return $builder instanceof EloquentBuilder ? $builder->getQuery() : $builder;
    }

    /**
     * 构建一个与当前构建器同源的「全新」查询（用于 CTE / 子查询 / 延迟关联等）。
     * - Eloquent\Builder：返回模型的 `newQuery()`；
     * - Query\Builder：返回 `DB::table(表名)`，清空所有既有条件。
     *
     * @param  EloquentBuilder|QueryBuilder  $builder
     * @return EloquentBuilder|QueryBuilder
     */
    public static function freshQuery($builder)
    {
        if ($builder instanceof EloquentBuilder) {
            return $builder->getModel()->newQuery();
        }

        return DB::table(static::resolveTable($builder));
    }

    /**
     * 安全地重置子查询的 select / limit / offset / order / where 状态（双构建器通用）。
     * 直接对 Eloquent 子查询写 `$sub->getQuery()->columns = null` 在 Query 构建器上会失败，
     * 故统一经此方法处理，保证 ORM 与 DB 路径行为一致。
     *
     * @param  EloquentBuilder|QueryBuilder  $subQuery
     */
    public static function resetSubQuery($subQuery): void
    {
        $query = static::queryOf($subQuery);
        $query->columns = null;
        $query->limit = null;
        $query->offset = null;
        $query->orders = null;
        if (isset($query->bindings['where'])) {
            $query->bindings['where'] = [];
        }
        $query->wheres = [];
    }

    /**
     * 校验并 wrap 列名（标识符安全）。
     *
     * @param  EloquentBuilder|QueryBuilder  $builder
     * @param  string  $column  待校验列名（支持 `表.列` 形式，逐段白名单校验）
     * @return string 经 grammar wrap 后的安全标识符
     */
    public static function wrapColumn($builder, string $column): string
    {
        return static::wrapIdentifier($builder, static::assertValidIdentifier($column, 'column'));
    }

    /**
     * 校验并 wrap 聚合/排序列名（允许 `*`，仅用于 COUNT(*) 等场景）。
     *
     * @param  EloquentBuilder|QueryBuilder  $builder
     */
    public static function wrapColumnOrStar($builder, string $column): string
    {
        $column = trim($column);
        if ($column === '*') {
            return '*';
        }

        return static::wrapColumn($builder, $column);
    }

    /* =====================================================================
     * 3. 查询性能守卫（SELECT 前缀 / 优化器提示 / 索引提示）
     * ===================================================================== */

    /**
     * 构造一个原始表达式（Expression），用于注入方言特定的 SQL 片段。
     *
     * @param  string  $sql  原始 SQL 片段（由调用方确保已做标识符校验与参数绑定）
     */
    public static function rawExpression(string $sql): Expression
    {
        return new Expression($sql);
    }

    /**
     * 在 SELECT 列表前累加一个 SQL 前缀（幂等，多次调用会顺序拼接）。
     * 用于注入：
     *  - 优化器提示：`/*+ MAX_EXECUTION_TIME(2000) *\/`
     *  - MySQL 查询修饰符：`STRAIGHT_JOIN`
     * 通过读取当前 columns 重建为单个表达式，避免重复嵌套；若后续再次调用
     * `select()` 整体替换列，则此前缀会失效（属预期，请在列选择之后再调用本类宏）。
     * 前缀的中间状态存于 {@see self::$xbqState}（以 WeakReference 关联构建器，避免
     * 在 Builder 上写入动态属性而在 PHP 8.2+ 触发 E_DEPRECATED，亦无内存泄漏风险）。
     *
     * @param  EloquentBuilder|QueryBuilder  $builder
     * @param  string  $prefix  待前置的 SQL 片段（前后留白由本方法统一处理）
     */
    public static function addSelectPrefix($builder, string $prefix): void
    {
        $state = static::xbqGetState($builder);

        // 首次注入前缀时，记录「未被前缀污染」的干净列集合，供后续重建反复使用。
        // 仅捕获一次：若当时尚未显式 select，则以 ['*'] 哨兵占位；若之后整体替换了列，则以最新列为准。
        if ($state['base'] === null) {
            $cols = static::queryOf($builder)->columns;
            $state['base'] = ($cols === null || $cols === []) ? ['*'] : $cols;
        }

        $state['prefix'] = trim($state['prefix'].' '.$prefix);
        static::xbqSetState($builder, $state);

        static::rebuildSelectWithPrefix($builder);
    }

    /**
     * 累加一个优化器提示（MySQL `/*+ ... *\/` 或 PostgreSQL pg_hint_plan `/*+ ... *\/`）。
     * 多次调用会合并为单个注释、仅出现一次。示例：
     *  - `MAX_EXECUTION_TIME(2000)`（MySQL）
     *  - `IndexScan(users idx_name)` / `NoIndexScan(...)`（pg_hint_plan）
     *  - `JOIN_ORDER(t1, t2)` / `HASH_JOIN(t1)` / `SKIP_SCAN(...)` / `SET_VAR(...)`
     *
     * @param  EloquentBuilder|QueryBuilder  $builder
     * @param  string  $hint  提示内容（不要包含 `/*+ *\/` 注释符，由本方法统一包裹）
     */
    public static function addOptimizerHint($builder, string $hint): void
    {
        static::addSelectPrefix($builder, '/*+ '.trim($hint).' */');
    }

    /**
     * 将已累加的 SELECT 前缀前置到当前 SELECT 列表（用于实际生成 SQL）。
     *
     * @param  EloquentBuilder|QueryBuilder  $builder
     */
    private static function rebuildSelectWithPrefix($builder): void
    {
        $state = static::xbqGetState($builder);
        $prefix = $state['prefix'];
        if ($prefix === '') {
            return;
        }

        $query = static::queryOf($builder);
        $columns = $state['base'];

        if ($columns === null || $columns === [] || $columns === ['*']) {
            $colsSql = '*';
        } else {
            $parts = [];
            foreach ((array) $columns as $col) {
                $parts[] = $col instanceof Expression ? (string) $col : (string) $col;
            }
            $colsSql = implode(', ', $parts);
        }

        $query->columns = [new Expression($prefix.' '.$colsSql)];
    }

    /**
     * 取得与指定构建器关联的前缀状态（前缀串 + 干净列集合）。
     * 以 `spl_object_id` 为键、WeakReference 做身份校验：对象被回收或同 id 被复用为新对象时自动重置，
     * 避免动态属性（PHP 8.2+ deprecated）与内存泄漏，同时规避对象 id 复用导致的状态串号。
     *
     * @param  EloquentBuilder|QueryBuilder  $builder
     * @return array{prefix: string, base: array|null}
     */
    private static function xbqGetState($builder): array
    {
        $id = spl_object_id($builder);
        if (isset(static::$xbqState[$id])) {
            $entry = static::$xbqState[$id];
            if ($entry['ref']->get() === $builder) {
                return ['prefix' => $entry['prefix'], 'base' => $entry['base']];
            }
        }
        $fresh = ['ref' => \WeakReference::create($builder), 'prefix' => '', 'base' => null];
        static::$xbqState[$id] = $fresh;

        return ['prefix' => '', 'base' => null];
    }

    /**
     * 写回与指定构建器关联的前缀状态。
     *
     * @param  EloquentBuilder|QueryBuilder  $builder
     * @param  array{prefix: string, base: array|null}  $state
     */
    private static function xbqSetState($builder, array $state): void
    {
        $id = spl_object_id($builder);
        static::$xbqState[$id] = [
            'ref' => \WeakReference::create($builder),
            'prefix' => $state['prefix'],
            'base' => $state['base'],
        ];
    }
}
