<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 系统日志模型（数据库日志通道落库表 logs）。
 *
 * 一条记录代表一条日志，并尽可能完整地携带触发它的「请求」与「响应」上下文，
 * 便于事后在后台检索、复盘问题（尤其是生产环境的 500 / 异常）。
 *
 * 命名说明：本模型类名为 Log（对应数据表 logs）。在引用 Laravel 的日志门面时，
 * 请使用 Illuminate\Support\Facades\Log 的完全限定名或别名，避免与本类冲突。
 *
 * 字段语义（核心字段）：
 *   - module_name      模块名称（由宿主应用 module_name() 助手获取，小写下划线蛇形），按模块检索 / 统计；
 *   - level            日志等级，统一为 PSR 小写键：debug / info / notice / warning / error / critical / alert / emergency；
 *   - is_crawler       是否为爬虫 / 机器人请求（bool）；
 *   - response_content 响应内容：视图页面响应记录「视图页面地址（视图名）」，JSON 响应记录「JSON 数据」。
 *
 * 常用查询示例：
 *   Log::recent(7)->errors()->get();                       // 近 7 天所有异常 / 错误
 *   Log::level('error')->where('module_name','users');     // 某模块的错误
 *   Log::ofChannel('database')->search('timeout');         // 按通道 + 关键字模糊检索
 *   Log::where('is_crawler', true)->count();               // 爬虫请求数
 *
 * @property int         $id
 * @property string|null $module_name       模块名称（由 module_name() 函数获取，小写下划线蛇形）
 * @property string      $level             日志等级（PSR 小写键，如 error / info / warning）
 * @property string|null $channel
 * @property string      $message
 * @property array|null  $context
 * @property array|null  $extra
 * @property bool        $is_crawler        是否为爬虫请求
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
 * @property string|null $response_content  响应内容：视图页面地址（视图名） 或 JSON 数据
 * @property int|null    $response_duration_ms
 * @property float|null  $response_memory_mb
 * @property array|null  $response_headers
 * @property string|null $response_body
 * @property string|null $exception_class
 * @property string|null $exception_message
 * @property string|null $file
 * @property string|null $trace
 *
 * @method static Builder|Log level(string $level)
 * @method static Builder|Log ofChannel(string $channel)
 * @method static Builder|Log recent(int $days = 7)
 * @method static Builder|Log errors()
 * @method static Builder|Log between($from, $to)
 * @method static Builder|Log search(string $term)
 *
 * @package zxf\Modules\Logging
 */
class Log extends Model
{
    protected $table = 'logs';

    protected $fillable = [
        'module_name', 'level', 'channel', 'message', 'context', 'extra', 'is_crawler',
        'request_method', 'request_url', 'request_path', 'request_route', 'request_controller',
        'request_ip', 'request_user_agent', 'request_referer', 'request_session_id',
        'request_input', 'request_headers',
        'user_id', 'user_type',
        'response_code', 'response_status', 'response_content', 'response_duration_ms', 'response_memory_mb',
        'response_headers', 'response_body',
        'exception_class', 'exception_message', 'file', 'trace',
    ];

    protected $casts = [
        'context' => 'array',
        'extra' => 'array',
        'is_crawler' => 'boolean',
        'request_input' => 'array',
        'request_headers' => 'array',
        'response_headers' => 'array',
        'response_duration_ms' => 'integer',
        'response_memory_mb' => 'decimal:2',
    ];

    public $timestamps = true;

    /** 按等级查询（如 'error' / 'info'，统一转小写 PSR 键） */
    public function scopeLevel($query, string $level)
    {
        return $query->where('level', strtolower($level));
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

    /** 时间区间（created_at 闭区间，供 datatable 时间范围过滤） */
    public function scopeBetween($query, $from, $to)
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    /** 消息模糊检索（用于后台搜索框，转义 % 与 _ 通配符） */
    public function scopeSearch($query, string $term)
    {
        return $query->where('message', 'like', '%'.addcslashes($term, '%_').'%');
    }

    /** 仅异常 / 错误级别（与 PSR 小写键保持一致） */
    public function scopeErrors($query)
    {
        return $query->whereIn('level', ['error', 'critical', 'alert', 'emergency']);
    }
}
