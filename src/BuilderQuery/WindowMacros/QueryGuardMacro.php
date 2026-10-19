<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * 查询性能守卫与诊断宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 * 基于 study_mysql「04-performance / 05-params-and-resource-tuning」「07-bottlenecks-and-advanced-ops」
 * 系统化封装，聚焦「防止查询失控」这一最常见的系统稳定性瓶颈：
 * 提供的宏：
 *  - withLimitGuard：软守卫——仅当未设置 LIMIT 时才补一个安全上限，避免意外全表返回/扫描
 *  - requireLimit：硬守卫——未设 LIMIT 或 LIMIT 超过阈值时直接拒绝执行（fail-fast）
 *  - explain：返回 EXPLAIN 执行计划（诊断慢查询的第一抓手）
 *  - explainAnalyze：返回 EXPLAIN ANALYZE 实际执行计划（含真实耗时/行数，开销更高）
 * 设计原则：守卫类宏不改变业务语义，只做「兜底 + 熔断」，确保在大表、高并发、参数异常等
 * 场景下不会出现「长时间未响应 / 系统资源占用高 / 卡死」等问题。
 *
 * @version 1.13.9
 *
 * @requires MySQL 8.0+ / PostgreSQL 15+
 */
class QueryGuardMacro extends BaseMacro
{
    /**
     *  默认安全 LIMIT（withLimitGuard 在未设 LIMIT 时补的下限）。
     */
    public const DEFAULT_SAFE_LIMIT = 1000;

    /**
     *  注册所有查询守卫与诊断宏。
     */
    public static function register(): void
    {
        self::registerLimitGuards();
        self::registerExplain();
    }

    /**
     * 注册 LIMIT 守卫宏。
     */
    public static function registerLimitGuards(): void
    {
        /**
         * 软守卫：仅当当前查询未设置 LIMIT 时，补一个安全上限（默认 1000）。
         * 不破坏已有语义——若已显式 limit()，则本宏无任何副作用。用于「不可信来源」的查询
         * （如运营后台、用户自定义筛选）兜底，防止误查全表导致内存/带宽打满。
         *
         * @param  int  $limit  安全上限（默认 1000）
         *
         * @example
         * // 用户自定义筛选，无论如何最多返回 1000 行，避免全表拖垮
         * $q->where(...)->withLimitGuard(1000)->get();
         *
         * @scenario 后台/报表/开放筛选等不可信查询，作为资源占用的最后兜底。
         */
        static::define('withLimitGuard', function (int $limit = QueryGuardMacro::DEFAULT_SAFE_LIMIT): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            if ($limit < 1) {
                throw new \InvalidArgumentException('withLimitGuard 的 limit 必须大于 0。');
            }
            $query = BaseMacro::queryOf($this);
            if ($query->limit === null) {
                $this->limit($limit);
            }

            return $this;
        });

        /**
         * 硬守卫：未设置 LIMIT 或 LIMIT 超过阈值时，直接抛出异常拒绝执行（fail-fast）。
         * 适用于「必须分页 / 必须受控」的关键查询（如批量导出前置、全量遍历前的拦截）。
         * 与 withLimitGuard 的区别：本宏不自动补 LIMIT，而是强制调用方显式声明边界。
         *
         * @param  int|null  $maxAllowed  允许的最大 LIMIT（null 表示只要求存在 LIMIT，不限大小）
         *
         * @example
         * // 导出任务前置：必须显式 limit，且不得超过 50000 行
         * ->requireLimit(50000)->get();
         *
         * @scenario 关键/危险查询的强制边界，避免无 LIMIT 全表扫描或超大结果集。
         */
        static::define('requireLimit', function (?int $maxAllowed = null): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            $query = BaseMacro::queryOf($this);
            if ($query->limit === null) {
                throw new \RuntimeException(
                    '查询未设置 LIMIT，存在全表扫描/结果集失控风险，已拒绝执行。'
                    .'请显式调用 ->limit(n) 或 ->withLimitGuard(n)。'
                );
            }
            if ($maxAllowed !== null && $query->limit > $maxAllowed) {
                throw new \RuntimeException(
                    "查询 LIMIT={$query->limit} 超过安全上限 {$maxAllowed}，已拒绝执行。"
                );
            }

            return $this;
        });
    }

    /**
     * 注册 EXPLAIN 诊断宏。
     */
    public static function registerExplain(): void
    {
        /**
         * 返回该查询的执行计划（EXPLAIN），用于诊断慢查询。
         * MySQL：`EXPLAIN [FORMAT={TRADITIONAL|JSON|TREE}] <sql>`；
         * PostgreSQL：`EXPLAIN [FORMAT {TEXT|JSON|XML|YAML}] <sql>`。
         * 返回数据库驱动原生结果（通常为数组）。
         *
         * @param  string|null  $format  计划格式：mysql: traditional|json|tree；pg: text|json|xml|yaml
         * @return mixed 执行计划结果（数组）
         *
         * @example
         * // 查看某慢查询的执行计划，确认是否走了索引
         * $plan = Order::query()->where('status', 1)->explain('json');
         *
         * @scenario 慢查询根因定位：是否全表扫描、是否用错索引、是否出现 filesort。
         */
        static::define('explainPlan', function (?string $format = null): mixed {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */

            return QueryGuardMacro::runExplain($this, false, false, $format);
        });

        /**
         * 返回真实执行计划（EXPLAIN ANALYZE），含实际耗时与扫描行数（开销更高）。
         * MySQL 8.0.18+ 与 PostgreSQL 均支持 `EXPLAIN ANALYZE`；PostgreSQL 可附加 VERBOSE。
         * 注意：ANALYZE 会真正执行语句，对写入/大表有实际开销，请谨慎用于生产热路径。
         *
         * @param  bool  $verbose  PostgreSQL 下输出更详尽信息（默认 false）
         * @return mixed 实际执行计划结果（数组）
         *
         * @example
         * // 拿到真实执行耗时与行数，验证索引是否生效
         * $plan = Order::query()->where('status', 1)->explainAnalyze();
         *
         * @scenario 需要真实行数/耗时（而非预估）时，确认执行计划与优化器判断是否一致。
         */
        static::define('explainAnalyze', function (bool $verbose = false): mixed {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */

            return QueryGuardMacro::runExplain($this, true, $verbose, null);
        });
    }

    /**
     * 构建并执行 EXPLAIN 语句。
     *
     * @param  bool  $analyze  是否 ANALYZE（真实执行）
     * @param  bool  $verbose  PostgreSQL VERBOSE
     * @param  string|null  $format  计划格式
     */
    public static function runExplain(Builder|\Illuminate\Database\Query\Builder $builder, bool $analyze, bool $verbose, ?string $format): mixed
    {
        $sql = $builder->toSql();
        $bindings = $builder->getBindings();
        $isPg = BaseMacro::isPostgres($builder);

        // 安全守卫：EXPLAIN ANALYZE 会真实执行语句，若对 DELETE/UPDATE/INSERT 调用将造成数据误修改；
        // 即使普通 EXPLAIN 也能解析 SELECT/CTE，故统一仅允许以 SELECT/WITH 开头的读查询。
        $headSql = strtoupper(trim($sql));
        if (! str_starts_with($headSql, 'SELECT') && ! str_starts_with($headSql, 'WITH')) {
            throw new \RuntimeException(
                'explain / explainAnalyze 仅支持 SELECT 类查询（含 CTE 的 WITH ... SELECT），已拒绝执行以避免非预期的数据修改。'
            );
        }

        if ($analyze) {
            $head = $isPg
                ? 'EXPLAIN '.($verbose ? 'ANALYZE VERBOSE' : 'ANALYZE')
                : 'EXPLAIN ANALYZE';
        } else {
            $fmt = '';
            if ($format !== null) {
                $fmt = $isPg
                    ? 'FORMAT '.BaseMacro::assertValidIdentifier(strtoupper($format), 'format')
                    : 'FORMAT='.BaseMacro::assertValidIdentifier(strtoupper($format), 'format');
            }
            $head = 'EXPLAIN'.($fmt !== '' ? " {$fmt}" : '');
        }

        $explainSql = "{$head} {$sql}";

        return $builder->getConnection()->select($explainSql, $bindings);
    }
}
