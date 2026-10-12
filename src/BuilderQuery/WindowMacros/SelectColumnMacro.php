<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * 列选择纪律宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 *
 * 基于 study_mysql「04-performance / 01-query-rewrite-antipatterns」「覆盖索引」章节系统化封装。
 * `SELECT *` 是生产环境最常见的隐性性能与资源杀手：放大 I/O、内存、网络带宽，并使覆盖索引失效。
 * 本模块把「只取需要的列」「排除冗余列」「只取主键」抽象为语义化宏，从源头减少结果集体积，
 * 直击用户关心的「系统资源占用高 / 卡死」问题。双构建器（ORM/DB）均可用。
 *
 * 提供的宏：
 *  - selectOnly：只保留指定列（替换当前 select 列表，等价于精确列投影）
 *  - selectExcept：从当前显式列中剔除指定列（需先显式 select；MySQL 不支持 SELECT * EXCEPT）
 *  - selectPrimaryKey：只取主键列（用于「仅判断存在性 / 仅取 ID 列表」等轻量场景）
 *
 * @package zxf\Modules\BuilderQuery\WindowMacros
 * @version 3.2.0
 * @requires MySQL 8.0+ / PostgreSQL 15+
 */
class SelectColumnMacro extends BaseMacro
{
    /** 注册所有列选择宏。 */
    public static function register(): void
    {
        self::registerSelectOnly();
        self::registerSelectExcept();
        self::registerSelectPrimaryKey();
    }

    /**
     * 注册 selectOnly。
     */
    public static function registerSelectOnly(): void
    {
        /**
         * 只保留指定列（精确投影，替代 SELECT *）。
         *
         * 直接替换当前 select 列表为该批列；若此前已 select 多列，会被本宏覆盖为精确列集。
         * 所有列名经白名单校验并 wrap，支持 `表.列` 形式。
         *
         * @param string|array $columns 需要保留的列（可多列）
         * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 列表页只取展示所需字段，避免拉取 body/text 等大字段
         * Article::query()->selectOnly(['id','title','created_at'])->paginate(20);
         * DB::table('users')->selectOnly(['id','name'])->get();
         *
         * @scenario 列表/接口只返回必要字段，减少 I/O 与内存，并让覆盖索引生效。
         */
        static::define('selectOnly', function (string|array $columns): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
            $builder = $this;
            $wrapped = [];
            foreach ((array) $columns as $column) {
                $wrapped[] = BaseMacro::wrapColumn($builder, $column);
            }
            if ($wrapped === []) {
                throw new \InvalidArgumentException('selectOnly 至少需要一列。');
            }

            return $this->select($wrapped);
        });
    }

    /**
     * 注册 selectExcept。
     */
    public static function registerSelectExcept(): void
    {
        /**
         * 从当前「显式」选择列中剔除指定列（其余保留）。
         *
         * 注意：本宏作用于已显式 select 的列集合；若当前为 SELECT *（未显式列），
         * MySQL/PostgreSQL 无统一 `SELECT * EXCEPT` 语法，故直接抛异常提示先显式 select。
         * 比对按列的基础名（忽略表前缀与别名），如 'body' 会剔除 `articles`.`body` 与 `body AS b`。
         *
         * @param string|array $columns 需要剔除的列（按基础列名匹配）
         * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 已 select 多列后，剔除大字段 text
         * Article::query()->select(['id','title','body','created_at'])->selectExcept('body')->get();
         *
         * @scenario 复用既有 select 列表但排除个别大字段（避免改一长串列名）。
         */
        static::define('selectExcept', function (string|array $columns): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
            $query = BaseMacro::queryOf($this);
            $current = $query->columns;

            if ($current === null || $current === [] || $current === ['*']) {
                throw new \RuntimeException('selectExcept 需配合显式 select 使用（MySQL/PostgreSQL 不支持 SELECT * EXCEPT）。请先调用 select([...]) 或 selectOnly([...])。');
            }

            $dropBases = [];
            foreach ((array) $columns as $column) {
                $dropBases[] = strtolower(trim((string) $column, '`"'));
            }

            $keep = [];
            foreach ((array) $current as $col) {
                $base = SelectColumnMacro::columnBaseName((string) $col);
                if (!in_array($base, $dropBases, true)) {
                    $keep[] = $col;
                }
            }

            return $this->select($keep);
        });
    }

    /**
     * 注册 selectPrimaryKey。
     */
    public static function registerSelectPrimaryKey(): void
    {
        /**
         * 只取主键列（轻量投影）。
         *
         * 适用于「仅判断存在性 / 仅取 ID 列表 / 仅做关联键集合」等不需要完整字段的场景，
         * 最大限度减少结果集体积。主键列默认取模型主键，DB 模式回退为 'id'。
         *
         * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 仅取 ID 集合用于后续 in 查询，避免拉取整行
         * $ids = Order::query()->where('status', 1)->selectPrimaryKey()->pluck('id');
         *
         * @scenario 仅需主键/关联键的轻量查询，显著降低 I/O 与内存占用。
         */
        static::define('selectPrimaryKey', function (): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
            $key = BaseMacro::keyName($this);

            return $this->select([BaseMacro::wrapColumn($this, $key)]);
        });
    }

    /**
     * 提取列的基础名（忽略表前缀与别名），用于 selectExcept 的剔除匹配。
     *
     * 处理以下形态：
     *  - `` `articles`.`body` `` → body
     *  - `articles.body` → body
     *  - `body AS b` / `body as b` → body
     *  - `body` → body
     *
     * @param string $column 已 wrap 或原始的列标识
     * @return string 小写基础列名（无法解析时回退为去除引号后的整体）
     */
    public static function columnBaseName(string $column): string
    {
        $col = str_replace(['`', '"', '[', ']'], '', $column);
        $col = trim($col);

        // 先剥离别名：col AS alias
        if (preg_match('/\s+AS\s+\w+$/i', $col, $m)) {
            $col = preg_replace('/\s+AS\s+\w+$/i', '', $col);
        }

        // 再取表前缀后的部分（最后的 . 之后）
        if (($pos = strrpos($col, '.')) !== false) {
            $col = substr($col, $pos + 1);
        }

        $col = trim($col);

        return strtolower($col === '' ? trim((string) $column, '`"') : $col);
    }
}
