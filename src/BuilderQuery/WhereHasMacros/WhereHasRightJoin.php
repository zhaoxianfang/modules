<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WhereHasMacros;

/**
 * whereHasRightJoin：以 RIGHT JOIN 关联查询（以关联表为主，保留关联表全部记录）。
 *
 *   - 关联等值条件放在 ON 子句，回调的业务筛选条件放在 WHERE，
 *     因此「保留关联表全部记录」这一 RIGHT JOIN 的核心价值得以保留
 *     （历史实现把条件混在 WHERE，等价于 INNER JOIN）。
 *   - 常见用法：Order::query()->whereHasRightJoin('user', fn ($q) => $q->where('active', 1))
 *     —— 以订单为主表，反向带出用户信息。
 *
 * 一对多关联会产生关联表重复行，请在必要时使用 distinct() 或分组聚合。
 */
class WhereHasRightJoin extends WhereHasJoin
{
    protected string $method = 'rightJoin';
}
