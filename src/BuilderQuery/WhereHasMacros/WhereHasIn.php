<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WhereHasMacros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations;
use Illuminate\Database\Eloquent\Relations\Relation;
use zxf\Modules\BuilderQuery\WhereHasMacros\Concerns\BaseWhereHas;

/**
 * whereHasIn / orWhereHasIn 的实现：用 IN 子查询替代 EXISTS，优化关联子查询的索引命中。
 *
 * 生成相关子查询：
 *   主表.本地键 IN (SELECT DISTINCT 关联表.指向主表的列 FROM 关联表
 *                    WHERE <关联条件> AND 主表.本地键 = 关联表.指向主表的列)
 * 末尾的 whereColumn 使子查询与主表保持关联（correlated），从而让优化器把外层条件
 * 下推为索引扫描，而不是「先全表算出所有关联值再 IN 匹配」。
 *
 *  1. HasOneThrough / HasManyThrough 之前用 getQualifiedLocalKeyName() 作为外层 IN 列，
 *     而该方法返回的是「远端父表上的 localKey」（如 countries.id），
 *     而主表上的对应列是 secondLocalKey（如 users.country_id）——列根本不是主表的列，
 *     生成的 SQL 要么直接报错，要么语义完全错误。
 *     降级到框架原生 whereHas：手写 Through 的 IN 子查询需要额外 JOIN through 表，
 *     极易出错；而 whereHasIn 的价值只是优化执行计划，不值得为此冒正确性风险。
 *  2. MorphOne / MorphMany 必须用框架提供的 getQualifiedMorphType() /
 *     getQualifiedMorphTypeName()：getMorphType() 在 Laravel 6+ 返回的已经是限定列名，
 *     再拼表名会得到 `table.table.type`。
 *  3. 不支持的关联类型抛 \InvalidArgumentException（\Exception 会被误当成业务异常），
 *     错误消息带上具体关系类名与可替代方案，便于定位。
 *
 * 与原生 whereHas 保持一致：不自动追加 deleted_at 过滤，需要时请在回调中显式声明
 *（$q->whereNull('deleted_at')），或使用关联模型自身的全局作用域。
 */
class WhereHasIn extends BaseWhereHas
{
    protected string $method = 'whereIn';

    protected function applyRelation(Relation $relation): void
    {
        if ($relation instanceof Relations\MorphTo) {
            throw new \InvalidArgumentException('whereHasIn 不支持 MorphTo 关系，请改用 whereHasMorphIn()。');
        }

        // Through 关系降级到原生 whereHas（正确性优先，详见类注释第 1 条）
        if ($relation instanceof Relations\HasOneOrManyThrough) {
            $this->applyThroughFallback($relation);

            return;
        }

        $relationQuery = $this->relationQuery($relation);

        // ---- MorphOne / MorphMany：额外按多态类型列过滤（修复 2）----
        if ($relation instanceof Relations\MorphOne || $relation instanceof Relations\MorphMany) {
            $relationQuery->where($relation->getQualifiedMorphType(), $relation->getMorphClass());

            $this->builder->{$this->method}(
                $relation->getQualifiedParentKeyName(),
                $this->withRelationQueryCallback(
                    $this->correlatedSubQuery($relationQuery, $relation->getQualifiedForeignKeyName(), $relation->getQualifiedParentKeyName())
                )
            );

            return;
        }

        // ---- MorphToMany：输出中间表上指向「关联模型」的外键 ----
        if ($relation instanceof Relations\MorphToMany) {
            $relationQuery->where($relation->getQualifiedMorphTypeName(), $relation->getMorphClass());

            $this->builder->{$this->method}(
                $relation->getQualifiedParentKeyName(),
                $this->withRelationQueryCallback(
                    $this->correlatedSubQuery($relationQuery, $relation->getQualifiedForeignPivotKeyName(), $relation->getQualifiedParentKeyName())
                )
            );

            return;
        }

        // ---- BelongsTo：外层用主表外键，子查询输出关联表主键 ----
        if ($relation instanceof Relations\BelongsTo) {
            $foreign = $this->qualifiedForeignKeyName($relation);
            $owner = $relation->getQualifiedOwnerKeyName();

            $this->builder->{$this->method}(
                $foreign,
                $this->withRelationQueryCallback($this->correlatedSubQuery($relationQuery, $owner, $foreign))
            );

            return;
        }

        // ---- HasOne / HasMany：外层用主表主键，子查询输出关联表外键 ----
        if ($relation instanceof Relations\HasOne || $relation instanceof Relations\HasMany) {
            $parent = $relation->getQualifiedParentKeyName();

            $this->builder->{$this->method}(
                $parent,
                $this->withRelationQueryCallback(
                    $this->correlatedSubQuery($relationQuery, $relation->getQualifiedForeignKeyName(), $parent)
                )
            );

            return;
        }

        // ---- BelongsToMany：外层用主表主键，子查询输出中间表上指向关联模型的外键 ----
        if ($relation instanceof Relations\BelongsToMany) {
            $parent = $relation->getQualifiedParentKeyName();

            $this->builder->{$this->method}(
                $parent,
                $this->withRelationQueryCallback(
                    $this->correlatedSubQuery($relationQuery, $relation->getQualifiedForeignPivotKeyName(), $parent)
                )
            );

            return;
        }

        throw new \InvalidArgumentException(sprintf(
            '关系 [%s] 不支持 whereHasIn；可改用原生 whereHas 或 whereHasJoin 系列。',
            $relation::class
        ));
    }

    /**
     * 组装「相关子查询」：SELECT DISTINCT $selectColumn，并附加与主表的等值关联条件。
     *
     * @param  Builder  $relationQuery  关联查询（可已被回调追加了业务条件）
     * @param  string  $selectColumn  子查询输出列（指向主表的那一列）
     * @param  string  $correlateTo  主表侧的关联列
     */
    protected function correlatedSubQuery(Builder $relationQuery, string $selectColumn, string $correlateTo): Builder
    {
        return $relationQuery
            ->select($selectColumn)
            ->distinct()
            ->whereColumn($correlateTo, $selectColumn);
    }

    /**
     * Through 关系降级：使用框架原生 whereHas，保证正确性优先于执行计划优化。
     */
    protected function applyThroughFallback(Relation $relation): void
    {
        // 关联名优先取调用方传入的字符串（'country.postsViaCountry' 取首段）；
        // 若调用方直接传了关联对象，则退回关联对象自带的 getRelationName()（部分关系类型有）。
        $name = null;
        if (is_string($this->relation) && $this->relation !== '') {
            $name = explode('.', $this->relation)[0];
        } elseif (method_exists($relation, 'getRelationName')) {
            $name = $relation->getRelationName();
        }

        if ($name === null || $name === '') {
            throw new \InvalidArgumentException(sprintf(
                '关系 [%s] 无法解析出关联名，无法自动降级为原生 whereHas；请改用 whereHas(%s) 并手动指定关联名。',
                $relation::class,
                is_string($this->relation) ? (string) $this->relation : '(请手动指定)'
            ));
        }

        $next = $this->nextRelation;
        $nested = is_array($next) ? implode('.', $next) : $next;

        $this->builder->whereHas(
            $nested === null ? $name : $name.'.'.$nested,
            function (Builder $query): void {
                // 复用同一套回调契约（$nextRelation 已被拼进关联名，这里传 null 避免二次下钻）
                $this->withRelationQueryCallback($query);
            }
        );
    }
}
