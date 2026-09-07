<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use zxf\Modules\BuilderQuery\Concerns\SqlSecurity;

class GroupSortMacro
{
    use SqlSecurity;

    /**
     * 注册 groupSort 宏函数
     *
     * @return void
     */
    public static function register()
    {
        /**
         * groupSort(分组排序查询) 宏函数
         *
         * @example Article::query()->groupSort('classify_id', [1,3],'read','desc')->get(); // 查询每个文章分组下read最高的第1到3名文章
         *          Article::query()->groupSort('classify_id', 9,'read','desc')->get(); // 获取每个文章分组下read第9名文章
         *          Article::query()->groupSort('classify_id', -1,'read','desc')->get(); // 获取每个文章分组下read最后1名文章
         *
         * @param  string  $groupBy  分组字段名 eg: classify_id
         * @param  int|array  $ranks  排名，名次(数字表示第n名，带2个数字的数组[n,m]表示查询第n到m名)，倒数第n名使用负数，eg: 1, [2, 4], -1
         * @param  string  $orderBy  排序字段名 eg: read
         * @param  string  $direction  排序方向，eg: desc, asc
         */
        Builder::macro('groupSort', function (string $groupBy, int|array $ranks, string $orderBy = 'read', string $direction = 'desc') {
            /** @var Builder $this */
            $model = $this->getModel();
            $table = $model->getTable();
            $primaryKey = $model->getKeyName();

            // 校验外部传入的字段名，防止 SQL 注入（表名/主键来自模型定义，视为可信）。
            $groupBy = GroupSortMacro::assertValidIdentifier($groupBy, 'group');
            $orderBy = GroupSortMacro::assertValidIdentifier($orderBy, 'order');
            GroupSortMacro::assertValidIdentifier($primaryKey, 'primaryKey');

            // 克隆查询构造器并移除分页限制避免影响子查询
            $baseQuery = clone $this;
            $baseQuery->getQuery()->limit = null;
            $baseQuery->getQuery()->offset = null;

            // 添加开窗函数排名（使用 grammar wrap 生成适配驱动的标识符引用）
            $orderDirection = GroupSortMacro::assertDirection($direction);
            $wrappedGroup = GroupSortMacro::wrapIdentifier($baseQuery, $groupBy);
            $wrappedOrder = GroupSortMacro::wrapIdentifier($baseQuery, $orderBy);
            $partitionExpr = "ROW_NUMBER() OVER (PARTITION BY {$wrappedGroup} ORDER BY {$wrappedOrder} {$orderDirection}) AS row_rank";
            // 必须同时选出分组列，供倒数排名子查询中 `ranked.{$wrappedGroup}` 引用
            $baseQuery->select([$table.'.'.$primaryKey, $table.'.'.$groupBy, DB::raw($partitionExpr)]);

            // 包装子查询并合并绑定
            $subSql = $baseQuery->toSql();
            $rankedSubQuery = DB::table(DB::raw("({$subSql}) as ranked"))
                ->mergeBindings($baseQuery->getQuery());

            // 添加 row_rank 条件
            // 负数表示倒数排名：需计算 max_rank，并过滤 row_rank = max_rank + ranks + 1
            if (is_array($ranks) && count($ranks) === 2) {
                $rankedSubQuery->whereBetween('row_rank', [$ranks[0], $ranks[1]]);
            } elseif (is_int($ranks) && $ranks > 0) {
                $rankedSubQuery->where('row_rank', $ranks);
            } elseif (is_int($ranks) && $ranks < 0) {
                // 负数表示倒数排名：通过子查询计算每组最大排名。
                // 直接复用外层 `ranked` 别名而非再次内嵌 {$subSql}，避免占位符与绑定错位。
                $rankedSubQuery->whereColumn('row_rank', DB::raw(
                    "(SELECT MAX(inner_r.row_rank) FROM ranked AS inner_r WHERE inner_r.{$wrappedGroup} = ranked.{$wrappedGroup}) + {$ranks} + 1"
                ));
            }

            // 查询主表数据
            return $model->newQuery()->whereIn($primaryKey, $rankedSubQuery->select($primaryKey));
        });
    }
}
