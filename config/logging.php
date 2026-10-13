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
 *     LOG_CHANNEL=database
 * 即可让全站日志（以及每次 HTTP 请求自动产生的「请求 → 响应」汇总日志）写入
 * logs 数据表。无需手动修改应用的 config/logging.php。
 * 如需覆盖默认行为，可在应用自身的 config/logging.php 中重新定义
 * channels.database 或 logging.db 段（应用配置优先）。
 * logs 数据表核心字段说明（详见迁移）：
 *   - module_name      模块名称（由宿主应用的 module_name() 助手获取，小写下划线）
 *   - level            日志等级（PSR 小写键：error / info / warning / critical …，与 Monolog 一致）
 *   - is_crawler       是否为爬虫 / 机器人请求（bool）
 *   - response_content 响应内容：视图页面响应记录「视图页面地址（视图名）」，JSON 响应记录「JSON 数据」
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
                'captureRequestBody' => true,     // 是否采集请求入参 / 请求头
                'captureResponseBody' => true,    // 是否采集响应头 / 响应体
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
    ],

];
