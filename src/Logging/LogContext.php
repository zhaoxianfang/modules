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
 * @package zxf\Modules\Logging
 */
final class LogContext
{
    private static array $request = [];

    private static array $response = [];

    private static bool $hasResponse = false;

    private static float $startTime = 0.0;

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

    public static function reset(): void
    {
        self::$request = [];
        self::$response = [];
        self::$hasResponse = false;
        self::$startTime = 0.0;
    }

    public static function all(): array
    {
        return ['request' => self::$request, 'response' => self::$response];
    }
}
