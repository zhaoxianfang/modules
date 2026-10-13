<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * 全文检索宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 * 基于 study_mysql 的「搜索、模糊匹配与相关度排名」章节系统化封装。所有语法经
 * {@see Dialect} 抽象层生成，双库通用：
 *  - MySQL：MATCH(col) AGAINST(? IN NATURAL LANGUAGE MODE|IN BOOLEAN MODE|WITH QUERY EXPANSION)，
 *           中文场景配合 `FULLTEXT(col) WITH PARSER ngram`；
 *  - PostgreSQL：to_tsvector('simple', col) @@ plainto_tsquery / to_tsquery，
 *           中文场景配合 `pg_jieba` / `zhparser` 分词扩展。
 * 提供的宏：
 *  - whereFullText / whereFullTextBoolean：自然语言 / 布尔模式筛选
 *  - orderByFullTextRelevance：按相关度分数排序
 *  - selectFullTextScore：将相关度分数作为字段返回（可用于展示或二次排序）
 *  - whereFullTextScoreGte：按相关度阈值过滤（过滤低质量命中）
 *  - whereFullTextOrLike：两阶段检索（先全文粗筛，无索引时回退 LOCATE 兜底）
 * 安全性：列名经白名单校验并由 grammar wrap；关键词一律参数绑定，杜绝注入。
 *
 * @version 3.1.0
 *
 * @requires MySQL 8.0+ / PostgreSQL 15+（全文索引/tsvector 配置非必须，但强烈建议以获最佳性能）
 *
 * @scenario 全文检索宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 */
class FullTextMacro extends BaseMacro
{
    /**
     *  注册所有全文检索宏。
     *
     * @scenario 注册所有全文检索宏。
     */
    public static function register(): void
    {
        self::registerWhereFullText();
        self::registerFullTextOrder();
        self::registerFullTextScore();
        self::registerTwoStage();
    }

    /**
     * 校验并 wrap 全文检索列集合。
     *
     * @return string[]
     *
     * @scenario 校验并 wrap 全文检索列集合。
     */
    public static function wrapColumns(Builder|\Illuminate\Database\Query\Builder $builder, array|string $columns): array
    {
        return array_map(
            static fn (string $c) => BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($c, 'column')),
            (array) $columns
        );
    }

    /**
     * 注册全文筛选与布尔检索宏。
     *
     * @scenario 注册全文筛选与布尔检索宏。
     */
    public static function registerWhereFullText(): void
    {
        /**
         * 全文检索筛选（自然语言 / 布尔 / 查询扩展）。
         *
         * @param  array|string  $columns  参与检索的列（单列或数组）
         * @param  string  $keyword  检索关键词
         * @param  string  $mode  natural（默认）/ boolean / expansion
         * @param  string  $boolean  连接条件 and|or
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 自然语言模式：检索标题或正文中包含 "laravel" 的文章
         * Article::query()->whereFullText(['title','body'], 'laravel')->get();
         * // 布尔模式：必须包含 laravel 且排除旧版，支持 + - "短语" word* 前缀
         * Article::query()->whereFullText(['title','body'], 'laravel', 'boolean')->get();
         *
         * @scenario 站内搜索、商品检索、文档检索等需要「词级相关度」而非简单 LIKE 的场景；
         *           相比 LIKE '%kw%' 全表扫描，全文索引可走倒排索引，性能与召回质量更优。
         */
        static::define('whereFullText', function (
            array|string $columns,
            string $keyword,
            string $mode = 'natural',
            string $boolean = 'and'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrapped = FullTextMacro::wrapColumns($this, $columns);
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
            [$sql, $bindings] = BaseMacro::fullTextMatchFragment($this, $wrapped, $keyword, $mode);

            return $this->{$method}($sql, $bindings);
        });

        /**
         * 布尔模式全文检索（显式传入布尔查询语法）。
         *
         * @param  array|string  $columns  检索列
         * @param  string  $query  布尔查询串，如 '+数据库 -mysql "查询优化" php*'
         * @param  string  $boolean  连接条件
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 必须含 "优化"、排除 "入门"、短语 "查询性能"、前缀匹配 "索引*"
         * Post::query()->whereFullTextBoolean('body', '+优化 -入门 "查询性能" 索引*')->get();
         *
         * @scenario 高级搜索框：用户直接输入带 + - " 等布尔语法的检索式。
         */
        static::define('whereFullTextBoolean', function (
            array|string $columns,
            string $query,
            string $boolean = 'and'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrapped = FullTextMacro::wrapColumns($this, $columns);
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
            [$sql, $bindings] = BaseMacro::fullTextMatchFragment($this, $wrapped, $query, 'boolean');

            return $this->{$method}($sql, $bindings);
        });
    }

    /**
     * 注册全文相关度排序宏。
     *
     * @scenario 注册全文相关度排序宏。
     */
    public static function registerFullTextOrder(): void
    {
        /**
         * 按全文检索相关度分数排序（降序通常更符合预期）。
         *
         * @param  array|string  $columns  检索列（须与 whereFullText 一致）
         * @param  string  $keyword  检索关键词（须与筛选一致）
         * @param  string  $mode  natural / boolean / expansion
         * @param  string  $direction  排序方向 desc（默认）| asc
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 先按相关度排序，再按发布时间兜底
         * Article::query()
         *     ->whereFullText(['title','body'], 'laravel')
         *     ->orderByFullTextRelevance(['title','body'], 'laravel')
         *     ->orderByDesc('created_at')
         *     ->get();
         *
         * @scenario 搜索结果列表，使最相关的内容排在最前；可与 created_at 等组合排序。
         */
        static::define('orderByFullTextRelevance', function (
            array|string $columns,
            string $keyword,
            string $mode = 'natural',
            string $direction = 'desc'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrapped = FullTextMacro::wrapColumns($this, $columns);
            $direction = BaseMacro::assertDirection($direction);
            $sql = BaseMacro::fullTextRelevanceExpr($this, $wrapped, $mode, $direction);

            return $this->orderByRaw($sql, [$keyword]);
        });
    }

    /**
     * 注册全文相关度打分宏。
     *
     * @scenario 注册全文相关度打分宏。
     */
    public static function registerFullTextScore(): void
    {
        /**
         * 将全文相关度分数作为字段返回（供展示或二次排序）。
         *
         * @param  array|string  $columns  检索列
         * @param  string  $keyword  关键词
         * @param  string  $alias  分数别名（默认 relevance）
         * @param  string  $mode  natural / boolean / expansion
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 返回每条记录的匹配分数，前端可展示「相关度 92%」
         * Article::query()
         *     ->whereFullText(['title','body'], 'laravel')
         *     ->selectFullTextScore(['title','body'], 'laravel')
         *     ->get();
         *
         * @scenario 需要在结果中展示相关度、或做「相关度 + 时间」的复合排序。
         */
        static::define('selectFullTextScore', function (
            array|string $columns,
            string $keyword,
            string $alias = 'relevance',
            string $mode = 'natural'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrapped = FullTextMacro::wrapColumns($this, $columns);
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            $direction = BaseMacro::assertDirection('desc');
            $sql = BaseMacro::fullTextRelevanceExpr($this, $wrapped, $mode, $direction);

            return $this->selectRaw("{$sql} AS {$alias}", [$keyword]);
        });

        /**
         * 按相关度阈值过滤（仅保留相关度 >= 阈值的命中）。
         *
         * @param  array|string  $columns  检索列
         * @param  string  $keyword  关键词
         * @param  float  $minScore  最小相关度（建议 0~10，依数据分布调参）
         * @param  string  $mode  natural / boolean / expansion
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 只保留相关度较高（>= 1.5）的结果，过滤噪声命中
         * Article::query()
         *     ->whereFullText(['title','body'], 'laravel')
         *     ->whereFullTextScoreGte(['title','body'], 'laravel', 1.5)
         *     ->get();
         *
         * @scenario 搜索结果降噪，避免低相关度内容挤占前列。
         */
        static::define('whereFullTextScoreGte', function (
            array|string $columns,
            string $keyword,
            float $minScore = 1.0,
            string $mode = 'natural'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrapped = FullTextMacro::wrapColumns($this, $columns);
            $direction = BaseMacro::assertDirection('desc');
            $sql = BaseMacro::fullTextRelevanceExpr($this, $wrapped, $mode, $direction);

            return $this->whereRaw("{$sql} >= ?", [$keyword, $minScore]);
        });
    }

    /**
     * 注册两阶段检索宏（全文 + LOCATE 兜底）。
     *
     * @scenario 注册两阶段检索宏（全文 + LOCATE 兜底）。
     */
    public static function registerTwoStage(): void
    {
        /**
         * 两阶段检索：全文索引命中 或 LOCATE 子串命中（提升召回鲁棒性）。
         * 当表未建全文索引、或全文分词未覆盖目标词时，纯 MATCH 会漏召回；
         * 本宏以 `(MATCH ... OR 任意列 LOCATE>0)` 组合，兼顾性能与召回。
         *
         * @param  array|string  $columns  检索列
         * @param  string  $keyword  关键词
         * @param  string  $boolean  连接条件
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 既走全文索引，又对未分词命中的记录用 LOCATE 兜底
         * Article::query()->whereFullTextOrLike(['title','body'], 'laravel')->get();
         *
         * @scenario 搜索召回率要求高的场景；或全文索引尚未建立时的过渡方案。
         */
        static::define('whereFullTextOrLike', function (
            array|string $columns,
            string $keyword,
            string $boolean = 'and'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrapped = FullTextMacro::wrapColumns($this, $columns);
            $method = $boolean === 'or' ? 'orWhere' : 'where';

            return $this->{$method}(function (Builder|\Illuminate\Database\Query\Builder $q) use ($wrapped, $keyword): void {
                [$matchSql, $matchBind] = BaseMacro::fullTextMatchFragment($q, $wrapped, $keyword, 'natural');
                $q->whereRaw($matchSql, $matchBind);
                foreach ($wrapped as $col) {
                    [$locSql, $locBind] = BaseMacro::likeRelevanceExpr($q, $col, $keyword);
                    $q->orWhereRaw("{$locSql} > 0", $locBind);
                }
            });
        });
    }
}
