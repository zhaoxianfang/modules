<?php

declare(strict_types=1);

namespace zxf\Modules\Logging;

/**
 * 请求 / 响应上下文存储（请求级单例）。
 *
 * 设计：中间件在请求开始时填充请求信息，在响应结束（terminating）后填充响应信息；
 * Monolog 处理器在每条日志记录写入时读取此处数据，注入到 extra，最终落库。
 *
 * 之所以不直接依赖 Laravel 13 的 Context 门面作为唯一来源，是为了在不同 Laravel
 * 版本间行为一致、并精确控制写入字段；同时我们也会把数据同步进 Context 门面，
 * 供其它日志通道 / 处理器共享（见 LogContextMiddleware）。
 *
 * 同时承担「单次请求内去重集合」的存储：因为 PHP 常驻场景（Laravel Octane / Swoole）
 * 下，处理器中的静态变量会跨请求残留，导致去重错乱；统一放在 here 的 reset() 中
 * 随每次请求清空，即可保证去重仅在当前请求内生效。
 *
 * @package zxf\Modules\Logging
 */
final class LogContext
{
    private static array $request = [];

    private static array $response = [];

    private static bool $hasResponse = false;

    private static float $startTime = 0.0;

    /** 单次请求内去重集合（随 reset() 在每次请求开始时清空） */
    private static array $seen = [];

    /**
     * 日志表存在性检查结果（按请求缓存）。
     *
     * 挂在请求级静态存储（而非处理器实例）有两层考量：
     * 1. 避免每条日志都查询 information_schema（性能）；
     * 2. Octane / Swoole 常驻进程下，处理器实例会跨请求复用，若缓存挂在实例上会导致
     *    表存在性判定跨请求残留（表创建/删除后无法即时生效）。随 reset() 在每次请求
     *    开始时清空即可保证「每请求最多查一次」且判定始终正确。
     */
    private static ?bool $logsTableExists = null;

    public static function setStart(float $t): void
    {
        self::$startTime = $t;
    }

    public static function getStart(): float
    {
        return self::$startTime;
    }

    public static function setRequest(array $data): void
    {
        self::$request = $data;
    }

    public static function getRequest(): array
    {
        return self::$request;
    }

    public static function setResponse(array $data): void
    {
        self::$response = $data;
        self::$hasResponse = true;
    }

    public static function getResponse(): array
    {
        return self::$response;
    }

    public static function hasResponse(): bool
    {
        return self::$hasResponse;
    }

    /**
     * 请求内去重：是否已记录过该 key（level|message|exception）。
     */
    public static function hasSeen(string $key): bool
    {
        return isset(self::$seen[$key]);
    }

    public static function markSeen(string $key): void
    {
        self::$seen[$key] = true;
    }

    /**
     * 读取按请求缓存的日志表存在性判定（未检测过返回 null）。
     */
    public static function logsTableExists(): ?bool
    {
        return self::$logsTableExists;
    }

    /**
     * 缓存日志表存在性判定（随 reset() 在每次请求开始时清空）。
     */
    public static function setLogsTableExists(bool $exists): void
    {
        self::$logsTableExists = $exists;
    }

    /**
     * 重置全部状态。中间件在每个请求开始时调用，确保请求级上下文与去重集合
     * 不会跨请求（尤其是 Octane / Swoole 常驻进程）串味。
     */
    public static function reset(): void
    {
        self::$request = [];
        self::$response = [];
        self::$hasResponse = false;
        self::$startTime = 0.0;
        self::$seen = [];
        self::$logsTableExists = null;
    }
}
