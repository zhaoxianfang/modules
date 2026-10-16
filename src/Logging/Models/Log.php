<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use zxf\Modules\Logging\Support\BotSignature;

/**
 * 系统日志模型（数据库日志通道落库表 logs）。
 *
 * 表中每一行代表**一条日志**，有两类来源：
 *  1. **请求日志** —— 每个 HTTP 请求恰好一行，由 LogContextMiddleware 在响应采集完成后写入，
 *     request_* 与 response_* 列为完整值，可直接用于访问统计与性能分析；
 *  2. **业务日志** —— 业务代码的 Log::xxx() / report($e) 逐条写入，携带轻量请求上下文以便溯源。
 *
 * 命名说明：本模型类名为 Log（对应数据表 logs）。在引用 Laravel 的日志门面时，
 * 请使用 Illuminate\Support\Facades\Log 的完全限定名或别名，避免与本类冲突。
 *
 * 字段语义（核心字段）：
 *   - module_name       模块名称（由宿主应用 module_name() 助手获取，小写下划线蛇形），按模块检索 / 统计；
 *   - level             日志等级（PSR 小写键）：debug/info/notice/warning/error/critical/alert/emergency；
 *   - is_crawler        是否为爬虫 / 机器人 / 自动化脚本请求（bool）；
 *   - bot_type          客户端分类（search_engine / ai_crawler / seo / monitor / social / feed /
 *                       security_scanner / http_library / headless / library_other /
 *                       attack_probe / unknown_bot / no_ua / human），见 BotSignature；
 *   - bot_score         判定置信分（0~100），用于人工复核误判；
 *   - bot_client        命中的客户端 / 工具标识（如 python-requests、go-http-client）；
 *   - bot_signals       命中证据链（' | ' 分隔），可解释「为什么判定为爬虫」；
 *   - response_content  响应内容：视图页面响应记录「视图页面地址（视图名）」，JSON 响应记录「JSON 数据」。
 *
 * 常用查询示例：
 *   Log::recent(7)->errors()->get();                       // 近 7 天所有异常 / 错误
 *   Log::level('error')->where('module_name','users');     // 某模块的错误
 *   Log::ofChannel('database')->search('timeout');         // 按通道 + 关键字模糊检索
 *   Log::crawlers()->count();                              // 爬虫 / 脚本请求数
 *   Log::bots(BotSignature::TYPE_SECURITY_SCANNER)->get(); // 安全扫描器流量
 *   Log::ofIp('1.2.3.4')->get();                           // 某 IP 的全部请求
 *
 * @property int $id 自增主键
 * @property string|null $module_name 模块名称（由 module_name() 函数获取，小写下划线蛇形，如 ticket）
 * @property string $level 日志等级（PSR 小写键：debug / info / notice / warning / error / critical / alert / emergency）
 * @property string|null $channel 产生该日志的 Monolog 通道名（database / stack / single / daily …）
 * @property string $message 日志正文（已脱敏并截断至 65535 字符）
 * @property array|null $context Monolog context 业务上下文（已脱敏、剔除不可序列化对象，JSON 字符串）
 * @property array|null $extra Monolog extra 附加数据（已脱敏，JSON 字符串；不含已有独立列的 request_ / response_ 前缀字段）
 * @property bool $is_crawler 是否为爬虫 / 机器人 / 自动化脚本请求
 * @property string|null $bot_type 客户端分类（见 BotSignature::TYPE_*，如 http_client / security_scanner / seo_spider）
 * @property int|null $bot_score 判定置信分（0~100，越高越确定是自动化客户端）
 * @property string|null $bot_client 命中的客户端 / 工具标识（如 python-requests / curl / Scrapy）
 * @property string|null $bot_signals 命中证据链（多个证据以 ' | ' 分隔，如 empty_ua|known_bot_tool）
 * @property string|null $request_method HTTP 请求方法（GET / POST / PUT / PATCH / DELETE）
 * @property string|null $request_url 完整请求 URL（含 query string，其中的凭证参数已脱敏）
 * @property string|null $request_path 请求路径（不含域名与查询串，如 admin/users/1）
 * @property string|null $request_route 路由名称（Route::name()，未命名路由为 null）
 * @property string|null $request_controller 控制器@方法（闭包路由为 null）
 * @property string|null $request_ip 客户端 IP（已识别可信代理的真实 IP）
 * @property string|null $request_user_agent 客户端 User-Agent 原文
 * @property string|null $request_referer 来源页 Referer（已脱敏）
 * @property string|null $request_session_id 会话 ID（已脱敏；无会话的请求为 null）
 * @property array|null $request_input 请求体入参（已脱敏，JSON 字符串；仅请求日志行有值）
 * @property array|null $request_headers 请求头（Cookie / Authorization 等敏感头已脱敏，JSON 字符串；仅请求日志行有值）
 * @property int|null $user_id 已认证用户主键（未登录为 null）
 * @property string|null $user_type 已认证用户类名（未登录为 null）
 * @property int|null $response_code HTTP 响应状态码（每个 HTTP 请求的请求日志行必有值）
 * @property string|null $response_status HTTP 状态短语（如 OK / Not Found / Internal Server Error）
 * @property string|null $response_content 响应内容：视图页面响应记视图名，JSON 响应记 JSON 数据
 * @property int|null $response_duration_ms 请求耗时（毫秒，整型列；拿不到开始时间时为 0）
 * @property float|null $response_memory_mb 本次请求的峰值内存占用（MB）
 * @property array|null $response_headers 响应头（Set-Cookie 等敏感头已脱敏，JSON 字符串；仅请求日志行有值）
 * @property string|null $response_body 响应体原始内容（仅文本类，按 max_body_length 截断；仅请求日志行有值）
 * @property string|null $exception_class 异常类名（如 RuntimeException）
 * @property string|null $exception_message 异常消息（截断至 65535 字符）
 * @property string|null $file 异常抛出位置（文件路径:行号）
 * @property string|null $trace 异常堆栈（截断至 60000 字符）
 * @property Carbon|null $created_at 记录创建时间
 * @property Carbon|null $updated_at 记录更新时间
 *
 * @method static Builder|Log level(string $level) 按单个等级精确过滤（自动转小写，如 'ERROR' → error）
 * @method static Builder|Log anyLevel(array<int, string>|string $levels) 按一组等级过滤；自动过白名单，全部非法时返回恒假条件（防外部输入直接进 whereIn）
 * @method static Builder|Log ofChannel(string $channel) 按 Monolog 通道名过滤
 * @method static Builder|Log recent(int $days = 7) 仅最近 N 天的记录
 * @method static Builder|Log errors() 仅错误及以上等级（error / critical / alert / emergency）
 * @method static Builder|Log between($from, $to) created_at 落在指定时间区间（含边界）内
 * @method static Builder|Log search(string $term) 按 message 模糊检索；自动转义 % _ \ ，空串不附加条件
 * @method static Builder|Log crawlers() 仅爬虫 / 自动化脚本请求
 * @method static Builder|Log humans() 仅真实用户请求（排除爬虫）
 * @method static Builder|Log bots(string $type) 按客户端分类过滤（BotSignature::TYPE_*）
 * @method static Builder|Log ofIp(string $ip) 按客户端 IP 过滤；含 % 时自动走 LIKE
 * @method static Builder|Log ofPath(string $path) 按请求路径过滤；含 * 时自动转成 LIKE 通配
 * @method static Builder|Log ofModule(?string $module) 按模块过滤（自动转小写）；传 null / 空串不附加条件
 * @method static Builder|Log slow(float $milliseconds) 耗时 ≥ 指定毫秒数的慢请求
 * @method static Builder|Log failed() HTTP 失败请求（状态码 ≥ 400，含 4xx 与 5xx）
 * @method static Builder|Log byUser(int|string|null $userId) 按已认证用户主键过滤
 * @method static Builder|Log businessLogs() 仅业务日志行（context 非空，即携带业务上下文的那些）
 * @method static Builder|Log serverErrors() 仅 5xx 服务端错误
 * @method static Builder|Log clientErrors() 仅 4xx 客户端错误
 * @method static Builder|Log slowest(int $limit = 50) 按耗时倒序取最慢 N 条（配合 logs_duration_index）
 * @method static Builder|Log metrics(string $dateColumn = 'created_at') 按时间列分组统计（总数 / 错误数 / 平均与最大耗时 / 爬虫数 / 失败数）
 * @method static Builder|Log ordered() 默认排序：created_at 倒序（最新在前）
 */
class Log extends Model
{
    protected $table = 'logs';

    /**
     * 合法等级白名单（PSR 小写键 + Monolog 的大写别名）。
     *
     * 供 {@see self::scopeAnyLevel()} 与 {@see self::scopeLevel()} 之外的
     * 外部输入校验使用，避免任意字符串进入查询条件。
     *
     * @var list<string>
     */
    public const LEVELS = [
        'debug', 'info', 'notice', 'warning', 'error',
        'critical', 'alert', 'emergency',
    ];

    /** 等级 → 严重度权重，供「取最严重等级」类聚合与排序使用 */
    public const LEVEL_WEIGHTS = [
        'debug' => 0,
        'info' => 1,
        'notice' => 2,
        'warning' => 3,
        'error' => 4,
        'critical' => 5,
        'alert' => 6,
        'emergency' => 7,
    ];

    /**
     * 允许批量赋值的字段（写入器使用 forceFill，此处主要用于业务侧手工 new Log()->fill(...) 场景）。
     *
     * @var list<string>
     */
    protected $fillable = [
        'module_name', 'level', 'channel', 'message', 'context', 'extra', 'is_crawler',
        'bot_type', 'bot_score', 'bot_client', 'bot_signals',
        'request_method', 'request_url', 'request_path', 'request_route', 'request_controller',
        'request_ip', 'request_user_agent', 'request_referer', 'request_session_id',
        'request_input', 'request_headers',
        'user_id', 'user_type',
        'response_code', 'response_status', 'response_content', 'response_duration_ms', 'response_memory_mb',
        'response_headers', 'response_body',
        'exception_class', 'exception_message', 'file', 'trace',
    ];

    /**
     * 字段类型转换。
     *
     * 采用 Laravel 11+ 推荐的 casts() 方法（而非 $casts 属性），保持与本包其它生成代码风格一致；
     * 语义与旧 $casts 完全等价（模型自身无任何行为差异）。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'extra' => 'array',
            'is_crawler' => 'boolean',
            'bot_score' => 'integer',
            'request_input' => 'array',
            'request_headers' => 'array',
            'response_headers' => 'array',
            'response_duration_ms' => 'integer',
            'response_memory_mb' => 'decimal:2',
        ];
    }

    public $timestamps = true;

    /** 按等级查询（如 'error' / 'info'，统一转小写 PSR 键） */
    public function scopeLevel(Builder $query, string $level): Builder
    {
        return $query->where('level', mb_strtolower($level));
    }

    /**
     * 按一组等级查询，自动过滤非法等级键。
     *
     * 等级值常来自后台筛选表单或外部 API，必须先过白名单：
     * 直接 whereIn($input) 会把任意字符串带进查询条件，既产生无效索引命中，
     * 也让攻击者可构造超大数组放大 SQL 体积。
     *
     * @param  array<int, string>|string  $levels
     */
    public function scopeAnyLevel(Builder $query, array|string $levels): Builder
    {
        $allowed = self::LEVELS;
        $list = is_array($levels) ? $levels : [$levels];
        $valid = array_values(array_intersect(
            array_map(static fn ($lv) => mb_strtolower(trim((string) $lv)), $list),
            $allowed
        ));

        return $valid === [] ? $query->whereRaw('1 = 0') : $query->whereIn('level', $valid);
    }

    /** 按通道查询 */
    public function scopeOfChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    /** 最近 N 天 */
    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /** 时间区间（created_at 闭区间，供 datatable 时间范围过滤） */
    public function scopeBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    /** 消息模糊检索（用于后台搜索框，转义 % 与 _ 通配符） */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        if ($term === '') {
            return $query;
        }

        $escaped = addcslashes($term, '%_\\');
        $pattern = '%'.$escaped.'%';

        // SQLite 的 LIKE 没有默认转义字符，不声明 ESCAPE 时反斜杠被当作普通字符，
        // % 与 _ 仍按通配符解释，搜索语义错误且可被构造为全表扫描。
        // 注意 ESCAPE 后必须是**单个**字符：SQLite 的字符串字面量不解析反斜杠转义
        // （只有标准 SQL 的两个单引号表示一个单引号），写成 '\\' 会得到两个字符，
        // 直接触发 "ESCAPE expression must be a single character"。
        // MySQL / PostgreSQL 的 LIKE 默认转义字符本就是反斜杠，故走 where() 即可。
        if ($query->getConnection()->getDriverName() === 'sqlite') {
            return $query->whereRaw("message LIKE ? ESCAPE '\\'", [$pattern]);
        }

        return $query->where('message', 'like', $pattern);
    }

    /** 仅异常 / 错误级别（与 PSR 小写键保持一致） */
    public function scopeErrors(Builder $query): Builder
    {
        return $query->whereIn('level', ['error', 'critical', 'alert', 'emergency']);
    }

    /** 仅爬虫 / 机器人 / 自动化脚本请求 */
    public function scopeCrawlers(Builder $query): Builder
    {
        return $query->where('is_crawler', true);
    }

    /** 仅普通浏览器 / 已认证用户流量（is_crawler = false） */
    public function scopeHumans(Builder $query): Builder
    {
        return $query->where('is_crawler', false);
    }

    /** 按客户端分类查询（如 BotSignature::TYPE_HTTP_LIBRARY） */
    public function scopeBots(Builder $query, string $type): Builder
    {
        return $query->where('bot_type', $type);
    }

    /** 按客户端 IP 查询（支持 CIDR 前缀，如 1.2.3.） */
    public function scopeOfIp(Builder $query, string $ip): Builder
    {
        return $query->where('request_ip', str_contains($ip, '%') ? 'like' : '=', $ip);
    }

    /** 按请求路径查询（支持前后通配，如 %admin%） */
    public function scopeOfPath(Builder $query, string $path): Builder
    {
        return str_contains($path, '*')
            ? $query->where('request_path', 'like', str_replace('*', '%', $path))
            : $query->where('request_path', $path);
    }

    /** 按模块查询 */
    public function scopeOfModule(Builder $query, ?string $module): Builder
    {
        return $module === null || $module === ''
            ? $query
            : $query->where('module_name', mb_strtolower($module));
    }

    /** 慢请求（耗时 ≥ 指定毫秒） */
    public function scopeSlow(Builder $query, float $milliseconds): Builder
    {
        return $query->whereNotNull('response_duration_ms')
            ->where('response_duration_ms', '>=', $milliseconds);
    }

    /** HTTP 失败请求（4xx / 5xx） */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereNotNull('response_code')
            ->where('response_code', '>=', 400);
    }

    /** 5xx 服务端错误（对应「每个请求都有响应状态码」这一列的前提） */
    public function scopeServerErrors(Builder $query): Builder
    {
        return $query->whereNotNull('response_code')
            ->where('response_code', '>=', 500);
    }

    /** 4xx 客户端错误 */
    public function scopeClientErrors(Builder $query): Builder
    {
        return $query->whereNotNull('response_code')
            ->whereBetween('response_code', [400, 499]);
    }

    /** 携带业务上下文的日志行（同一请求还应有一条无业务 context 的请求日志行） */
    public function scopeBusinessLogs(Builder $query): Builder
    {
        return $query->whereNotNull('context')->where('context', '!=', '[]');
    }

    /** 按耗时倒序（慢请求排查，配合 logs_duration_index 使用） */
    public function scopeSlowest(Builder $query, int $limit = 50): Builder
    {
        return $query->whereNotNull('response_duration_ms')
            ->orderByDesc('response_duration_ms')
            ->limit($limit);
    }

    /**
     * 时间窗口分组统计（PGSQL / MySQL8 / SQLite 均可用，避免 SQL 方言差异）。
     *
     * 之所以不直接 groupBy(HOUR())：那是 MySQL 专有函数，
     * 迁到 PostgreSQL 或用 SQLite 做测试时会直接报错。
     *
     * @param  string  $column  可信列名（仅接受本模型声明过的候选列）
     * @param  string  $dateColumn  时间列（默认 created_at）
     */
    public function scopeMetrics(Builder $query, string $dateColumn = 'created_at'): Builder
    {
        $dateColumn = in_array($dateColumn, ['created_at', 'updated_at'], true) ? $dateColumn : 'created_at';

        return $query
            ->select([
                $dateColumn,
                DB::raw('COUNT(*) AS total'),
                DB::raw("SUM(CASE WHEN level IN ('error','critical','alert','emergency') THEN 1 ELSE 0 END) AS errors"),
                DB::raw('AVG(response_duration_ms) AS avg_duration_ms'),
                DB::raw('MAX(response_duration_ms) AS max_duration_ms'),
                DB::raw('SUM(CASE WHEN is_crawler = 1 THEN 1 ELSE 0 END) AS crawler_hits'),
                DB::raw('SUM(CASE WHEN response_code >= 400 THEN 1 ELSE 0 END) AS failed_hits'),
            ])
            ->groupBy($dateColumn)
            ->orderByDesc($dateColumn);
    }

    /** 按认证用户查询 */
    public function scopeByUser(Builder $query, int|string|null $userId): Builder
    {
        return $userId === null ? $query : $query->where('user_id', $userId);
    }

    /** 默认排序：最新在前（同秒内按自增 id 倒序，保证分页稳定不重复） */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
