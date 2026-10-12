<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * 相关度搜索宏（无需全文索引的两阶段检索，MySQL 8.0+ / PostgreSQL 15+ 通用）
 *
 * 基于 study_mysql「搜索、模糊匹配与相关度排名」章节中「无全文索引时的 LOCATE + CHAR_LENGTH
 * 相关度模型」系统化封装。当表未建 FULLTEXT 索引、或全文分词未覆盖目标词时，用
 * `LOCATE(关键词, 列)` 命中位置（越靠前分越高）× `1/CHAR_LENGTH(列)`（文本越短越精准）构造相关度，
 * 作为 MATCH...AGAINST 的轻量替代 / 兜底方案。
 *
 * 提供的宏：
 *  - whereRelevance：按子串相关度命中筛选（列中出现关键词即命中）
 *  - orderByRelevance：按多列组合相关度排序（越相关越靠前）
 *  - selectRelevance：返回单列的命中相关度分数（可展示）
 *  - searchRank：组合「命中筛选 + 相关度排序」的一站式轻量搜索
 *
 * 安全性：列名经白名单校验并由 grammar wrap；关键词一律参数绑定。
 *
 * @package zxf\Modules\BuilderQuery\WindowMacros
 * @version 3.1.0
 * @requires MySQL 8.0+ / PostgreSQL 15+
 */
class SearchRelevanceMacro extends BaseMacro
{
    /** 注册所有相关度搜索宏。 */
    public static function register(): void
    {
        self::registerRelevance();
    }

    /**
     * 校验并 wrap 列集合并返回每列的 likeRelevance 表达式。
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $builder
     * @param array|string $columns
     * @param string $keyword
     * @return array{0: string[], 1: array} [表达式数组, 合并后的绑定]
     */
    public static function relevanceExpressions(\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $builder, array|string $columns, string $keyword): array
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
     */
public static function registerRelevance(): void
    {
        /**
         * 按子串相关度命中筛选（列中出现关键词即命中）。
         *
         * @param string $column 检索列
         * @param string $keyword 关键词
         * @param string $boolean 连接条件 and|or
         * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
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
        ): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
            $wrapped = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            [$sql, $bindings] = BaseMacro::likeRelevanceExpr($this, $wrapped, $keyword);
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

            return $this->{$method}("{$sql} > 0", $bindings);
        });

        /**
         * 按多列组合相关度排序（越相关越靠前）。
         *
         * @param array|string $columns 检索列（相关度求和）
         * @param string $keyword 关键词
         * @param string $direction 排序方向 desc（默认）| asc
         * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
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
        ): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
            $direction = BaseMacro::assertDirection($direction);
            [$exprs, $bindings] = SearchRelevanceMacro::relevanceExpressions($this, $columns, $keyword);

            return $this->orderByRaw('(' . implode(' + ', $exprs) . ") {$direction}", $bindings);
        });

        /**
         * 返回单列相关度分数（可展示或二次排序）。
         *
         * @param string $column 检索列
         * @param string $keyword 关键词
         * @param string $alias 分数别名（默认 relevance）
         * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
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
        ): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
            $wrapped = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            [$sql, $bindings] = BaseMacro::likeRelevanceExpr($this, $wrapped, $keyword);

            return $this->selectRaw("{$sql} AS {$alias}", $bindings);
        });

        /**
         * 组合「命中筛选 + 相关度排序」的一站式轻量搜索。
         *
         * @param array|string $columns 检索列
         * @param string $keyword 关键词
         * @param string $direction 排序方向 desc（默认）| asc
         * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
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
        ): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
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
            $bindings = [];
            foreach ($wrapped as $col) {
                [$hitSql, $hitBind] = BaseMacro::likeRelevanceExpr($this, $col, $keyword);
                $boolParts[] = "({$hitSql} > 0)";
                $bindings = array_merge($bindings, $hitBind);
                [$ordSql, $ordBind] = BaseMacro::likeRelevanceExpr($this, $col, $keyword);
                $orderExprs[] = "({$ordSql})";
                $bindings = array_merge($bindings, $ordBind);
            }

            return $this->whereRaw('(' . implode(' OR ', $boolParts) . ')', $bindings)
                ->orderByRaw('(' . implode(' + ', $orderExprs) . ") {$direction}", $bindings);
        });
    }
}
