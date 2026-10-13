<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * 并发与行锁宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 * 基于 study_mysql「锁、事务、并发治理」章节系统化封装。Laravel 查询构造器已原生提供
 * `lockForUpdate()` / `sharedLock()` / `skipLocked()` / `nowait()`，本模块在双库通用前提下
 * 提供语义化组合宏，并补充两库原生缺失统一的** advisory lock（咨询锁）**能力，
 * 用于队列消费防重、计数器并发保护等场景。
 * 提供的宏：
 *  - lockForUpdateSkipLocked：FOR UPDATE SKIP LOCKED（取锁时跳过已被锁定的行）
 *  - lockForUpdateNoWait：FOR UPDATE NOWAIT（拿不到锁立即失败，不阻塞）
 *  - sharedLockSkipLocked：FOR SHARE / LOCK IN SHARE MODE SKIP LOCKED（共享锁跳过）
 *  - advisoryLock：咨询锁（MySQL GET_LOCK / PG pg_advisory_lock），同一连接内生效
 *  - advisoryUnlock：释放咨询锁
 * 注意：行锁 / 咨询锁须在事务内（DB::transaction）方才有效；跳过锁与立即失败行为在
 * MySQL 8.0.1+ 与 PostgreSQL 9.5+ 均支持。
 *
 * @version 3.1.0
 *
 * @requires MySQL 8.0+ / PostgreSQL 15+
 *
 * @scenario 并发与行锁宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 */
class LockingMacro extends BaseMacro
{
    /**
     *  注册所有并发锁宏。
     *
     * @scenario 注册所有并发锁宏。
     */
    public static function register(): void
    {
        self::registerRowLocks();
        self::registerAdvisory();
    }

    /**
     * 注册行锁组合宏（均委托 Laravel 原生能力，保证双库一致语义）。
     *
     * @scenario 注册行锁组合宏（均委托 Laravel 原生能力，保证双库一致语义）。
     */
    public static function registerRowLocks(): void
    {
        /**
         * 加排他行锁并跳过已被锁定的行（SKIP LOCKED）。
         *
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 队列消费：每次取 10 条未被其他 worker 锁定的任务
         * DB::transaction(function () {
         *     $jobs = Job::query()->lockForUpdateSkipLocked()->orderBy('id')->limit(10)->get();
         *     foreach ($jobs as $job) { /* 处理并删除 *\/ }
         * });
         *
         * @scenario 多进程 / 多实例队列消费、库存扣减、工单分配等「取锁即处理」的高并发场景；
         *           相比 SELECT ... FOR UPDATE 会阻塞等待，SKIP LOCKED 让 worker 直接跳过忙行。
         */
        static::define('lockForUpdateSkipLocked', function (): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */

            return $this->lockForUpdate()->skipLocked();
        });

        /**
         * 加排他行锁，拿不到锁立即失败（NOWAIT，不阻塞等待）。
         *
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 并发编辑场景：若记录已被他人锁定则立即抛异常，避免长时等待
         * try {
         *     $row = Doc::query()->lockForUpdateNoWait()->find($id);
         * } catch (\Illuminate\Database\QueryException $e) { /* 已被锁定 *\/ }
         *
         * @scenario 编辑锁、秒杀下单等「不允许等待」的强一致场景。
         */
        static::define('lockForUpdateNoWait', function (): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */

            return $this->lockForUpdate()->nowait();
        });

        /**
         * 加共享锁并跳过已被锁定的行（共享锁 + SKIP LOCKED）。
         *
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 读多写少：以共享锁读取，避免与写事务冲突的同时不被他人阻塞
         * $rows = Snapshot::query()->sharedLockSkipLocked()->where('status', 1)->get();
         *
         * @scenario 一致性读、快照读取等需要防止幻读但不阻塞其他读的场景。
         */
        static::define('sharedLockSkipLocked', function (): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */

            return $this->sharedLock()->skipLocked();
        });
    }

    /**
     * 注册咨询锁宏（MySQL GET_LOCK / PostgreSQL pg_advisory_lock）。
     *
     * @scenario 注册咨询锁宏（MySQL GET_LOCK / PostgreSQL pg_advisory_lock）。
     */
    public static function registerAdvisory(): void
    {
        /**
         * 获取咨询锁（advisory lock），在同一数据库连接内生效，用于跨语句的轻量互斥。
         * - MySQL：`GET_LOCK(?, 10)`（最多等待 10 秒）
         * - PostgreSQL：`pg_advisory_lock(?)`（会话级）/ `pg_advisory_xact_lock(?)`（事务级，默认）
         *
         * @param  int|string  $key  锁标识（建议使用业务唯一键，如 `CONCAT('job:', id)` 的整数哈希）
         * @param  bool  $session  PG 下 true=会话级锁（连接关闭才释放），false=事务级锁（事务结束释放）
         * @return Builder|\Illuminate\Database\Query\Builder 返回当前构建器以便继续链式拼装实际查询
         *
         * @example
         * // 定时任务防重：同一时刻仅一个实例能获取该锁
         * DB::transaction(function () {
         *     Report::query()->advisoryLock('report:daily:2025-06-01');
         *     // ... 执行报表生成
         * });
         *
         * @scenario 单机定时任务防并发重复执行、分布式单节点互斥、缓存重建防击穿等；
         *           注意咨询锁非分布式锁，仅保证同一数据库连接的互斥。
         */
        static::define('advisoryLock', function (int|string $key, bool $session = false): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $conn = $this->getConnection();
            if (BaseMacro::isPostgres($this)) {
                $fn = $session ? 'pg_advisory_lock' : 'pg_advisory_xact_lock';
                $conn->statement("SELECT {$fn}(?)", [(int) $key]);
            } else {
                $conn->statement('SELECT GET_LOCK(?, 10)', [(string) $key]);
            }

            return $this;
        });

        /**
         * 释放咨询锁（与 {@see self::advisoryLock()} 配对）。
         *
         * @param  int|string  $key  锁标识
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * Report::query()->advisoryUnlock('report:daily:2025-06-01');
         *
         * @scenario 显式释放会话级咨询锁（事务级锁会随事务提交自动释放，通常无需手动释放）。
         */
        static::define('advisoryUnlock', function (int|string $key): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $conn = $this->getConnection();
            if (BaseMacro::isPostgres($this)) {
                $conn->statement('SELECT pg_advisory_unlock(?)', [(int) $key]);
            } else {
                $conn->statement('SELECT RELEASE_LOCK(?)', [(string) $key]);
            }

            return $this;
        });
    }
}
