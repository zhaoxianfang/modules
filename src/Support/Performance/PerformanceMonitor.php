<?php

namespace zxf\Modules\Support\Performance;

/**
 * 性能监控器
 *
 * 提供性能监控、分析和报告功能
 *
 * @date 2026-04-07
 */
class PerformanceMonitor
{
    /**
     * 性能指标
     */
    protected static array $metrics = [];

    /**
     * 计时器
     */
    protected static array $timers = [];

    /**
     * 是否启用监控
     */
    protected static bool $enabled = false;

    /**
     * 慢操作阈值（毫秒）
     */
    protected static int $slowThreshold = 100;

    /**
     * 启用监控
     */
    public static function enable(): void
    {
        self::$enabled = true;
    }

    /**
     * 禁用监控
     */
    public static function disable(): void
    {
        self::$enabled = false;
    }

    /**
     * 检查是否启用
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * 开始计时
     */
    public static function startTimer(string $name): void
    {
        if (! self::$enabled) {
            return;
        }

        self::$timers[$name] = [
            'start' => microtime(true),
            'memory_start' => memory_get_usage(),
        ];
    }

    /**
     * 停止计时
     */
    public static function stopTimer(string $name): ?array
    {
        if (! self::$enabled || ! isset(self::$timers[$name])) {
            return null;
        }

        $timer = self::$timers[$name];
        $end = microtime(true);
        $memoryEnd = memory_get_usage();

        $duration = ($end - $timer['start']) * 1000; // 毫秒
        $memoryUsed = ($memoryEnd - $timer['memory_start']) / 1024; // KB

        $metric = [
            'name' => $name,
            'duration_ms' => round($duration, 2),
            'memory_kb' => round($memoryUsed, 2),
            'timestamp' => date('Y-m-d H:i:s'),
            'slow' => $duration > self::$slowThreshold,
        ];

        self::$metrics[] = $metric;

        unset(self::$timers[$name]);

        return $metric;
    }

    /**
     * 记录自定义指标
     */
    public static function record(string $name, float $value, string $unit = 'ms', array $tags = []): void
    {
        if (! self::$enabled) {
            return;
        }

        self::$metrics[] = [
            'name' => $name,
            'value' => $value,
            'unit' => $unit,
            'tags' => $tags,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 执行并监控
     *
     * @template T
     * @param string $name
     * @param callable(): T $callback
     * @return T
     */
    public static function measure(string $name, callable $callback): mixed
    {
        self::startTimer($name);

        try {
            return $callback();
        } finally {
            self::stopTimer($name);
        }
    }

    /**
     * 获取所有指标
     */
    public static function getMetrics(): array
    {
        return self::$metrics;
    }

    /**
     * 获取慢操作
     */
    public static function getSlowOperations(): array
    {
        return array_filter(self::$metrics, fn ($m) => $m['slow'] ?? false);
    }

    /**
     * 获取统计摘要
     */
    public static function getSummary(): array
    {
        $summary = [
            'total_operations' => count(self::$metrics),
            'slow_operations' => 0,
            'total_duration_ms' => 0,
            'avg_duration_ms' => 0,
            'max_duration_ms' => 0,
            'by_name' => [],
        ];

        foreach (self::$metrics as $metric) {
            if (! isset($metric['duration_ms'])) {
                continue;
            }

            $name = $metric['name'];
            $duration = $metric['duration_ms'];

            if ($metric['slow'] ?? false) {
                $summary['slow_operations']++;
            }

            $summary['total_duration_ms'] += $duration;
            $summary['max_duration_ms'] = max($summary['max_duration_ms'], $duration);

            if (! isset($summary['by_name'][$name])) {
                $summary['by_name'][$name] = [
                    'count' => 0,
                    'total_ms' => 0,
                    'avg_ms' => 0,
                    'max_ms' => 0,
                    'slow_count' => 0,
                ];
            }

            $summary['by_name'][$name]['count']++;
            $summary['by_name'][$name]['total_ms'] += $duration;
            $summary['by_name'][$name]['max_ms'] = max($summary['by_name'][$name]['max_ms'], $duration);

            if ($metric['slow'] ?? false) {
                $summary['by_name'][$name]['slow_count']++;
            }
        }

        if ($summary['total_operations'] > 0) {
            $summary['avg_duration_ms'] = round($summary['total_duration_ms'] / $summary['total_operations'], 2);
        }

        foreach ($summary['by_name'] as &$nameStats) {
            $nameStats['avg_ms'] = round($nameStats['total_ms'] / $nameStats['count'], 2);
        }

        $summary['total_duration_ms'] = round($summary['total_duration_ms'], 2);

        return $summary;
    }

    /**
     * 生成报告
     */
    public static function generateReport(): array
    {
        return [
            'enabled' => self::$enabled,
            'summary' => self::getSummary(),
            'slow_operations' => self::getSlowOperations(),
            'memory' => [
                'current_mb' => round(memory_get_usage() / 1024 / 1024, 2),
                'peak_mb' => round(memory_get_peak_usage() / 1024 / 1024, 2),
            ],
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 重置所有指标
     */
    public static function reset(): void
    {
        self::$metrics = [];
        self::$timers = [];
    }

    /**
     * 设置慢操作阈值
     */
    public static function setSlowThreshold(int $milliseconds): void
    {
        self::$slowThreshold = $milliseconds;
    }

    /**
     * 获取慢操作阈值
     */
    public static function getSlowThreshold(): int
    {
        return self::$slowThreshold;
    }
}
