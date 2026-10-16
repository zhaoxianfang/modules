<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WhereHasMacros;

/**
 * whereHasLeftJoin：以 LEFT JOIN 关联查询，保留主表全部记录。
 *
 *   - 关联等值条件放在 **ON 子句**，回调的业务筛选条件放在 **WHERE**。
 *     这正是 LEFT JOIN 能在「保留未匹配主表记录」的同时按关联字段筛选的关键：
 *     若把等值条件也放进 WHERE，LEFT JOIN 会被退化为 INNER JOIN，「保留主表全部记录」失效。
 *   - 因此 whereHasLeftJoin('orders', fn ($q) => $q->where('status', 'paid'))
 *     得到的是：全部用户 + 其已支付订单（未匹配用户的 order 列为 NULL），
 *     非常适合「左连接统计/补全」类报表。
 *
 * 一对多关联会产生主表重复行，请在必要时使用 distinct() 或分组聚合。
 *
 * 不会自动追加 deleted_at 条件，与原生 whereHas 一致；需要时请在回调中显式声明。
 */
class WhereHasLeftJoin extends WhereHasJoin
{
    protected string $method = 'leftJoin';
}
