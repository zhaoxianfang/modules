<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WhereHasMacros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

class WhereHasIn
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
    protected $method = 'whereIn';

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
        if ($relation instanceof Relations\MorphTo) {
            throw new \Exception('Please use whereHasMorphIn() for MorphTo relationships.');
        }

        $relationQuery = $this->getRelationQuery($relation);
        $method = $this->method;

        if (
            $relation instanceof Relations\MorphOne
            || $relation instanceof Relations\MorphMany
        ) {
            return $this->builder->{$method}(
                $relation->getQualifiedParentKeyName(),
                $this->withRelationQueryCallback(
                    $relationQuery
                        ->select($relation->getQualifiedForeignKeyName())
                        ->distinct()
                        ->whereColumn($relation->getQualifiedParentKeyName(), $relation->getQualifiedForeignKeyName())
                        ->where($relation->getQualifiedMorphType(), $relation->getMorphClass())
                )
            );
        }

        if ($relation instanceof Relations\MorphToMany) {
            return $this->builder->{$method}(
                $relation->getQualifiedParentKeyName(),
                $this->withRelationQueryCallback(
                    $relationQuery
                        ->select($relation->getQualifiedForeignPivotKeyName())
                        ->distinct()
                        ->whereColumn($relation->getQualifiedParentKeyName(), $relation->getQualifiedForeignPivotKeyName())
                        ->where($relation->getTable().'.'.$relation->getMorphType(), $relation->getMorphClass())
                )
            );
        }

        // BelongsTo
        if ($relation instanceof Relations\BelongsTo) {
            return $this->builder->{$method}(
                $this->getRelationQualifiedForeignKeyName($relation),
                $this->withRelationQueryCallback(
                    $relationQuery
                        ->select($relation->getQualifiedOwnerKeyName())
                        ->distinct()
                        ->whereColumn($this->getRelationQualifiedForeignKeyName($relation), $relation->getQualifiedOwnerKeyName())
                )
            );
        }

        if (
            $relation instanceof Relations\HasOne
            || $relation instanceof Relations\HasMany
        ) {
            return $this->builder->{$method}(
                $relation->getQualifiedParentKeyName(),
                $this->withRelationQueryCallback(
                    $relationQuery
                        ->select($relation->getQualifiedForeignKeyName())
                        ->distinct()
                        ->whereColumn($relation->getQualifiedParentKeyName(), $relation->getQualifiedForeignKeyName())
                )
            );
        }

        // BelongsToMany
        if ($relation instanceof Relations\BelongsToMany) {
            return $this->builder->{$method}(
                $relation->getQualifiedParentKeyName(),
                $this->withRelationQueryCallback(
                    $relationQuery
                        ->select($relation->getQualifiedForeignPivotKeyName())
                        ->distinct()
                        ->whereColumn($relation->getQualifiedParentKeyName(), $relation->getQualifiedForeignPivotKeyName())
                )
            );
        }

        if (
            $relation instanceof Relations\HasOneThrough
            || $relation instanceof Relations\HasManyThrough
        ) {
            return $this->builder->{$method}(
                $relation->getQualifiedLocalKeyName(),
                $this->withRelationQueryCallback(
                    $relationQuery
                        ->select($relation->getQualifiedFirstKeyName())
                        ->distinct()
                        ->whereColumn($relation->getQualifiedLocalKeyName(), $relation->getQualifiedFirstKeyName())
                )
            );
        }

        throw new \Exception(sprintf('%s does not support "whereHasIn".', get_class($relation)));
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

            $method = $relationNames[0];

            $relation = Relation::noConstraints(function () use ($method) {
                return $this->builder->getModel()->$method();
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
        if ($this->callback === null) {
            return $relationQuery;
        }

        // 兼容「就地修改关系查询（回调返回 null）」与「返回查询对象」两种风格；
        // 若回调仅就地修改而未返回查询对象，则回退为已修改的 $relationQuery 本身。
        return call_user_func($this->callback, $this->nextRelation, $relationQuery) ?: $relationQuery;
    }
}
