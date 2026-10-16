<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WhereHasMacros;

/**
 * whereHasCrossJoin：以 CROSS JOIN（笛卡尔积）关联查询。
 *
 * CROSS JOIN 语法上【不允许 ON 子句】（PostgreSQL 下 `CROSS JOIN ... ON`
 * 直接是语法错误，MySQL 下会静默退化为 INNER JOIN），因此关联等值条件与回调筛选条件
 * 一并下沉到 WHERE，结果等价于「笛卡尔积 + 按条件过滤」。
 *
 * 与配置/字典表做全量组合（如「每个商品 × 全部币种」的价格矩阵）。
 * 一般业务请优先使用 whereHasJoin —— 笛卡尔积的行数是 M×N，数据量一大即不可接受。
 */
class WhereHasCrossJoin extends WhereHasJoin
{
    protected string $method = 'crossJoin';
}
