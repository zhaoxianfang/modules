<?php

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * MySQL 8.4+ VALUES 行构造函数和批量操作宏
 *
 * 提供 VALUES ROW 语法支持：
 * - valuesQuery: 使用 VALUES 构建内存表
 * - valuesJoin: 使用 VALUES 作为 JOIN 表
 * - valuesInsert: 优化的批量插入
 * - batchUpsert: 批量插入或更新
 *
 * @package zxf\Modules\BuilderQuery\WindowMacros
 * @version 1.0.0
 * @requires MySQL 8.4+
 */
class ValuesMacro
{
    /**
     * 注册所有 VALUES 宏
     *
     * @return void
     */
    public static function register(): void
    {
        self::registerValuesQuery();
        self::registerValuesJoin();
        self::registerValuesInsert();
        self::registerBatchUpsert();
    }

    /**
     * 注册 VALUES 查询方法
     */
    protected static function registerValuesQuery(): void
    {
        /**
         * valuesQuery - 使用 VALUES ROW 构建内存表查询
         *
         * MySQL 8.0.19+ 支持 VALUES ROW 语法，可在查询中构造临时数据表
         *
         * @param array $rows 数据行 [['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']]
         * @param string $alias 表别名
         * @return Builder
         *
         * @example
         * // 使用 VALUES 作为临时表进行查询
         * User::query()
         *     ->valuesQuery([
         *         ['id' => 1, 'status' => 'active'],
         *         ['id' => 2, 'status' => 'inactive'],
         *     ], 'temp_status')
         *     ->select('users.*', 'temp_status.status as override_status')
         *     ->join('temp_status', 'users.id', '=', 'temp_status.id')
         *     ->get();
         *
         * // 批量 ID 查询（替代大量 OR 条件）
         * $ids = [['id' => 1], ['id' => 5], ['id' => 10]];
         * User::query()->valuesQuery($ids, 'target_ids')
         *     ->join('target_ids', 'users.id', '=', 'target_ids.id')
         *     ->get();
         */
        Builder::macro('valuesQuery', function (
            array $rows,
            string $alias = 'val'
        ): Builder {
            /** @var Builder $this */
            if (empty($rows)) {
                throw new \InvalidArgumentException('VALUES rows cannot be empty');
            }

            // 获取列名
            $columns = array_keys($rows[0]);
            $valueRows = [];
            $bindings = [];

            foreach ($rows as $row) {
                $placeholders = array_fill(0, count($columns), '?');
                $valueRows[] = 'ROW(' . implode(', ', $placeholders) . ')';
                foreach ($columns as $col) {
                    $bindings[] = $row[$col] ?? null;
                }
            }

            $columnList = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));
            $valuesSql = "VALUES " . implode(', ', $valueRows);

            return $this->getModel()->newQuery()
                ->fromRaw("({$valuesSql}) AS `{$alias}` ({$columnList})", $bindings);
        });
    }

    /**
     * 注册 VALUES JOIN 方法
     */
    protected static function registerValuesJoin(): void
    {
        /**
         * valuesJoin - 使用 VALUES 作为 JOIN 表
         *
         * 简化使用 VALUES 进行 JOIN 的操作
         *
         * @param array $rows 数据行
         * @param string $alias 表别名
         * @param string $localKey 主表关联键
         * @param string $valuesKey VALUES 表关联键
         * @param string $joinType JOIN 类型: inner|left|right
         * @return Builder
         *
         * @example
         * // 使用 VALUES JOIN 批量更新状态
         * $updates = [
         *     ['order_id' => 1, 'new_status' => 'shipped'],
         *     ['order_id' => 2, 'new_status' => 'delivered'],
         * ];
         * Order::query()
         *     ->valuesJoin($updates, 'updates', 'id', 'order_id')
         *     ->select('orders.*', 'updates.new_status')
         *     ->get();
         */
        Builder::macro('valuesJoin', function (
            array $rows,
            string $alias,
            string $localKey,
            string $valuesKey,
            string $joinType = 'inner'
        ): Builder {
            /** @var Builder $this */
            $model = $this->getModel();
            $table = $model->getTable();

            if (empty($rows)) {
                return $this;
            }

            // 构建 VALUES 子查询
            $columns = array_keys($rows[0]);
            $valueRows = [];
            $bindings = [];

            foreach ($rows as $row) {
                $placeholders = array_fill(0, count($columns), '?');
                $valueRows[] = 'ROW(' . implode(', ', $placeholders) . ')';
                foreach ($columns as $col) {
                    $bindings[] = $row[$col] ?? null;
                }
            }

            $columnList = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));
            $valuesSql = "VALUES " . implode(', ', $valueRows);

            // 构建 JOIN
            $joinMethod = match (strtolower($joinType)) {
                'left' => 'leftJoin',
                'right' => 'rightJoin',
                default => 'join',
            };

            $joinSql = "({$valuesSql}) AS `{$alias}` ({$columnList})";

            return $this->{$joinMethod}(DB::raw($joinSql), function ($join) use ($localKey, $valuesKey, $table, $alias) {
                $join->on("{$table}.{$localKey}", '=', "{$alias}.{$valuesKey}");
            })->addBinding($bindings, 'join');
        });
    }

    /**
     * 注册 VALUES 批量插入方法
     */
    protected static function registerValuesInsert(): void
    {
        /**
         * valuesInsert - 使用 VALUES ROW 语法进行高效批量插入
         *
         * 比传统多条 INSERT 语句更高效，减少网络往返
         *
         * @param array $rows 要插入的数据行
         * @param int $chunkSize 每批次大小（避免 SQL 过长）
         * @return int 影响行数
         *
         * @example
         * // 批量插入日志
         * Log::query()->valuesInsert([
         *     ['level' => 'info', 'message' => 'User login', 'created_at' => now()],
         *     ['level' => 'error', 'message' => 'DB fail', 'created_at' => now()],
         *     ['level' => 'warning', 'message' => 'High load', 'created_at' => now()],
         * ]);
         *
         * // 大批量插入（自动分块）
         * $data = array_map(fn ($i) => ['name' => "Item {$i}", 'sort' => $i], range(1, 10000));
         * Item::query()->valuesInsert($data, 1000);
         */
        Builder::macro('valuesInsert', function (
            array $rows,
            int $chunkSize = 1000
        ): int {
            /** @var Builder $this */
            $model = $this->getModel();
            $table = $model->getTable();

            if (empty($rows)) {
                return 0;
            }

            $columns = array_keys($rows[0]);
            $columnStr = implode('`, `', $columns);
            $totalAffected = 0;

            $chunks = array_chunk($rows, $chunkSize);

            foreach ($chunks as $chunk) {
                $valueRows = [];
                $bindings = [];

                foreach ($chunk as $row) {
                    $placeholders = array_fill(0, count($columns), '?');
                    $valueRows[] = 'ROW(' . implode(', ', $placeholders) . ')';
                    foreach ($columns as $col) {
                        $bindings[] = $row[$col] ?? null;
                    }
                }

                $sql = "INSERT INTO `{$table}` (`{$columnStr}`) VALUES " . implode(', ', $valueRows);
                $totalAffected += DB::affectingStatement($sql, $bindings);
            }

            return $totalAffected;
        });
    }

    /**
     * 注册批量 Upsert 方法
     */
    protected static function registerBatchUpsert(): void
    {
        /**
         * batchUpsert - 批量插入或更新（INSERT ... ON DUPLICATE KEY UPDATE）
         *
         * @param array $rows 数据行
         * @param array|string $uniqueBy 唯一键列
         * @param array|null $updateColumns 需要更新的列（null=更新所有非唯一列）
         * @param int $chunkSize 每批次大小
         * @return int 影响行数
         *
         * @example
         * // 批量导入用户（存在则更新，不存在则插入）
         * User::query()->batchUpsert([
         *     ['id' => 1, 'name' => '张三', 'email' => 'zhang@example.com', 'updated_at' => now()],
         *     ['id' => 2, 'name' => '李四', 'email' => 'li@example.com', 'updated_at' => now()],
         * ], 'id', ['name', 'email', 'updated_at']);
         *
         * // 使用复合唯一键
         * Stat::query()->batchUpsert([
         *     ['date' => '2024-01-01', 'metric' => 'pv', 'value' => 1000],
         *     ['date' => '2024-01-01', 'metric' => 'uv', 'value' => 500],
         * ], ['date', 'metric'], ['value']);
         */
        Builder::macro('batchUpsert', function (
            array $rows,
            array|string $uniqueBy,
            ?array $updateColumns = null,
            int $chunkSize = 1000
        ): int {
            /** @var Builder $this */
            $model = $this->getModel();
            $table = $model->getTable();

            if (empty($rows)) {
                return 0;
            }

            $columns = array_keys($rows[0]);
            $columnStr = implode('`, `', $columns);

            // 确定更新列
            $uniqueColumns = is_array($uniqueBy) ? $uniqueBy : [$uniqueBy];
            if ($updateColumns === null) {
                $updateColumns = array_diff($columns, $uniqueColumns);
            }

            // 构建 ON DUPLICATE KEY UPDATE 部分（使用新值引用语法，兼容MySQL 8.0.20+）
            $updateParts = [];
            foreach ($updateColumns as $col) {
                // MySQL 8.0.20+ 推荐使用别名.列名引用新值，VALUES()函数已弃用
                $updateParts[] = "`{$col}` = new_values.`{$col}`";
            }
            $updateStr = implode(', ', $updateParts);

            $totalAffected = 0;
            $chunks = array_chunk($rows, $chunkSize);

            foreach ($chunks as $chunk) {
                $valueRows = [];
                $bindings = [];

                foreach ($chunk as $row) {
                    $placeholders = array_fill(0, count($columns), '?');
                    $valueRows[] = '(' . implode(', ', $placeholders) . ')';
                    foreach ($columns as $col) {
                        $bindings[] = $row[$col] ?? null;
                    }
                }

                $sql = "INSERT INTO `{$table}` (`{$columnStr}`) VALUES " . implode(', ', $valueRows) . " AS new_values";
                if (!empty($updateStr)) {
                    $sql .= " ON DUPLICATE KEY UPDATE {$updateStr}";
                }

                $totalAffected += DB::affectingStatement($sql, $bindings);
            }

            return $totalAffected;
        });
    }
}
