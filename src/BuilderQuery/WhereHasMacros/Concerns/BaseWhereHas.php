<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WhereHasMacros\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

/**
 * whereHas 系列宏的公共基类（消除 7 个宏类之间的重复代码，并统一修复公共缺陷）。
 *
 *  1. 跨库关联：「跨库时给 from 加库名前缀」的逻辑统一收敛到 {@see relationQuery()}，
 *     并对 config() 不可用（纯单元测试 / 未启动容器）做兜底。
 *  2. 嵌套关联（'a.b.c'）：基类负责拆解并逐级传递 $nextRelation，子类必须显式消费它，
 *     否则 `whereHasJoin('a.b')` 会静默退化成只关联 a（条件丢失且不报错）。
 *  3. 列名安全：关联键名全部来自 Eloquent 关联对象（由模型定义派生，非用户输入），
 *     因此不做白名单校验（校验反而会误拒 `table.*` 这类合法限定列）；
 *     但用户传入的「关联名」会经 {@see resolveRelation()} 走模型方法解析，不做 eval。
 *  4. 软删除：与 Laravel 原生 whereHas 保持一致 —— 不自动追加 deleted_at 条件。
 *     如需过滤软删除，请在回调里显式写 $q->whereNull('deleted_at') 或使用 withTrashed 等。
 *
 * 泛型参数说明：
 *  - $relation 可以是关联名字符串（支持 'a.b.c' 点号嵌套）、关联对象实例，
 *    或它们的数组（逐个 AND 处理）。
 *  - $callback 由 MacrosBuilder 注入，签名为 fn ($nextRelation, Builder $relationQuery) => Builder。
 */
abstract class BaseWhereHas
{
    /** 主构建器（Eloquent Builder） */
    protected Builder $builder;

    /** 关联名（支持点号嵌套）/ 关联对象 / 数组 */
    protected mixed $relation;

    /**
     * 关联约束回调：由 MacrosBuilder 注入。
     * 约定签名 fn (string|array|null $nextRelation, Builder $relationQuery): Builder|null
     */
    protected ?Closure $callback;

    /** 关联名解析结果（首段） */
    protected ?Relation $resolved = null;

    /** 嵌套关联的剩余部分（点号之后），交给回调继续处理；无嵌套时为 null */
    protected string|array|null $nextRelation = null;

    /** 子类可覆盖：本类主查询使用的 where 方法（whereIn / whereNotIn …） */
    protected string $method = 'whereIn';

    public function __construct(Builder $builder, mixed $relation, ?Closure $callback = null)
    {
        $this->builder = $builder;
        $this->relation = $relation;
        $this->callback = $callback;
    }

    /**
     * 执行入口。
     */
    public function execute(): Builder
    {
        if ($this->relation === null || $this->relation === '' || $this->relation === []) {
            return $this->builder;
        }

        // 数组形式：逐个 AND 处理（每个元素独立解析，支持 'a' 与 ['b','c'] 混用）
        if (is_array($this->relation)) {
            foreach ($this->relation as $item) {
                $this->relation = $item;
                $this->nextRelation = null;
                $this->executeOne();
            }
            $this->relation = null;

            return $this->builder;
        }

        $this->executeOne();

        return $this->builder;
    }

    /**
     * 处理单个关联（字符串或关联对象）。
     */
    protected function executeOne(): void
    {
        $relation = $this->resolveRelation();
        $this->resolved = $relation;

        $this->applyRelation($relation);
    }

    /**
     * 子类实现：把已解析的关联应用到主查询上。
     */
    abstract protected function applyRelation(Relation $relation): void;

    /**
     * 解析关联（字符串 → 关联对象），并拆出嵌套的剩余部分。
     *
     * 使用 Relation::noConstraints 包裹，避免解析阶段就产生 where 约束
     * （约束应由关联查询自身通过回调追加）。
     */
    protected function resolveRelation(): Relation
    {
        if ($this->relation instanceof Relation) {
            $this->nextRelation = null;

            return $this->relation;
        }

        $names = explode('.', (string) $this->relation);
        $this->nextRelation = count($names) > 1 ? array_slice($names, 1) : null;

        return Relation::noConstraints(fn () => $this->builder->getRelation($names[0]));
    }

    /**
     * 取关联查询，并在「跨库关联」时补上库名前缀。
     *
     * 为什么需要：若关联模型与主模型使用不同连接（跨库），子查询里不带库名会到主连接
     * 所在的库里找表，导致「表不存在」。这里在 from 上补 `database.table` 前缀。
     */
    protected function relationQuery(Relation $relation): Builder
    {
        $q = $relation->getQuery();

        if ($this->builder->getModel()->getConnectionName() === $q->getModel()->getConnectionName()) {
            return $q;
        }

        $database = $this->relationDatabaseName($q);
        $table = $q->getModel()->getTable();
        if ($database === null || $database === '') {
            return $q;
        }

        if (! Str::contains($table, ["`{$database}`.", "{$database}."])) {
            $q->from("{$database}.{$table}");
        }

        return $q;
    }

    /**
     * 取关联连接对应的库名（按连接名做进程内缓存）。
     *
     * 对 config() 做了完整兜底：本包强调「最小框架依赖」，在未启动 Laravel 容器的
     * 场景（如纯单元测试）下 config() 会抛异常，此处兜底可避免整个宏失败。
     */
    protected function relationDatabaseName(Builder $relationQuery): ?string
    {
        static $cache = [];

        $name = $relationQuery->getModel()->getConnectionName();
        if ($name === null) {
            $name = function_exists('config') ? config('database.default') : null;
        }
        if ($name === null) {
            return null;
        }

        if (array_key_exists($name, $cache)) {
            return $cache[$name];
        }

        $database = null;
        try {
            $database = function_exists('config') ? config('database.connections.'.$name.'.database') : null;
        } catch (\Throwable) {
            // 容器未启动 / 配置不可读时按「非跨库」处理
            $database = null;
        }

        return $cache[$name] = is_string($database) ? $database : null;
    }

    /**
     * 取关联的限定外键列名（兼容不同关联类型的方法命名差异）。
     */
    protected function qualifiedForeignKeyName(Relation $relation): string
    {
        if (method_exists($relation, 'getQualifiedForeignKeyName')) {
            return $relation->getQualifiedForeignKeyName();
        }

        return $relation->getQualifiedForeignKey();
    }

    /**
     * 把「关联约束回调」应用到关联查询上。
     *
     * 兼容两种回调风格：
     *  - 就地修改并返回 void（传统写法）；
     *  - 返回一个新的 Builder（链式写法）。
     * 后者若返回 null，则回退为已被就地修改的 $relationQuery 本身。
     *
     * 回调的第一个入参 $nextRelation 即嵌套关联的剩余部分，由 MacrosBuilder 注入的闭包
     * 负责下钻到下一级同名宏（形如 `a.b.c` 逐级展开）。子类若需要支持嵌套，
     * 必须保证该回调确实消费了 $nextRelation，否则嵌套层级会被静默丢弃。
     */
    protected function withRelationQueryCallback(Builder $relationQuery): Builder
    {
        if ($this->callback === null) {
            return $relationQuery;
        }

        return ($this->callback)($this->nextRelation, $relationQuery) ?: $relationQuery;
    }
}
