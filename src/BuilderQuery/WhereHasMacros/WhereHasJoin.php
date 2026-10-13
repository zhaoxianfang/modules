<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WhereHasMacros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

class WhereHasJoin
{
    /**
     * @var Builder
     *
     * @scenario 本类/成员说明。
     */
    protected $builder;

    /**
     * @var string
     *
     * @scenario 本类/成员说明。
     */
    protected $relation;

    /**
     * @var string
     *
     * @scenario 本类/成员说明。
     */
    protected $nextRelation;

    /**
     * @var \Closure
     *
     * @scenario 本类/成员说明。
     */
    protected $callback;

    /**
     * @var string
     *
     * @scenario 本类/成员说明。
     */
    protected $method = 'join';

    public function __construct(Builder $builder, $relation, $callback)
    {
        $this->builder = $builder;
        $this->relation = $relation;
        $this->callback = $callback;
    }

    /**
     * @return Builder
     *
     * @throws \Exception
     *
     * @scenario 本类/成员说明。
     */
    public function execute()
    {
        if (! $this->relation) {
            return $this->builder;
        }

        return $this->where(
            $this->formatRelation()
        );
    }

    /**
     * @param  Relation  $relation
     * @return Builder
     *
     * @throws \Exception
     *
     * @scenario 本类/成员说明。
     */
    protected function where($relation)
    {

        $relationQuery = $this->getRelationQuery($relation);

        $method = $this->method;

        if ($relation instanceof Relations\HasOne || $relation instanceof Relations\BelongsTo) {

            $relationTable = $relationQuery->getModel()->getTable();
            if ($relation instanceof Relations\BelongsTo) {
                $first = $relation->getQualifiedForeignKeyName();
                $second = $relation->getQualifiedOwnerKeyName();
            } else {
                $first = $relationTable.'.'.$relation->getForeignKeyName();
                $secTable = $this->builder->getModel()->getTable();
                $second = $secTable.'.'.$relation->getLocalKeyName();
            }

            // 跨库关联：JOIN 的表名同样需要带库名前缀，否则会到父连接所在库查找该表而失败。
            if ($this->builder->getModel()->getConnectionName() !== $relationQuery->getModel()->getConnectionName()) {
                $relationTable = $this->getRelationDatabaseName($relationQuery).'.'.$relationTable;
            }

            // CROSS JOIN 不应带 ON 子句：PostgreSQL 下 CROSS JOIN ... ON 属于语法错误，
            // MySQL 下则静默退化为 INNER JOIN。故对 crossJoin 仅做笛卡尔连接，
            // 关联过滤交由下方回调的 WHERE 条件表达，语义等价于「笛卡尔积后按条件过滤」。
            if ($method === 'crossJoin') {
                $this->builder->crossJoin($relationTable);

                return $this->builder->where($this->withRelationQueryCallback($relationQuery));
            }

            // 仅当主表查询尚未 JOIN 该关联表时才追加 JOIN，
            // 避免重复 JOIN 导致笛卡尔积 / 结果翻倍（同一次链式调用内多次 whereHasJoin 同一关联时尤其重要）。
            // 用纯数组遍历替代 collect()->where()->count()，避免每次调用都实例化 Collection，降低开销。
            // 去重判定同时比对表名与完整 ON 条件（first/second）：同一物理表经不同外键被关联两次时
            // （如 user 同时关联 author / editor），不应被错误去重，否则第二条关联的约束会丢失、结果出错。
            $alreadyJoined = false;
            foreach ($this->builder->getQuery()->joins ?? [] as $existingJoin) {
                if (
                    ($existingJoin->table ?? null) === $relationTable
                    && ($existingJoin->first ?? null) === $first
                    && ($existingJoin->second ?? null) === $second
                ) {
                    $alreadyJoined = true;
                    break;
                }
            }
            if (! $alreadyJoined) {
                $this->builder->{$method}($relationTable, $first, $second);
            }

            return $this->builder->where($this->withRelationQueryCallback($relationQuery));
        }

        throw new \Exception(sprintf('%s does not support "whereHasJoin".', get_class($relation)));
    }

    /**
     * @param  Relation  $relation
     * @return Builder
     *
     * @scenario 本类/成员说明。
     */
    protected function getRelationQuery($relation)
    {
        $q = $relation->getQuery();

        if ($this->builder->getModel()->getConnectionName() !== $q->getModel()->getConnectionName()) {
            $databaseName = $this->getRelationDatabaseName($q);
            $table = $q->getModel()->getTable();

            if (! Str::contains($table, ["`$databaseName`.", "{$databaseName}."])) {
                $q->from("{$databaseName}.{$table}");
            }
        }

        return $q;
    }

    protected function getRelationDatabaseName($q)
    {
        // 连接配置在进程生命周期内不可变，按连接名缓存避免每次跨库关联都读取 config()。
        static $cache = [];
        $name = $q->getModel()->getConnectionName();
        // 未显式指定连接时使用默认连接名，避免拼接出 database.connections..database 的非法键（双点）导致返回 null。
        if ($name === null) {
            $name = config('database.default');
        }

        return $cache[$name] ??= config('database.connections.'.$name.'.database');
    }

    protected function getRelationQualifiedForeignKeyName($relation)
    {
        if (method_exists($relation, 'getQualifiedForeignKeyName')) {
            return $relation->getQualifiedForeignKeyName();
        }

        return $relation->getQualifiedForeignKey();
    }

    /**
     * @return Relation
     *
     * @scenario 本类/成员说明。
     */
    protected function formatRelation()
    {
        if (is_object($this->relation)) {
            $relation = $this->relation;
        } else {
            $relationNames = explode('.', $this->relation);
            $this->nextRelation = implode('.', array_slice($relationNames, 1));

            $currentRelationMethod = $relationNames[0];

            $relation = Relation::noConstraints(function () use ($currentRelationMethod) {
                return $this->builder->getRelation($currentRelationMethod);
            });
        }

        return $relation;
    }

    /**
     * @param  Builder  $relation
     * @return Builder
     *
     * @scenario 本类/成员说明。
     */
    protected function withRelationQueryCallback($relationQuery)
    {
        $callback = $this->callback;

        return function ($query) use ($callback, $relationQuery) {
            $callback($query, $relationQuery);
        };
    }
}
