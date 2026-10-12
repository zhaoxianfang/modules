<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Support\Facades\DB;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * 数据抽样查询宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 *
 * 提供高效的数据抽样功能：
 * - sample: 基于百分比的表级随机抽样（RAND() / random()）
 * - randomSample: 基于窗口函数的精确随机抽样（支持种子可重复）
 * - stratifiedSample: 分层抽样（按分层列分别随机取 N）
 * - systematicSample: 系统抽样（等距抽样，基于主键取模）
 *
 * 随机性统一经 {@see BaseMacro::randomFunction()} 生成（MySQL RAND() / PostgreSQL random()）；
 * 种子可重复的确定性随机序使用跨库通用的 md5(CONCAT(主键, 种子)) 实现，MySQL 与 PostgreSQL 行为一致。
 * 安全性：抽样列名 / 主键经白名单校验并由 grammar wrap；随机种子走参数绑定。
 *
 * @package zxf\Modules\BuilderQuery\WindowMacros
 * @version 3.0.0
 * @requires MySQL 8.0+ / PostgreSQL 15+
 */
class TableSampleMacro extends BaseMacro
{

    /**
     * 注册所有抽样宏
     *
     * @return void
     */
    public static function register(): void
    {
        TableSampleMacro::registerTableSample();
        TableSampleMacro::registerRandomSample();
        TableSampleMacro::registerStratifiedSample();
        TableSampleMacro::registerSystematicSample();
    }

    /**
     * 注册 TABLESAMPLE 方法
     */
public static function registerTableSample(): void
    {
        /**
         * TABLESAMPLE - 表级抽样
         *
         * 从表中按指定百分比或行数随机抽取样本
         * MySQL 8.0+ 对 InnoDB 表的抽样有进一步优化
         *
         * @param float $percentage 抽样百分比 (0-100)
         * @param string $method 抽样方法: 'random'|'system'
         * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 随机抽取10%的数据进行分析
         * User::query()->sample(10)->get();
         *
         * // 系统抽样5%的数据
         * Log::query()->sample(5, 'system')->get();
         *
         * // 快速估算统计值（基于样本）
         * $avgAge = User::query()->sample(1)->avg('age');
         */
        static::define('sample', function (
            float $percentage,
            string $method = 'random'
        ): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
            $model = BaseMacro::modelOf($this);
            $table = $model->getTable();

            // 百分比需大于 0，否则 MOD(pk, ceil(100/0)) 会除零
            if ($percentage <= 0 || $percentage > 100) {
                throw new \InvalidArgumentException('sample 抽样百分比必须大于 0 且不超过 100');
            }
            $percentage = max(0, min(100, $percentage));

            if ($method === 'system') {
                // 系统抽样：使用主键的模运算
                $mod = (int) ceil(100 / $percentage);
                $primaryKey = BaseMacro::assertValidIdentifier($model->getKeyName(), 'primaryKey');
                $wrappedPk = BaseMacro::wrapIdentifier($this, $primaryKey);

                return $this->whereRaw("MOD({$wrappedPk}, ?) = 0", [$mod]);
            }

            // 随机抽样：使用数据库随机函数（MySQL RAND() / PostgreSQL random()）
            $randomFn = BaseMacro::randomFunction($this);
            return $this->whereRaw("{$randomFn} * 100 <= ?", [$percentage]);
        });
    }

    /**
     * 注册随机抽样方法
     */
public static function registerRandomSample(): void
    {
        /**
         * randomSample - 精确随机抽样（指定样本量）
         *
         * 使用窗口函数实现精确的随机抽样，保证返回指定数量的记录
         *
         * @param int $sampleSize 样本大小
         * @param string|null $seed 随机种子（可重复抽样）
         * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 精确抽取100条随机记录
         * User::query()->randomSample(100)->get();
         *
         * // 使用种子保证可重复性
         * Survey::query()->randomSample(500, 'seed_2024')->get();
         *
         * // 带条件的随机抽样
         * Order::query()->where('status', 'completed')->randomSample(50)->get();
         */
        static::define('randomSample', function (
            int $sampleSize,
            ?string $seed = null
        ): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
            $model = BaseMacro::modelOf($this);
            $table = $model->getTable();
            $primaryKey = BaseMacro::assertValidIdentifier($model->getKeyName(), 'primaryKey');
            $wrappedPk = BaseMacro::wrapIdentifier($this, $primaryKey);

            if ($seed !== null) {
                // 跨库通用的确定性随机序：以 (主键, 种子) 的 md5 作为确定性伪随机排序键，MySQL 与 PostgreSQL 行为一致。
                $randExpr = "ROW_NUMBER() OVER (ORDER BY md5(CONCAT({$wrappedPk}, ?)))";
                $randBindings = [$seed];
            } else {
                $randExpr = "ROW_NUMBER() OVER (ORDER BY " . BaseMacro::randomFunction($this) . ")";
                $randBindings = [];
            }

            // 构建子查询：给每行分配随机排名。
            // 深拷贝当前查询（含全部 where/join 条件），但清空列选择、排序与分页，
            // 避免丢失调用者已添加的过滤条件，同时不影响 ROW_NUMBER 的随机顺序。
            $subQuery = $model->newQuery()->setQuery(clone BaseMacro::queryOf($this));
            BaseMacro::queryOf($subQuery)->columns = null;
            BaseMacro::queryOf($subQuery)->limit = null;
            BaseMacro::queryOf($subQuery)->offset = null;
            BaseMacro::queryOf($subQuery)->orders = null;
            $subQuery->select("{$table}.*")
                ->selectRaw("{$randExpr} AS __sample_rank", $randBindings);

            $subSql = $subQuery->toSql();
            $bindings = $subQuery->getBindings();

            // 包装并限制样本量
            return $model->newQuery()
                ->fromRaw("({$subSql}) AS sampled", $bindings)
                ->where('__sample_rank', '<=', $sampleSize);
        });
    }

    /**
     * 注册分层抽样方法
     */
public static function registerStratifiedSample(): void
    {
        /**
         * stratifiedSample - 分层抽样
         *
         * 按指定列分层，每层抽取指定数量或比例的样本
         *
         * @param string $stratumColumn 分层列
         * @param int $samplesPerStratum 每层样本数
         * @param string|null $seed 随机种子
         * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 按性别分层，每层抽取50人
         * User::query()->stratifiedSample('gender', 50)->get();
         *
         * // 按地区分层抽样（保证各地区代表性）
         * Customer::query()->stratifiedSample('region', 100, 'audit_2024')->get();
         */
        static::define('stratifiedSample', function (
            string $stratumColumn,
            int $samplesPerStratum,
            ?string $seed = null
        ): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
            $model = BaseMacro::modelOf($this);
            $table = $model->getTable();
            $primaryKey = BaseMacro::assertValidIdentifier($model->getKeyName(), 'primaryKey');
            $wrappedPk = BaseMacro::wrapIdentifier($this, $primaryKey);
            $stratumColumn = BaseMacro::assertValidIdentifier($stratumColumn, 'stratum');
            $wrappedStratum = BaseMacro::wrapIdentifier($this, $stratumColumn);

            if ($seed !== null) {
                // 跨库通用的确定性随机序：以 (主键, 种子) 的 md5 作为确定性伪随机排序键，MySQL 与 PostgreSQL 行为一致。
                $randExpr = "ROW_NUMBER() OVER (PARTITION BY {$wrappedStratum} ORDER BY md5(CONCAT({$wrappedPk}, ?)))";
                $randBindings = [$seed];
            } else {
                $randExpr = "ROW_NUMBER() OVER (PARTITION BY {$wrappedStratum} ORDER BY " . BaseMacro::randomFunction($this) . ")";
                $randBindings = [];
            }

            // 构建分层抽样子查询（深拷贝当前查询条件，并清空列选择、排序与分页）
            $subQuery = $model->newQuery()->setQuery(clone BaseMacro::queryOf($this));
            BaseMacro::queryOf($subQuery)->columns = null;
            BaseMacro::queryOf($subQuery)->limit = null;
            BaseMacro::queryOf($subQuery)->offset = null;
            BaseMacro::queryOf($subQuery)->orders = null;
            $subQuery->select("{$table}.*")
                ->selectRaw("{$randExpr} AS __stratum_rank", $randBindings);

            $subSql = $subQuery->toSql();
            $bindings = $subQuery->getBindings();

            return $model->newQuery()
                ->fromRaw("({$subSql}) AS stratified", $bindings)
                ->where('__stratum_rank', '<=', $samplesPerStratum);
        });
    }

    /**
     * 注册系统抽样方法
     */
public static function registerSystematicSample(): void
    {
        /**
         * systematicSample - 系统抽样（等距抽样）
         *
         * 按固定间隔抽取样本，适合有序数据
         *
         * @param int $interval 抽样间隔
         * @param int $startOffset 起始偏移量（默认随机）
         * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 每隔10条抽取1条
         * Log::query()->systematicSample(10)->get();
         *
         * // 从第5条开始，每隔20条抽取
         * Order::query()->systematicSample(20, 5)->get();
         *
         * // 按时间顺序的系统抽样
         * Event::query()->orderBy('created_at')->systematicSample(100)->get();
         */
        static::define('systematicSample', function (
            int $interval,
            ?int $startOffset = null
        ): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
            $model = BaseMacro::modelOf($this);

            // 抽样间隔必须大于 0，否则取模运算会除零、rand 区间非法
            if ($interval <= 0) {
                throw new \InvalidArgumentException('systematicSample 抽样间隔必须大于 0');
            }

            $primaryKey = BaseMacro::assertValidIdentifier($model->getKeyName(), 'primaryKey');
            $wrappedPk = BaseMacro::wrapIdentifier($this, $primaryKey);

            $offset = $startOffset ?? rand(0, $interval - 1);

            // 使用主键模运算实现等距抽样
            return $this->whereRaw(
                "({$wrappedPk} + ?) % ? = 0",
                [$offset, $interval]
            );
        });
    }
}
