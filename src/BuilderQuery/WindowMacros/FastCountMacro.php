<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * 大表计数加速宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 * 基于 study_mysql「08-playbook / 04-statistics-acceleration」「04-performance / 07-bottlenecks-and-advanced-ops」
 * 中关于「大表 COUNT 超时 / 大屏卡顿」的加速思路系统化封装。生产环境中，对千万/亿级表执行
 * `SELECT COUNT(*) FROM 大表` 常常全索引/全表扫描，拖垮连接、导致大屏与报表接口超时（长时间未响应）。
 * 本模块提供：
 *  - `approximateCount()`：对**无任何过滤条件**的简单表，直接读取数据字典估算行数
 *    （MySQL `information_schema.TABLES.TABLE_ROWS` / PostgreSQL `pg_class.reltuples`），复杂度为 O(1)，
 *    不触碰业务数据，瞬间返回；一旦带有 WHERE/JOIN/GROUP/HAVING 或复杂 FROM，自动回退为精确 `COUNT(*)`，
 *    保证结果正确性优先。
 * 安全与效率约定：
 *  - 仅读取系统目录视图，不锁业务表、不产生 undo/redo，对线上零侵入；
 *  - 所有可能抛异常的目录查询均包裹 try/catch，失败时**静默回退精确 COUNT**，绝不向上暴露异常；
 *  - 估算值非实时（MySQL TABLE_ROWS 为统计信息近似值，可能滞后），已在文档与返回语义中明确标注，
 *    适用于「概览数字 / 分页总数近似 / 大屏趋势」等对精度不敏感的场景；精确业务统计仍应使用精确 COUNT。
 * 双构建器友好：全部通过 {@see BaseMacro::define()} 同时注册到 Eloquent\Builder 与 Query\Builder。
 *
 * @version 1.13.9
 *
 * @requires PHP 8.2+（兼容 8.3/8.4/8.5），Laravel 11+ / 12+ / 13+，MySQL 8.0+ / PostgreSQL 15+
 */
class FastCountMacro extends BaseMacro
{
    /**
     *  注册大表计数加速宏。
     */
    public static function register(): void
    {
        self::registerApproximateCount();
    }

    /**
     * 注册 approximateCount。
     */
    public static function registerApproximateCount(): void
    {
        /**
         * 获取行数：无过滤时走数据字典 O(1) 估算，有过滤时回退精确 COUNT(*)。
         * 返回值为 int：
         *  - 无 WHERE/JOIN/GROUP/HAVING 且 FROM 为单个简单表名 → 返回 `information_schema`/`pg_class` 估算行数；
         *  - 其余情况 → 返回精确 `COUNT(*)`。
         *
         * @param  string|null  $column  精确计数时的计数列（默认主键；估算路径下忽略）
         * @return int 估算或精确行数（估算值可能略滞后，仅适用于概览/近似场景）
         *
         * @example
         * // 大屏展示「全站用户总数」概览：千万级用户表瞬间返回，无需全表扫描
         * $total = User::query()->approximateCount();
         * // 带条件的精确计数（自动识别并回退）
         * $paid  = Order::query()->where('status', 1)->approximateCount('id');
         *
         * @scenario 管理后台概览、数据大屏、分页总数近似等「不容忍慢查询」且「允许粗略数字」的场景；
         *           对账单、财务报表等强一致场景请勿使用本宏，改用精确 count()。
         */
        static::define('approximateCount', function (?string $column = null): int {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */

            return FastCountMacro::approximateCountOf($this, $column);
        });
    }

    /**
     * approximateCount 的实现（宏闭包外部，可安全使用显式类名调用）。
     *
     * @param  string|null  $column  精确计数时的计数列（可空，缺省取主键）
     */
    public static function approximateCountOf(
        Builder|\Illuminate\Database\Query\Builder $builder,
        ?string $column
    ): int {
        $query = BaseMacro::queryOf($builder);

        // 存在过滤条件时，估算无意义且不准确，直接精确 COUNT。
        $hasFilter = ! empty($query->wheres)
            || ! empty($query->joins)
            || ! empty($query->groups)
            || ! empty($query->havings);

        $table = BaseMacro::resolveTable($builder);
        // 仅当 FROM 为单个简单表名（无空格、无子查询括号）时才可安全估算。
        $isSimpleTable = is_string($table)
            && $table !== ''
            && ! preg_match('/\s/', $table)
            && ! str_contains($table, '(');

        if ($hasFilter || ! $isSimpleTable) {
            return (int) $builder->count($column ?? BaseMacro::keyName($builder));
        }

        // 读取数据字典估算（O(1)，不触达业务数据）。任何异常均回退精确 COUNT。
        try {
            $connection = BaseMacro::connOf($builder) ?? DB::getFacadeRoot()->getConnection();

            if (BaseMacro::isPostgres($builder)) {
                $estimate = $connection->table('pg_class')
                    ->selectRaw('reltuples::bigint AS n')
                    ->where('relname', $table)
                    ->value('n');
            } else {
                $schema = $connection->getDatabaseName();
                $estimate = $connection->table('information_schema.TABLES')
                    ->where('TABLE_SCHEMA', $schema)
                    ->where('TABLE_NAME', $table)
                    ->value('TABLE_ROWS');
            }

            // 估算值必须先转成整数再判大小：统计字典里的「无效估算」有两个约定值，
            // PostgreSQL 用 -1（表从未 ANALYZE），MySQL InnoDB 用 0
            // （未 ANALYZE / 关闭 innodb_stats_auto_recalc / 分区表）。
            // 只判 null 会把 0 和 -1 当成合法结果返回，
            // 使分页 total 变成 0 或负数。
            $estimate = (int) $estimate;

            if ($estimate > 0) {
                return $estimate;
            }
        } catch (\Throwable) {
            // 目录视图不可用 / 权限不足等：静默回退为精确计数，保证调用方永远拿到可用数字。
        }

        return (int) $builder->count($column ?? BaseMacro::keyName($builder));
    }
}
