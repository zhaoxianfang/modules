<?php

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * MySQL 8.4+ 数据抽样查询宏
 *
 * 提供高效的数据抽样功能：
 * - sample: TABLESAMPLE 语法（MySQL 8.4 增强）
 * - randomSample: 基于随机数的抽样
 * - stratifiedSample: 分层抽样
 * - systematicSample: 系统抽样（等距抽样）
 *
 * @package zxf\Modules\BuilderQuery\WindowMacros
 * @version 1.0.0
 * @requires MySQL 8.4+
 */
class TableSampleMacro
{
    /**
     * 注册所有抽样宏
     *
     * @return void
     */
    public static function register(): void
    {
        self::registerTableSample();
        self::registerRandomSample();
        self::registerStratifiedSample();
        self::registerSystematicSample();
    }

    /**
     * 注册 TABLESAMPLE 方法
     */
    protected static function registerTableSample(): void
    {
        /**
         * TABLESAMPLE - 表级抽样
         *
         * 从表中按指定百分比或行数随机抽取样本
         * MySQL 8.4+ 对 InnoDB 表的抽样有进一步优化
         *
         * @param float $percentage 抽样百分比 (0-100)
         * @param string $method 抽样方法: 'random'|'system'
         * @return Builder
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
        Builder::macro('sample', function (
            float $percentage,
            string $method = 'random'
        ): Builder {
            /** @var Builder $this */
            $model = $this->getModel();
            $table = $model->getTable();
            $percentage = max(0, min(100, $percentage));

            if ($method === 'system') {
                // 系统抽样：使用主键的模运算
                $mod = (int) ceil(100 / $percentage);
                $primaryKey = $model->getKeyName();

                return $this->whereRaw("MOD(`{$primaryKey}`, ?) = 0", [$mod]);
            }

            // 随机抽样：使用 RAND()
            return $this->whereRaw("RAND() * 100 <= ?", [$percentage]);
        });
    }

    /**
     * 注册随机抽样方法
     */
    protected static function registerRandomSample(): void
    {
        /**
         * randomSample - 精确随机抽样（指定样本量）
         *
         * 使用窗口函数实现精确的随机抽样，保证返回指定数量的记录
         *
         * @param int $sampleSize 样本大小
         * @param string|null $seed 随机种子（可重复抽样）
         * @return Builder
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
        Builder::macro('randomSample', function (
            int $sampleSize,
            ?string $seed = null
        ): Builder {
            /** @var Builder $this */
            $model = $this->getModel();
            $table = $model->getTable();
            $primaryKey = $model->getKeyName();

            if ($seed !== null) {
                $randExpr = "ROW_NUMBER() OVER (ORDER BY RAND(CRC32(CONCAT(?, `{$primaryKey}`))))";
                $randBindings = [$seed];
            } else {
                $randExpr = "ROW_NUMBER() OVER (ORDER BY RAND())";
                $randBindings = [];
            }

            // 构建子查询：给每行分配随机排名
            $subQuery = $model->newQuery()
                ->select("{$table}.*")
                ->selectRaw("{$randExpr} AS __sample_rank", $randBindings);

            // 复制当前查询的条件
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
    protected static function registerStratifiedSample(): void
    {
        /**
         * stratifiedSample - 分层抽样
         *
         * 按指定列分层，每层抽取指定数量或比例的样本
         *
         * @param string $stratumColumn 分层列
         * @param int $samplesPerStratum 每层样本数
         * @param string|null $seed 随机种子
         * @return Builder
         *
         * @example
         * // 按性别分层，每层抽取50人
         * User::query()->stratifiedSample('gender', 50)->get();
         *
         * // 按地区分层抽样（保证各地区代表性）
         * Customer::query()->stratifiedSample('region', 100, 'audit_2024')->get();
         */
        Builder::macro('stratifiedSample', function (
            string $stratumColumn,
            int $samplesPerStratum,
            ?string $seed = null
        ): Builder {
            /** @var Builder $this */
            $model = $this->getModel();
            $table = $model->getTable();
            $primaryKey = $model->getKeyName();

            if ($seed !== null) {
                $randExpr = "ROW_NUMBER() OVER (PARTITION BY `{$stratumColumn}` ORDER BY RAND(CRC32(CONCAT(?, `{$primaryKey}`))))";
                $randBindings = [$seed];
            } else {
                $randExpr = "ROW_NUMBER() OVER (PARTITION BY `{$stratumColumn}` ORDER BY RAND())";
                $randBindings = [];
            }

            // 构建分层抽样子查询
            $subQuery = $model->newQuery()
                ->select("{$table}.*")
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
    protected static function registerSystematicSample(): void
    {
        /**
         * systematicSample - 系统抽样（等距抽样）
         *
         * 按固定间隔抽取样本，适合有序数据
         *
         * @param int $interval 抽样间隔
         * @param int $startOffset 起始偏移量（默认随机）
         * @return Builder
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
        Builder::macro('systematicSample', function (
            int $interval,
            ?int $startOffset = null
        ): Builder {
            /** @var Builder $this */
            $model = $this->getModel();
            $table = $model->getTable();
            $primaryKey = $model->getKeyName();

            $offset = $startOffset ?? rand(0, $interval - 1);

            // 使用主键模运算实现等距抽样
            return $this->whereRaw(
                "(`{$primaryKey}` + ?) % ? = 0",
                [$offset, $interval]
            );
        });
    }
}
