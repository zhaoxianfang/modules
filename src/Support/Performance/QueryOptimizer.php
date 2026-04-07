<?php

namespace zxf\Modules\Support\Performance;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 查询优化器
 *
 * 提供查询性能优化功能，包括索引提示、查询重写、执行计划分析等
 *
 * @date 2026-04-07
 */
class QueryOptimizer
{
    /**
     * 查询优化建议缓存
     */
    protected static array $optimizationCache = [];

    /**
     * 慢查询阈值（毫秒）
     */
    protected static int $slowQueryThreshold = 1000;

    /**
     * 启用查询分析
     */
    protected static bool $enableAnalysis = false;

    /**
     * 查询日志
     */
    protected static array $queryLog = [];

    /**
     * 注册查询优化宏
     */
    public static function register(): void
    {
        // useIndex - 强制使用索引
        Builder::macro('useIndex', function (string ...$indexes) {
            /** @var Builder $this */
            $indexList = implode(', ', $indexes);
            $table = $this->from;

            $this->from = DB::raw("{$table} USE INDEX ({$indexList})");

            return $this;
        });

        // forceIndex - 强制使用指定索引
        Builder::macro('forceIndex', function (string ...$indexes) {
            /** @var Builder $this */
            $indexList = implode(', ', $indexes);
            $table = $this->from;

            $this->from = DB::raw("{$table} FORCE INDEX ({$indexList})");

            return $this;
        });

        // ignoreIndex - 忽略索引
        Builder::macro('ignoreIndex', function (string ...$indexes) {
            /** @var Builder $this */
            $indexList = implode(', ', $indexes);
            $table = $this->from;

            $this->from = DB::raw("{$table} IGNORE INDEX ({$indexList})");

            return $this;
        });

        // optimizeFor - 优化查询以获取前 N 条记录
        Builder::macro('optimizeFor', function (int $limit) {
            /** @var Builder $this */
            return $this->limit($limit)->hint('LIMIT_ROWS', $limit);
        });

        // parallel - 并行查询提示（MySQL 8.4 InnoDB 并行扫描）
        Builder::macro('parallel', function (int $threads = 4) {
            /** @var Builder $this */
            return $this->hint('PARALLEL', $threads);
        });

        // bufferResult - 缓冲结果（小结果集优化）
        Builder::macro('bufferResult', function () {
            /** @var Builder $this */
            return $this->hint('SQL_BUFFER_RESULT');
        });

        // noCache - 禁用查询缓存（MySQL 8.4 查询缓存已移除，保留兼容性）
        Builder::macro('noCache', function () {
            /** @var Builder $this */
            return $this;
        });

        // calcFoundRows - 计算总行数（优化分页）
        Builder::macro('calcFoundRows', function () {
            /** @var Builder $this */
            return $this->hint('SQL_CALC_FOUND_ROWS');
        });

        // straightJoin - 强制按指定顺序连接表
        Builder::macro('straightJoin', function () {
            /** @var Builder $this */
            return $this->hint('STRAIGHT_JOIN');
        });

        // forUpdateSkipLocked - 跳过锁定的行（乐观锁优化）
        Builder::macro('forUpdateSkipLocked', function () {
            /** @var Builder $this */
            return $this->lock('FOR UPDATE SKIP LOCKED');
        });

        // forUpdateNowait - 不等待锁（立即返回）
        Builder::macro('forUpdateNowait', function () {
            /** @var Builder $this */
            return $this->lock('FOR UPDATE NOWAIT');
        });

        // shareLockSkipLocked - 共享锁跳过锁定
        Builder::macro('shareLockSkipLocked', function () {
            /** @var Builder $this */
            return $this->lock('LOCK IN SHARE MODE SKIP LOCKED');
        });

        // batchUpdate - 批量更新优化
        Builder::macro('batchUpdate', function (string $table, string $key, array $data, int $batchSize = 1000) {
            /** @var Builder $this */
            $chunks = array_chunk($data, $batchSize, true);
            $affected = 0;

            foreach ($chunks as $chunk) {
                $cases = [];
                $ids = [];
                $bindings = [];

                foreach ($chunk as $id => $values) {
                    $ids[] = $id;
                    foreach ($values as $column => $value) {
                        $cases[$column][] = "WHEN {$key} = ? THEN ?";
                        $bindings[] = $id;
                        $bindings[] = $value;
                    }
                }

                $setClauses = [];
                foreach ($cases as $column => $caseStatements) {
                    $setClauses[] = "{$column} = CASE " . implode(' ', $caseStatements) . " ELSE {$column} END";
                }

                $idList = implode(', ', $ids);
                $sql = "UPDATE {$table} SET " . implode(', ', $setClauses) . " WHERE {$key} IN ({$idList})";

                $affected += DB::update($sql, $bindings);
            }

            return $affected;
        });

        // insertIgnore - 插入忽略重复（优化批量插入）
        Builder::macro('insertIgnore', function (array $values) {
            /** @var Builder $this */
            if (empty($values)) {
                return true;
            }

            $table = $this->from;
            $columns = array_keys(is_array(reset($values)) ? reset($values) : $values);
            $columnStr = implode(', ', $columns);

            if (! is_array(reset($values))) {
                $values = [$values];
            }

            $placeholders = [];
            $bindings = [];
            foreach ($values as $row) {
                $rowPlaceholders = [];
                foreach ($columns as $column) {
                    $rowPlaceholders[] = '?';
                    $bindings[] = $row[$column] ?? null;
                }
                $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
            }

            $sql = "INSERT IGNORE INTO {$table} ({$columnStr}) VALUES " . implode(', ', $placeholders);

            return DB::insert($sql, $bindings);
        });

        // insertOnDuplicate - 插入或更新（优化批量操作）
        Builder::macro('insertOnDuplicate', function (array $values, ?array $updateColumns = null) {
            /** @var Builder $this */
            if (empty($values)) {
                return true;
            }

            $table = $this->from;
            $columns = array_keys(is_array(reset($values)) ? reset($values) : $values);
            $columnStr = implode(', ', $columns);

            if (! is_array(reset($values))) {
                $values = [$values];
            }

            $placeholders = [];
            $bindings = [];
            foreach ($values as $row) {
                $rowPlaceholders = [];
                foreach ($columns as $column) {
                    $rowPlaceholders[] = '?';
                    $bindings[] = $row[$column] ?? null;
                }
                $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
            }

            $updateColumns = $updateColumns ?? $columns;
            $updateClauses = [];
            foreach ($updateColumns as $col) {
                $updateClauses[] = "{$col} = VALUES({$col})";
            }

            $sql = "INSERT INTO {$table} ({$columnStr}) VALUES " . implode(', ', $placeholders) .
                " ON DUPLICATE KEY UPDATE " . implode(', ', $updateClauses);

            return DB::insert($sql, $bindings);
        });

        // upsert - 现代批量插入更新（MySQL 8.4 优化）
        Builder::macro('upsert', function (array $values, array $uniqueBy, array $update = null) {
            /** @var Builder $this */
            if (empty($values)) {
                return 0;
            }

            $table = $this->from;
            $columns = array_keys(is_array(reset($values)) ? reset($values) : $values);

            if (! is_array(reset($values))) {
                $values = [$values];
            }

            // 构建 VALUES
            $placeholders = [];
            $bindings = [];
            foreach ($values as $row) {
                $rowPlaceholders = [];
                foreach ($columns as $column) {
                    $rowPlaceholders[] = '?';
                    $bindings[] = $row[$column] ?? null;
                }
                $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
            }

            $columnStr = implode(', ', $columns);

            // 构建 ON DUPLICATE KEY UPDATE
            $updateColumns = $update ?? array_diff($columns, $uniqueBy);
            $updateClauses = [];
            foreach ($updateColumns as $col) {
                $updateClauses[] = "{$col} = VALUES({$col})";
            }

            $sql = "INSERT INTO {$table} ({$columnStr}) VALUES " . implode(', ', $placeholders);

            if (! empty($updateClauses)) {
                $sql .= " ON DUPLICATE KEY UPDATE " . implode(', ', $updateClauses);
            } else {
                $sql .= " ON DUPLICATE KEY UPDATE " . implode(', ', array_map(fn ($c) => "{$c} = {$c}", $uniqueBy));
            }

            return DB::affectingStatement($sql, $bindings);
        });

        // lazyChunk - 惰性分块查询（减少内存占用）
        Builder::macro('lazyChunk', function (int $size = 1000, ?string $column = 'id', string $order = 'asc') {
            /** @var Builder $this */
            return function () use ($size, $column, $order) {
                $lastId = 0;
                $direction = strtolower($order) === 'desc' ? '<' : '>';

                do {
                    $clone = clone $this;
                    $results = $clone->where($column, $direction, $lastId)
                        ->orderBy($column, $order)
                        ->limit($size)
                        ->get();

                    $count = $results->count();

                    if ($count > 0) {
                        $lastId = $results->last()->{$column};
                        yield $results;
                    }
                } while ($count === $size);
            };
        });

        // lazyById - 按 ID 惰性迭代（高效处理大数据集）
        Builder::macro('lazyById', function (int $chunkSize = 1000, ?string $column = 'id', ?string $alias = null) {
            /** @var Builder $this */
            $alias = $alias ?? $column;

            return $this->lazyChunk($chunkSize, $column)();
        });
    }

    /**
     * 添加查询提示
     */
    public static function addHint(string $query, string $hint, $value = null): string
    {
        $hintStr = $value !== null ? "/*+ {$hint}({$value}) */" : "/*+ {$hint} */";

        return preg_replace('/^\s*(SELECT|INSERT|UPDATE|DELETE)/i', "$hintStr $1", $query);
    }

    /**
     * 分析查询性能
     */
    public static function analyzeQuery(string $sql, array $bindings = []): array
    {
        $key = md5($sql);

        if (isset(self::$optimizationCache[$key])) {
            return self::$optimizationCache[$key];
        }

        $explain = DB::select("EXPLAIN ANALYZE {$sql}", $bindings);

        $analysis = [
            'sql' => $sql,
            'explain' => $explain,
            'suggestions' => self::generateSuggestions($explain),
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        if (self::$enableAnalysis) {
            self::$optimizationCache[$key] = $analysis;
        }

        return $analysis;
    }

    /**
     * 生成优化建议
     */
    protected static function generateSuggestions(array $explain): array
    {
        $suggestions = [];

        foreach ($explain as $row) {
            if (isset($row->type) && $row->type === 'ALL') {
                $suggestions[] = '考虑添加索引以避免全表扫描';
            }

            if (isset($row->Extra) && str_contains($row->Extra, 'Using filesort')) {
                $suggestions[] = '考虑添加排序索引以优化 filesort';
            }

            if (isset($row->Extra) && str_contains($row->Extra, 'Using temporary')) {
                $suggestions[] = '考虑优化查询以减少临时表使用';
            }

            if (isset($row->rows) && $row->rows > 10000) {
                $suggestions[] = '扫描行数较多，考虑添加更精确的过滤条件';
            }
        }

        return array_unique($suggestions);
    }

    /**
     * 启用查询分析
     */
    public static function enableAnalysis(): void
    {
        self::$enableAnalysis = true;
    }

    /**
     * 禁用查询分析
     */
    public static function disableAnalysis(): void
    {
        self::$enableAnalysis = false;
    }

    /**
     * 设置慢查询阈值
     */
    public static function setSlowQueryThreshold(int $milliseconds): void
    {
        self::$slowQueryThreshold = $milliseconds;
    }

    /**
     * 记录查询
     */
    public static function logQuery(string $sql, float $time): void
    {
        if ($time > self::$slowQueryThreshold) {
            self::$queryLog[] = [
                'sql' => $sql,
                'time' => $time,
                'slow' => true,
                'timestamp' => date('Y-m-d H:i:s'),
            ];
        }
    }

    /**
     * 获取慢查询日志
     */
    public static function getSlowQueries(): array
    {
        return array_filter(self::$queryLog, fn ($q) => $q['slow'] ?? false);
    }

    /**
     * 清除查询日志
     */
    public static function clearLogs(): void
    {
        self::$queryLog = [];
        self::$optimizationCache = [];
    }
}
