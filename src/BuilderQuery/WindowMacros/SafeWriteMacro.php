<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use RuntimeException;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * 防失控写入 / 删除护栏宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 *
 * 基于 study_mysql「07-appendix / 01-pitfalls-compendium」「04-performance / 07-bottlenecks-and-advanced-ops」
 * 中关于「无 WHERE 的 UPDATE/DELETE 误操作」「单条巨 DELETE 锁表 / undo 膨胀」「批量写入包体超限」的事故清单，
 * 针对用户对「避免长时间未响应、系统资源占用高、卡死、异常」的硬性要求，系统化封装两类安全防护：
 *
 *  1. `mustHaveWhere()` —— 写前闸门：在执行 UPDATE/DELETE 之前断言当前构建器已携带 WHERE 条件，
 *     否则立即抛异常拦截，杜绝「少写一句 where 导致全表被改/被删」的灾难性误操作。
 *  2. `deleteInChunks()` —— 分批安全删除：将命中条件的行按主键切成每 `$chunkSize` 一批，
 *     每批仅删除有限行数，单事务锁范围与 undo/redo 体积都被严格限制在可控区间，
 *     避免长时间持锁、主从延迟暴涨、乃至实例级卡死。迭代过程内置 `deleted === 0 即停` 与
 *     最大迭代上限双重保护，杜绝无限循环导致的 CPU/连接耗尽。
 *
 * 安全与效率约定：
 *  - 删除按**主键**分批，复用原始 WHERE/JOIN 条件仅用于「取主键」，删除操作本身不带 JOIN，
 *    兼容 MySQL「单表 DELETE 不支持 LIMIT + 多表语法」等约束；
 *  - 采用 `clone()`（Laravel 深拷贝，含底层 query）隔离「取键」与「删除」两套查询，互不污染；
 *  - 所有异常路径均不会导致静默错误结果，失败即抛出，便于上层捕获与告警；
 *  - 全程内存占用与单批数据量成正比（O(分片大小)），与待删总量无关，稳定可控。
 *
 * 双构建器友好：全部通过 {@see BaseMacro::define()} 同时注册到 Eloquent\Builder 与 Query\Builder。
 *
 * @package zxf\Modules\BuilderQuery\WindowMacros
 * @version 3.3.0
 * @requires PHP 8.2+（兼容 8.3/8.4/8.5），Laravel 11+ / 12+ / 13+，MySQL 8.0+ / PostgreSQL 15+
 */
class SafeWriteMacro extends BaseMacro
{
    /** 默认每批删除行数（兼顾吞吐与锁粒度）。 */
    public const DEFAULT_CHUNK_SIZE = 1000;

    /** 单批次删除的默认最大迭代次数（防御性上限，防极端异常下的无限循环）。 */
    public const MAX_ITERATIONS = 100000;

    /** 注册所有防失控写入 / 删除护栏宏。 */
    public static function register(): void
    {
        self::registerMustHaveWhere();
        self::registerDeleteInChunks();
    }

    /**
     * 注册 mustHaveWhere。
     */
    public static function registerMustHaveWhere(): void
    {
        /**
         * 写前闸门：断言当前查询已携带 WHERE 条件，否则抛异常拦截，防止无 WHERE 的全表 UPDATE/DELETE 误操作。
         *
         * 设计为链式前置调用，应在 `->update()` / `->delete()` 之前调用：
         *
         * ```php
         * User::query()->where('id', $id)->mustHaveWhere()->delete();
         * ```
         *
         * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 安全删除：已带 where，正常通过闸门
         * Order::query()->where('id', 123)->mustHaveWhere()->delete();
         *
         * // 危险操作：缺少 where，mustHaveWhere 立即抛 RuntimeException 拦截
         * // Order::query()->mustHaveWhere()->delete(); // 抛出异常
         *
         * @scenario 任何面向生产库的批量改/删操作前的安全兜底；可与 CI / 代码评审配合，作为强制约束。
         */
        static::define('mustHaveWhere', function (): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
            return SafeWriteMacro::assertHasWhere($this);
        });
    }

    /**
     * 注册 deleteInChunks。
     */
    public static function registerDeleteInChunks(): void
    {
        /**
         * 按主键分批安全删除命中条件的行，返回删除总数（终端宏，立即执行）。
         *
         * 与一次性 `->delete()` 不同，本宏将删除拆成每 `$chunkSize` 行一批，每批独立成事务/语句，
         * 严格限制单批锁范围与 undo 体积，避免长时间持锁、主从延迟与实例卡死；迭代内置
         * `某批删除 0 行即终止` + 最大迭代上限双重保护，杜绝死循环。
         *
         * @param int $chunkSize 每批删除行数（默认 1000，自动取 max(1, ...)）
         * @param string|null $key 主键列名（默认取模型/表主键；通常为 id）
         * @return int 实际删除的总行数
         *
         * @example
         * // 清理 30 天前的日志，千万级数据也按每批 1000 行安全删除，不会锁死整表
         * $removed = AuditLog::query()
         *     ->where('created_at', '<', now()->subDays(30))
         *     ->deleteInChunks(1000);
         *
         * @scenario 大表数据清理、软删转物理删、合规到期销毁等「待删量可能极大」的场景；
         *           相比单条巨 DELETE，本宏将资源占用与锁竞争控制在可接受区间。
         */
        static::define('deleteInChunks', function (
            int $chunkSize = SafeWriteMacro::DEFAULT_CHUNK_SIZE,
            ?string $key = null
        ): int {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
            return SafeWriteMacro::deleteByChunks($this, $chunkSize, $key);
        });
    }

    /**
     * mustHaveWhere 的实现：断言存在 WHERE 条件，否则抛异常。
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $builder
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     * @throws RuntimeException 当构建器未携带任何 WHERE 条件时
     */
    public static function assertHasWhere(
        \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $builder
    ): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder {
        $query = BaseMacro::queryOf($builder);

        $hasWhere = ! empty($query->wheres);

        if (! $hasWhere) {
            throw new RuntimeException(
                '防误操作：检测到写操作缺少 WHERE 条件，已拦截全表操作。请先调用 where(...) 等限定条件后再执行。'
            );
        }

        return $builder;
    }

    /**
     * deleteInChunks 的实现：按主键分批删除并返回总数（终端执行）。
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $builder
     * @param int $chunkSize
     * @param string|null $key
     * @return int
     */
    public static function deleteByChunks(
        \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $builder,
        int $chunkSize,
        ?string $key
    ): int {
        $key = $key ?? BaseMacro::keyName($builder);
        $key = BaseMacro::assertValidIdentifier($key, 'primaryKey');

        $chunkSize = max(1, $chunkSize);
        $total = 0;
        $iterations = 0;

        while ($iterations++ < self::MAX_ITERATIONS) {
            // 深拷贝当前查询条件，仅投影主键并限制行数，用于本批取键（不触达业务行数据）。
            $clone = $builder->clone();
            $ids = $clone->select($key)->limit($chunkSize)->pluck($key)->all();

            if ($ids === []) {
                break;
            }

            // 按主键批量删除：复用 freshQuery 得到干净构建器，仅以主键 IN 限定，单批可控。
            $sub = BaseMacro::freshQuery($builder);
            $deleted = (int) $sub->whereIn($key, $ids)->delete();

            $total += $deleted;

            // 本批未删任何行（可能已被并发/其他路径删掉），停止迭代，避免无限循环。
            if ($deleted === 0) {
                break;
            }

            // 已不足一批，删除完毕。
            if (count($ids) < $chunkSize) {
                break;
            }
        }

        return $total;
    }
}
