<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Config;

use Illuminate\Foundation\Application;

/**
 * 数据库日志模块强类型配置对象。
 *
 * 把散落在 config('logging.db.*') 与 config('logging.channels.database.handler_with.*')
 * 的读取收敛为单一 DTO，各组件（handler / middleware / 脱敏器）统一读取，避免口径不一致、
 * 重复解析；同时在缺少 Laravel 环境（如纯单元测试）时自动回退默认值，可独立测试。
 *
 * 落库语义：**每个 HTTP 请求记录一条请求日志**（携带完整请求 + 响应上下文），
 * 业务代码的 Log::xxx() 逐条落库（仅带轻量请求上下文）。
 *
 * 缓存相关配置（均为进程级，用于降低高频的表结构探测开销）：
 * - schemaCacheTtl  ：日志表存在性判定缓存时长（秒）；
 * - columnCacheTtl  ：日志表列清单缓存时长（秒）；
 * - configCacheTtl  ：本配置对象自身的缓存时长（秒）。
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
     * @param  bool  $burstThrottle  是否启用跨请求错误签名限流
     * @param  int  $burstThrottleSeconds  限流窗口（秒）
     * @param  int  $schemaCacheTtl  日志表存在性判定缓存时长（秒），0 = 永不过期
     * @param  int  $columnCacheTtl  日志表列清单缓存时长（秒），0 = 永不过期
     * @param  int  $configCacheTtl  配置对象缓存时长（秒），0 = 进程内只读一次
     * @param  string[]  $middlewareGroups  额外挂载上下文中间件的中间件组
     * @param  string[]  $skipPaths  完全不记录日志的路径前缀（健康检查、调试面板、轮询等）
     * @param  bool  $botEnabled  是否启用爬虫 / 脚本请求检测
     * @param  int  $botThreshold  判定为爬虫的置信分阈值
     * @param  bool  $botMarkUnknownUa  无 User-Agent 的请求是否一律按爬虫处理
     */
    public function __construct(
        public bool $logCli = false,
        public bool $captureRequestBody = true,
        public bool $captureResponseBody = true,
        public int $maxBodyLength = 20000,
        public int $maxFieldLength = 20000,
        public bool $queue = false,
        public array $sensitiveKeys = [],
        public bool $burstThrottle = false,
        public int $burstThrottleSeconds = 60,
        public int $schemaCacheTtl = 60,
        public int $columnCacheTtl = 300,
        public int $configCacheTtl = 300,
        public array $middlewareGroups = ['web', 'api'],
        public array $skipPaths = [],
        public bool $botEnabled = true,
        public int $botThreshold = 40,
        public bool $botMarkUnknownUa = true,
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

        $sensitiveKeys = self::toStringArray($db['sensitive_keys'] ?? []);
        $groups = self::toStringArray($db['middleware_groups'] ?? ['web', 'api']);
        $skipPaths = self::toStringArray($db['skip_paths'] ?? []);
        $bot = $db['bot_detection'] ?? [];
        $bot = is_array($bot) ? $bot : [];

        return new self(
            logCli: (bool) ($db['log_cli'] ?? false),
            captureRequestBody: (bool) ($with['captureRequestBody'] ?? true),
            captureResponseBody: (bool) ($with['captureResponseBody'] ?? true),
            maxBodyLength: max(0, (int) ($db['max_body_length'] ?? 20000)),
            maxFieldLength: max(0, (int) ($db['max_field_length'] ?? 20000)),
            queue: (bool) ($db['queue'] ?? false),
            sensitiveKeys: $sensitiveKeys,
            burstThrottle: (bool) ($db['burst_throttle'] ?? false),
            burstThrottleSeconds: max(1, (int) ($db['burst_throttle_seconds'] ?? 60)),
            schemaCacheTtl: max(0, (int) ($db['schema_cache_ttl'] ?? 60)),
            columnCacheTtl: max(0, (int) ($db['column_cache_ttl'] ?? 300)),
            configCacheTtl: max(0, (int) ($db['config_cache_ttl'] ?? 300)),
            middlewareGroups: $groups === [] ? ['web', 'api'] : $groups,
            skipPaths: $skipPaths,
            botEnabled: (bool) ($bot['enabled'] ?? true),
            botThreshold: max(1, (int) ($bot['threshold'] ?? 40)),
            botMarkUnknownUa: (bool) ($bot['mark_unknown_ua'] ?? true),
        );
    }

    /**
     * 路径是否命中「跳过记录」名单（支持 * 通配，如 livewire/*）。
     */
    public function isSkippedPath(?string $path): bool
    {
        if ($path === null || $path === '' || $this->skipPaths === []) {
            return false;
        }

        $path = '/'.ltrim($path, '/');

        foreach ($this->skipPaths as $pattern) {
            if ($pattern === '') {
                continue;
            }
            if (str_contains($pattern, '*')) {
                $regex = '#^'.str_replace('\*', '.*', preg_quote($pattern, '#')).'$#i';
                if (preg_match($regex, $path) === 1) {
                    return true;
                }

                continue;
            }
            if (str_starts_with($path, '/'.ltrim($pattern, '/'))) {
                return true;
            }
        }

        return false;
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

    /**
     * 强制归一为 string[]（过滤非字符串项，避免后续 str_starts_with 等触发类型错误）。
     *
     * @return string[]
     */
    private static function toStringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            } elseif (is_numeric($item)) {
                $out[] = (string) $item;
            }
        }

        return array_values(array_unique($out));
    }
}
