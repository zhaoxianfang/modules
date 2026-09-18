<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 系统日志模型（数据库日志通道落库表 system_logs）。
 *
 * 一条记录代表一条日志，并尽可能完整地携带触发它的「请求」与「响应」上下文，
 * 便于事后在后台检索、复盘问题（尤其是生产环境的 500 / 异常）。
 *
 * @property int         $id
 * @property string      $level            等级键（error）
 * @property string      $level_name       等级名（ERROR）
 * @property string|null $channel
 * @property string      $message
 * @property array|null  $context
 * @property array|null  $extra
 * @property string|null $request_method
 * @property string|null $request_url
 * @property string|null $request_path
 * @property string|null $request_route
 * @property string|null $request_controller
 * @property string|null $request_ip
 * @property string|null $request_user_agent
 * @property string|null $request_referer
 * @property string|null $request_session_id
 * @property array|null  $request_input
 * @property array|null  $request_headers
 * @property int|null    $user_id
 * @property string|null $user_type
 * @property int|null    $response_code
 * @property string|null $response_status
 * @property int|null    $response_duration_ms
 * @property float|null  $response_memory_mb
 * @property array|null  $response_headers
 * @property string|null $response_body
 * @property string|null $exception_class
 * @property string|null $exception_message
 * @property string|null $file
 * @property string|null $trace
 *
 * @package zxf\Modules\Logging
 */
class SystemLog extends Model
{
    protected $table = 'system_logs';

    protected $fillable = [
        'level', 'level_name', 'channel', 'message', 'context', 'extra',
        'request_method', 'request_url', 'request_path', 'request_route', 'request_controller',
        'request_ip', 'request_user_agent', 'request_referer', 'request_session_id',
        'request_input', 'request_headers',
        'user_id', 'user_type',
        'response_code', 'response_status', 'response_duration_ms', 'response_memory_mb',
        'response_headers', 'response_body',
        'exception_class', 'exception_message', 'file', 'trace',
    ];

    protected $casts = [
        'context' => 'array',
        'extra' => 'array',
        'request_input' => 'array',
        'request_headers' => 'array',
        'response_headers' => 'array',
        'response_duration_ms' => 'integer',
        'response_memory_mb' => 'decimal:2',
    ];

    public $timestamps = true;

    /** 按等级名查询（如 'ERROR'） */
    public function scopeLevel($query, string $level)
    {
        return $query->where('level_name', strtoupper($level));
    }

    /** 按通道查询 */
    public function scopeOfChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    /** 最近 N 天 */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /** 仅异常 / 错误级别 */
    public function scopeErrors($query)
    {
        return $query->whereIn('level_name', ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY']);
    }
}
