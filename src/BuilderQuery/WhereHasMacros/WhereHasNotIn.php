<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WhereHasMacros;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * whereHasNotIn / orWhereHasNotIn 的实现：关联反选（用 NOT IN 子查询）。
 *
 *   `主表键 NOT IN (SELECT 关联列 FROM 关联表)` 只要子查询结果里【出现任何一个 NULL】，
 *   整个条件就恒为 UNKNOWN，从而返回**空集**。而关联列（外键）允许为空极为常见，
 *   于是「查询没有待处理订单的用户」会静默返回 0 条 —— 不报错、结果全错，极难排查。
 *   例：`orders.user_id` 中只要有一行为 NULL，NOT IN 结果恒为空。
 *
 *   在 NOT IN 之外补一条 `主表键 IS NOT NULL`：
 *   - 外层键为 NULL 时，按 SQL 语义本就不满足任何等值关联，「不满足关联条件」应判定为真，
 *     补上 IS NOT NULL 后这些行被正确纳入结果；
 *   - 外层键非 NULL 时该条件恒真，不影响原有语义；
 *   - 或组合（orWhereHasNotIn）场景下同样不改变既有结果（NULL 键在 NOT IN 分支本就恒假）。
 *
 *   关联表极大时 NOT IN 的执行计划可能不如 NOT EXISTS；框架原生 `whereDoesntHave()`
 *   生成的就是 NOT EXISTS，语义与性能都更稳。
 *   本宏保留的价值在于：需要与 whereHasIn 保持完全对称的 SQL 形态，
 *   或需要在同一条查询里混合 whereIn / whereNotIn 时复用同一套关联解析逻辑。
 */
class WhereHasNotIn extends WhereHasIn
{
    protected string $method = 'whereNotIn';

    protected function applyRelation(Relation $relation): void
    {
        // 先执行父类逻辑，写入 NOT IN 条件
        parent::applyRelation($relation);

        // 再补 IS NOT NULL 守卫，规避 NOT IN 的 NULL 陷阱
        $outer = $this->outerColumnFor($relation);
        if ($outer !== null) {
            $this->builder->whereNotNull($outer);
        }
    }

    /**
     * 推断 NOT IN 所使用的「主表侧列」，用于补 IS NOT NULL 守卫。
     *
     * 分支必须与 {@see WhereHasIn::applyRelation()} 严格一致；
     * 无法确定时（如已降级为原生 whereHas 的 Through 关系，走的是 NOT EXISTS 语义，
     * 本就没有 NULL 陷阱）返回 null，跳过守卫。
     */
    protected function outerColumnFor(Relation $relation): ?string
    {
        return match (true) {
            // HasManyThrough / HasOneThrough 已在父类降级为原生 whereHas（NOT EXISTS，无 NULL 陷阱）
            $relation instanceof HasOneOrManyThrough => null,
            $relation instanceof MorphOne,
            $relation instanceof MorphMany,
            $relation instanceof MorphToMany,
            $relation instanceof BelongsToMany,
            $relation instanceof HasOne,
            $relation instanceof HasMany => $relation->getQualifiedParentKeyName(),
            $relation instanceof BelongsTo => $this->qualifiedForeignKeyName($relation),
            default => null,
        };
    }
}
