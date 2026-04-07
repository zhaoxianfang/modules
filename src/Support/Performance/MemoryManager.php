<?php

namespace zxf\Modules\Support\Performance;

/**
 * 内存管理器
 *
 * 提供内存优化和监控功能，减少内存占用
 *
 * @date 2026-04-07
 */
class MemoryManager
{
    /**
     * 内存使用日志
     *
     * @var array<string, array>
     */
    protected static array $memoryLog = [];

    /**
     * 内存限制（MB）
     */
    protected static int $memoryLimit = 512;

    /**
     * 是否启用自动垃圾回收
     */
    protected static bool $autoGc = true;

    /**
     * 垃圾回收阈值（MB）
     */
    protected static int $gcThreshold = 256;

    /**
     * 对象缓存池
     *
     * @var array<string, array<object>>
     */
    protected static array $objectPool = [];

    /**
     * 初始化内存管理
     */
    public static function init(): void
    {
        // 设置内存限制
        $limit = ini_get('memory_limit');
        if ($limit !== '-1') {
            self::$memoryLimit = (int) self::parseMemoryLimit($limit);
        }

        // 注册关闭时的内存清理
        register_shutdown_function([self::class, 'cleanup']);
    }

    /**
     * 获取当前内存使用（MB）
     */
    public static function getUsage(bool $real = false): float
    {
        return memory_get_usage($real) / 1024 / 1024;
    }

    /**
     * 获取峰值内存使用（MB）
     */
    public static function getPeakUsage(bool $real = false): float
    {
        return memory_get_peak_usage($real) / 1024 / 1024;
    }

    /**
     * 记录内存使用点
     */
    public static function mark(string $label): void
    {
        self::$memoryLog[$label] = [
            'usage' => self::getUsage(),
            'peak' => self::getPeakUsage(),
            'timestamp' => microtime(true),
        ];
    }

    /**
     * 获取内存使用报告
     */
    public static function getReport(): array
    {
        $report = [];
        $prev = null;

        foreach (self::$memoryLog as $label => $data) {
            $report[$label] = [
                'usage_mb' => round($data['usage'], 2),
                'peak_mb' => round($data['peak'], 2),
                'delta_mb' => $prev ? round($data['usage'] - $prev['usage'], 2) : 0,
                'time_ms' => $prev ? round(($data['timestamp'] - $prev['timestamp']) * 1000, 2) : 0,
            ];
            $prev = $data;
        }

        return $report;
    }

    /**
     * 检查内存是否充足
     */
    public static function hasEnoughMemory(int $requiredMb = 64): bool
    {
        $current = self::getUsage(true);
        $limit = self::$memoryLimit;

        return ($limit - $current) > $requiredMb;
    }

    /**
     * 执行垃圾回收
     */
    public static function gc(bool $force = false): void
    {
        if (! $force && ! self::$autoGc) {
            return;
        }

        $current = self::getUsage(true);

        if ($force || $current > self::$gcThreshold) {
            gc_collect_cycles();

            // 清理对象池
            self::cleanupPool();
        }
    }

    /**
     * 尝试释放内存
     */
    public static function freeMemory(): void
    {
        // 执行垃圾回收
        gc_collect_cycles();

        // 清理对象池
        self::cleanupPool();

        // 重置峰值统计
        memory_reset_peak_usage();
    }

    /**
     * 批量处理大数据集（控制内存使用）
     *
     * @template T
     * @param iterable<T> $items
     * @param callable(T): void $callback
     * @param int $batchSize 每批处理数量
     * @param int $memoryCheckInterval 内存检查间隔（每多少条检查一次）
     */
    public static function batchProcess(
        iterable $items,
        callable $callback,
        int $batchSize = 1000,
        int $memoryCheckInterval = 100
    ): void {
        $batch = [];
        $count = 0;

        foreach ($items as $item) {
            $batch[] = $item;
            $count++;

            // 达到批次大小，处理并清理
            if (count($batch) >= $batchSize) {
                foreach ($batch as $batchItem) {
                    $callback($batchItem);
                }

                $batch = [];

                // 定期检查内存
                if ($count % $memoryCheckInterval === 0) {
                    self::gc();
                }
            }
        }

        // 处理剩余项目
        foreach ($batch as $batchItem) {
            $callback($batchItem);
        }

        // 最终清理
        self::gc(true);
    }

    /**
     * 流式处理（最小内存占用）
     *
     * @template T
     * @param iterable<T> $items
     * @param callable(T): void $callback
     */
    public static function streamProcess(iterable $items, callable $callback): void
    {
        foreach ($items as $item) {
            $callback($item);
            unset($item);
        }

        self::gc(true);
    }

    /**
     * 从对象池获取对象
     *
     * @template T
     * @param class-string<T> $class
     * @return T
     */
    public static function acquire(string $class, array $args = [])
    {
        $key = $class . ':' . md5(serialize($args));

        if (isset(self::$objectPool[$key]) && ! empty(self::$objectPool[$key])) {
            return array_pop(self::$objectPool[$key]);
        }

        return new $class(...$args);
    }

    /**
     * 归还对象到对象池
     */
    public static function release(object $object, ?string $class = null): void
    {
        $class = $class ?? get_class($object);
        $key = $class . ':*';

        if (! isset(self::$objectPool[$key])) {
            self::$objectPool[$key] = [];
        }

        // 限制池大小
        if (count(self::$objectPool[$key]) < 100) {
            self::$objectPool[$key][] = $object;
        }
    }

    /**
     * 清理对象池
     */
    public static function cleanupPool(): void
    {
        self::$objectPool = [];
    }

    /**
     * 优化数组内存使用
     */
    public static function optimizeArray(array &$array, bool $reindex = true): void
    {
        if ($reindex) {
            $array = array_values($array);
        }

        // 触发内存整理
        gc_collect_cycles();
    }

    /**
     * 生成器包装（自动内存管理）
     *
     * @template T
     * @param iterable<T> $items
     * @return \Generator<T>
     */
    public static function lazy(iterable $items): \Generator
    {
        $count = 0;

        foreach ($items as $key => $item) {
            yield $key => $item;

            $count++;

            // 定期垃圾回收
            if ($count % 1000 === 0) {
                self::gc();
            }
        }
    }

    /**
     * 设置内存限制
     */
    public static function setMemoryLimit(int $mb): void
    {
        self::$memoryLimit = $mb;
        ini_set('memory_limit', $mb . 'M');
    }

    /**
     * 设置垃圾回收阈值
     */
    public static function setGcThreshold(int $mb): void
    {
        self::$gcThreshold = $mb;
    }

    /**
     * 启用/禁用自动垃圾回收
     */
    public static function setAutoGc(bool $enabled): void
    {
        self::$autoGc = $enabled;
    }

    /**
     * 清理所有资源
     */
    public static function cleanup(): void
    {
        self::cleanupPool();
        self::$memoryLog = [];

        gc_collect_cycles();
    }

    /**
     * 解析内存限制字符串
     */
    protected static function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;

        return match ($unit) {
            'g' => $value * 1024,
            'm' => $value,
            'k' => (int) ($value / 1024),
            default => (int) ($value / 1024 / 1024),
        };
    }
}
