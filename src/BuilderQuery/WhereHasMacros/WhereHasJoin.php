<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WhereHasMacros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations;
use Illuminate\Database\Eloquent\Relations\Relation;
use zxf\Modules\BuilderQuery\WhereHasMacros\Concerns\BaseWhereHas;

/**
 * whereHasJoin 家族（INNER / LEFT / RIGHT / CROSS）的公共实现。
 *
 *   SELECT ... FROM 主表
 *   {INNER|LEFT|RIGHT} JOIN 关联表 ON 主表.本地键 = 关联表.外键   ← 关联等值条件
 *
 *  1. **LEFT / RIGHT JOIN 语义错误（严重）**
 *     且依赖 `join($table, $first, $second)` 把等值条件放进 ON 子句。
 *     问题在于：一旦在 WHERE 里追加了任何回调条件（例如 where('status','paid')），
 *     LEFT JOIN 会被降级为 INNER JOIN，「保留主表全部记录」的语义荡然无存；
 *     而 CROSS JOIN 因语法不允许 ON 子句，又必须把条件放到 WHERE —— 两套行为混在一起，
 *     用户无法预测结果。现统一为：
 *       - 关联等值条件 → 放 ON 子句（INNER/LEFT/RIGHT）；
 *       - 回调的业务筛选条件 → 放 WHERE（这才是「筛选父表」的语义）；
 *       - CROSS JOIN 因语法限制，等值条件与筛选条件一并放 WHERE。
 *     这样 whereHasLeftJoin 才真正做到「未匹配的主表记录仍然保留」。
 *  2. **嵌套关联（'a.b'）被静默丢弃（严重）**
 *     原 WhereHasJoin 的回调闭包形如 fn ($builder, $relationBuilder) => …，
 *     完全忽略 $nextRelation，导致 whereHasJoin('orders.items') 只关联了 orders，
 *     后半段条件无声消失、不报错。现统一回调契约为
 *     由本类透传给 MacrosBuilder 注入的闭包，由后者下钻到下一级同名宏。
 *  3. **重复 JOIN 判定不完整**
 *     原去重逻辑把 first/second 比较放在「同表」的分支内，跨表同 ON 条件时无法命中；
 *     现统一以「表名 + first + second」三元组判定，并使用数组遍历（不实例化 Collection）。
 *  4. **HasMany 场景结果翻倍未提示**
 *     一对多 JOIN 必然产生主表重复行（需 distinct 或分组聚合）。已在类注释中明确标注，
 *     并在文档示例里给出正确用法，避免用户误以为数据重复是 bug。
 *
 * 与原生 whereHas 一致：不在 JOIN 上自动追加 deleted_at 条件，需要时请在回调中声明。
 */
class WhereHasJoin extends BaseWhereHas
{
    /** INNER JOIN（默认值） */
    protected string $method = 'join';

    protected function applyRelation(Relation $relation): void
    {
        if ($relation instanceof Relations\MorphTo) {
            throw new \InvalidArgumentException('whereHasJoin 家族不支持 MorphTo 关系，请改用 whereHasMorphIn()。');
        }

        $relationQuery = $this->relationQuery($relation);
        [$table, $first, $second, $extraOn] = $this->resolveJoinKeys($relation, $relationQuery);

        if ($this->method === 'crossJoin') {
            // CROSS JOIN 语法上不允许 ON 子句，等值条件必须下沉到 WHERE
            $this->builder->crossJoin($table);
            $this->applyFilter($relationQuery, [$first, $second], $extraOn);

            return;
        }

        // 已 JOIN 过同一「表 + 等值条件」则跳过，避免重复 JOIN 造成结果翻倍
        if (! $this->alreadyJoined($table, $first, $second)) {
            if ($extraOn === []) {
                $this->builder->{$this->method}($table, $first, '=', $second);
            } else {
                // 有额外 ON 条件（如多态类型列）时改用闭包式 JOIN，
                // 显式构造完整 ON 子句。注意：此时 joins[] 里的 first/second 为 null，
                // 去重判定无法命中，重复调用同一多态关联仍会重复 JOIN（行为与历史版本一致）。
                $type = $this->method;
                $this->builder->join($table, function ($join) use ($first, $second, $extraOn, $type, $table): void {
                    $join->{$type}($table)->on($first, '=', $second);
                    foreach ($extraOn as [$column, $operator, $value]) {
                        $join->where($column, $operator, $value);
                    }
                });
            }
        }

        // 嵌套关联（'a.b'）：下一级关联必须【基于当前关联的模型】解析（a.b 的 b 是 a 的关联），
        // 但 JOIN 又必须落在【主查询】上（JOIN 无法通过 addNestedWhereQuery 内嵌进子查询）。
        // 因此这里用关联查询再跑一次本类，把产生的 JOIN / 筛选条件整体搬回主查询。
        if ($this->nextRelation !== null) {
            $nested = is_array($this->nextRelation) ? implode('.', $this->nextRelation) : $this->nextRelation;
            if ($nested !== '') {
                (new static($relationQuery, $nested, $this->callback))->execute();
                $this->adoptFrom($relationQuery);
            }

            return;
        }

        // 业务筛选条件（回调）一律进 WHERE —— 这正是「筛选父表」的语义。
        // 关联等值条件留在 ON 中，从而保证 LEFT/RIGHT JOIN 仍能保留未匹配的主表记录。
        $this->applyFilter($relationQuery, [], []);
    }

    /**
     * 把「嵌套关联」在中间构建器上产生的 JOIN 与筛选条件，整体搬到主查询上。
     *
     * 这是 JOIN 家族支持点号嵌套（'a.b.c'）的关键：
     * 子查询里可以正常解析下一级关系（它挂在上一级关联的模型上），
     * 但最终产物必须落在主查询，否则用户根本无法 select / orderBy 关联表字段。
     */
    protected function adoptFrom(Builder $relationQuery): void
    {
        $from = $relationQuery->getQuery();
        $target = $this->builder->getQuery();

        $appended = 0;
        foreach ($from->joins ?? [] as $join) {
            if (! in_array($join, $target->joins ?? [], true)) {
                $target->joins[] = $join;
                $appended++;
            }
        }

        // JOIN 结构与 JOIN 绑定必须【成对】搬运。
        // 原实现只搬 $from->joins 而丢掉 bindings['join']，于是：
        //   子查询里 ON 上带占位符（多态关联 whereColumn / 关联等值筛选走 whereRaw 时）
        //   → SQL 里有 ? 而 getBindings() 里没有对应值 → 驱动报 "number of bindings doesn't match"。
        // array_slice 限定为"实际追加的 join 个数"，保证绑定与结构严格 1:1，
        // 不会在去重命中时搬进多余的绑定。
        if ($appended > 0) {
            $target->addBinding(array_slice($from->bindings['join'] ?? [], 0, $appended), 'join');
        }

        if (($from->wheres ?? []) !== []) {
            $this->builder->addNestedWhereQuery($from);
        }
    }

    /**
     * 把「业务筛选条件（回调）」写入 WHERE；CROSS JOIN 时额外写入等值条件与多态条件。
     *
     * @param  Builder  $relationQuery  关联查询（回调将作用于它）
     * @param  string[]  $equalityColumns  成对的「列 = 列」等值条件（仅 CROSS JOIN 使用，元素个数必为 2 的倍数）
     * @param  array<int, array{0: string, 1: string, 2: mixed}>  $extraOn  附加条件三元组（仅 CROSS JOIN 使用）
     */
    protected function applyFilter(Builder $relationQuery, array $equalityColumns, array $extraOn): void
    {
        $this->builder->where(function (Builder $q) use ($relationQuery, $equalityColumns, $extraOn): void {
            // 列 = 列 必须用 whereColumn；若误用 where 会把列名当成绑定值，
            // 生成 `orders.user_id = ?` 这种永远匹配不到任何行的条件。
            for ($i = 0, $n = count($equalityColumns); $i + 1 < $n; $i += 2) {
                $q->whereColumn($equalityColumns[$i], '=', $equalityColumns[$i + 1]);
            }
            foreach ($extraOn as [$column, $operator, $value]) {
                $q->where($column, $operator, $value);
            }

            // 复用统一回调契约
            $result = $this->withRelationQueryCallback($relationQuery);

            // 嵌套关联（'a.b'）时，MacrosBuilder 的闭包会把下一级 JOIN 直接加到【主查询】上
            // （JOIN 无法通过 addNestedWhereQuery 传递），此时关联查询上的 where 已被
            // 合并进同一组条件，再搬一次会造成条件重复，故跳过。
            if ($this->nextRelation === null && $result instanceof Builder) {
                $q->addNestedWhereQuery($relationQuery->getQuery());
            }
        });
    }

    /**
     * 解析 JOIN 所需的「表名 / 等值左列 / 等值右列 / 额外 ON 条件」。
     *
     * @return array{0: string, 1: string, 2: string, 3: array<int, array{0: string, 1: string, 2: mixed}>}
     *                                                                                                      [JOIN 表名, ON 左列, ON 右列, 附加条件三元组列表]
     */
    protected function resolveJoinKeys(Relation $relation, Builder $relationQuery): array
    {
        $table = $relationQuery->getModel()->getTable();

        // 仅当关联模型与主模型【确实使用不同连接】时才补库名前缀。
        // 早期实现无条件加前缀，会让同库 JOIN 也变成 `demo`.`orders`，既污染可读性，
        // 也会在部分数据库（SQLite 等不支持跨库限定）上直接报语法错误。
        if ($this->builder->getModel()->getConnectionName() !== $relationQuery->getModel()->getConnectionName()) {
            $database = $this->relationDatabaseName($relationQuery);
            if ($database !== null && $database !== '' && ! str_contains($table, $database.'.')) {
                $table = $database.'.'.$table;
            }
        }

        // BelongsTo：主表外键 = 关联表主键
        if ($relation instanceof Relations\BelongsTo) {
            return [$table, $this->qualifiedForeignKeyName($relation), $relation->getQualifiedOwnerKeyName(), []];
        }

        // HasOne / HasMany：关联表外键 = 主表本地键
        if ($relation instanceof Relations\HasOne || $relation instanceof Relations\HasMany) {
            return [$table, $relation->getQualifiedForeignKeyName(), $relation->getQualifiedParentKeyName(), []];
        }

        // MorphOne / MorphMany：额外的 ON 条件——多态类型列必须匹配
        if ($relation instanceof Relations\MorphOne || $relation instanceof Relations\MorphMany) {
            return [
                $table,
                $relation->getQualifiedForeignKeyName(),
                $relation->getQualifiedParentKeyName(),
                [[$relation->getQualifiedMorphType(), '=', $relation->getMorphClass()]],
            ];
        }

        throw new \InvalidArgumentException(sprintf(
            '关系 [%s] 不支持 whereHasJoin 家族；多对多等场景请改用 whereHasIn（IN 子查询，不产生重复行）。',
            $relation::class
        ));
    }

    /**
     * 判断目标「表 + 等值条件」是否已被 JOIN 过。
     *
     * Laravel 的 JoinClause **没有 `$first` / `$second` 属性**：
     * `JoinClause::on()` 会转交给 `whereColumn()`，条件最终存放在 `$join->wheres[]`
     * （type = 'Column' 的条目）中。历史实现按 `$join->first` / `$join->second` 判重，
     * 这两个属性永远取不到值（还会触发 Undefined property 警告），
     * 于是「重复 JOIN 去重」这一直处于【失效】状态 —— 同一条链式调用里重复
     * whereHasJoin 同一关联会产生两次 JOIN，导致结果翻倍。
     *
     * 通过遍历 `$join->wheres` 按 type=Column 的 first/second 精确比对，
     * 并保留对仍暴露 first/second 属性的旧版本的兼容分支。
     *
     * 必须同时比对表名与完整等值条件：同一物理表经不同外键被关联两次时
     *（如 user 同时是 author 与 editor），不应被错误去重，否则第二条关联的约束会丢失。
     *
     * 用纯数组遍历替代 collect()->where()->count()，避免每次宏调用都实例化 Collection。
     */
    protected function alreadyJoined(string $table, string $first, string $second): bool
    {
        foreach ($this->builder->getQuery()->joins ?? [] as $join) {
            if (($join->table ?? null) !== $table) {
                continue;
            }

            foreach ($join->wheres ?? [] as $where) {
                if (($where['type'] ?? null) === 'Column'
                    && ($where['first'] ?? null) === $first
                    && ($where['second'] ?? null) === $second) {
                    return true;
                }
            }

            // 兼容仍暴露 first/second 属性的旧版本 JoinClause
            if (isset($join->first) && ($join->first ?? null) === $first && ($join->second ?? null) === $second) {
                return true;
            }
        }

        return false;
    }
}
