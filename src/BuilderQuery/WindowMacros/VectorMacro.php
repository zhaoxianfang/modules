<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * 向量相似度检索宏（PostgreSQL pgvector · MariaDB 11.7+ · MySQL HeatWave）
 *
 * ## 为什么不直接用 Laravel 13 原生的 whereVectorSimilarTo()
 *
 * Laravel 13.32 的 `Query\Builder::whereVectorSimilarTo()` 有三个硬限制：
 *
 *  1. **不支持 MySQL**。原生实现先调 `Grammar::supportsVectorDistance()`，该方法只有
 *     `PostgresGrammar` / `MariaDbGrammar` 覆写为 true，MySqlGrammar 未实现 → 走 MySQL 会
 *     直接抛 `RuntimeException: Vector distance queries are only supported by Postgres and
 *     MariaDB.`。而 Oracle MySQL 9.x 社区版**只有 VECTOR 列类型、没有距离函数**
 *     （`VEC_*` 是 MariaDB 11.7+ 的函数，`DISTANCE()` 属 HeatWave/MySQL AI），
 *     想在 MySQL 上做距离检索只能靠 HeatWave/AI 或换后端，本组宏正是为此而生。
 *  2. **只支持余弦距离**。`compileVectorDistanceExpression()` 在 PG 侧硬编码 `<=>`、
 *     在 MariaDB 侧硬编码 `vec_distance_cosine`。想用 L2 / 内积必须绕开它手写 raw，
 *     而手写极易写错运算符与索引的对应关系（见下方「索引配对」）。
 *  3. **隐藏的外部依赖**。向量参数传字符串时，原生方法会调用
 *     `Stringable::toEmbeddings(cache: true)` 请求外部 AI 服务 —— 一个纯数据库宏里出现
 *     了网络调用，且未配置 provider 时抛出的异常与本问题毫无关联。
 *
 * 本组宏自行按方言生成 SQL，**不依赖上述原生实现**，因此在 PostgreSQL/pgvector、
 * MariaDB 11.7+、MySQL HeatWave/AI 上均可等价使用；MySQL 社区版会给出明确的迁移提示。
 *
 * ## 度量矩阵
 *
 * 后端由 {@see BaseMacro::vectorBackend()} 运行时探测，分四档（MySQL 系内部差异极大）：
 *
 * | 后端                    | cosine            | l2                   | inner            | manhattan      | 近似索引           |
 * |-------------------------|-------------------|----------------------|------------------|-----------------|--------------------|
 * | PostgreSQL + pgvector   | `<=>`             | `<->`                | `<#>`            | `<+>`（sparsevec）| HNSW / IVFFlat    |
 * | MariaDB 11.7+           | `VEC_DISTANCE_COSINE` | `VEC_DISTANCE_EUCLIDEAN` | ❌          | ❌               | VECTOR INDEX       |
 * | MySQL HeatWave / AI     | `DISTANCE(.,.,'COSINE')` | `DISTANCE(.,.,'EUCLIDEAN')` | `'DOT_PRODUCT'` | ❌        | VECTOR INDEX       |
 * | **MySQL 社区版 9.x**    | ❌ **无距离函数** | ❌                    | ❌               | ❌               | ❌ 无              |
 *
 * ★ **MySQL 社区版的现实**：Oracle MySQL 9.0+ 能建 `VECTOR` 列、能用 `STRING_TO_VECTOR()`
 *   / `VECTOR_TO_STRING()` / `VECTOR_DIM()` 存取向量，**但没有提供任何距离函数**
 *   （`DISTANCE()` 仅 HeatWave / MySQL AI 版有），也没有向量索引。因此社区版上
 *   **无法在库内做相似度检索** —— 此时本包会抛出带四种可选迁移方案的明确异常，
 *   而不是生成一段注定报 `FUNCTION ... does not exist` 的 SQL。
 *
 * 度量别名：cos/cosine/similarity、l2/euclidean、inner/ip/dot/inner_product、l1/manhattan/cityblock。
 *
 * 别名：cos/cosine/similarity、l2/euclidean、inner/ip/dot/inner_product、l1/manhattan/cityblock。
 *
 * ## 索引配对（最容易踩、也最难查的坑）
 *
 * pgvector 的近似索引（IVFFlat / HNSW）**只有在 ORDER BY 使用的运算符与索引 opclass 完全
 * 一致时才会被采用**。用 `<->`（L2）排序却只建了 `vector_cosine_ops` 索引时，查询会
 * **静默退化为全表暴力扫描** —— 结果完全正确、不报任何错，但百万行下从毫秒变成分钟。
 * MariaDB / HeatWave 的 `VECTOR INDEX` 同样按建索引时声明的 metric 决定能否命中，
 * 且**仅支持 COSINE 与 EUCLIDEAN**（HeatWave 另有 DOT_PRODUCT），用 inner（内积）在
 * MariaDB 上必然退化为全量扫描。
 *
 * 因此本类提供 {@see VectorMacro::indexAdvice()}，按「当前驱动 + 当前度量」生成正确的建索引
 * DDL 片段，避免凭记忆猜错。
 *
 * ## 双构建器友好
 *
 * 全部经 {@see BaseMacro::define()} 同时注册到 `Eloquent\Builder` 与 `Query\Builder`，
 * 链式宏一律返回 `$this`（保持调用方原有构建器类型，不会把 Eloquent 链降级成 Query 链）。
 *
 * @version 1.14.0
 *
 * @requires PostgreSQL 15+（pgvector 扩展；`l2_normalize()` 需 pgvector 0.7.0+）
 *               / MariaDB 11.7+ / MySQL HeatWave 或 MySQL AI 版
 *               （Oracle MySQL 社区版无距离函数，调用距离类宏会抛出带迁移方案的异常）
 *
 * @scenario 向量相似度搜索 / RAG 召回 / 语义检索 / 推荐系统近邻匹配 / 图文去重
 */
class VectorMacro extends BaseMacro
{
    /**
     * 注册全部向量宏。
     *
     * @scenario 注册全部向量宏（距离、相似度、KNN、维度校验、文本互转、索引调优）。
     */
    public static function register(): void
    {
        self::registerVectorDistance();
        self::registerVectorSearch();
        self::registerVectorUtilities();
    }

    /* =====================================================================
     * 1. 距离 / 相似度表达式（SELECT 列）
     * ===================================================================== */

    /**
     * 注册距离与相似度表达式宏。
     *
     * @scenario 注册距离与相似度表达式宏（addVectorDistance / addVectorSimilarity）。
     */
    public static function registerVectorDistance(): void
    {
        /**
         * 取列表达式：到**查询向量**的向量距离（越小越相似）。
         *
         * 命名说明：宏名取 `addVectorDistance` 而非更顺口的 `selectVectorDistance`，因为
         * Laravel 13 的 `Query\Builder` 已有原生 `selectVectorDistance($column, $vector, $as)`
         * —— 同名宏会被 `Macroable::__call` 遮蔽、**永远不会被调用**，而 `DB::table()` 路径
         * 会静默走进原生实现。`add*` 前缀与 Laravel 自身的 `addSelect()` 约定一致且无冲突。
         *
         * 距离无上界（l2 / manhattan）、有上界 2（cosine）；inner 度量下本宏返回 `-内积`，
         * 使「越大越相似」的方向保持一致。需要「越大越好」的分值请用
         * {@see addVectorSimilarity}。
         *
         * @param  string  $column  向量列名（标识符，经白名单校验，支持 `表.列`）
         * @param  array|\Illuminate\Support\Collection|string  $vector  查询向量（数值数组或 [x,y,z] 字面量）
         * @param  string  $metric  cosine|l2|inner|manhattan（含别名，默认 cosine）
         * @param  string  $alias  输出列别名（标识符）
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // PG 生成 (embedding <=> '[1,2,3]'::vector)；MariaDB 生成 VEC_DISTANCE_COSINE(embedding, VEC_FromText('[1,2,3]'))
         * Post::query()->addVectorDistance('embedding', [1, 2, 3], 'cosine', 'dist')->get();
         * // 欧氏距离，DB::table() 路径同样可用
         * DB::table('docs')->addVectorDistance('embedding', $vec, 'l2');
         *
         * @scenario 需要在结果集中看到「每个候选离查询向量多远」，用于打分展示、
         *           阈值调试，或作为 HAVING 过滤的输入。
         */
        static::define('addVectorDistance', function (
            string $column,
            array|\Illuminate\Support\Collection|string $vector,
            string $metric = 'cosine',
            string $alias = 'vector_distance'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            $wrapped = BaseMacro::wrapColumn($this, $column);
            // 校验顺序：先把「与数据库无关」的入参（列名、别名）全部校验完，再检查数据库能力。
            // 否则在不支持向量的库上，别名拼错这种纯代码问题也会被报成「你的数据库不支持向量」，
            // 把真正该改的那一行藏了起来。
            $safeAlias = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($alias, 'alias'));

            [$sql, $bindings] = BaseMacro::vectorDistanceFragment($this, $wrapped, $vector, $metric);

            return BaseMacro::addWindowSelect($this, "{$sql} AS {$safeAlias}")
                ->addBinding($bindings, 'select');
        });

        /**
         * 取列表达式：到**查询向量**的向量相似度（越大越相似）。
         *
         * 换算规则随度量而不同（详见 {@see BaseMacro::vectorSimilarityFragment}）：
         *  - cosine：`1 - distance`，取值 [-1,1]（完全反向为 -1）。刻意不折半到 [0,1]，
         *    否则「相似度 0.5」会从「正交」变成「无关」，让既有阈值悄悄失效。
         *  - l2 / manhattan：`1 / (1 + distance)`，落在 (0,1]。
         *  - inner：返回真实内积（可为负），能表达「方向相反」，不压缩到 (0,1]。
         *
         * @param  string  $column  向量列名（标识符）
         * @param  array|\Illuminate\Support\Collection|string  $vector  查询向量
         * @param  string  $metric  cosine|l2|inner|manhattan（含别名，默认 cosine）
         * @param  string  $alias  输出列别名（标识符）
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 余弦相似度分值（-1 ~ 1）
         * Post::query()->addVectorSimilarity('embedding', $vec, 'cosine', 'score')->get();
         *
         * @scenario 需要一个「越大越相似」的连续分值用于加权排序、多路召回结果融合
         *           （RRF / 分数加权）、或对外输出的 relevance 分。
         */
        static::define('addVectorSimilarity', function (
            string $column,
            array|\Illuminate\Support\Collection|string $vector,
            string $metric = 'cosine',
            string $alias = 'vector_similarity'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            $wrapped = BaseMacro::wrapColumn($this, $column);
            // 同 addVectorDistance：入参校验先于数据库能力检查
            $safeAlias = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($alias, 'alias'));

            [$sql, $bindings] = BaseMacro::vectorSimilarityFragment($this, $wrapped, $vector, $metric);

            return BaseMacro::addWindowSelect($this, "{$sql} AS {$safeAlias}")
                ->addBinding($bindings, 'select');
        });
    }

    /* =====================================================================
     * 2. 相似度筛选与 KNN 排序
     * ===================================================================== */

    /**
     * 注册相似度筛选与 KNN 排序宏。
     *
     * @scenario 注册相似度筛选与 KNN 排序宏（whereVectorDistance / orderByVectorSimilarity / vectorKnn）。
     */
    public static function registerVectorSearch(): void
    {
        /**
         * 按最大距离筛选（KNN 召回的半径约束）。
         *
         * 生成 `距离表达式 <= ?`；传 `$maxDistance = null` 时退化为「仅排序不过滤」，
         * 此时直接返回构建器而不追加条件，便于在可选阈值场景下省掉一次分支判断。
         *
         * 阈值语义随度量而不同，务必按所用度量选：
         *  - cosine：0 = 完全相同，0.5 = 正交，1 = 完全反向。常用召回阈值 0.3 ~ 0.5。
         *  - l2：与向量尺度强相关（1536 维 embedding 的 L2 常在 0.2 ~ 1.2），无通用阈值。
         *  - inner：**距离越小表示内积越大**，阈值需按模型分布标定。
         *
         * @param  string  $column  向量列名（标识符）
         * @param  array|\Illuminate\Support\Collection|string  $vector  查询向量
         * @param  float|null  $maxDistance  最大距离；null 表示不追加过滤条件
         * @param  string  $metric  cosine|l2|inner|manhattan（含别名，默认 cosine）
         * @param  string  $boolean  and|or（连接上一个条件）
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 召回与查询向量余弦距离 <= 0.4 的记录（并按距离升序）
         * Post::query()
         *     ->whereVectorDistance('embedding', $vec, 0.4, 'cosine')
         *     ->orderByVectorSimilarity('embedding', $vec, 'cosine')
         *     ->get();
         *
         * @scenario 先用距离阈值把候选集缩小（让近似索引只扫更小的范围），
         *           再按距离升序取 Top-K —— 这是 RAG 召回的常规两段式写法。
         */
        static::define('whereVectorDistance', function (
            string $column,
            array|\Illuminate\Support\Collection|string $vector,
            ?float $maxDistance = null,
            string $metric = 'cosine',
            string $boolean = 'and'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            if ($maxDistance === null) {
                return $this;
            }

            $wrapped = BaseMacro::wrapColumn($this, $column);
            [$sql, $bindings] = BaseMacro::vectorDistanceFragment($this, $wrapped, $vector, $metric);

            $type = strtolower($boolean) === 'or' ? 'or' : 'and';
            $this->whereRaw("{$sql} <= ?", [...$bindings, $maxDistance], $type);

            return $this;
        });

        /**
         * 按向量距离升序排序（KNN 检索的 ORDER BY）。
         *
         * 方向说明：向量检索的「更相似」恒等于「距离更小」，因此默认 `asc`；
         * `desc` 会得到「最不相似的排在最前」，通常只在需要「差异最大」时才有意义。
         *
         * ★ 索引能否命中的关键：ORDER BY 使用的运算符必须与向量索引的 opclass 完全一致。
         *   PG：`vector_l2_ops` 索引只在 ORDER BY 用 `<->` 时被采用；若索引是
         *   `vector_cosine_ops` 而本宏用 `l2`，会静默全表扫描。可用
         *   {@see VectorMacro::indexAdvice()} 生成正确的建索引语句。
         *
         * @param  string  $column  向量列名（标识符）
         * @param  array|\Illuminate\Support\Collection|string  $vector  查询向量
         * @param  string  $metric  cosine|l2|inner|manhattan（含别名，默认 cosine）
         * @param  string  $direction  asc|desc（默认 asc）
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 精确 KNN 排序（注意与索引度量保持一致）
         * Post::query()
         *     ->orderByVectorSimilarity('embedding', $vec, 'cosine')
         *     ->limit(10)->get();
         *
         * @scenario 近邻召回结果的排序；通常与 `limit()` 组合使用。
         *           注意本宏只加 ORDER BY，加了 `whereVectorDistance` 半径条件才会走近似索引。
         */
        static::define('orderByVectorSimilarity', function (
            string $column,
            array|\Illuminate\Support\Collection|string $vector,
            string $metric = 'cosine',
            string $direction = 'asc'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            $wrapped = BaseMacro::wrapColumn($this, $column);
            [$sql, $bindings] = BaseMacro::vectorDistanceFragment($this, $wrapped, $vector, $metric);

            // 绑定必须挂在 'order' 组：若被并入 'where' 组，生成的 SQL 里顺序与
            // getBindings() 的拼接顺序不符 → 绑定错位（距离值被当成别的条件用）。
            $this->addBinding($bindings, 'order');
            $this->orders[] = [
                'type' => 'Raw',
                'sql' => $sql,
                'direction' => BaseMacro::assertDirection($direction),
            ];

            return $this;
        });

        /**
         * KNN 一站式：按距离升序排序并限制 Top-K 条数。
         *
         * 等价于 `orderByVectorSimilarity(...)->limit($k)`，只是把两步合成一步，
         * 避免调用方漏掉 limit 导致「向量全表返回」的内存事故。
         *
         * @param  string  $column  向量列名（标识符）
         * @param  array|\Illuminate\Support\Collection|string  $vector  查询向量
         * @param  int  $limit  Top-K 条数（须 >= 1）
         * @param  string  $metric  cosine|l2|inner|manhattan（含别名，默认 cosine）
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 取最相似的 10 条
         * Post::query()->vectorKnn('embedding', $vec, 10, 'cosine')->get();
         *
         * @scenario RAG 召回、相似内容推荐、重复内容近邻查重的标准入口。
         *           注意：Oracle MySQL 社区版无距离函数，调用本宏会抛出带迁移方案的明确异常。
         */
        static::define('vectorKnn', function (
            string $column,
            array|\Illuminate\Support\Collection|string $vector,
            int $limit = 10,
            string $metric = 'cosine'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            if ($limit < 1) {
                throw new \InvalidArgumentException('vectorKnn 的 limit 必须 >= 1。');
            }

            VectorMacro::orderByDistance($this, $column, $vector, $metric, 'asc');

            return $this->limit($limit);
        });

        /**
         * 设置 pgvector 近似索引的检索参数（IVFFlat / HNSW 召回率调优）。
         *
         * 生成 `SET LOCAL ivfflat.probes = ?` / `SET LOCAL hnsw.ef_search = ?`。
         * `SET LOCAL` 只在当前事务内生效，事务结束后自动恢复 —— 这正是我们想要的：
         * 不希望一次「高召回」调优永久污染连接的默认行为。
         *
         * 参数含义：
         *  - `ivfflat.probes`（默认 1）：要访问的倒排列表数量。越大召回越高、越慢。
         *    建索引时 `lists` 越大，probes 需要相应增大；经验值 lists/10 起步。
         *  - `hnsw.ef_search`（默认 40）：候选队列大小。召回不足时调到 100~200。
         *
         * 仅 PostgreSQL 有效；MySQL / MariaDB 无等价参数，本宏直接抛异常而不是
         * 静默忽略（静默忽略会让人以为调优生效了）。
         *
         * @param  array{ivfflat_probes?: int, hnsw_ef_search?: int}  $params  参数（snake_case 键）
         *
         * @example
         * // 召回优先的 HNSW 检索
         * Post::query()
         *     ->vectorSearchParams(['hnsw_ef_search' => 200])
         *     ->whereVectorDistance('embedding', $vec, 0.4)
         *     ->orderByVectorSimilarity('embedding', $vec, 'cosine')
         *     ->limit(20)->get();
         *
         * @scenario 线上发现向量召回漏召回（明明有相似内容却没进结果）时，
         *           在不重建索引的前提下临时提高探测深度换召回率。
         */
        static::define('vectorSearchParams', function (array $params): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            if (! BaseMacro::isPostgres($this)) {
                throw new \InvalidArgumentException(
                    'vectorSearchParams 仅支持 PostgreSQL（依赖 pgvector 的 ivfflat.probes / hnsw.ef_search）；'
                    .'MySQL / MariaDB 没有等价的近似索引检索参数。'
                );
            }

            if ($params === []) {
                return $this;
            }

            $allowed = [
                'ivfflat_probes' => 'ivfflat.probes',
                'hnsw_ef_search' => 'hnsw.ef_search',
            ];

            foreach ($params as $key => $value) {
                $snake = strtolower((string) $key);
                if (! isset($allowed[$snake])) {
                    throw new \InvalidArgumentException(sprintf(
                        '未知的向量检索参数: "%s"。允许：ivfflat_probes / hnsw_ef_search。',
                        (string) $key
                    ));
                }

                if (! is_int($value) || $value < 1) {
                    throw new \InvalidArgumentException(sprintf(
                        '向量检索参数 %s 必须是 >= 1 的整数；收到 %s。',
                        $allowed[$snake],
                        var_export($value, true)
                    ));
                }

                // 参数名来自固定白名单、值是已校验的整数，二者均为字面量而非用户输入，
                // 因此可安全拼入 SET LOCAL；此处刻意不用绑定（SET 语句不支持 ? 占位符）。
                $this->getConnection()->statement('SET LOCAL '.$allowed[$snake].' = '.$value);
            }

            return $this;
        });
    }

    /* =====================================================================
     * 3. 向量工具宏（维度 / 文本互转 / 归一化）
     * ===================================================================== */

    /**
     * 注册向量工具宏。
     *
     * @scenario 注册向量工具宏（addVectorDimension / whereVectorDimension / addVectorText / addVectorNormalized）。
     */
    public static function registerVectorUtilities(): void
    {
        /**
         * 取列表达式：向量的维度（元素个数）。
         *
         * - PostgreSQL：`vector_dims(col)`
         * - MySQL 9.0+：`VECTOR_DIM(col)`
         *
         * @param  string  $column  向量列名（标识符）
         * @param  string  $alias  输出列别名（标识符）
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 找出历史上写入过错误维度的脏数据
         * DB::table('docs')->addVectorDimension('embedding', 'dim')
         *     ->whereRaw('dim <> ?', [1536])   // 注意：WHERE 不能用同级别名，请用 whereVectorDimension
         *     ->get();
         *
         * @scenario 排查向量数据质量问题：换 embedding 模型后旧数据的维度与新列不匹配，
         *           会导致距离计算报错或静默返回 NULL。
         */
        static::define('addVectorDimension', function (
            string $column,
            string $alias = 'vector_dim'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            // 同上：入参校验先于数据库能力检查
            $safeAlias = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($alias, 'alias'));
            $expr = BaseMacro::vectorDimensionExpr($this, BaseMacro::wrapColumn($this, $column));

            return BaseMacro::addWindowSelect($this, "{$expr} AS {$safeAlias}");
        });

        /**
         * 按维度筛选（校验列中向量维度是否等于期望值）。
         *
         * 用途：写入前批量体检，或在「同表混存多种 embedding 模型」时把当前查询限定到
         * 正确的那一批维度 —— 维度不同的两批数据放在一起做距离计算，PG 会直接报维度不匹配。
         *
         * @param  string  $column  向量列名（标识符）
         * @param  int  $dimension  期望维度（须 >= 1）
         * @param  string  $boolean  and|or（连接上一个条件）
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 只在 1536 维的数据里做近邻检索（避免与 768 维的旧数据混算）
         * Doc::query()
         *     ->whereVectorDimension('embedding', 1536)
         *     ->vectorKnn('embedding', $vec, 10, 'cosine')->get();
         *
         * @scenario 同一列里混有多种维度（历史上换过 embedding 模型）时的安全隔离。
         */
        static::define('whereVectorDimension', function (
            string $column,
            int $dimension,
            string $boolean = 'and'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            if ($dimension < 1) {
                throw new \InvalidArgumentException('whereVectorDimension 的维度必须 >= 1。');
            }

            $expr = BaseMacro::vectorDimensionExpr($this, BaseMacro::wrapColumn($this, $column));
            $type = strtolower($boolean) === 'or' ? 'or' : 'and';
            $this->whereRaw("{$expr} = ?", [$dimension], $type);

            return $this;
        });

        /**
         * 取列表达式：把向量列转成 `[1,2,3]` 形式的文本。
         *
         * - PostgreSQL：`col::text`
         * - MariaDB 11.7+：`VEC_ToText(col)`
         * - MySQL 9.0+：`VECTOR_TO_STRING(col)`（社区版亦可用，仅需向量类型支持）
         *
         * @param  string  $column  向量列名（标识符）
         * @param  string  $alias  输出列别名（标识符）
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 导出向量为可读文本，便于人工核对或做缓存 key
         * DB::table('docs')->addVectorText('embedding', 'embedding_text')->get();
         *
         * @scenario 调试期导出向量内容、生成 embedding 缓存键、
         *           或把向量搬到只支持文本字段的外部系统。
         */
        static::define('addVectorText', function (
            string $column,
            string $alias = 'vector_text'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            // 同上：入参校验先于数据库能力检查
            $safeAlias = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($alias, 'alias'));
            $expr = BaseMacro::vectorToTextExpr($this, BaseMacro::wrapColumn($this, $column));

            return BaseMacro::addWindowSelect($this, "{$expr} AS {$safeAlias}");
        });

        /**
         * 取列表达式：L2 归一化后的向量（模长恒为 1）。
         *
         * 为什么需要归一化：内积度量下，模长差异会主导结果 —— 「方向高度相似但模长差
         * 10 倍」的两个向量会被排得很远，这是内积检索最常见的误用。归一化后内积 ≡
         * 余弦相似度，且 HNSW / IVFFlat 的聚类质心才具有几何意义。
         *
         * 仅 PostgreSQL 支持（pgvector 0.7.0+ 的 `l2_normalize()`）。MySQL / MariaDB 的
         * VECTOR 没有内置范数函数（VEC_* / DISTANCE 系列只有距离与维度），且向量分量
         * 不可拆出，无法在 SQL 内等价表达归一化 —— 此时本宏抛异常并指引在应用层归一化
         * 后写入，而不是生成一个看似能跑、实则语义错误的表达式。
         *
         * @param  string  $column  向量列名（标识符）
         * @param  string  $alias  输出列别名（标识符）
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // PG：换算出「与本行内积」等价于「与本行余弦相似度」的中间结果
         * DB::table('docs')->addVectorNormalized('embedding', 'unit_vec');
         *
         * @scenario 排查内积排序异常（长向量总是排前面）；或在离线计算中核对
         *           数据是否已按内积检索的要求做过归一化。
         */
        static::define('addVectorNormalized', function (
            string $column,
            string $alias = 'vector_normalized'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             */
            // 同上：入参校验先于数据库能力检查
            $safeAlias = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($alias, 'alias'));
            $expr = BaseMacro::vectorNormalizeExpr($this, BaseMacro::wrapColumn($this, $column));

            return BaseMacro::addWindowSelect($this, "{$expr} AS {$safeAlias}");
        });
    }

    /* =====================================================================
     * 4. 公共实现与诊断
     * ===================================================================== */

    /**
     * 内部实现：按向量距离追加 ORDER BY（供 vectorKnn 复用）。
     *
     * @param  EloquentBuilder|QueryBuilder  $builder
     * @param  array|\Illuminate\Support\Collection|string  $vector
     */
    public static function orderByDistance(
        EloquentBuilder|QueryBuilder $builder,
        string $column,
        array|\Illuminate\Support\Collection|string $vector,
        string $metric,
        string $direction
    ): void {
        $wrapped = BaseMacro::wrapColumn($builder, $column);
        [$sql, $bindings] = BaseMacro::vectorDistanceFragment($builder, $wrapped, $vector, $metric);

        $builder->addBinding($bindings, 'order');
        $builder->orders[] = [
            'type' => 'Raw',
            'sql' => $sql,
            'direction' => BaseMacro::assertDirection($direction),
        ];
    }

    /**
     * 返回「当前驱动 + 当前度量」下正确的向量索引 DDL 片段与注意事项。
     *
     * 这是本组宏里最该被用到的诊断方法：向量检索慢十倍，绝大多数情况是
     * 「ORDER BY 运算符与索引 opclass 不匹配」导致近似索引未被采用，
     * 而这一点从查询结果和报错里都看不出来，只能靠核对索引定义。
     *
     * ```php
     * $advice = VectorMacro::indexAdvice($builder, 'cosine', 'embedding');
     * // PG:    CREATE INDEX ... USING hnsw (embedding vector_cosine_ops);
     * // MariaDB: CREATE VECTOR INDEX ... USING HNSW DISTANCE_METRIC=COSINE;
     * echo $advice['note'];
     * ```
     *
     * @param  EloquentBuilder|QueryBuilder  $builder  任一目标构建器（用于探测驱动）
     * @param  string  $metric  度量方式（含别名）
     * @param  string  $column  向量列名（标识符）
     * @return array{ddl: string, note: string}
     *
     * @throws \InvalidArgumentException
     */
    public static function indexAdvice(
        EloquentBuilder|QueryBuilder $builder,
        string $metric,
        string $column = 'embedding'
    ): array {
        return BaseMacro::vectorIndexAdvice($builder, $metric, $column);
    }
}
