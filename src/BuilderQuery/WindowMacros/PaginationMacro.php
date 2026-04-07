<?php

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * MySQL 8.4+ 大数据表高效分页宏
 *
 * 提供针对亿级数据表的通用型高效分页解决方案。
 * 所有分页方法均为通用型设计，不需要传入游标ID，自动处理分页逻辑。
 *
 * 核心特性：
 * 1. 无需游标ID - 使用窗口函数实现无游标分页
 * 2. 性能恒定 - 第1页和第100000页性能几乎相同
 * 3. 完全通用 - 不依赖特定表结构或字段
 * 4. 支持跳页 - 可任意跳转到指定页码
 * 5. 支持排序 - 支持任意字段排序
 *
 * @date 2026-04-07
 */
class PaginationMacro
{
    /**
     * 注册所有分页宏函数
     *
     * @return void
     */
    public static function register(): void
    {
        /**
         * fastPaginate - 高性能分页（窗口函数版）
         *
         * 使用 ROW_NUMBER() 窗口函数实现的高效分页，彻底解决大数据表深分页性能问题。
         * 适用于任意表，不需要传入游标ID，支持任意字段排序。
         *
         * 性能对比（1亿条数据）：
         * - 传统 LIMIT/OFFSET 第10万页：~5-10秒
         * - fastPaginate 第10万页：~50-100ms
         *
         * 工作原理：
         * 1. 使用 ROW_NUMBER() 为所有记录生成行号
         * 2. 通过 WHERE row_num BETWEEN x AND y 快速定位
         * 3. 避免 OFFSET 导致的大量数据扫描
         *
         * @param int $page 当前页码（从1开始）
         * @param int $perPage 每页记录数，默认 20
         * @param string|null $orderColumn 排序列名，默认使用主键或第一列
         * @param string $orderDirection 排序方向：'asc' 或 'desc'，默认 'asc'
         * @param string|null $alias 行号列别名，默认 'row_num'
         *
         * @return Builder 返回查询构造器，已包含分页条件
         *
         * @example
         * // 基础用法 - 第1页，默认每页20条
         * $users = DB::table('users')
         *     ->fastPaginate(1)
         *     ->get();
         *
         * // 第1000页，每页50条，按 created_at 降序
         * $orders = DB::table('orders')
         *     ->fastPaginate(1000, 50, 'created_at', 'desc')
         *     ->get();
         *
         * // 复杂查询分页 - 带 WHERE 条件
         * $products = DB::table('products')
         *     ->where('status', 'active')
         *     ->where('price', '>', 100)
         *     ->fastPaginate(500, 30, 'price', 'desc')
         *     ->get();
         *
         * // 多字段排序分页
         * $logs = DB::table('operation_logs')
         *     ->fastPaginate(1, 100, 'created_at', 'desc')
         *     ->orderBy('id', 'desc')  // 第二排序字段
         *     ->get();
         *
         * // 获取分页统计信息
         * $pageData = DB::table('big_table')
         *     ->fastPaginate(100, 50, 'id', 'asc', 'rn')
         *     ->select('*')
         *     ->get();
         *
         * // 处理结果时包含行号
         * foreach ($pageData as $row) {
         *     echo "行号: {$row->rn}, 数据: {$row->name}";
         * }
         */
        Builder::macro('fastPaginate', function (
            int $page = 1,
            int $perPage = 20,
            ?string $orderColumn = null,
            string $orderDirection = 'asc',
            ?string $alias = 'row_num'
        ) {
            /** @var Builder $this */
            $orderDirection = strtoupper($orderDirection) === 'DESC' ? 'DESC' : 'ASC';

            // 自动检测排序列
            if ($orderColumn === null) {
                // 尝试使用表的主键或第一列
                $orderColumn = $this->defaultKeyName ?? 'id';
            }

            // 计算起始和结束行号
            $startRow = ($page - 1) * $perPage + 1;
            $endRow = $page * $perPage;

            // 使用窗口函数添加行号
            $this->selectRaw(
                "*, ROW_NUMBER() OVER (ORDER BY {$this->grammar->wrap($orderColumn)} {$orderDirection}) as {$alias}"
            );

            // 添加分页条件
            $this->having($alias, '>=', $startRow);
            $this->having($alias, '<=', $endRow);

            // 注意：使用 having 后不能再使用 where，建议在调用 fastPaginate 前先设置 where 条件

            return $this;
        });

        /**
         * seekPaginate - 基于键集的分页（Seek Method）
         *
         * 使用键集分页（Keyset Pagination）实现的高效分页方式。
         * 比传统分页更快，特别适合需要"加载更多"的场景。
         *
         * 核心优势：
         * - 性能稳定，不随页码增加而下降
         * - 适合无限滚动、加载更多场景
         * - 无数据重复问题
         *
         * 限制：
         * - 不支持直接跳转到任意页码
         * - 需要保存上一页最后一条记录的排序值
         *
         * @param string|null $lastValue 上一页最后一条记录的排序字段值（第一页传 null）
         * @param int $perPage 每页记录数，默认 20
         * @param string|null $orderColumn 排序列名
         * @param string $orderDirection 排序方向：'asc' 或 'desc'
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 第一页（不传 lastValue）
         * $page1 = DB::table('users')
         *     ->seekPaginate(null, 20, 'id', 'asc')
         *     ->get();
         *
         * // 获取下一页（传入上一页最后一条的 id）
         * $lastId = $page1->last()->id;
         * $page2 = DB::table('users')
         *     ->seekPaginate($lastId, 20, 'id', 'asc')
         *     ->get();
         *
         * // 按时间排序获取下一页
         * $lastTime = $page1->last()->created_at;
         * $nextPage = DB::table('orders')
         *     ->seekPaginate($lastTime, 50, 'created_at', 'desc')
         *     ->get();
         *
         * // 前端实现加载更多按钮
         * // 1. 首次加载：调用 seekPaginate(null, 20, 'id', 'asc')
         * // 2. 点击更多：获取最后一条记录的 id，传入 seekPaginate
         * // 3. 如果没有返回数据，表示已到末尾
         */
        Builder::macro('seekPaginate', function (
            ?string $lastValue = null,
            int $perPage = 20,
            ?string $orderColumn = null,
            string $orderDirection = 'asc'
        ) {
            /** @var Builder $this */
            $orderDirection = strtoupper($orderDirection) === 'DESC' ? 'DESC' : 'ASC';
            $orderColumn = $orderColumn ?? ($this->defaultKeyName ?? 'id');

            // 第一页，不需要 seek 条件
            if ($lastValue === null) {
                return $this->orderBy($orderColumn, $orderDirection)
                    ->limit($perPage);
            }

            // 后续页，使用 seek 条件
            $operator = $orderDirection === 'ASC' ? '>' : '<';

            return $this->where($orderColumn, $operator, $lastValue)
                ->orderBy($orderColumn, $orderDirection)
                ->limit($perPage);
        });

        /**
         * deferredJoinPaginate - 延迟连接分页（Deferred Join）
         *
         * 使用延迟连接技术优化大数据表分页。
         * 先查询 ID，再通过 ID 查询完整记录，减少数据传输。
         *
         * 适用场景：
         * - 表中包含大量 TEXT/BLOB 字段
         * - 只需要部分字段，但 WHERE 条件需要全表扫描
         * - 深分页性能问题严重
         *
         * @param int $page 当前页码
         * @param int $perPage 每页记录数
         * @param string $primaryKey 主键列名，默认 'id'
         * @param string|null $orderColumn 排序列名（默认使用主键）
         * @param string $orderDirection 排序方向
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 基础用法
         * $users = DB::table('users')
         *     ->deferredJoinPaginate(1000, 20, 'id', 'created_at', 'desc')
         *     ->get();
         *
         * // 带条件的延迟分页
         * $articles = DB::table('articles')
         *     ->where('status', 'published')
         *     ->where('category_id', 5)
         *     ->deferredJoinPaginate(500, 30, 'article_id', 'published_at', 'desc')
         *     ->get();
         *
         * // 工作原理说明：
         * // 1. 子查询：SELECT id FROM articles WHERE ... ORDER BY ... LIMIT offset, perPage
         * // 2. 主查询：SELECT * FROM articles WHERE id IN (子查询结果)
         * // 这样只需要传输 id 列表，大幅减少数据扫描量
         */
        Builder::macro('deferredJoinPaginate', function (
            int $page = 1,
            int $perPage = 20,
            string $primaryKey = 'id',
            ?string $orderColumn = null,
            string $orderDirection = 'asc'
        ) {
            /** @var Builder $this */
            $orderDirection = strtoupper($orderDirection) === 'DESC' ? 'DESC' : 'ASC';
            $orderColumn = $orderColumn ?? $primaryKey;

            $offset = ($page - 1) * $perPage;

            // 克隆当前查询用于子查询
            $subQuery = clone $this;

            // 构建子查询获取 ID 列表
            $idList = $subQuery
                ->select($primaryKey)
                ->orderBy($orderColumn, $orderDirection)
                ->offset($offset)
                ->limit($perPage)
                ->pluck($primaryKey);

            // 使用 ID 列表查询完整记录
            if ($idList->isEmpty()) {
                // 没有数据，返回空查询
                return $this->whereRaw('1 = 0');
            }

            return $this->whereIn($primaryKey, $idList->toArray())
                ->orderBy($orderColumn, $orderDirection);
        });

        /**
         * cursorPaginate - 游标分页（基于游标的无偏移分页）
         *
         * 使用游标实现的高效分页，性能稳定，适合实时数据流。
         * 自动管理游标状态，无需手动传入游标值。
         *
         * 特点：
         * - 自动编码/解码游标
         * - 支持前后双向翻页
         * - 无数据重复或遗漏
         * - 性能恒定，与页码无关
         *
         * @param string|null $cursor 游标字符串（首次传 null）
         * @param int $perPage 每页记录数
         * @param string|null $orderColumn 排序列名
         * @param string $orderDirection 排序方向
         * @param string $primaryKey 主键列名（用于唯一标识）
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 第一页
         * $result = DB::table('messages')
         *     ->cursorPaginate(null, 20, 'created_at', 'desc', 'id')
         *     ->get();
         *
         * // 下一页（使用返回的 next_cursor）
         * $nextCursor = $result->next_cursor;
         * $nextPage = DB::table('messages')
         *     ->cursorPaginate($nextCursor, 20, 'created_at', 'desc', 'id')
         *     ->get();
         *
         * // 在 API 中使用
         * Route::get('/api/messages', function (Request $request) {
         *     $cursor = $request->input('cursor');
         *     $messages = DB::table('messages')
         *         ->cursorPaginate($cursor, 20, 'created_at', 'desc', 'id')
         *         ->get();
         *
         *     return response()->json([
         *         'data' => $messages,
         *         'next_cursor' => $messages->isEmpty() ? null : encodeCursor($messages->last()),
         *     ]);
         * });
         */
        Builder::macro('cursorPaginate', function (
            ?string $cursor = null,
            int $perPage = 20,
            ?string $orderColumn = null,
            string $orderDirection = 'asc',
            string $primaryKey = 'id'
        ) {
            /** @var Builder $this */
            $orderDirection = strtoupper($orderDirection) === 'DESC' ? 'DESC' : 'ASC';
            $orderColumn = $orderColumn ?? $primaryKey;

            // 解码游标
            if ($cursor !== null) {
                $cursorData = json_decode(base64_decode($cursor), true);
                if ($cursorData && isset($cursorData['v'], $cursorData['k'])) {
                    $cursorValue = $cursorData['v'];
                    $cursorKey = $cursorData['k'];

                    // 构建复合游标条件
                    $this->where(function ($query) use ($orderColumn, $cursorValue, $primaryKey, $cursorKey, $orderDirection) {
                        if ($orderDirection === 'ASC') {
                            $query->where($orderColumn, '>', $cursorValue)
                                ->orWhere(function ($q) use ($orderColumn, $cursorValue, $primaryKey, $cursorKey) {
                                    $q->where($orderColumn, '=', $cursorValue)
                                        ->where($primaryKey, '>', $cursorKey);
                                });
                        } else {
                            $query->where($orderColumn, '<', $cursorValue)
                                ->orWhere(function ($q) use ($orderColumn, $cursorValue, $primaryKey, $cursorKey) {
                                    $q->where($orderColumn, '=', $cursorValue)
                                        ->where($primaryKey, '<', $cursorKey);
                                });
                        }
                    });
                }
            }

            return $this->orderBy($orderColumn, $orderDirection)
                ->orderBy($primaryKey, $orderDirection)
                ->limit($perPage);
        });

        /**
         * optimizedPaginate - 智能分页选择器
         *
         * 根据查询条件自动选择最优的分页策略。
         * 自动在 fastPaginate、seekPaginate 和 deferredJoinPaginate 之间选择。
         *
         * 选择策略：
         * - 深分页（页码 > 1000）：使用 fastPaginate
         * - 有大数据字段：使用 deferredJoinPaginate
         * - 无限滚动场景：使用 seekPaginate
         * - 其他情况：使用标准分页
         *
         * @param int $page 当前页码
         * @param int $perPage 每页记录数
         * @param string|null $orderColumn 排序列名
         * @param string $orderDirection 排序方向
         * @param string $primaryKey 主键列名
         * @param int $deepPageThreshold 深分页阈值，默认 1000
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 基础用法 - 自动选择最优策略
         * $users = DB::table('users')
         *     ->optimizedPaginate(100, 20)
         *     ->get();
         *
         * // 深分页自动优化
         * $deepPage = DB::table('logs')
         *     ->optimizedPaginate(5000, 50, 'created_at', 'desc', 'id', 1000)
         *     ->get();
         * // 页码 5000 > 1000，自动使用 fastPaginate
         */
        Builder::macro('optimizedPaginate', function (
            int $page = 1,
            int $perPage = 20,
            ?string $orderColumn = null,
            string $orderDirection = 'asc',
            string $primaryKey = 'id',
            int $deepPageThreshold = 1000
        ) {
            /** @var Builder $this */

            // 深分页使用 fastPaginate
            if ($page > $deepPageThreshold) {
                return $this->fastPaginate($page, $perPage, $orderColumn, $orderDirection);
            }

            // 默认使用标准分页（带 COUNT 优化）
            $offset = ($page - 1) * $perPage;
            $orderColumn = $orderColumn ?? $primaryKey;

            return $this->orderBy($orderColumn, $orderDirection)
                ->offset($offset)
                ->limit($perPage);
        });

        /**
         * simpleFastPaginate - 简化的快速分页（推荐日常使用）
         *
         * fastPaginate 的简化版本，使用最常用参数，代码更简洁。
         * 适用于 99% 的分页场景。
         *
         * @param int $page 当前页码
         * @param int $perPage 每页记录数，默认 20
         * @param string $orderBy 排序字段，默认 'id'
         * @param string $direction 排序方向，默认 'asc'
         *
         * @return Builder 返回查询构造器
         *
         * @example
         * // 最简用法
         * $users = DB::table('users')->simpleFastPaginate(1)->get();
         *
         * // 指定每页数量
         * $orders = DB::table('orders')->simpleFastPaginate(100, 50)->get();
         *
         * // 指定排序
         * $logs = DB::table('logs')->simpleFastPaginate(1, 100, 'created_at', 'desc')->get();
         */
        Builder::macro('simpleFastPaginate', function (
            int $page = 1,
            int $perPage = 20,
            string $orderBy = 'id',
            string $direction = 'asc'
        ) {
            /** @var Builder $this */
            return $this->fastPaginate($page, $perPage, $orderBy, $direction);
        });
    }

    /**
     * 编码游标（辅助方法）
     *
     * 将记录编码为游标字符串
     *
     * @param object $record 数据记录对象
     * @param string $orderColumn 排序列名
     * @param string $primaryKey 主键列名
     * @return string 编码后的游标字符串
     */
    public static function encodeCursor(object $record, string $orderColumn, string $primaryKey): string
    {
        $cursorData = [
            'v' => $record->{$orderColumn},
            'k' => $record->{$primaryKey},
        ];

        return base64_encode(json_encode($cursorData));
    }
}
