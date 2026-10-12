<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Support\Facades\DB;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * 每组 Top-N 去重宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 *
 * 基于 study_mysql「04-performance」「08-playbook / 01-advanced-query-patterns」中高频的
 * 「取每组最新/最大/前 N 条」模式：如「每个用户最新一笔订单」「每个分类 Top3 商品」「每篇文章
 * 最新评论」。传统写法（GROUP BY 丢字段、或 correlated subquery 逐行执行）在大数据量下是典型性能瓶颈，
 * 本宏统一用窗口函数 ROW_NUMBER() 一次性完成，O(1) 级别改写。
 *
 * 提供的宏：
 *  - whereTopNPerGroup：仅保留每个分组内按指定排序列取前 N 条（默认第 1 条）的记录
 *
 * 实现：构造一个 ROW_NUMBER() OVER (PARTITION BY 分组 ORDER BY 排序列 方向) 的子查询，
 * 再用 whereIn(主键, 子查询 WHERE _rn <= N) 完成过滤；双构建器（ORM/DB）均可用。
 *
 * @package zxf\Modules\BuilderQuery\WindowMacros
 * @version 3.2.0
 * @requires MySQL 8.0+ / PostgreSQL 15+
 */
class TopNMacro extends BaseMacro
{
    /** 注册所有 Top-N 分组宏。 */
    public static function register(): void
    {
        self::registerTopN();
    }

    /**
     * 注册分组 Top-N 宏。
     */
    public static function registerTopN(): void
    {
        /**
         * 仅保留每个分组内排在最前的 N 条记录（窗口函数去重）。
         *
         * 典型场景：每个用户最新一笔订单、每个分类销量前 3 的商品、每篇文章最新评论。
         * 相比「GROUP BY 分组列」会丢失其余字段、或「关联子查询」逐行执行慢，本宏用
         * ROW_NUMBER() 一次性改写，性能与可读性俱佳。
         *
         * @param string|array $partitionBy 分组列（可多列，如 ['user_id'] 或 ['category_id','status']）
         * @param string|array $orderBy 排序依据列（可多列；与 $direction 一一对应）
         * @param string|array $direction 排序方向 desc|asc（单值时对所有 orderBy 生效；多值需与 orderBy 等长）
         * @param int $n 每组保留前 N 条（默认 1）
         * @param string|null $keyColumn 用于 whereIn 的主键/关联键列（默认取模型主键，DB 模式回退 'id'）
         * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 每个用户最新的一笔订单
         * Order::query()->whereTopNPerGroup('user_id', 'created_at', 'desc', 1)->get();
         * // 每个分类销量前 3 的商品
         * Product::query()->whereTopNPerGroup('category_id', 'sales', 'desc', 3)->get();
         * // 多列排序：先按状态再按时间
         * Order::query()->whereTopNPerGroup('user_id', ['status','created_at'], ['asc','desc'], 1)->get();
         *
         * @scenario 报表/列表需要从「一对多」关系中取每组最新/最大/前 N 条，且需保留完整行字段。
         */
        static::define('whereTopNPerGroup', function (
            string|array $partitionBy,
            string|array $orderBy,
            string|array $direction = 'desc',
            int $n = 1,
            ?string $keyColumn = null
        ): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
            if ($n < 1) {
                throw new \InvalidArgumentException('whereTopNPerGroup 的 n 必须 >= 1。');
            }

            $builder = $this;
            $keyColumn = $keyColumn ?? BaseMacro::keyName($builder);
            $wrappedKey = BaseMacro::wrapColumn($builder, $keyColumn);
            $keyAlias = '_xbq_key';

            // 分组列
            $partitions = [];
            foreach ((array) $partitionBy as $p) {
                $partitions[] = BaseMacro::wrapColumn($builder, $p);
            }
            $partitionSql = implode(', ', $partitions);

            // 排序列 + 方向（支持多列）
            $orderCols = (array) $orderBy;
            $dirs = (array) $direction;
            if (count($dirs) === 1 && count($orderCols) > 1) {
                $dirs = array_fill(0, count($orderCols), $dirs[0]);
            }
            if (count($dirs) !== count($orderCols)) {
                throw new \InvalidArgumentException('whereTopNPerGroup 的 direction 与 orderBy 数量不一致。');
            }
            $orderParts = [];
            foreach ($orderCols as $i => $col) {
                $d = BaseMacro::assertDirection($dirs[$i]);
                $orderParts[] = BaseMacro::wrapColumn($builder, $col) . ' ' . $d;
            }
            $orderSql = implode(', ', $orderParts);

            // 构造带 ROW_NUMBER 的内部子查询（始终落到底层 Query Builder，参数绑定稳定可控）
            $inner = BaseMacro::queryOf(BaseMacro::freshQuery($builder))
                ->from(BaseMacro::resolveTable($builder))
                ->select([
                    BaseMacro::rawExpression("{$wrappedKey} AS {$keyAlias}"),
                    BaseMacro::rawExpression("ROW_NUMBER() OVER (PARTITION BY {$partitionSql} ORDER BY {$orderSql}) AS _xbq_rn"),
                ]);
            $innerSql = $inner->toSql();

            // 外层子查询：FROM (inner) AS _xbq_topn，WHERE _xbq_rn <= N
            $sub = DB::table(DB::raw("({$innerSql}) AS _xbq_topn"));
            $sub->mergeBindings($inner);
            $sub->select("_xbq_topn.{$keyAlias}")->where("_xbq_topn._xbq_rn", '<=', $n);

            // 主查询：WHERE 主键 IN (子查询)，由 Laravel 负责合并绑定与编译
            $builder->whereIn($wrappedKey, $sub);

            return $builder;
        });
    }
}
