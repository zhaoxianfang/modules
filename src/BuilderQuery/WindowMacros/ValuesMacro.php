<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * VALUES 行构造函数与批量操作宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 * 提供基于标准 VALUES 语法的内存表与批量写入能力，所有数据库专属差异经 {@see Dialect} 抽象：
 * - valuesQuery: 使用 VALUES 构建内存表（MySQL ROW(...) / PostgreSQL (...)）
 * - valuesJoin: 使用 VALUES 作为 JOIN 表
 * - valuesInsert: 优化的批量插入（标准 (...) 行构造，双库通用）
 * - batchUpsert: 批量插入或更新（MySQL ON DUPLICATE KEY UPDATE / PostgreSQL ON CONFLICT DO UPDATE）
 * 安全性：行数据的键名、表别名、关联键、唯一键、更新列均经白名单校验并由 grammar wrap，
 * 数值 / 字面值全部走参数绑定。
 *
 * @version 3.0.0
 *
 * @requires MySQL 8.0+ / PostgreSQL 15+
 *
 * @scenario VALUES 行构造函数与批量操作宏（MySQL 8.0+ / PostgreSQL 15+ 通用）
 */
class ValuesMacro extends BaseMacro
{
    /**
     * 注册所有 VALUES 宏
     *
     * @scenario 注册所有 VALUES 宏
     */
    public static function register(): void
    {
        ValuesMacro::registerValuesQuery();
        ValuesMacro::registerValuesJoin();
        ValuesMacro::registerValuesInsert();
        ValuesMacro::registerBatchUpsert();
    }

    /**
     * 校验行数据键名并返回安全标识符列表
     *
     * @return array 已校验的列名
     *
     * @scenario 校验行数据键名并返回安全标识符列表
     */

    /**
     * 校验批量行数据的列名合法性
     * 注意：本方法在 \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder::macro 闭包内通过 `ValuesMacro::assertRowColumns(...)` 显式
     * 类名调用，必须声明为 public（闭包被重绑到 \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder 作用域，不在本类内部，无法访问
     * protected/private 成员）。
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array 已校验的列名
     *
     * @scenario 校验批量行数据的列名合法性
     */
    public static function assertRowColumns(array $rows): array
    {
        $columns = array_keys($rows[0]);
        foreach ($columns as &$column) {
            $column = ValuesMacro::assertValidIdentifier((string) $column, 'column');
        }
        unset($column);

        return $columns;
    }

    /**
     * 注册 VALUES 查询方法
     *
     * @scenario 注册 VALUES 查询方法
     */
    public static function registerValuesQuery(): void
    {
        /**
         * valuesQuery - 使用 VALUES ROW 构建内存表查询
         * MySQL 8.0.19+ 支持 VALUES ROW 语法，可在查询中构造临时数据表
         *
         * @param  array  $rows  数据行 [['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']]
         * @param  string  $alias  表别名
         * @return Builder|\Illuminate\Database\Query\Builder
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
         * // 批量 ID 查询（替代大量 OR 条件）
         * $ids = [['id' => 1], ['id' => 5], ['id' => 10]];
         * User::query()->valuesQuery($ids, 'target_ids')
         *     ->join('target_ids', 'users.id', '=', 'target_ids.id')
         *     ->get();
         *
         * @scenario valuesQuery - 使用 VALUES ROW 构建内存表查询
         */
        static::define('valuesQuery', function (
            array $rows,
            string $alias = 'val'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $builder = $this;
            if (empty($rows)) {
                throw new \InvalidArgumentException('VALUES rows cannot be empty');
            }

            // 获取列名（校验为合法标识符）
            $columns = ValuesMacro::assertRowColumns($rows);
            $valueRows = [];
            $bindings = [];

            foreach ($rows as $row) {
                $valueRows[] = BaseMacro::valuesRowSyntax($this, count($columns));
                foreach ($columns as $col) {
                    $bindings[] = $row[$col] ?? null;
                }
            }

            $columnList = implode(', ', array_map(fn ($c) => BaseMacro::wrapIdentifier($builder, $c), $columns));
            $valuesSql = 'VALUES '.implode(', ', $valueRows);
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = BaseMacro::wrapIdentifier($this, $alias);

            return BaseMacro::freshQuery($this)
                ->fromRaw("({$valuesSql}) AS {$wrappedAlias} ({$columnList})", $bindings);
        });
    }

    /**
     * 注册 VALUES JOIN 方法
     *
     * @scenario 注册 VALUES JOIN 方法
     */
    public static function registerValuesJoin(): void
    {
        /**
         * valuesJoin - 使用 VALUES 作为 JOIN 表
         * 简化使用 VALUES 进行 JOIN 的操作
         *
         * @param  array  $rows  数据行
         * @param  string  $alias  表别名
         * @param  string  $localKey  主表关联键
         * @param  string  $valuesKey  VALUES 表关联键
         * @param  string  $joinType  JOIN 类型: inner|left|right
         * @return Builder|\Illuminate\Database\Query\Builder
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
         *
         * @scenario valuesJoin - 使用 VALUES 作为 JOIN 表
         */
        static::define('valuesJoin', function (
            array $rows,
            string $alias,
            string $localKey,
            string $valuesKey,
            string $joinType = 'inner'
        ): Builder|\Illuminate\Database\Query\Builder {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $builder = $this;
            $model = BaseMacro::modelOf($this);
            $table = $model->getTable();

            if (empty($rows)) {
                return $this;
            }

            // 构建 VALUES 子查询（列名经白名单校验）
            $columns = ValuesMacro::assertRowColumns($rows);
            $valueRows = [];
            $bindings = [];

            foreach ($rows as $row) {
                $valueRows[] = BaseMacro::valuesRowSyntax($this, count($columns));
                foreach ($columns as $col) {
                    $bindings[] = $row[$col] ?? null;
                }
            }

            $columnList = implode(', ', array_map(fn ($c) => BaseMacro::wrapIdentifier($builder, $c), $columns));
            $valuesSql = 'VALUES '.implode(', ', $valueRows);

            $localKey = BaseMacro::assertValidIdentifier($localKey, 'localKey');
            $valuesKey = BaseMacro::assertValidIdentifier($valuesKey, 'valuesKey');
            $alias = BaseMacro::assertValidIdentifier($alias, 'alias');
            $wrappedAlias = BaseMacro::wrapIdentifier($this, $alias);
            $wrappedTable = BaseMacro::wrapIdentifier($this, $table);
            $wrappedLocal = BaseMacro::wrapIdentifier($this, $localKey);
            $wrappedValues = BaseMacro::wrapIdentifier($this, $valuesKey);

            // 构建 JOIN
            $joinMethod = match (strtolower($joinType)) {
                'left' => 'leftJoin',
                'right' => 'rightJoin',
                default => 'join',
            };

            $joinSql = "({$valuesSql}) AS {$wrappedAlias} ({$columnList})";

            return $this->{$joinMethod}(DB::raw($joinSql), function ($join) use ($wrappedTable, $wrappedLocal, $wrappedAlias, $wrappedValues) {
                $join->on("{$wrappedTable}.{$wrappedLocal}", '=', "{$wrappedAlias}.{$wrappedValues}");
            })->addBinding($bindings, 'join');
        });
    }

    /**
     * 注册 VALUES 批量插入方法
     *
     * @scenario 注册 VALUES 批量插入方法
     */
    public static function registerValuesInsert(): void
    {
        /**
         * valuesInsert - 使用 VALUES ROW 语法进行高效批量插入
         * 比传统多条 INSERT 语句更高效，减少网络往返
         *
         * @param  array  $rows  要插入的数据行
         * @param  int  $chunkSize  每批次大小（避免 SQL 过长）
         * @return int 影响行数
         *
         * @example
         * // 批量插入日志
         * Log::query()->valuesInsert([
         *     ['level' => 'info', 'message' => 'User login', 'created_at' => now()],
         *     ['level' => 'error', 'message' => 'DB fail', 'created_at' => now()],
         *     ['level' => 'warning', 'message' => 'High load', 'created_at' => now()],
         * ]);
         * // 大批量插入（自动分块）
         * $data = array_map(fn ($i) => ['name' => "Item {$i}", 'sort' => $i], range(1, 10000));
         * Item::query()->valuesInsert($data, 1000);
         *
         * @scenario valuesInsert - 使用 VALUES ROW 语法进行高效批量插入
         */
        static::define('valuesInsert', function (
            array $rows,
            int $chunkSize = 1000
        ): int {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $builder = $this;
            $model = BaseMacro::modelOf($this);
            $table = $model->getTable();

            if (empty($rows)) {
                return 0;
            }

            $columns = ValuesMacro::assertRowColumns($rows);
            $columnStr = implode(', ', array_map(fn ($c) => BaseMacro::wrapIdentifier($builder, $c), $columns));
            $wrappedTable = BaseMacro::wrapIdentifier($this, $table);
            $totalAffected = 0;

            $chunks = array_chunk($rows, $chunkSize);

            foreach ($chunks as $chunk) {
                $valueRows = [];
                $bindings = [];

                foreach ($chunk as $row) {
                    // 使用驱动抽象生成 VALUES 行语法：MySQL 为 ROW(?, ?, ...)，PostgreSQL 为 (?, ?, ...)。
                    $valueRows[] = BaseMacro::valuesRowSyntax($this, count($columns));
                    foreach ($columns as $col) {
                        $bindings[] = $row[$col] ?? null;
                    }
                }

                $sql = "INSERT INTO {$wrappedTable} ({$columnStr}) VALUES ".implode(', ', $valueRows);
                $totalAffected += DB::affectingStatement($sql, $bindings);
            }

            return $totalAffected;
        });
    }

    /**
     * 注册批量 Upsert 方法
     *
     * @scenario 注册批量 Upsert 方法
     */
    public static function registerBatchUpsert(): void
    {
        /**
         * batchUpsert - 批量插入或更新（INSERT ... ON DUPLICATE KEY UPDATE）
         *
         * @param  array  $rows  数据行
         * @param  array|string  $uniqueBy  唯一键列
         * @param  array|null  $updateColumns  需要更新的列（null=更新所有非唯一列）
         * @param  int  $chunkSize  每批次大小
         * @return int 影响行数
         *
         * @example
         * // 批量导入用户（存在则更新，不存在则插入）
         * User::query()->batchUpsert([
         *     ['id' => 1, 'name' => '张三', 'email' => 'zhang@example.com', 'updated_at' => now()],
         *     ['id' => 2, 'name' => '李四', 'email' => 'li@example.com', 'updated_at' => now()],
         * ], 'id', ['name', 'email', 'updated_at']);
         * // 使用复合唯一键
         * Stat::query()->batchUpsert([
         *     ['date' => '2024-01-01', 'metric' => 'pv', 'value' => 1000],
         *     ['date' => '2024-01-01', 'metric' => 'uv', 'value' => 500],
         * ], ['date', 'metric'], ['value']);
         *
         * @scenario batchUpsert - 批量插入或更新（INSERT ... ON DUPLICATE KEY UPDATE）
         */
        static::define('batchUpsert', function (
            array $rows,
            array|string $uniqueBy,
            ?array $updateColumns = null,
            int $chunkSize = 1000
        ): int {
            /**
             * @var Builder|\Illuminate\Database\Query\Builder $this
             *
             * @scenario 本类/成员说明。
             */
            $builder = $this;
            $model = BaseMacro::modelOf($this);
            $table = $model->getTable();

            if (empty($rows)) {
                return 0;
            }

            $columns = ValuesMacro::assertRowColumns($rows);
            $columnStr = implode(', ', array_map(fn ($c) => BaseMacro::wrapIdentifier($builder, $c), $columns));
            $wrappedTable = BaseMacro::wrapIdentifier($this, $table);

            // 确定更新列
            $uniqueColumns = array_map(
                fn ($c) => BaseMacro::assertValidIdentifier((string) $c, 'uniqueBy'),
                is_array($uniqueBy) ? $uniqueBy : [$uniqueBy]
            );
            if ($updateColumns === null) {
                $updateColumns = array_diff($columns, $uniqueColumns);
            }
            $updateColumns = array_map(
                fn ($c) => BaseMacro::assertValidIdentifier((string) $c, 'updateColumn'),
                $updateColumns
            );

            // 构建 UPSERT 后缀：MySQL 走 ON DUPLICATE KEY UPDATE（引用 new_values 别名），
            // PostgreSQL 走 ON CONFLICT (唯一键) DO UPDATE SET（引用 EXCLUDED），均由 Dialect 生成。
            $wrappedUpdateColumns = array_map(fn ($c) => BaseMacro::wrapIdentifier($builder, $c), $updateColumns);
            $wrappedUniqueColumns = array_map(fn ($c) => BaseMacro::wrapIdentifier($builder, $c), $uniqueColumns);
            [$upsertSuffix, $upsertBindings] = BaseMacro::upsertSuffix($this, $wrappedUpdateColumns, $wrappedUniqueColumns);
            $isPostgres = BaseMacro::isPostgres($this);

            $totalAffected = 0;
            $chunks = array_chunk($rows, $chunkSize);

            foreach ($chunks as $chunk) {
                $valueRows = [];
                $bindings = [];

                foreach ($chunk as $row) {
                    $placeholders = array_fill(0, count($columns), '?');
                    $valueRows[] = '('.implode(', ', $placeholders).')';
                    foreach ($columns as $col) {
                        $bindings[] = $row[$col] ?? null;
                    }
                }

                // MySQL 仅在带 ON DUPLICATE KEY UPDATE 时允许 AS new_values 别名；PostgreSQL 不支持该别名。
                $newValuesAlias = (! $isPostgres && $upsertSuffix !== '') ? ' AS new_values' : '';
                $sql = "INSERT INTO {$wrappedTable} ({$columnStr}) VALUES ".implode(', ', $valueRows).$newValuesAlias;
                if ($upsertSuffix !== '') {
                    $sql .= " {$upsertSuffix}";
                }

                $totalAffected += DB::affectingStatement($sql, array_merge($bindings, $upsertBindings));
            }

            return $totalAffected;
        });
    }
}
