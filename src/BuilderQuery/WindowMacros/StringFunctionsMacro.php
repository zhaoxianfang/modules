<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use zxf\Modules\BuilderQuery\Concerns\SqlSecurity;

/**
 * MySQL 8.0+ 字符串高效处理宏
 *
 * 基于 MySQL 8.0 内置字符串函数封装的高性能查询能力：
 * - LOCATE：查找子字符串在另一个字符串中首次出现的位置（分词查询匹配）
 * - CHAR_LENGTH：按字符数（多字节安全）排序与筛选
 * - FIELD：按指定值顺序自定义排序
 * - FIND_IN_SET：逗号分隔列表成员查找
 * - CONCAT_WS：多列拼接模糊搜索
 * - SUBSTRING_INDEX：按分隔符提取子串
 * - GROUP_CONCAT：行转字符串聚合
 * - MATCH...AGAINST：全文检索（自然语言 / 布尔模式 / 查询扩展）
 * - SOUNDEX：发音相似匹配
 * - REPLACE / TRIM / LOWER / UPPER / REVERSE / LPAD / RPAD：字符串变换
 * - REGEXP_REPLACE 自然排序：字符串内嵌数字按数值大小排序
 *
 * 安全性：列名 / 别名均经白名单校验并由 grammar wrap；所有字面值走参数绑定。
 *
 * @package zxf\Modules\BuilderQuery\WindowMacros
 * @version 1.0.0
 * @requires MySQL 8.0+
 */
class StringFunctionsMacro
{
    use SqlSecurity;

    /** 全文检索模式映射 */
    protected const FULLTEXT_MODES = [
        'natural'   => 'IN NATURAL LANGUAGE MODE',
        'boolean'   => 'IN BOOLEAN MODE',
        'expansion' => 'WITH QUERY EXPANSION',
    ];

    /**
     * 注册所有字符串宏
     *
     * @return void
     */
    public static function register(): void
    {
        self::registerLocateSearch();      // LOCATE 分词查询匹配
        self::registerCharLengthSorting(); // CHAR_LENGTH 排序/筛选 + 自然排序
        self::registerFieldOrdering();     // FIELD 自定义排序
        self::registerFindInSet();         // FIND_IN_SET 列表查找
        self::registerConcatSearch();      // CONCAT_WS 多列拼接搜索
        self::registerStringExtraction();  // SUBSTRING_INDEX 提取
        self::registerStringAggregation(); // GROUP_CONCAT 聚合
        self::registerFullTextSearch();    // MATCH...AGAINST 全文检索
        self::registerSoundexSearch();     // SOUNDEX 发音匹配
        self::registerStringTransform();   // 字符串变换
    }

    /**
     * 注册 LOCATE 分词查询匹配系列
     */
    protected static function registerLocateSearch(): void
    {
        /**
         * whereLocate - 子字符串位置查找（分词查询匹配）
         *
         * 使用 MySQL 的 LOCATE(substr, str) 函数查找子串在字段中首次出现的位置，
         * 位置 > 0 即表示命中。相比 LIKE '%keyword%'，LOCATE 在部分场景下
         * 可利用索引前缀匹配，且天然支持以空格分隔的多词查询（分词匹配）。
         *
         * @param string $column 查询字段名
         * @param string $search 要查找的子字符串（可为空格分隔的多词分词）
         * @param string $boolean 连接条件: and|or
         * @return Builder
         *
         * @example
         * // 查找描述中包含 "Laravel" 的记录
         * Post::query()->whereLocate('description', 'Laravel')->get();
         *
         * // 分词查询：包含 "高级" 或 "查询" 任意一词
         * Article::query()->whereLocate('content', '高级 查询')->get();
         *
         * // 与其它条件组合
         * Post::query()->where('status', 1)->whereLocate('title', 'MySQL')->get();
         */
        Builder::macro('whereLocate', function (string $column, string $search, string $boolean = 'and'): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::whereLocate($this, $column, $search, $boolean);
        });

        /**
         * orWhereLocate - OR 条件的子字符串位置查找
         *
         * @param string $column 查询字段名
         * @param string $search 要查找的子字符串
         * @return Builder
         *
         * @example
         * // 标题包含 "MySQL" 或描述包含 "数据库"
         * Post::query()
         *     ->whereLocate('title', 'MySQL')
         *     ->orWhereLocate('description', '数据库')
         *     ->get();
         */
        Builder::macro('orWhereLocate', function (string $column, string $search): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::whereLocate($this, $column, $search, 'or');
        });

        /**
         * whereLocateAll - 多关键词全命中查询（AND 连接）
         *
         * 每个关键词都会生成一个 LOCATE 条件并用 AND 连接，
         * 要求字段同时包含所有关键词，实现"全分词匹配"。
         *
         * @param string $column 查询字段名
         * @param array<int, string> $keywords 关键词数组
         * @return Builder
         *
         * @example
         * // 内容必须同时包含 "Laravel" 与 "MySQL"
         * Article::query()->whereLocateAll('content', ['Laravel', 'MySQL'])->get();
         *
         * // 搜索框多关键词（全部命中才返回）
         * $keywords = explode(' ', trim($request->input('q')));
         * Post::query()->whereLocateAll('title', $keywords)->get();
         */
        Builder::macro('whereLocateAll', function (string $column, array $keywords): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::whereLocateAll($this, $column, $keywords);
        });

        /**
         * whereLocateAny - 多关键词任一命中查询（OR 连接）
         *
         * 字段包含任意一个关键词即返回，实现"任一分词匹配"。
         *
         * @param string $column 查询字段名
         * @param array<int, string> $keywords 关键词数组
         * @return Builder
         *
         * @example
         * // 内容包含 "Laravel" 或 "MySQL" 任一即可
         * Article::query()->whereLocateAny('content', ['Laravel', 'MySQL'])->get();
         */
        Builder::macro('whereLocateAny', function (string $column, array $keywords): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::whereLocateAny($this, $column, $keywords);
        });
    }

    /**
     * 注册 CHAR_LENGTH 排序/筛选与自然排序系列
     */
    protected static function registerCharLengthSorting(): void
    {
        /**
         * orderByCharLength - 按字符长度排序
         *
         * 使用 CHAR_LENGTH() 计算字段的字符数（多字节安全，中英文均按 1 个字符计），
         * 常用于"标题最短优先"、"名称长度分组"等场景。
         *
         * @param string $column 排序字段名
         * @param string $direction 排序方向: asc|desc
         * @return Builder
         *
         * @example
         * // 按用户名长度升序排列
         * User::query()->orderByCharLength('name')->get();
         *
         * // 按标题字符数从长到短（新闻类站点的头条优先）
         * News::query()->orderByDescCharLength('title')->get();
         */
        Builder::macro('orderByCharLength', function (string $column, string $direction = 'asc'): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::orderByCharLength($this, $column, $direction);
        });

        /**
         * orderByDescCharLength - 按字符长度降序排序
         *
         * @param string $column 排序字段名
         * @return Builder
         *
         * @example
         * // 内容最长的文章排在最前
         * Post::query()->orderByDescCharLength('body')->get();
         */
        Builder::macro('orderByDescCharLength', function (string $column): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::orderByCharLength($this, $column, 'desc');
        });

        /**
         * whereCharLength - 按字符长度筛选
         *
         * @param string $column 查询字段名
         * @param string $operator 比较运算符: =|>|<|>=|<=|<>|!=
         * @param int $length 字符长度
         * @param string $boolean 连接条件: and|or
         * @return Builder
         *
         * @example
         * // 用户名长度大于等于 4 的账号
         * User::query()->whereCharLength('name', '>=', 4)->get();
         *
         * // 密码长度必须为 8 位（校验用）
         * User::query()->whereCharLength('password', '=', 8)->get();
         */
        Builder::macro('whereCharLength', function (string $column, string $operator, int $length, string $boolean = 'and'): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::whereCharLength($this, $column, $operator, $length, $boolean);
        });

        /**
         * orWhereCharLength - OR 条件的字符长度筛选
         *
         * @param string $column 查询字段名
         * @param string $operator 比较运算符
         * @param int $length 字符长度
         * @return Builder
         *
         * @example
         * // 名称等于 3 个字，或描述大于 50 个字
         * Product::query()
         *     ->whereCharLength('name', '=', 3)
         *     ->orWhereCharLength('description', '>', 50)
         *     ->get();
         */
        Builder::macro('orWhereCharLength', function (string $column, string $operator, int $length): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::whereCharLength($this, $column, $operator, $length, 'or');
        });

        /**
         * orderByNatural - 自然排序（字符串中内嵌数字按数值大小排序）
         *
         * 使用 REGEXP_REPLACE 提取字段中所有数字并转为整数作为主排序键，
         * 原字段作为次排序键，实现 "V1, V2, V10" 而非 "V1, V10, V2" 的自然序。
         *
         * @param string $column 排序字段名
         * @param string $direction 排序方向: asc|desc
         * @return Builder
         *
         * @example
         * // 版本号自然排序：v1, v2, ..., v10 而非字典序
         * Version::query()->orderByNatural('tag')->get();
         *
         * // 编号字段（如 "A-3", "A-12"）按数字部分排序
         * Sku::query()->orderByNatural('code')->get();
         */
        Builder::macro('orderByNatural', function (string $column, string $direction = 'asc'): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::orderByNatural($this, $column, $direction);
        });
    }

    /**
     * 注册 FIELD 自定义排序系列
     */
    protected static function registerFieldOrdering(): void
    {
        /**
         * fieldOrderBy - 按指定值顺序自定义排序
         *
         * 使用 MySQL 的 FIELD(value, val1, val2, ...) 函数，将字段值映射为
         * 其在给定列表中的下标作为排序键，实现"枚举状态优先序"等业务排序。
         * 未出现在列表中的值排在最前（下标 0）。
         *
         * @param string $column 排序字段名
         * @param array<int, mixed> $order 自定义顺序的值列表（按期望的排序顺序）
         * @param string $direction 排序方向: asc|desc（作用于 FIELD 下标）
         * @return Builder
         *
         * @example
         * // 按订单状态优先级排序：待支付 -> 待发货 -> 已发货 -> 已完成
         * Order::query()->fieldOrderBy('status', ['pending', 'shipped', 'delivered', 'completed'])->get();
         *
         * // 热门城市优先展示
         * City::query()->fieldOrderBy('name', ['北京', '上海', '深圳'])->get();
         */
        Builder::macro('fieldOrderBy', function (string $column, array $order, string $direction = 'asc'): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::fieldOrderBy($this, $column, $order, $direction);
        });
    }

    /**
     * 注册 FIND_IN_SET 列表查找系列
     */
    protected static function registerFindInSet(): void
    {
        /**
         * whereFindInSet - 逗号分隔列表成员查找
         *
         * 使用 FIND_IN_SET(needle, haystack) 判断字段（逗号分隔字符串）
         * 是否包含指定值，比 LIKE '%,val,%' 更准确、性能更好。
         *
         * @param string $column 查询字段名（逗号分隔的字符串列）
         * @param mixed $value 要查找的值
         * @param string $boolean 连接条件: and|or
         * @return Builder
         *
         * @example
         * // 标签字段 "php,mysql,laravel" 中包含 "php" 的文章
         * Article::query()->whereFindInSet('tags', 'php')->get();
         *
         * // 推荐位 ID 列表字段包含指定 ID 的商品
         * Product::query()->whereFindInSet('recommend_ids', 5)->get();
         */
        Builder::macro('whereFindInSet', function (string $column, mixed $value, string $boolean = 'and'): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::whereFindInSet($this, $column, $value, $boolean);
        });

        /**
         * orWhereFindInSet - OR 条件的列表成员查找
         *
         * @param string $column 查询字段名
         * @param mixed $value 要查找的值
         * @return Builder
         *
         * @example
         * // 分类包含 "news" 或标签包含 "hot"
         * Post::query()
         *     ->whereFindInSet('categories', 'news')
         *     ->orWhereFindInSet('tags', 'hot')
         *     ->get();
         */
        Builder::macro('orWhereFindInSet', function (string $column, mixed $value): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::whereFindInSet($this, $column, $value, 'or');
        });

        /**
         * whereFindInSetAny - 列表字段包含任意指定值
         *
         * @param string $column 查询字段名
         * @param array<int, mixed> $values 候选值数组
         * @return Builder
         *
         * @example
         * // 标签列表包含 "php" 或 "go" 任一标签的文章
         * Article::query()->whereFindInSetAny('tags', ['php', 'go'])->get();
         */
        Builder::macro('whereFindInSetAny', function (string $column, array $values): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::whereFindInSetAny($this, $column, $values);
        });

        /**
         * whereFindInSetAll - 列表字段包含全部指定值
         *
         * 字段（逗号分隔）必须同时包含列表中的所有值。
         *
         * @param string $column 查询字段名
         * @param array<int, mixed> $values 必须全部包含的值数组
         * @return Builder
         *
         * @example
         * // 必须同时带 "php" 与 "mysql" 两个标签
         * Article::query()->whereFindInSetAll('tags', ['php', 'mysql'])->get();
         */
        Builder::macro('whereFindInSetAll', function (string $column, array $values): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::whereFindInSetAll($this, $column, $values);
        });
    }

    /**
     * 注册 CONCAT_WS 多列拼接搜索系列
     */
    protected static function registerConcatSearch(): void
    {
        /**
         * whereConcatLike - 多列拼接模糊搜索
         *
         * 使用 CONCAT_WS 将多个字段以空格拼接后做 LIKE 模糊匹配，
         * 实现"跨字段全文关键词检索"（如姓名+手机号+邮箱 一站式搜索）。
         *
         * @param array<int, string> $columns 参与拼接的字段数组
         * @param string $keyword 搜索关键词
         * @param string $boolean 连接条件: and|or
         * @return Builder
         *
         * @example
         * // 在 姓名/手机号/邮箱 三个字段中搜索 "张"
         * User::query()->whereConcatLike(['name', 'phone', 'email'], '张')->get();
         *
         * // 工单标题与描述联合搜索
         * Ticket::query()->whereConcatLike(['title', 'content'], $request->input('q'))->get();
         */
        Builder::macro('whereConcatLike', function (array $columns, string $keyword, string $boolean = 'and'): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::whereConcatLike($this, $columns, $keyword, $boolean);
        });

        /**
         * orWhereConcatLike - OR 条件的多列拼接搜索
         *
         * @param array<int, string> $columns 参与拼接的字段数组
         * @param string $keyword 搜索关键词
         * @return Builder
         *
         * @example
         * // 备注包含 "加急"，或 标题+内容 包含 "催单"
         * Order::query()
         *     ->whereLocate('remark', '加急')
         *     ->orWhereConcatLike(['title', 'content'], '催单')
         *     ->get();
         */
        Builder::macro('orWhereConcatLike', function (array $columns, string $keyword): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::whereConcatLike($this, $columns, $keyword, 'or');
        });
    }

    /**
     * 注册 SUBSTRING_INDEX 提取系列
     */
    protected static function registerStringExtraction(): void
    {
        /**
         * substringIndex - 按分隔符提取子串（SELECT 场景）
         *
         * 使用 SUBSTRING_INDEX(str, delimiter, count) 提取分隔字段中的一段。
         * count 为正数取从左第 count 个分隔符之前的子串；
         * count 为负数取从右第 |count| 个分隔符之后的子串。
         *
         * @param string $column 字段名
         * @param string $delimiter 分隔符
         * @param int $count 提取方向与个数（正数从左、负数从右）
         * @param string $alias 结果别名
         * @return Builder
         *
         * @example
         * // 提取邮箱的用户名部分（"a@b.com" -> "a"）
         * User::query()->substringIndex('email', '@', 1, 'username_part')->get();
         *
         * // 提取 IPv4 最后一段（"192.168.1.10" -> "10"）
         * Log::query()->substringIndex('ip', '.', -1, 'last_segment')->get();
         *
         * // 提取商品规格："颜色:红色" -> "红色"
         * Sku::query()->substringIndex('spec', ':', -1, 'color_value')->get();
         */
        Builder::macro('substringIndex', function (string $column, string $delimiter, int $count, string $alias = 'part'): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::substringIndex($this, $column, $delimiter, $count, $alias);
        });
    }

    /**
     * 注册 GROUP_CONCAT 聚合系列
     */
    protected static function registerStringAggregation(): void
    {
        /**
         * groupConcat - 行转字符串聚合（GROUP_CONCAT）
         *
         * 将分组内的多行字段值拼接为一个字符串，配合 groupBy 使用，
         * 常用于"一篇文章的所有标签"、"订单关联的所有商品名"。
         *
         * @param string $column 要聚合的字段名
         * @param string $alias 结果别名
         * @param string $separator 分隔符（默认英文逗号）
         * @param bool $distinct 是否去重
         * @return Builder
         *
         * @example
         * // 每个分类下的所有文章标题（逗号分隔）
         * Post::query()
         *     ->groupBy('category_id')
         *     ->groupConcat('title', 'titles')
         *     ->get();
         *
         * // 订单关联的商品名列表（竖线分隔、去重）
         * OrderItem::query()
         *     ->where('order_id', 100)
         *     ->groupConcat('product_name', 'names', '|', true)
         *     ->first();
         */
        Builder::macro('groupConcat', function (string $column, string $alias = 'items', string $separator = ',', bool $distinct = false): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::groupConcat($this, $column, $alias, $separator, $distinct);
        });
    }

    /**
     * 注册全文检索系列（MATCH...AGAINST）
     */
    protected static function registerFullTextSearch(): void
    {
        /**
         * whereFullText - 全文检索筛选
         *
         * 使用 MySQL 全文索引 MATCH(columns) AGAINST(? IN NATURAL LANGUAGE MODE)
         * 进行语义级全文搜索，命中返回相关度 > 0 的记录。
         * 注意：参与检索的列必须建立 FULLTEXT 索引（MyISAM 或 InnoDB）。
         *
         * @param array<int, string>|string $columns 参与全文检索的字段（需 FULLTEXT 索引）
         * @param string $keyword 搜索关键词
         * @param string $mode 检索模式: natural(自然语言)|boolean(布尔模式)|expansion(查询扩展)
         * @param string $boolean 连接条件: and|or
         * @return Builder
         *
         * @example
         * // 自然语言全文搜索（最常用）
         * Article::query()->whereFullText(['title', 'body'], 'Laravel 查询构造器')->get();
         *
         * // 查询扩展模式（自动补充同义词，召回率更高）
         * Article::query()->whereFullText('body', '数据库优化', 'expansion')->get();
         */
        Builder::macro('whereFullText', function (array|string $columns, string $keyword, string $mode = 'natural', string $boolean = 'and'): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::whereFullText($this, $columns, $keyword, $mode, $boolean);
        });

        /**
         * whereFullTextBoolean - 布尔模式全文检索（支持分词语法）
         *
         * BOOLEAN MODE 支持高级检索语法：
         *   +word  必须包含   -word  必须不包含
         *   "word phrase"  精确短语   word*  前缀匹配   >word  权重提升
         *
         * @param array<int, string>|string $columns 参与全文检索的字段
         * @param string $query 布尔检索表达式（支持 + - " * 等语法）
         * @param string $boolean 连接条件: and|or
         * @return Builder
         *
         * @example
         * // 必须包含 Laravel、不能包含 Vue
         * Post::query()->whereFullTextBoolean('title', '+Laravel -Vue')->get();
         *
         * // 精确短语匹配
         * Post::query()->whereFullTextBoolean('body', '"query builder"')->get();
         *
         * // 前缀匹配：php* 命中 php、php8 等
         * Post::query()->whereFullTextBoolean('tags', 'php*')->get();
         */
        Builder::macro('whereFullTextBoolean', function (array|string $columns, string $query, string $boolean = 'and'): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::whereFullText($this, $columns, $query, 'boolean', $boolean);
        });

        /**
         * orderByFullTextRelevance - 按全文检索相关度排序
         *
         * 与 whereFullText 配合，使用 MATCH...AGAINST 的相关度分数排序，
         * 让最相关的记录排在最前。
         *
         * @param array<int, string>|string $columns 参与全文检索的字段
         * @param string $keyword 搜索关键词
         * @param string $mode 检索模式: natural|boolean|expansion
         * @param string $direction 排序方向: asc|desc（默认 desc，相关度最高在前）
         * @return Builder
         *
         * @example
         * // 搜索并按相关度从高到低排序
         * Article::query()
         *     ->whereFullText(['title', 'body'], 'Laravel')
         *     ->orderByFullTextRelevance(['title', 'body'], 'Laravel')
         *     ->get();
         */
        Builder::macro('orderByFullTextRelevance', function (array|string $columns, string $keyword, string $mode = 'natural', string $direction = 'desc'): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::orderByFullTextRelevance($this, $columns, $keyword, $mode, $direction);
        });
    }

    /**
     * 注册 SOUNDEX 发音匹配系列
     */
    protected static function registerSoundexSearch(): void
    {
        /**
         * whereSoundex - 发音相似匹配
         *
         * 使用 SOUNDEX() 比较两个字符串的发音编码是否一致，
         * 适用于英文姓名、公司名的模糊发音匹配（如 "Smith" 与 "Smyth"）。
         *
         * @param string $column 查询字段名
         * @param string $value 要匹配发音的值
         * @param string $boolean 连接条件: and|or
         * @return Builder
         *
         * @example
         * // 查找名字发音与 "Smith" 相似的用户
         * Contact::query()->whereSoundex('last_name', 'Smith')->get();
         */
        Builder::macro('whereSoundex', function (string $column, string $value, string $boolean = 'and'): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::whereSoundex($this, $column, $value, $boolean);
        });

        /**
         * orWhereSoundex - OR 条件的发音相似匹配
         *
         * @param string $column 查询字段名
         * @param string $value 要匹配发音的值
         * @return Builder
         */
        Builder::macro('orWhereSoundex', function (string $column, string $value): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::whereSoundex($this, $column, $value, 'or');
        });
    }

    /**
     * 注册字符串变换系列
     */
    protected static function registerStringTransform(): void
    {
        /**
         * replaceString - 字符串替换（SELECT 场景）
         *
         * 使用 REPLACE(str, from, to) 将字段中所有出现的 from 替换为 to。
         *
         * @param string $column 字段名
         * @param string $search 要查找的字符串
         * @param string $replace 替换为的字符串
         * @param string $alias 结果别名
         * @return Builder
         *
         * @example
         * // 将手机号中的前 3 位替换为 *（脱敏示例）
         * User::query()->replaceString('phone', substr($phone, 0, 3), '***', 'masked_phone')->get();
         */
        Builder::macro('replaceString', function (string $column, string $search, string $replace, string $alias = 'replaced'): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::replaceString($this, $column, $search, $replace, $alias);
        });

        /**
         * trimString - 去除首尾空格（SELECT 场景）
         *
         * @param string $column 字段名
         * @param string $alias 结果别名
         * @return Builder
         *
         * @example
         * // 标准化导出：去除名称首尾空白
         * User::query()->trimString('name', 'clean_name')->get();
         */
        Builder::macro('trimString', function (string $column, string $alias = 'trimmed'): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::trimString($this, $column, $alias);
        });

        /**
         * lowerString - 转小写（SELECT 场景）
         *
         * @param string $column 字段名
         * @param string $alias 结果别名
         * @return Builder
         *
         * @example
         * // 获取邮箱的小写形式用于比较
         * User::query()->lowerString('email', 'email_lower')->get();
         */
        Builder::macro('lowerString', function (string $column, string $alias = 'lower_val'): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::lowerString($this, $column, $alias);
        });

        /**
         * upperString - 转大写（SELECT 场景）
         *
         * @param string $column 字段名
         * @param string $alias 结果别名
         * @return Builder
         *
         * @example
         * // 国家代码统一为大写展示
         * Country::query()->upperString('code', 'code_upper')->get();
         */
        Builder::macro('upperString', function (string $column, string $alias = 'upper_val'): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::upperString($this, $column, $alias);
        });

        /**
         * reverseString - 字符串反转（SELECT 场景）
         *
         * @param string $column 字段名
         * @param string $alias 结果别名
         * @return Builder
         *
         * @example
         * // 反转手机号后 4 位做展示
         * User::query()->reverseString('phone', 'reversed_phone')->get();
         */
        Builder::macro('reverseString', function (string $column, string $alias = 'reversed'): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::reverseString($this, $column, $alias);
        });

        /**
         * padString - 填充字符串到指定长度（SELECT 场景）
         *
         * 使用 LPAD/RPAD 将字段填充到指定长度，常用于流水号、编号格式化。
         *
         * @param string $column 字段名
         * @param int $length 目标总长度
         * @param string $padString 用于填充的字符串
         * @param string $position 填充位置: left|right
         * @param string $alias 结果别名
         * @return Builder
         *
         * @example
         * // 订单号左侧补零到 8 位（"123" -> "00000123"）
         * Order::query()->padString('order_no', 8, '0', 'left', 'padded_no')->get();
         */
        Builder::macro('padString', function (string $column, int $length, string $padString, string $position = 'left', string $alias = 'padded'): Builder {
            /** @var Builder $this */
            return StringFunctionsMacro::padString($this, $column, $length, $padString, $position, $alias);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | 静态实现（宏闭包内 self:: 会解析到 Builder，故必须经显式类名调用）
    |--------------------------------------------------------------------------
    */

    /**
     * 实现 whereLocate / orWhereLocate
     */
    public static function whereLocate(EloquentBuilder|QueryBuilder $builder, string $column, string $search, string $boolean = 'and'): EloquentBuilder|QueryBuilder
    {
        self::assertMysql($builder, 'whereLocate');
        $wrapped = self::wrapIdentifier($builder, self::assertValidIdentifier($column));

        return $builder->whereRaw("LOCATE(?, {$wrapped}) > 0", [$search], $boolean);
    }

    /**
     * 实现 whereLocateAll（AND 全命中）
     */
    public static function whereLocateAll(EloquentBuilder|QueryBuilder $builder, string $column, array $keywords): EloquentBuilder|QueryBuilder
    {
        self::assertMysql($builder, 'whereLocateAll');
        $wrapped = self::wrapIdentifier($builder, self::assertValidIdentifier($column));

        foreach ($keywords as $keyword) {
            $builder->whereRaw("LOCATE(?, {$wrapped}) > 0", [(string) $keyword]);
        }

        return $builder;
    }

    /**
     * 实现 whereLocateAny（OR 任一命中，嵌套分组避免布尔优先级陷阱）
     */
    public static function whereLocateAny(EloquentBuilder|QueryBuilder $builder, string $column, array $keywords): EloquentBuilder|QueryBuilder
    {
        self::assertMysql($builder, 'whereLocateAny');
        $wrapped = self::wrapIdentifier($builder, self::assertValidIdentifier($column));

        return $builder->where(function ($query) use ($wrapped, $keywords) {
            foreach ($keywords as $index => $keyword) {
                $query->whereRaw("LOCATE(?, {$wrapped}) > 0", [(string) $keyword], $index === 0 ? 'and' : 'or');
            }
        });
    }

    /**
     * 实现 orderByCharLength / orderByDescCharLength
     */
    public static function orderByCharLength(EloquentBuilder|QueryBuilder $builder, string $column, string $direction = 'asc'): EloquentBuilder|QueryBuilder
    {
        $wrapped = self::wrapIdentifier($builder, self::assertValidIdentifier($column));
        $direction = self::assertDirection($direction);

        return $builder->orderByRaw("CHAR_LENGTH({$wrapped}) {$direction}");
    }

    /**
     * 实现 whereCharLength / orWhereCharLength
     */
    public static function whereCharLength(EloquentBuilder|QueryBuilder $builder, string $column, string $operator, int $length, string $boolean = 'and'): EloquentBuilder|QueryBuilder
    {
        $wrapped = self::wrapIdentifier($builder, self::assertValidIdentifier($column));
        $operator = self::assertOperator($operator);

        return $builder->whereRaw("CHAR_LENGTH({$wrapped}) {$operator} ?", [$length], $boolean);
    }

    /**
     * 实现 orderByNatural（REGEXP_REPLACE 提取数字的自然排序，MySQL 8.0+）
     */
    public static function orderByNatural(EloquentBuilder|QueryBuilder $builder, string $column, string $direction = 'asc'): EloquentBuilder|QueryBuilder
    {
        self::assertMysql($builder, 'orderByNatural');
        $wrapped = self::wrapIdentifier($builder, self::assertValidIdentifier($column));
        $direction = self::assertDirection($direction);

        // 提取字符串中所有数字转为无符号整数作为主排序键，原字段作为次排序键
        return $builder->orderByRaw("CAST(REGEXP_REPLACE({$wrapped}, '[^0-9]', '') AS UNSIGNED) {$direction}, {$wrapped} {$direction}");
    }

    /**
     * 实现 fieldOrderBy（FIELD 自定义排序）
     */
    public static function fieldOrderBy(EloquentBuilder|QueryBuilder $builder, string $column, array $order, string $direction = 'asc'): EloquentBuilder|QueryBuilder
    {
        self::assertMysql($builder, 'fieldOrderBy');
        $wrapped = self::wrapIdentifier($builder, self::assertValidIdentifier($column));
        $direction = self::assertDirection($direction);

        $placeholders = implode(', ', array_fill(0, count($order), '?'));

        return $builder->orderByRaw("FIELD({$wrapped}, {$placeholders}) {$direction}", array_values($order));
    }

    /**
     * 实现 whereFindInSet / orWhereFindInSet
     */
    public static function whereFindInSet(EloquentBuilder|QueryBuilder $builder, string $column, mixed $value, string $boolean = 'and'): EloquentBuilder|QueryBuilder
    {
        self::assertMysql($builder, 'whereFindInSet');
        $wrapped = self::wrapIdentifier($builder, self::assertValidIdentifier($column));

        return $builder->whereRaw("FIND_IN_SET(?, {$wrapped}) > 0", [(string) $value], $boolean);
    }

    /**
     * 实现 whereFindInSetAny（OR 任一命中）
     */
    public static function whereFindInSetAny(EloquentBuilder|QueryBuilder $builder, string $column, array $values): EloquentBuilder|QueryBuilder
    {
        self::assertMysql($builder, 'whereFindInSetAny');
        $wrapped = self::wrapIdentifier($builder, self::assertValidIdentifier($column));

        return $builder->where(function ($query) use ($wrapped, $values) {
            foreach ($values as $index => $value) {
                $query->whereRaw("FIND_IN_SET(?, {$wrapped}) > 0", [(string) $value], $index === 0 ? 'and' : 'or');
            }
        });
    }

    /**
     * 实现 whereFindInSetAll（AND 全命中）
     */
    public static function whereFindInSetAll(EloquentBuilder|QueryBuilder $builder, string $column, array $values): EloquentBuilder|QueryBuilder
    {
        self::assertMysql($builder, 'whereFindInSetAll');
        $wrapped = self::wrapIdentifier($builder, self::assertValidIdentifier($column));

        foreach ($values as $value) {
            $builder->whereRaw("FIND_IN_SET(?, {$wrapped}) > 0", [(string) $value]);
        }

        return $builder;
    }

    /**
     * 实现 whereConcatLike / orWhereConcatLike
     */
    public static function whereConcatLike(EloquentBuilder|QueryBuilder $builder, array $columns, string $keyword, string $boolean = 'and'): EloquentBuilder|QueryBuilder
    {
        self::assertMysql($builder, 'whereConcatLike');
        $wrappedColumns = [];
        foreach ($columns as $column) {
            $wrappedColumns[] = self::wrapIdentifier($builder, self::assertValidIdentifier($column));
        }
        $concatExpr = 'CONCAT_WS(\' \', '.implode(', ', $wrappedColumns).')';

        return $builder->whereRaw("{$concatExpr} LIKE ?", ['%'.$keyword.'%'], $boolean);
    }

    /**
     * 实现 substringIndex（SUBSTRING_INDEX 提取）
     */
    public static function substringIndex(EloquentBuilder|QueryBuilder $builder, string $column, string $delimiter, int $count, string $alias = 'part'): EloquentBuilder|QueryBuilder
    {
        self::assertMysql($builder, 'substringIndex');
        $wrapped = self::wrapIdentifier($builder, self::assertValidIdentifier($column));
        $alias = self::assertValidIdentifier($alias, 'alias');

        return $builder->selectRaw("SUBSTRING_INDEX({$wrapped}, ?, ?) AS ".self::wrapIdentifier($builder, $alias), [$delimiter, $count]);
    }

    /**
     * 实现 groupConcat（GROUP_CONCAT 聚合）
     */
    public static function groupConcat(EloquentBuilder|QueryBuilder $builder, string $column, string $alias = 'items', string $separator = ',', bool $distinct = false): EloquentBuilder|QueryBuilder
    {
        self::assertMysql($builder, 'groupConcat');
        $wrapped = self::wrapIdentifier($builder, self::assertValidIdentifier($column));
        $alias = self::assertValidIdentifier($alias, 'alias');
        $distinctSql = $distinct ? 'DISTINCT ' : '';

        return $builder->selectRaw("GROUP_CONCAT({$distinctSql}{$wrapped} SEPARATOR ?) AS ".self::wrapIdentifier($builder, $alias), [$separator]);
    }

    /**
     * 实现 whereFullText / whereFullTextBoolean
     */
    public static function whereFullText(EloquentBuilder|QueryBuilder $builder, array|string $columns, string $keyword, string $mode = 'natural', string $boolean = 'and'): EloquentBuilder|QueryBuilder
    {
        self::assertMysql($builder, 'whereFullText');
        $matchSql = self::buildMatchAgainst($builder, $columns, $mode);

        return $builder->whereRaw("{$matchSql} > 0", [$keyword], $boolean);
    }

    /**
     * 实现 orderByFullTextRelevance（相关度排序）
     */
    public static function orderByFullTextRelevance(EloquentBuilder|QueryBuilder $builder, array|string $columns, string $keyword, string $mode = 'natural', string $direction = 'desc'): EloquentBuilder|QueryBuilder
    {
        self::assertMysql($builder, 'orderByFullTextRelevance');
        $matchSql = self::buildMatchAgainst($builder, $columns, $mode);
        $direction = self::assertDirection($direction);

        return $builder->orderByRaw("{$matchSql} {$direction}", [$keyword]);
    }

    /**
     * 实现 replaceString
     */
    public static function replaceString(EloquentBuilder|QueryBuilder $builder, string $column, string $search, string $replace, string $alias = 'replaced'): EloquentBuilder|QueryBuilder
    {
        self::assertMysql($builder, 'replaceString');
        $wrapped = self::wrapIdentifier($builder, self::assertValidIdentifier($column));
        $alias = self::assertValidIdentifier($alias, 'alias');

        return $builder->selectRaw("REPLACE({$wrapped}, ?, ?) AS ".self::wrapIdentifier($builder, $alias), [$search, $replace]);
    }

    /**
     * 实现 trimString
     */
    public static function trimString(EloquentBuilder|QueryBuilder $builder, string $column, string $alias = 'trimmed'): EloquentBuilder|QueryBuilder
    {
        self::assertMysql($builder, 'trimString');
        $wrapped = self::wrapIdentifier($builder, self::assertValidIdentifier($column));
        $alias = self::assertValidIdentifier($alias, 'alias');

        return $builder->selectRaw("TRIM({$wrapped}) AS ".self::wrapIdentifier($builder, $alias));
    }

    /**
     * 实现 lowerString
     */
    public static function lowerString(EloquentBuilder|QueryBuilder $builder, string $column, string $alias = 'lower_val'): EloquentBuilder|QueryBuilder
    {
        self::assertMysql($builder, 'lowerString');
        $wrapped = self::wrapIdentifier($builder, self::assertValidIdentifier($column));
        $alias = self::assertValidIdentifier($alias, 'alias');

        return $builder->selectRaw("LOWER({$wrapped}) AS ".self::wrapIdentifier($builder, $alias));
    }

    /**
     * 实现 upperString
     */
    public static function upperString(EloquentBuilder|QueryBuilder $builder, string $column, string $alias = 'upper_val'): EloquentBuilder|QueryBuilder
    {
        self::assertMysql($builder, 'upperString');
        $wrapped = self::wrapIdentifier($builder, self::assertValidIdentifier($column));
        $alias = self::assertValidIdentifier($alias, 'alias');

        return $builder->selectRaw("UPPER({$wrapped}) AS ".self::wrapIdentifier($builder, $alias));
    }

    /**
     * 实现 reverseString
     */
    public static function reverseString(EloquentBuilder|QueryBuilder $builder, string $column, string $alias = 'reversed'): EloquentBuilder|QueryBuilder
    {
        self::assertMysql($builder, 'reverseString');
        $wrapped = self::wrapIdentifier($builder, self::assertValidIdentifier($column));
        $alias = self::assertValidIdentifier($alias, 'alias');

        return $builder->selectRaw("REVERSE({$wrapped}) AS ".self::wrapIdentifier($builder, $alias));
    }

    /**
     * 实现 padString（LPAD / RPAD）
     */
    public static function padString(EloquentBuilder|QueryBuilder $builder, string $column, int $length, string $padString, string $position = 'left', string $alias = 'padded'): EloquentBuilder|QueryBuilder
    {
        self::assertMysql($builder, 'padString');
        $wrapped = self::wrapIdentifier($builder, self::assertValidIdentifier($column));
        $alias = self::assertValidIdentifier($alias, 'alias');

        $position = strtolower(trim($position));
        if (! in_array($position, ['left', 'right'], true)) {
            throw new \InvalidArgumentException('padString 填充位置必须为 left 或 right');
        }
        $function = $position === 'left' ? 'LPAD' : 'RPAD';

        return $builder->selectRaw("{$function}({$wrapped}, ?, ?) AS ".self::wrapIdentifier($builder, $alias), [$length, $padString]);
    }

    /**
     * 实现 whereSoundex / orWhereSoundex
     */
    public static function whereSoundex(EloquentBuilder|QueryBuilder $builder, string $column, string $value, string $boolean = 'and'): EloquentBuilder|QueryBuilder
    {
        self::assertMysql($builder, 'whereSoundex');
        $wrapped = self::wrapIdentifier($builder, self::assertValidIdentifier($column));

        return $builder->whereRaw("SOUNDEX({$wrapped}) = SOUNDEX(?)", [$value], $boolean);
    }

    /**
     * 构建 MATCH...AGAINST 表达式
     *
     * @param EloquentBuilder|QueryBuilder $builder 查询构建器
     * @param array<int, string>|string $columns 参与全文检索的字段
     * @param string $mode 检索模式: natural|boolean|expansion
     * @return string MATCH(列...) AGAINST(? MODE) 表达式（含 ? 占位符）
     *
     * @throws \InvalidArgumentException 列名非法或模式不支持时抛出
     */
    protected static function buildMatchAgainst(EloquentBuilder|QueryBuilder $builder, array|string $columns, string $mode = 'natural'): string
    {
        $mode = strtolower(trim($mode));
        if (! isset(self::FULLTEXT_MODES[$mode])) {
            throw new \InvalidArgumentException(
                sprintf('非法的全文检索模式: "%s"。仅允许 natural / boolean / expansion。', $mode)
            );
        }

        $wrappedColumns = [];
        foreach ((array) $columns as $column) {
            $wrappedColumns[] = self::wrapIdentifier($builder, self::assertValidIdentifier($column));
        }

        return 'MATCH('.implode(', ', $wrappedColumns).') AGAINST (? '.self::FULLTEXT_MODES[$mode].')';
    }
}
