<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\Concerns;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Foundation\Application;
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
 * @version 1.13.9
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
     * 宏名与框架原生方法冲突登记表。
     *
     * Laravel 13.32 起，Query\Builder 新增了原生方法
     * whereFullText() / orWhereFullText()（带 array $options 的全文检索）。
     * 由于 Macroable 只在「方法不存在」时才会走 __call，一旦框架提供了同名真实方法，
     * 本包的同名宏就永远不会生效（静默失效）；更糟的是：本包文档里
     * whereFullText($cols, $kw, 'boolean') 的「字符串模式」写法在新版框架上会直接
     * 触发 TypeError（原生签名第三参是 array $options）。
     *
     * 本表在注册时自动探测冲突并记录，供诊断接口 {@see conflicts()} 查询，
     * 避免这类「注册成功但永远不会被调用」的静默坑被彻底遗漏。
     *
     * @var array<string, array{class: class-string, method: string, since: string}>
     */
    private static array $nativeConflicts = [];

    /**
     * 将宏同时注册到 Eloquent Builder（ORM）与 Query Builder（DB）。
     * 注意：闭包内 `$this` 在运行时会被重绑到被调用的构建器实例，
     * 因此闭包体必须兼容两种构建器（详见类注释第 2 点）。
     *
     * 冲突处置：若某一侧的构建器已经自带同名**真实方法**（Laravel 13.32+ 的
     * whereFullText / orWhereFullText、8.0 起的 cursorPaginate 等），
     * Macroable 的 __call 根本不会被触发 —— 宏注册成功却**永远不会被调用**，
     * 而调用方拿到的是签名完全不同的原生方法，静默给错结果。
     *
     * 这里对被遮蔽的一侧**跳过注册**（注册了也没用，只会掩盖问题），
     * 并在提供了 $alias 时把别名注册上去，让功能真正可用且可发现。
     *
     * @param  string  $name  宏名称（小驼峰，语义化、场景化）
     * @param  \Closure  $closure  宏实现闭包
     * @param  string|null  $alias  宏名被原生方法遮蔽时改用的别名（如 whereFullTextMatch）
     */
    public static function define(string $name, \Closure $closure, ?string $alias = null): void
    {
        $targets = [EloquentBuilder::class, QueryBuilder::class];

        foreach ($targets as $class) {
            if (! self::hasNativeMethod($class, $name)) {
                $class::macro($name, $closure);

                continue;
            }

            self::recordNativeConflict($class, $name);

            // 原生方法优先：不在这一侧注册同名宏。
            // 若调用方本意是本包的能力，提供的别名就是它们的出口。
            if ($alias !== null && $alias !== '' && ! self::hasNativeMethod($class, $alias)) {
                $class::macro($alias, $closure);
            }
        }
    }

    /**
     * 判断目标构建器是否自带该**真实方法**（排除宏与继承来的无关方法）。
     *
     * 注意判定方式必须是「反射查真实方法」而非 method_exists：
     * method_exists 会把通过 Macroable 动态混入的方法也算进来，导致自我误报。
     *
     * @param  class-string  $class  目标构建器类
     */
    private static function hasNativeMethod(string $class, string $name): bool
    {
        if (! method_exists($class, $name)) {
            return false;
        }

        try {
            $declaring = (new \ReflectionMethod($class, $name))->getDeclaringClass()->getName();
        } catch (\ReflectionException) {
            return false;
        }

        return $declaring === $class;
    }

    /**
     * 把冲突登记到冲突表（供诊断接口查询）。
     *
     * @param  class-string  $class  目标构建器类
     * @param  string  $name  宏名
     */
    private static function recordNativeConflict(string $class, string $name): void
    {
        if (isset(self::$nativeConflicts[$class.'::'.$name])) {
            return;
        }

        self::$nativeConflicts[$class.'::'.$name] = [
            'class' => $class,
            'method' => $name,
            'since' => self::frameworkVersion(),
        ];
    }

    /**
     * 取当前 Laravel 框架版本（不可用时返回 'unknown'）。
     */
    private static function frameworkVersion(): string
    {
        if (class_exists(Application::class)
            && defined(Application::class.'::VERSION')) {
            return (string) constant(Application::class.'::VERSION');
        }

        return 'unknown';
    }

    /**
     * 返回「宏名与框架原生方法冲突」登记表。
     *
     * 维护指引：新增宏时若此处出现同名条目，说明该宏在对应 Laravel 版本上永远不会被调用，
     * 必须二选一：
     *   ① 把本包宏的签名调整为与原生方法兼容（推荐，保证跨版本行为一致）；
     *   ② 改用不冲突的宏名并在文档中说明。
     *
     * @return array<string, array{class: class-string, method: string, since: string}>
     */
    public static function conflicts(): array
    {
        return self::$nativeConflicts;
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
     * 取当前构建器所用的数据库连接
     *
     * 宏承诺 Eloquent / Query 双构建器等价，而宏内部若用 `DB::table()` /
     * `DB::select()` 会走 DatabaseManager 的**默认连接**。在多库、读写分离、
     * 多租户场景下，这会让子查询作用到另一个库上（`deleteInChunks()` 会删错库）。
     * 因此内部一律经此方法取连接。
     *
     * @param  EloquentBuilder|QueryBuilder|null  $builder
     */
    public static function connOf($builder): ?ConnectionInterface
    {
        if ($builder instanceof EloquentBuilder) {
            return $builder->getQuery()->getConnection();
        }

        if ($builder instanceof QueryBuilder) {
            return $builder->getConnection();
        }

        return null;
    }

    /**
     * 构建一个与当前构建器同源的「全新」查询（用于 CTE / 子查询 / 延迟关联等）。
     * - Eloquent\Builder：返回模型的 `newQuery()`；
     * - Query\Builder：返回**同连接**上的 `table(表名)`，清空所有既有条件。
     *
     * @param  EloquentBuilder|QueryBuilder  $builder
     * @return EloquentBuilder|QueryBuilder
     */
    public static function freshQuery($builder)
    {
        if ($builder instanceof EloquentBuilder) {
            return $builder->getModel()->newQuery();
        }

        $connection = self::connOf($builder);

        return $connection !== null
            ? $connection->table(static::resolveTable($builder))
            : DB::table(static::resolveTable($builder));
    }

    /**
     * 在**当前构建器所用的连接**上开一个 table() 构建器。
     *
     * 不能直接用 DB::table()：那是 DatabaseManager 的【默认连接】。多库、读写分离、
     * 多租户场景下，主查询在 from_db、而子查询却落到默认库 —— 结果是子查询查不到数据，
     * 甚至让危险写操作作用到错误的库上。DB::raw() 则无此问题（只是构造表达式对象）。
     *
     * @param  EloquentBuilder|QueryBuilder  $builder
     * @param  string|Expression|null  $table  缺省取当前构建器的表名
     */
    public static function connTable($builder, $table = null)
    {
        $connection = static::connOf($builder);
        $table ??= static::resolveTable($builder);

        return $connection !== null ? $connection->table($table) : DB::table($table);
    }

    /**
     * 在当前构建器所用的连接上执行原生 SELECT（EXPLAIN / 估算行数等场景）。
     *
     * @param  EloquentBuilder|QueryBuilder  $builder
     * @param  array<int, mixed>  $bindings
     * @return array<int, mixed>
     */
    public static function connSelect($builder, string $sql, array $bindings = [])
    {
        $connection = static::connOf($builder);

        return $connection !== null
            ? $connection->select($sql, $bindings)
            : DB::select($sql, $bindings);
    }

    /**
     * 追加一个窗口函数表达式到 select 列。
     *
     * 不能直接用 `$builder->addSelect(DB::raw($expr))`：Laravel 的 addSelect 在
     * `columns === null` 时执行 `$this->columns[] = $column`，也就是**只留下窗口列**，
     * 把原本隐含的 `select *` 顶掉了。于是
     *     DB::table('orders')->lastValue('amount', 'region')->get()
     * 返回的对象里没有 id / region / amount 任何业务字段 —— 结果完全不可用，
     * 而且不报错，属于最难发现的一类缺陷（只在 Eloquent 已 select 过的查询上"看起来正常"）。
     *
     * 这里先确保 columns 非空（补 '*'），再追加表达式。
     *
     * @param  EloquentBuilder|QueryBuilder  $builder
     * @param  string  $expression  已由调用方构造好的窗口表达式文本
     * @return EloquentBuilder|QueryBuilder
     */
    public static function addWindowSelect($builder, string $expression)
    {
        $query = static::queryOf($builder);

        if (empty($query->columns)) {
            $query->columns = ['*'];
        }

        return $builder->addSelect(DB::raw($expression));
    }

    /**
     * 取得当前构建器已声明的 orderBy 列表（双构建器通用）。
     *
     * Eloquent 的 orders 在底层 query 上，Query 的就在自己身上；
     * 直接写 `$builder->getQuery()->orders` 在 DB::table() 路径上会抛
     * "Call to undefined method Query\Builder::getQuery()"。
     *
     * @param  EloquentBuilder|QueryBuilder  $builder
     * @return array<int, mixed>
     */
    public static function ordersOf($builder): array
    {
        $query = static::queryOf($builder);

        return is_array($query->orders ?? null) ? $query->orders : [];
    }

    /**
     * 取得一个"继承了调用方全部过滤条件、但清空了列选择/排序/分页"的子构建器（双构建器通用）。
     *
     * 用途：抽样、延迟关联、窗口分页等需要"把 WHERE 条件搬进子查询"的场景。
     *
     * 原先这些位置写成两行：
     *     $model = BaseMacro::modelOf($this);        // Query 构建器上返回 null
     *     $subQuery = $model->newQuery()             // ← null->newQuery() 直接 Fatal
     * 即便绕开 newQuery，`$model->getTable()` / `$model->getKeyName()` 在 DB::table() 路径上
     * 同样是 null 解引用。宏对外承诺 Eloquent / Query 双构建器等价，这里统一收口。
     *
     * @param  EloquentBuilder|QueryBuilder  $builder
     * @return EloquentBuilder|QueryBuilder
     */
    public static function cloneForSubQuery($builder)
    {
        $inner = static::queryOf($builder);
        $copy = clone $inner;

        $copy->columns = null;
        $copy->limit = null;
        $copy->offset = null;
        $copy->orders = null;

        // 结构与绑定成对清理，详见 resetSubQuery() 的说明。
        foreach (['select', 'order'] as $group) {
            if (isset($copy->bindings[$group])) {
                $copy->bindings[$group] = [];
            }
        }

        // where / joins / havings 及其绑定原样保留 —— 抽样与延迟关联必须继承调用方条件。
        if ($builder instanceof EloquentBuilder) {
            return (clone $builder)->setQuery($copy);
        }

        return $copy;
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

        // 关键：**结构与绑定必须成对清理**。
        // 只清 columns / orders 而留着 bindings['select'] / bindings['order']，
        // 调用方此前用过 selectRaw('f(?,?)', [a,b]) / orderByRaw('? desc', [x]) 时，
        // 残留绑定会排在 getBindings() 最前面，而新 SQL 里的 ? 数量已经变了 →
        // 绑定错位（轻则结果错乱，重则 SQLSTATE 驱动错误）。
        $query->columns = null;
        $query->limit = null;
        $query->offset = null;
        $query->orders = null;

        foreach (['select', 'order', 'where'] as $group) {
            if (isset($query->bindings[$group])) {
                $query->bindings[$group] = [];
            }
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
