<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * 相关度搜索宏（无需全文索引的两阶段检索，MySQL 8.0+ / PostgreSQL 15+ 通用）
 * 基于 study_mysql「搜索、模糊匹配与相关度排名」章节中「无全文索引时的 LOCATE + CHAR_LENGTH
 * 相关度模型」系统化封装。当表未建 FULLTEXT 索引、或全文分词未覆盖目标词时，用
 * `LOCATE(关键词, 列)` 命中位置（越靠前分越高）× `1/CHAR_LENGTH(列)`（文本越短越精准）构造相关度，
 * 作为 MATCH...AGAINST 的轻量替代 / 兜底方案。
 * 提供的宏：
 *  - whereRelevance：按子串相关度命中筛选（列中出现关键词即命中）
 *  - orderByRelevance：按多列组合相关度排序（越相关越靠前）
 *  - selectRelevance：返回单列的命中相关度分数（可展示）
 *  - searchRank：组合「命中筛选 + 相关度排序」的一站式轻量搜索
 * 安全性：列名经白名单校验并由 grammar wrap；关键词一律参数绑定。
 *
 * @version 3.1.0
 *
 * @requires MySQL 8.0+ / PostgreSQL 15+
 *
 * @scenario 相关度搜索宏（无需全文索引的两阶段检索，MySQL 8.0+ / PostgreSQL 15+ 通用）
 */
class SearchRelevanceMacro extends BaseMacro
{
    /**
     *  注册所有相关度搜索宏。
     *
     * @scenario 注册所有相关度搜索宏。
     */
    public static function register(): void
    {
        self::registerRelevance();
    }

    /**
     * 校验并 wrap 列集合并返回每列的 likeRelevance 表达式。
     *
     * @return array{0: string[], 1: array} [表达式数组, 合并后的绑定]
     *
     * @scenario 校验并 wrap 列集合并返回每列的 likeRelevance 表达式。
     */
    public static function relevanceExpressions(Builder|\Illuminate\Database\Query\Builder $builder, array|string $columns, string $keyword): array
    {
        $exprs = [];
        $bindings = [];
        foreach ((array) $columns as $col) {
            $wrapped = BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($col, 'column'));
            [$sql, $b] = BaseMacro::likeRelevanceExpr($builder, $wrapped, $keyword);
            $exprs[] = "({$sql})";
            $bindings = array_merge($bindings, $b);
        }

        return [$exprs, $bindings];
    }

    /**
     * 注册相关度搜索宏。
     *
     * @scenario 注册相关度搜索宏。
     */
    public static function registerRelevance(): void
    {
        /**
         * 按子串相关度命中筛选（列中出现关键词即命中）。
         *
         * @param  string  $column  检索列
         * @param  string  $keyword  关键词
         * @param  string  $boolean  连接条件 and|or
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 标题或正文中包含 "退款" 的记录（无需全文索引）
         * Ticket::query()->whereRelevance('content', '退款')->get();
         *
         * @scenario 小表 / 未建全文索引字段的关键词检索；或作为全文检索的兜底召回。
         */
        static::define('whereRelevance', function (
            string $column,
            string $keyword,
            string $boolean = 'and'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrapped = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            [$sql, $bindings] = BaseMacro::likeRelevanceExpr($this, $wrapped, $keyword);
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

            return $this->{$method}("{$sql} > 0", $bindings);
        });

        /**
         * 按多列组合相关度排序（越相关越靠前）。
         *
         * @param  array|string  $columns  检索列（相关度求和）
         * @param  string  $keyword  关键词
         * @param  string  $direction  排序方向 desc（默认）| asc
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * Product::query()
         *     ->whereRelevance('name', '手机')
         *     ->orderByRelevance(['name','description'], '手机')
         *     ->get();
         *
         * @scenario 轻量搜索结果排序；综合标题、描述等多列命中度给出排名。
         */
        static::define('orderByRelevance', function (
            array|string $columns,
            string $keyword,
            string $direction = 'desc'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $direction = BaseMacro::assertDirection($direction);
            [$exprs, $bindings] = SearchRelevanceMacro::relevanceExpressions($this, $columns, $keyword);

            return $this->orderByRaw('('.implode(' + ', $exprs).") {$direction}", $bindings);
        });

        /**
         * 返回单列相关度分数（可展示或二次排序）。
         *
         * @param  string  $column  检索列
         * @param  string  $keyword  关键词
         * @param  string  $alias  分数别名（默认 relevance）
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * Product::query()
         *     ->whereRelevance('name', '手机')
         *     ->selectRelevance('name', '手机', 'score')
         *     ->get();
         *
         * @scenario 前端展示「匹配度 87%」等场景。
         */
        static::define('selectRelevance', function (
            string $column,
            string $keyword,
            string $alias = 'relevance'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrapped = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            [$sql, $bindings] = BaseMacro::likeRelevanceExpr($this, $wrapped, $keyword);

            return $this->selectRaw("{$sql} AS {$alias}", $bindings);
        });

        /**
         * 组合「命中筛选 + 相关度排序」的一站式轻量搜索。
         *
         * @param  array|string  $columns  检索列
         * @param  string  $keyword  关键词
         * @param  string  $direction  排序方向 desc（默认）| asc
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 一行完成：命中关键词并按相关度排序返回
         * Article::query()->searchRank(['title','body'], 'laravel')->get();
         *
         * @scenario 最简单的「无全文索引站内搜索」实现；足够覆盖中小数据量的搜索需求。
         */
        static::define('searchRank', function (
            array|string $columns,
            string $keyword,
            string $direction = 'desc'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $builder = $this;
            $direction = BaseMacro::assertDirection($direction);
            $wrapped = array_map(
                static function (string $c) use ($builder) {
                    return BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($c, 'column'));
                },
                (array) $columns
            );
            $boolParts = [];
            $orderExprs = [];
            $whereBindings = [];
            $orderBindings = [];
            foreach ($wrapped as $col) {
                // 命中表达式与排序表达式共用同一相关度表达式（确定性、结果一致），仅计算一次。
                [$exprSql, $exprBind] = BaseMacro::likeRelevanceExpr($this, $col, $keyword);
                $boolParts[] = "({$exprSql} > 0)";
                $whereBindings = array_merge($whereBindings, $exprBind);
                $orderExprs[] = "({$exprSql})";
                $orderBindings = array_merge($orderBindings, $exprBind);
            }

            return $this->whereRaw('('.implode(' OR ', $boolParts).')', $whereBindings)
                ->orderByRaw('('.implode(' + ', $orderExprs).") {$direction}", $orderBindings);
        });
    }
}
