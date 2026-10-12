<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * 随机查询宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 *
 * 基于标准 SQL 窗口函数 ROW_NUMBER() + 数据库随机函数实现「随机取 N 条」与「分组随机取 N 条」：
 *  - MySQL：随机函数为 RAND()
 *  - PostgreSQL：随机函数为 random()
 * 通过 {@see BaseMacro::randomFunction()} 统一生成，因此同一套宏在两种数据库上均可直接使用。
 *
 * 安全性：主键 / 分组列名经白名单校验并由 grammar wrap；随机函数由 Driver 决定，不拼接用户输入。
 *
 * @package zxf\Modules\BuilderQuery\WindowMacros
 * @version 3.0.0
 * @requires MySQL 8.0+ / PostgreSQL 15+
 */
class RandomMacro extends BaseMacro
{

    /**
     * 注册 random() 宏函数，用于随机查询记录
     *
     * 使用方式：
     * User::where('status', 1)->random(5)->get();
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
                    // 使用克隆方式复制数组/对象属性，避免引用问题
                    $value = $source->{$property};
                    $dest->{$property} = is_object($value) ? clone $value : (is_array($value) ? unserialize(serialize($value)) : $value);
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
         */
        static::define('replicateWheres', function (QueryBuilder $source, QueryBuilder $dest) {
            RandomMacro::copyWheres($source, $dest);

            return $dest;
        });
    }

    /**
     * 复制源查询的全部 where 条件到目标查询
     *
     * 注意：Laravel Macroable 会将宏闭包重绑到 \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder 作用域，闭包内 `RandomMacro::` 会解析为
     * \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder 而非本类，因此复制逻辑必须抽为 public static 方法，供宏闭包与 Nested
     * 分支递归调用共用。
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $source 源查询（宏调用者实际为 Eloquent \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder）
     * @param QueryBuilder $dest 目标查询
     * @return void
     */
    public static function copyWheres(\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $source, QueryBuilder $dest): void
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

    // 另一种实现方式
    public static function randomHandle()
    {
        /**
         * 随机查询宏
         *
         * 随机选择5名学生
         * Student::where('class_id', 101)->random(5);
         *
         * @param  int  $limit  返回记录数，默认10
         * @param  string  $primaryKey  主键名，默认'id'
         * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
         */
        static::define('random', function (int $limit = 10, string $primaryKey = 'id') {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
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
                ->when(!empty(BaseMacro::queryOf($this)->columns), function ($query) {
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
         *
         *  每个班级随机选择2名学生
         *  Student::groupRandom('class_id', 2);
         *
         * @param  string  $groupColumn  分组字段名
         * @param  int  $limit  每组返回记录数，默认10
         * @param  string  $primaryKey  主键名，默认'id'
         * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
         */
        static::define('groupRandom', function (string $groupColumn, int $limit = 10, string $primaryKey = 'id') {
            /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $this */
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
                ->when(!empty(BaseMacro::queryOf($this)->columns), function ($query) {
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
