<?php

/**
 * ============================================================================
 * zxf/modules —— 数据库日志通道（database channel）配置
 * ============================================================================
 *
 * 本文件由 LoggingServiceProvider 通过 mergeConfigFrom 合并进应用的
 * config('logging')，从而向 logging.channels 注入一个名为 database 的通道。
 *
 * 因此使用方式极为简单：在 .env 中设置
 *
 *     LOG_CHANNEL=database
 *
 * 即可让全站日志（以及每次 HTTP 请求自动产生的「请求 → 响应」汇总日志）写入
 * system_logs 数据表。无需手动修改应用的 config/logging.php。
 *
 * 如需覆盖默认行为，可在应用自身的 config/logging.php 中重新定义
 * channels.database 或 logging.db 段（应用配置优先）。
 *
 * @package zxf\Modules\Logging
 */

return [

    // 注入到 config('logging.channels.database') 的数据库日志通道
    'channels' => [
        'database' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => \zxf\Modules\Logging\Handler\DatabaseLogHandler::class,
            // handler_with 的键必须与 DatabaseLogHandler 构造参数名完全一致
            'handler_with' => [
                'model' => null,                 // 自定义日志模型（默认 SystemLog）
                'table' => null,                 // 自定义表名（默认 SystemLog::$table）
                'captureRequestBody' => true,     // 是否采集请求入参 / 请求头
                'captureResponseBody' => true,    // 是否采集响应头 / 响应体
                'maxBodyLength' => 20000,        // 响应体最大存储字符数
                'retentionDays' => 30,           // 默认保留天数（供 logs:clean 使用）
            ],
            'tap' => [
                \zxf\Modules\Logging\Handler\DatabaseLogTap::class,
            ],
            'processors' => [],
        ],
    ],

    // 本扩展的额外配置（读取：config('logging.db.*')）
    'db' => [
        'viewer_enabled' => env('LOG_DB_VIEWER_ENABLED', true),
        'viewer_prefix' => env('LOG_DB_VIEWER_PREFIX', 'logs'),
        'viewer_middleware' => ['web'],
        'retention_days' => env('LOG_DB_RETENTION_DAYS', 30),
    ],

];
