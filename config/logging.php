<?php

use zxf\Modules\Logging\Handler\DatabaseLogHandler;
use zxf\Modules\Logging\Handler\DatabaseLogTap;

/**
 * ============================================================================
 * zxf/modules —— 数据库日志通道（database channel）配置
 * ============================================================================
 * 本文件由 LoggingServiceProvider 通过 mergeConfigFrom 合并进应用的
 * config('logging')，从而向 logging.channels 注入一个名为 database 的通道。
 * 因此使用方式极为简单：在 .env 中设置
 * 即可让全站日志（以及每次 HTTP 请求自动产生的「请求 → 响应」访问记录）
 * 写入 logs 数据表。无需手动修改应用的 config/logging.php。
 * 如需覆盖默认行为，可在应用自身的 config/logging.php 中重新定义
 * channels.database 或 logging.db 段（应用配置优先）。
 *
 * 落库语义
 *   1. 每个 HTTP 请求记录一条「请求日志」：由 LogContextMiddleware 在响应采集完成后写入，
 *      承载完整上下文 —— request_*（方法 / URL / 路径 / 路由 / 控制器 / IP / UA / Referer /
 *      会话 ID / 入参 / 请求头 / 模块 / 认证用户 / 机器人识别）与 response_*（状态码 /
 *      状态短语 / 耗时 / 峰值内存 / 响应头 / 响应体 / 视图名或 JSON 数据）。
 *      幂等由 LogContext::claimRequestLog() 保证，配合 RequestHandled 兜底，绝不重复写两条。
 *   2. 业务日志逐条落库：请求期间 Log::info() / Log::error() / report($e) 各自写入一行，
 *      携带轻量请求上下文（方法 / URL / 路径 / IP / UA / 模块 / 用户 / 机器人）以便溯源；
 *      大体积的入参 / 请求头 / 响应体只存在于请求日志那一行，不重复存储。
 *   3. 命中 skip_paths 的请求（健康检查 / 调试面板 / 轮询等）一条日志都不写。
 *   4. CLI / 队列无请求上下文，逐条落库，由 log_cli 开关控制。
 *
 * logs 数据表核心字段说明（详见迁移）：
 *   - module_name       模块名称（由宿主应用的 module_name() 助手获取，小写下划线）
 *   - level             日志等级（PSR 小写键：error / info / warning / critical …，与 Monolog 一致）
 *   - is_crawler        是否为爬虫 / 机器人 / 自动化脚本请求（bool）
 *   - bot_type          客户端分类（search_engine / ai_crawler / http_library / headless …）
 *   - bot_score         判定置信分（0~100）；bot_client 命中的客户端；bot_signals 命中证据链
 *   - response_content  响应内容：视图页面响应记录「视图页面地址（视图名）」，JSON 响应记录「JSON 数据」
 *
 * 爬虫与脚本检测（BotDetector）能力概览：
 *   - 搜索引擎 / AI 训练爬虫（GPTBot、ClaudeBot、Bytespider、Perplexity…）
 *   - SEO 分析、监控拨测、社交分享、Feed 订阅器
 *   - 各语言 HTTP 客户端（Python requests/httpx/scrapy、Node axios/fetch/undici、
 *     Go net/http、Java OkHttp/HttpClient、PHP Guzzle/curl、Ruby、.NET、Rust reqwest…）
 *   - 无头浏览器与自动化驱动（Puppeteer、Playwright、Selenium、HeadlessChrome…）
 *   - 安全扫描器（sqlmap、nikto、nuclei、masscan、gobuster、ffuf…）
 *   - 攻击探测流量（SQL 注入 / XSS / 目录穿越 / 敏感文件路径）
 *   - 高级「脱壳伪装」识别：HTML 实体编码、二次 URL 编码、零宽字符、控制符 / CRLF 注入、
 *     全角半角混用、西里尔同形字、字母间插入分隔符；并结合请求头指纹一致性
 *     （自称 Chrome 却缺 Client Hints、Client Hints 品牌与 UA 矛盾、Accept 通配符 + 无 Cookie 等）
 *     给伪装成浏览器的脚本判定可信分数。
 */

return [

    // 注入到 config('logging.channels.database') 的数据库日志通道
    'channels' => [
        'database' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => DatabaseLogHandler::class,
            // handler_with 的键必须与 DatabaseLogHandler 构造参数名完全一致
            'handler_with' => [
                'model' => null,                 // 自定义日志模型（默认 zxf\Modules\Logging\Models\Log）
                'table' => null,                 // 自定义表名（默认 logs）
                'captureRequestBody' => true,    // 是否采集请求入参 / 请求头
                'captureResponseBody' => true,   // 是否采集响应头 / 响应体
            ],
            'tap' => [
                DatabaseLogTap::class,
            ],
            'processors' => [],
        ],
    ],

    // 本扩展的额外配置（读取：config('logging.db.*')）
    'db' => [
        // 是否允许 CLI 模式下的日志也写入数据库（默认 false；亦可单条日志携带
        // context['console_to_log'] = true 精准开启某条 CLI 日志）
        'log_cli' => env('LOG_DB_LOG_CLI', false),

        // 响应体最大存储字符数（中间件在采集响应体时按此截断）
        'max_body_length' => env('LOG_DB_MAX_BODY_LENGTH', 20000),

        // 单字段最大长度（字符）：任意日志字段值超出将被截断，防止超长数据导致落库异常
        // 或占用过多内存 / 存储。0 表示不截断（不推荐，仅用于特殊场景）。
        'max_field_length' => env('LOG_DB_MAX_FIELD_LENGTH', 20000),

        // 应用层追加的敏感字段键名（会与内置 DEFAULT_KEYS 合并，而非覆盖）
        'sensitive_keys' => [],

        // 是否通过队列异步落库（默认 false = 同步写入；开启后由队列 worker 真正写库，
        // 将数据库 I/O 从请求主链路剥离，提升高并发接口吞吐。需配置可用的队列驱动；
        // 未配置真实队列（默认 sync 驱动）时退化为同步写入，行为不变）。
        'queue' => env('LOG_DB_QUEUE', false),

        // 跨请求错误签名限流（默认 false）。
        // 开启后，同一错误签名在 burst_throttle_seconds 内只落库第一条，
        // 可消除「每个请求都报同一个错」造成的刷屏；
        // 但它会吞掉本应各自留痕的偶发错误，因此默认关闭。
        // 仅在确认存在「同一错误每秒数万次」的极端场景时才建议开启。
        'burst_throttle' => env('LOG_DB_BURST_THROTTLE', false),

        // 限流窗口（秒），仅在 burst_throttle 开启时生效
        'burst_throttle_seconds' => env('LOG_DB_BURST_THROTTLE_SECONDS', 60),

        // ------------------------------------------------------------------
        // 进程级缓存 TTL（秒）：均为「降低高频表结构探测开销」而存在，
        // 设为 0 表示永不过期（需手动调用 flushXxxCache() 才会失效）。
        // ------------------------------------------------------------------

        // 日志表存在性判定缓存：避免每个请求都查一次 information_schema / sqlite_master。
        'schema_cache_ttl' => env('LOG_DB_SCHEMA_CACHE_TTL', 60),

        // 日志表列清单缓存：避免每条日志都查一次表结构（列过滤用）。
        'column_cache_ttl' => env('LOG_DB_COLUMN_CACHE_TTL', 300),

        // 配置对象缓存：常驻进程（Octane / queue worker）下按此间隔重读 logging.db.*。
        'config_cache_ttl' => env('LOG_DB_CONFIG_CACHE_TTL', 300),

        // 需要挂载「请求上下文中间件」的中间件组。
        // web / api 为 Laravel 默认组；若应用自定义了路由组，请在此追加，
        // 否则该组下的请求不会产生请求日志（中间件别名：logging.context，可手动挂载）。
        'middleware_groups' => ['web', 'api'],

        // 完全不记录日志的路径（前缀匹配，支持 * 通配）。
        // 命中时该请求既不写请求日志也不写业务日志，
        // 因此健康检查、调试面板、Livewire 轮询等高频路径零开销、零噪音。
        'skip_paths' => [
            'telescope*',
            'horizon*',
            '_debugbar*',
            'livewire/livewire.js',
            'livewire/message/*',
            'sanctum/csrf-cookie',
            'up',
            'health',
            'healthz',
            'ready',
        ],

        // ------------------------------------------------------------------
        // 爬虫 / 自动化脚本请求检测
        // ------------------------------------------------------------------
        'bot_detection' => [
            // 是否启用检测（关闭后 is_crawler 恒为 false、bot_* 列为 null）
            'enabled' => env('LOG_DB_BOT_DETECTION', true),

            // 启发式判定阈值：证据分 ≥ 阈值即判定为爬虫 / 脚本。
            // 调高 → 更保守（仅确定性特征命中才算）；调低 → 更激进。
            'threshold' => env('LOG_DB_BOT_THRESHOLD', 40),

            // 无 User-Agent 的请求是否一律按爬虫处理。
            // 若应用存在大量「内部调用不带 UA」的场景，可设为 false 以免误伤。
            'mark_unknown_ua' => env('LOG_DB_BOT_MARK_UNKNOWN_UA', true),
        ],
    ],

];
