<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Config;

use Illuminate\Foundation\Application;

/**
 * 数据库日志模块强类型配置对象。
 * 把散落在 config('logging.db.*') 与 config('logging.channels.database.handler_with.*')
 * 的读取收敛为单一 DTO，各组件（handler / middleware / 脱敏器）统一读取，避免口径不一致、
 * 重复解析；同时在缺少 Laravel 环境（如纯单元测试）时自动回退默认值，可独立测试。
 */
final class LoggingConfig
{
    /**
     * @param  bool  $logCli  是否允许 CLI 模式日志落库
     * @param  bool  $captureRequestBody  是否采集请求入参 / 请求头
     * @param  bool  $captureResponseBody  是否采集响应头 / 响应体
     * @param  int  $maxBodyLength  响应体最大存储字符数
     * @param  int  $maxFieldLength  单字段最大长度（字符），超出截断，防超长数据异常
     * @param  bool  $queue  是否通过队列异步落库
     * @param  string[]  $sensitiveKeys  应用层追加的敏感字段键名
     */
    public function __construct(
        public bool $logCli = false,
        public bool $captureRequestBody = true,
        public bool $captureResponseBody = true,
        public int $maxBodyLength = 20000,
        public int $maxFieldLength = 20000,
        public bool $queue = false,
        public array $sensitiveKeys = [],
    ) {}

    /**
     * 从 Laravel 配置构建；无 Laravel 环境（或未启动）时全部回退默认值。
     */
    public static function fromConfig(): self
    {
        $db = self::cfg('logging.db', []);
        $with = self::cfg('logging.channels.database.handler_with', []);
        $db = is_array($db) ? $db : [];
        $with = is_array($with) ? $with : [];

        $sensitiveKeys = $db['sensitive_keys'] ?? [];
        if (! is_array($sensitiveKeys)) {
            $sensitiveKeys = [];
        }

        return new self(
            logCli: (bool) ($db['log_cli'] ?? false),
            captureRequestBody: (bool) ($with['captureRequestBody'] ?? true),
            captureResponseBody: (bool) ($with['captureResponseBody'] ?? true),
            maxBodyLength: (int) ($db['max_body_length'] ?? 20000),
            maxFieldLength: (int) ($db['max_field_length'] ?? 20000),
            queue: (bool) ($db['queue'] ?? false),
            sensitiveKeys: $sensitiveKeys,
        );
    }

    /**
     * 安全读取配置：仅当 Laravel 应用已启动（config 绑定可用）时才读取，
     * 否则（如纯单元测试 / 未启动的宿主环境）直接回退默认值。
     * 这样可避免在非 Laravel 环境下调用 config() 触发宿主全局异常处理器打印 500 跟踪页。
     */
    private static function cfg(string $key, mixed $default = null): mixed
    {
        if (! function_exists('config')
            || ! function_exists('app')
            || ! (app() instanceof Application)
            || ! app()->bound('config')) {
            return $default;
        }

        try {
            return config($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
