<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * 数值 / 比较运算宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 * 基于 study_mysql「数学 / 比较函数」章节系统化封装：把常见的 NULL 安全比较、取值夹紧、
 * 多级兜底、加权排序、精度控制等模式抽象为语义化宏，避免在各处手写易错、易注入的裸 SQL。
 * 提供的宏：
 *  - whereNullSafeEqual：NULL 安全等值比较（MySQL <=> / PG IS NOT DISTINCT FROM）
 *  - selectClamp：将列值夹紧到 [min, max] 区间（GREATEST + LEAST），常用于评分/百分比封顶
 *  - selectCoalesce：多级兜底取值（COALESCE 链），末位为默认值
 *  - orderByWeighted：多列按权重线性组合的加权排序（搜索综合打分）
 *  - selectRound：四舍五入 / 截断到指定小数位（ROUND / TRUNCATE）
 * 安全性：列名 / 别名经白名单校验并由 grammar wrap；所有字面量一律参数绑定。
 *
 * @version 3.1.0
 *
 * @requires MySQL 8.0+ / PostgreSQL 15+
 *
 * @scenario 数值 / 比较运算宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 */
class MathMacro extends BaseMacro
{
    /**
     *  注册所有数值运算宏。
     *
     * @scenario 注册所有数值运算宏。
     */
    public static function register(): void
    {
        self::registerNullSafe();
        self::registerClamp();
        self::registerCoalesce();
        self::registerWeighted();
        self::registerRound();
    }

    /**
     * 注册 NULL 安全比较宏。
     *
     * @scenario 注册 NULL 安全比较宏。
     */
    public static function registerNullSafe(): void
    {
        /**
         * NULL 安全等值比较筛选。
         * 与普通 `=` 不同：当两侧都为 NULL 时返回真，一侧为 NULL 一侧非 NULL 时返回假，
         * 语义等价于「IS NOT DISTINCT FROM」，避免 `col = ?` 在 NULL 上永远不成立导致的漏判。
         *
         * @param  string  $column  比较列
         * @param  mixed  $value  比较值（可传 null 以匹配 NULL 行）
         * @param  string  $boolean  连接条件 and|or
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 找出 parent_id 为 NULL 的顶层记录（NULL = NULL 判定为真）
         * Category::query()->whereNullSafeEqual('parent_id', null)->get();
         * // 与普通 where('parent_id', null) 不同，后者会被 Laravel 转成 IS NULL
         *
         * @scenario 需要显式区分「值为 NULL」与「值不匹配」的精确比对场景。
         */
        static::define('whereNullSafeEqual', function (
            string $column,
            mixed $value,
            string $boolean = 'and'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrapped = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            [$sql, $bindings] = BaseMacro::nullSafeEqualsExpr($this, $wrapped, $value);
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

            return $this->{$method}($sql, $bindings);
        });
    }

    /**
     * 注册区间夹紧宏。
     *
     * @scenario 注册区间夹紧宏。
     */
    public static function registerClamp(): void
    {
        /**
         * 将列值夹紧（clamp）到 [min, max] 区间后作为字段返回。
         * 等价于 `GREATEST(min, LEAST(max, col))`，用于评分封顶、百分比限制、坐标范围约束等。
         *
         * @param  string  $column  目标列
         * @param  mixed  $min  下限
         * @param  mixed  $max  上限（须 >= min）
         * @param  string  $alias  返回字段别名（默认 clamped）
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 将折扣率限制在 0~0.5，避免脏数据导致超过 5 折
         * Product::query()->selectClamp('discount', 0, 0.5, 'safe_discount')->get();
         *
         * @scenario 输出前对数值做安全封顶；相比在应用层处理，数据库层一次性完成更可靠。
         */
        static::define('selectClamp', function (
            string $column,
            mixed $min,
            mixed $max,
            string $alias = 'clamped'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            if ($min > $max) {
                throw new \InvalidArgumentException('selectClamp 的下限必须大于等于上限。');
            }
            $wrapped = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            $expr = "GREATEST(? , LEAST(? , {$wrapped}))";

            return $this->selectRaw("{$expr} AS {$alias}", [$min, $max]);
        });
    }

    /**
     * 注册多级兜底宏。
     *
     * @scenario 注册多级兜底宏。
     */
    public static function registerCoalesce(): void
    {
        /**
         * 多级兜底取值（COALESCE 链），取首个非 NULL 值，末位为默认值。
         *
         * @param  array  $columns  候补列（按顺序，前者优先）
         * @param  mixed  $default  最终默认值（走绑定）
         * @param  string  $alias  返回字段别名（默认 value）
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 优先取用户昵称，其次取用户名，最后兜底为「匿名用户」
         * User::query()->selectCoalesce(['nickname','username'], '匿名用户', 'display_name')->get();
         *
         * @scenario 多来源字段合并展示（如 昵称 > 用户名 > 默认）、配置多级回退。
         */
        static::define('selectCoalesce', function (
            array $columns,
            mixed $default,
            string $alias = 'value'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $builder = $this;
            $wrapped = array_map(
                static function (string $c) use ($builder) {
                    return BaseMacro::wrapIdentifier($builder, BaseMacro::assertValidIdentifier($c, 'column'));
                },
                $columns
            );
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            [$sql, $bindings] = BaseMacro::coalesceChainExpr($this, $wrapped, $default);

            return $this->selectRaw("{$sql} AS {$alias}", $bindings);
        });
    }

    /**
     * 注册加权排序宏。
     *
     * @scenario 注册加权排序宏。
     */
    public static function registerWeighted(): void
    {
        /**
         * 多列按权重线性组合的加权排序（综合打分排序）。
         *
         * @param  array<int,array{column:string,weight:float}>  $items  每项 = ['column'=>列名, 'weight'=>权重]
         * @param  string  $direction  排序方向 desc（默认）| asc
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 热度权重 0.3、销量权重 0.2、评分权重 0.5 综合排序
         * Post::query()->orderByWeighted([
         *     ['column' => 'hot',   'weight' => 0.3],
         *     ['column' => 'sales', 'weight' => 0.2],
         *     ['column' => 'score', 'weight' => 0.5],
         * ])->get();
         *
         * @scenario 搜索结果 / 推荐列表的复合排序；相比硬编码 `ORDER BY a*0.3 + b*0.2` 更易维护。
         */
        static::define('orderByWeighted', function (
            array $items,
            string $direction = 'desc'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrappedItems = [];
            foreach ($items as $item) {
                $wrappedItems[] = [
                    'column' => BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($item['column'], 'column')),
                    'weight' => (float) ($item['weight'] ?? 1.0),
                ];
            }
            $direction = BaseMacro::assertDirection($direction);
            [$sql, $bindings] = BaseMacro::weightedOrderFragment($this, $wrappedItems);

            return $this->orderByRaw("{$sql} {$direction}", $bindings);
        });
    }

    /**
     * 注册精度控制宏。
     *
     * @scenario 注册精度控制宏。
     */
    public static function registerRound(): void
    {
        /**
         * 将列值四舍五入 / 截断到指定小数位后作为字段返回。
         *
         * @param  string  $column  目标列
         * @param  int  $decimals  小数位数（默认 2）
         * @param  bool  $truncate  是否截断（true=TRUNCATE 直接舍去，false=ROUND 四舍五入）
         * @param  string  $alias  返回字段别名（默认 rounded）
         * @return Builder|\Illuminate\Database\Query\Builder
         *
         * @example
         * // 金额截断到 2 位小数，避免浮点误差
         * Order::query()->selectRound('amount', 2, true, 'amount_2f')->get();
         *
         * @scenario 金额 / 比率等需要固定精度的展示或聚合场景。
         */
        static::define('selectRound', function (
            string $column,
            int $decimals = 2,
            bool $truncate = false,
            string $alias = 'rounded'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $wrapped = BaseMacro::wrapIdentifier($this, BaseMacro::assertValidIdentifier($column, 'column'));
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');

            if (BaseMacro::isPostgres($this)) {
                $fn = $truncate ? 'TRUNC' : 'ROUND';
                $expr = "{$fn}({$wrapped}::numeric, ?)";
            } else {
                $fn = $truncate ? 'TRUNCATE' : 'ROUND';
                $expr = "{$fn}({$wrapped}, ?)";
            }

            return $this->selectRaw("{$expr} AS {$alias}", [$decimals]);
        });
    }
}
