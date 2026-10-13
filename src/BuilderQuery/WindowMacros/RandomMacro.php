<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * 随机查询宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 * 基于标准 SQL 窗口函数 ROW_NUMBER() + 数据库随机函数实现「随机取 N 条」与「分组随机取 N 条」：
 *  - MySQL：随机函数为 RAND()
 *  - PostgreSQL：随机函数为 random()
 * 通过 {@see BaseMacro::randomFunction()} 统一生成，因此同一套宏在两种数据库上均可直接使用。
 * 安全性：主键 / 分组列名经白名单校验并由 grammar wrap；随机函数由 Driver 决定，不拼接用户输入。
 *
 * @version 3.0.0
 *
 * @requires MySQL 8.0+ / PostgreSQL 15+
 *
 * @scenario 随机查询宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 */
class RandomMacro extends BaseMacro
{
    /**
     * 注册 random() 宏函数，用于随机查询记录
     * 使用方式：
     * User::where('status', 1)->random(5)->get();
     *
     * @scenario 使用方式：
     */
    public static function register()
    {
        RandomMacro::randomBaseHandle();
        RandomMacro::randomHandle();
        RandomMacro::groupRandomHandle();
    }

    public static function randomBaseHandle()
    {

        /**
         * 完整复制查询条件
         *
         * @param  QueryBuilder  $source  源查询
         * @param  QueryBuilder  $dest  目标查询
         *
         * @example
         * Model::query()->replicateQuery($s, $d)->get();
         *
         * @scenario 完整复制查询条件
         */
        static::define('replicateQuery', function (QueryBuilder $source, QueryBuilder $dest) {
            // 复制基础属性
            //    $dest->bindings = $source->bindings;
            //    $dest->columns = $source->columns;
            //    $dest->distinct = $source->distinct;
            //    $dest->from = $source->from;
            //    $dest->joins = $source->joins;
            //    $dest->wheres = $source->wheres;
            //    $dest->groups = $source->groups;
            //    $dest->havings = $source->havings;
            //    $dest->orders = $source->orders;
            //    $dest->limit = $source->limit;
            //    $dest->offset = $source->offset;
            //    $dest->unions = $source->unions;
            //    $dest->unionLimit = $source->unionLimit;
            //    $dest->unionOrders = $source->unionOrders;

            // 定义需要复制的属性列表
            $properties = [
                'bindings', 'columns', 'distinct', 'from', 'joins',
                'wheres', 'groups', 'havings', 'orders', 'limit',
                'offset', 'unions', 'unionLimit', 'unionOrders',
                'lock', 'operators', 'useWritePdo',
            ];

            foreach ($properties as $property) {
                if (property_exists($source, $property)) {
                    $dest->{$property} = RandomMacro::cloneValue($source->{$property});
                }
            }

            // 特殊处理聚合函数
            if (! empty($source->aggregate)) {
                $dest->aggregate = $source->aggregate;
                $dest->aggregate['column'] = $source->aggregate['column'] ?? '*';
            }
        });

        /**
         * 完整复制所有where条件
         *
         * @param  QueryBuilder  $source  源查询
         * @param  QueryBuilder  $dest  目标查询
         *
         * @example
         * Model::query()->replicateWheres($s, $d)->get();
         *
         * @scenario 完整复制所有where条件
         */
        static::define('replicateWheres', function (QueryBuilder $source, QueryBuilder $dest) {
            RandomMacro::copyWheres($source, $dest);

            return $dest;
        });
    }

    /**
     * 复制源查询的全部 where 条件到目标查询
     * 注意：Laravel Macroable 会将宏闭包重绑到 \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder 作用域，闭包内 `RandomMacro::` 会解析为
     * \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder 而非本类，因此复制逻辑必须抽为 public static 方法，供宏闭包与 Nested
     * 分支递归调用共用。
     *
     * @param  Builder|QueryBuilder  $source  源查询（宏调用者实际为 Eloquent \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder）
     * @param  QueryBuilder  $dest  目标查询
     *
     * @scenario 复制源查询的全部 where 条件到目标查询
     */
    public static function copyWheres(Builder|QueryBuilder $source, QueryBuilder $dest): void
    {
        foreach ($source->wheres as $where) {
            $method = $where['type'] ?? 'Basic';
            $boolean = $where['boolean'] ?? 'and';

            switch ($method) {
                case 'Basic':
                    $dest->where($where['column'], $where['operator'], $where['value'], $boolean);
                    break;
                case 'In':
                    $dest->whereIn($where['column'], $where['values'], $boolean, $where['not'] ?? false);
                    break;
                case 'NotIn':
                    $dest->whereNotIn($where['column'], $where['values'], $boolean);
                    break;
                case 'Null':
                    $dest->whereNull($where['column'], $boolean, $where['not'] ?? false);
                    break;
                case 'NotNull':
                    $dest->whereNotNull($where['column'], $boolean);
                    break;
                case 'Between':
                    $dest->whereBetween($where['column'], $where['values'], $boolean, $where['not'] ?? false);
                    break;
                case 'NotBetween':
                    $dest->whereNotBetween($where['column'], $where['values'], $boolean);
                    break;
                case 'Exists':
                    $dest->whereExists($where['query'], $boolean, $where['not'] ?? false);
                    break;
                case 'NotExists':
                    $dest->whereNotExists($where['query'], $boolean);
                    break;
                case 'Raw':
                    $dest->whereRaw($where['sql'], (array) ($where['bindings'] ?? []), $boolean);
                    break;
                case 'Nested':
                    $dest->whereNested(function ($query) use ($where) {
                        RandomMacro::copyWheres($where['query'], $query);
                    }, $boolean);
                    break;
                case 'Column':
                    $dest->whereColumn(
                        $where['first'],
                        $where['operator'],
                        $where['second'] ?? null,
                        $boolean
                    );
                    break;
                case 'Date':
                    $dest->whereDate($where['column'], $where['operator'], $where['value'], $boolean);
                    break;
                case 'Time':
                    $dest->whereTime($where['column'], $where['operator'], $where['value'], $boolean);
                    break;
                case 'Day':
                    $dest->whereDay($where['column'], $where['operator'], $where['value'], $boolean);
                    break;
                case 'Month':
                    $dest->whereMonth($where['column'], $where['operator'], $where['value'], $boolean);
                    break;
                case 'Year':
                    $dest->whereYear($where['column'], $where['operator'], $where['value'], $boolean);
                    break;
                case 'JsonContains':
                    $dest->whereJsonContains($where['column'], $where['value'], $boolean, $where['not'] ?? false);
                    break;
                case 'JsonLength':
                    $dest->whereJsonLength($where['column'], $where['operator'], $where['value'], $boolean);
                    break;
                default:
                    throw new \InvalidArgumentException("不支持的where类型: {$method}");
            }
        }
    }

    /**
     * 安全深拷贝查询构建器的属性值（替代原先的 unserialize(serialize()) 方案）。
     * 原方案用 unserialize(serialize()) 做深拷贝，存在两个严重问题：
     *  1. 性能差：序列化/反序列化大型查询状态（含 bindings/wheres）开销高；
     *  2. 致命错误：当 bindings 中含 Closure（如 whereExists 子查询闭包）时，serialize() 直接抛出
     *     "Serialization of closure is not allowed"，导致 random() / groupRandom() 在带存在子查询的
     *     查询上直接崩溃。
     * 本方法对数组做递归拷贝（PHP 数组本身为值语义，递归即可安全分离），对对象仅在可克隆时 clone
     * （Closure 等不可克隆资源型对象原样保留引用，与 Laravel 构建器自身的浅拷贝语义一致），无任何序列化。
     *
     * @scenario 1. 性能差：序列化/反序列化大型查询状态（含 bindings/wheres）开销高；
     */
    private static function cloneValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([self::class, 'cloneValue'], $value);
        }

        if (is_object($value)) {
            // Closure 不可克隆，原样保留引用（共享同一闭包实例不会影响只读复制用途）。
            return $value instanceof \Closure ? $value : clone $value;
        }

        return $value;
    }

    // 另一种实现方式
    public static function randomHandle()
    {
        /**
         * 随机查询宏
         * 基于 ROW_NUMBER() + 数据库随机函数（MySQL RAND() / PostgreSQL random()）实现高效随机取样，
         * 子查询按随机序排名后用主键 IN 回查，避免 ORDER BY RAND() 导致的全表排序与filesort。
         *
         * @param  int  $limit  返回记录数，默认 10
         * @param  string  $primaryKey  主键名，默认 'id'
         * @return Builder|QueryBuilder
         *
         * @example
         * // 随机取 5 条已发布文章
         * Article::query()->where('status', 1)->random(5)->get();
         * // 随机取 3 名学生（指定主键）
         * Student::query()->random(3, 'student_id')->get();
         *
         * @scenario 首页推荐、随机展示、抽奖、测试数据抽样等只需「随机 N 条」且无需排序稳定性的场景。
         */
        static::define('random', function (int $limit = 10, string $primaryKey = 'id') {
            /**
             * @var Builder|QueryBuilder $this
             *
             * @scenario 本类/成员说明。
             */
            $model = BaseMacro::modelOf($this);
            $table = $model->getTable();
            $primaryKey = BaseMacro::assertValidIdentifier($primaryKey, 'primaryKey');
            $wrappedPk = BaseMacro::wrapIdentifier($this, $primaryKey);

            // 构建窗口函数子查询：使用 ROW_NUMBER() + 数据库随机函数实现高效随机
            $randomFn = BaseMacro::randomFunction($this);
            $subQuery = DB::table($table)
                ->select(
                    $primaryKey,
                    DB::raw("ROW_NUMBER() OVER (ORDER BY {$randomFn}) AS rnd_rank")
                );

            // 复制所有where条件到子查询
            $this->replicateWheres(BaseMacro::queryOf($this), $subQuery);

            // 构建主查询
            return $model->newQuery()
                ->when(! empty(BaseMacro::queryOf($this)->columns), function ($query) {
                    $query->select(BaseMacro::queryOf($this)->columns);
                })
                ->whereIn("{$table}.{$primaryKey}", function ($query) use ($subQuery, $limit, $primaryKey) {
                    $query->select($primaryKey)
                        ->from(DB::raw("({$subQuery->toSql()}) AS ranked"))
                        ->mergeBindings($subQuery)
                        ->where('rnd_rank', '<=', $limit);
                });
        });
    }

    public static function groupRandomHandle()
    {

        /**
         * 分组随机查询宏
         * 在 {@see random()} 基础上增加 PARTITION BY 分组，按分组字段分别取随机 N 条（每组内独立随机）。
         *
         * @param  string  $groupColumn  分组字段名（经白名单校验）
         * @param  int  $limit  每组返回记录数，默认 10
         * @param  string  $primaryKey  主键名，默认 'id'
         * @return Builder|QueryBuilder
         *
         * @example
         * // 每个班级各随机取 2 名学生
         * Student::query()->groupRandom('class_id', 2)->get();
         * // 每个分类各随机取 3 篇推荐文章
         * Article::query()->groupRandom('category_id', 3)->get();
         *
         * @scenario 运营活动「每个分类/地区各抽一名」、排行榜分区取样等需要「分组内随机」的场景。
         */
        static::define('groupRandom', function (string $groupColumn, int $limit = 10, string $primaryKey = 'id') {
            /**
             * @var Builder|QueryBuilder $this
             *
             * @scenario 本类/成员说明。
             */
            $model = BaseMacro::modelOf($this);
            $table = $model->getTable();
            $primaryKey = BaseMacro::assertValidIdentifier($primaryKey, 'primaryKey');
            $groupColumn = BaseMacro::assertValidIdentifier($groupColumn, 'group');
            $wrappedGroup = BaseMacro::wrapIdentifier($this, $groupColumn);

            // 构建窗口函数子查询 - 按分组随机排序
            $randomFn = BaseMacro::randomFunction($this);
            $subQuery = DB::table($table)
                ->select(
                    $primaryKey,
                    $groupColumn,
                    DB::raw("ROW_NUMBER() OVER (PARTITION BY {$wrappedGroup} ORDER BY {$randomFn}) AS group_rnd_rank")
                );

            // 复制所有where条件到子查询
            $this->replicateWheres(BaseMacro::queryOf($this), $subQuery);

            // 构建主查询
            return $model->newQuery()
                ->when(! empty(BaseMacro::queryOf($this)->columns), function ($query) {
                    $query->select(BaseMacro::queryOf($this)->columns);
                })
                ->whereIn("{$table}.{$primaryKey}", function ($query) use ($subQuery, $limit, $primaryKey) {
                    $query->select($primaryKey)
                        ->from(DB::raw("({$subQuery->toSql()}) AS grouped_ranked"))
                        ->mergeBindings($subQuery)
                        ->where('group_rnd_rank', '<=', $limit);
                });
        });
    }
}
