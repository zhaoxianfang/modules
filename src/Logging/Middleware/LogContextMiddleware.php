<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Middleware;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use zxf\Modules\Logging\Config\LoggingConfig;
use zxf\Modules\Logging\LogContext;
use zxf\Modules\Logging\Support\Redactor;

/**
 * 请求 / 响应上下文中间件。
 * - 请求开始：采集丰富请求信息（方法 / URL / 路由 / 控制器 / IP / UA / 来源 /
 *   会话 / 认证用户 / 入参 / 请求头），写入 LogContext，并同步进 Laravel 13 的
 *   Context 门面（Context::add），供所有日志通道共享。
 * - 请求结束（handle 返回前，$response 已是最终响应）：同步采集最终响应结果
 *   （状态码 / 状态短语 / 耗时 / 峰值内存 / 响应头 / 响应体），写入 LogContext，
 *   并向 database 通道写入一条「请求 → 响应」汇总日志，保证 logs 数据表中天然带有
 *   完整响应信息。此处故意采用「同步」采集而非 app()->terminating() 回调，以避免在
 *   Octane / Swoole 等常驻进程下回调跨请求累积、并以过期响应重复执行。
 * 脱敏统一交由 Redactor（全模块唯一事实来源），避免与落库侧口径不一致。
 * 仅 HTTP 模式生效；CLI 无请求上下文，数据库通道也不会落库（见 DatabaseLogHandler）。
 */
class LogContextMiddleware
{
    private LoggingConfig $config;

    private Redactor $redactor;

    public function __construct(?Redactor $redactor = null)
    {
        $this->config = LoggingConfig::fromConfig();
        $this->redactor = $redactor ?? new Redactor($this->config->sensitiveKeys, $this->config->maxFieldLength);
    }

    public function handle(Request $request, Closure $next)
    {
        // 每次请求开始时重置上下文（含去重集合与表存在性缓存），避免 Octane / Swoole 常驻进程跨请求串味
        LogContext::reset();

        LogContext::setStart(microtime(true));

        // 构建请求上下文：任何失败都绝不影响主业务流程（只是少记录一些上下文），
        // 故整体包裹 try/catch，确保日志采集异常不会中断正常请求。
        try {
            LogContext::setRequest($this->buildRequestContext($request));
        } catch (\Throwable) {
            LogContext::setRequest([]);
        }

        // 同步到 Laravel 13 Context（其他日志通道 / 处理器可共享同一上下文）
        try {
            Context::add('request', LogContext::getRequest());
        } catch (\Throwable) {
            // Context 门面不可用时静默跳过（不影响主流程）
        }

        $response = $next($request);

        // 请求处理结束后（下游与响应中间件均已执行完毕，$response 已是最终响应）立即采集响应信息，
        // 并向 database 通道写入一条「请求 → 响应」汇总日志，保证 logs 数据表天然带有完整响应上下文。
        //
        // 注意：此处「同步」采集，而非注册 app()->terminating() 回调。原因：在 Octane / Swoole 等
        // 常驻进程下，每次请求都注册 terminating 回调会导致回调跨请求累积，既泄漏内存、又可能在
        // worker 退出时以过期响应重复执行。响应对象在 handle 返回时已是终态（状态码 / 头 / 体均确定），
        // 故同步采集在语义上完全等价且更安全。
        try {
            $this->captureResponse($request, $response);
        } catch (\Throwable) {
            // 采集失败不影响主流程
        }

        return $response;
    }

    /**
     * 构建请求上下文（Laravel 13+ 丰富的请求信息）。
     */
    protected function buildRequestContext(Request $request): array
    {
        $route = $request->route();
        $routeName = $route?->getName();
        $controller = $route?->getActionName(); // "App\Http\Controllers\X@method" 或 "Closure"

        // 会话 ID：仅在「会话已启动」时读取，绝不主动调用 session()->getId() 去强制启动会话。
        //
        // 关键修复：旧实现无条件调用 session()->getId()，而该方法内部会启动会话，从而强制为
        // 「本不需要会话」的请求（如 stateless API、纯接口调用）创建一个会话；框架随后在响应体已发送后
        // 保存该会话并设置 Set-Cookie，触发 "Cannot modify header information - headers already sent" 警告。
        // 该警告会经 Laravel 异常处理器进入 database 通道，并在大量请求下反复记录，形成「海量重复报错」。
        //
        // 修复要点：仅当 $request->hasSession() 为真时读取 ID。hasSession() 仅判断会话实例是否已存在，
        // 不会启动会话；从而避免为无会话请求强制创建/保存会话，从源头消除上述警告。
        $sessionId = null;
        try {
            if ($request->hasSession()) {
                $sessionId = $request->session()->getId();
            }
        } catch (\Throwable) {
            // 无会话（如 stateless API）时忽略
        }

        $user = null;
        try {
            $u = $request->user();
            if ($u !== null) {
                $user = [
                    'id' => $u->getAuthIdentifier(),
                    'type' => get_class($u),
                ];
            }
        } catch (\Throwable) {
            // 未认证时忽略
        }

        // 仅采集请求体入参（不含 URL 查询串），并对敏感字段脱敏
        $input = [];
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $input = $this->redactor->maskArray($request->request->all());
        }

        // 模块名称：优先使用宿主应用提供的 module_name() 助手（由 zxf/modules 的 helper.php 注入，
        // 返回当前路由所属的小写蛇形模块名）。传入 true 取小写下划线形式，与既有日志模块约定一致。
        // 该助手不可用时（非模块宿主环境）降级为 null，不影响落库。
        $moduleName = null;
        try {
            if (function_exists('module_name')) {
                $moduleName = module_name(true) ?: null;
            }
        } catch (\Throwable) {
            $moduleName = null;
        }

        // 爬虫判定：基于 User-Agent 等特征识别搜索引擎 / 机器人，单独成列便于统计与过滤。
        $isCrawler = self::detectCrawler($request);

        return [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'path' => $request->path(),
            'route' => $routeName,
            'controller' => ($controller === 'Closure' || $controller === null) ? null : $controller,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'referer' => $request->header('referer'),
            'session_id' => $sessionId,
            'module_name' => $moduleName,
            'is_crawler' => $isCrawler,
            'user' => $user,
            'input' => $input,
            'headers' => $this->redactor->maskHeaders($request->headers->all()),
        ];
    }

    /**
     * 是否为爬虫 / 机器人请求。
     * 判定依据（保守、可解释、易扩展）：
     *   - 缺少 User-Agent，或 User-Agent 命中已知爬虫特征（googlebot / bingbot / baiduspider /
     *     yandex / duckduckbot / slurp / sogou / exabot / petalbot / bytespider / applebot /
     *     facebookexternalhit / twitterbot / linkedinbot / telegrambot / 以及含 bot|spider|crawl|slurp|crawler 字样）；
     *   - 命中常见 SEO / 监控 UA 关键词。
     * 返回布尔，供 logs.is_crawler 列直接落库；非爬虫返回 false。
     *
     * @param  Request  $request  当前请求
     */
    public static function detectCrawler(Request $request): bool
    {
        $ua = (string) $request->userAgent();

        // 无 UA 多数为脚本 / 探测工具，按爬虫对待（便于与真实浏览器区分统计）
        if ($ua === '') {
            return true;
        }

        // 已知爬虫 / 机器人特征（小写匹配）
        static $patterns = [
            'googlebot', 'bingbot', 'bingpreview', 'slurp', 'duckduckbot', 'baiduspider',
            'yandexbot', 'yandex', 'sogou', 'exabot', 'petalbot', 'bytespider', 'applebot',
            'facebookexternalhit', 'twitterbot', 'linkedinbot', 'telegrambot', 'whatsapp',
            'discordbot', 'slackbot', 'embedly', 'pinterest', 'outbrain', 'semrushbot',
            'ahrefsbot', 'mj12bot', 'dotbot', 'dataforseo', 'python-requests', 'python-urllib',
            'go-http-client', 'java/', 'curl/', 'wget', 'okhttp', 'postmanruntime',
            'headlesschrome', 'phantomjs', 'scrapy', 'node-fetch', 'guzzle', 'httpclient',
        ];

        $lower = strtolower($ua);
        foreach ($patterns as $p) {
            if (str_contains($lower, $p)) {
                return true;
            }
        }

        // 兜底：含通用爬虫语义字样
        return (bool) preg_match('#\b(bot|spider|crawl|slurp|crawler|scraper|archiver|monitor|probe|checker)\b#i', $lower);
    }

    /**
     * 捕获最终响应结果（Laravel 13+ 的响应信息）。
     */
    protected function captureResponse(Request $request, $response): void
    {
        // 守卫：若响应内容已经发送（headers_sent = true），则不再写入汇总日志。
        // 此时任何日志采集都无意义，且可避免在「输出已开始」的状态下再次触碰会话 / 触发
        // "Cannot modify header information - headers already sent" 类问题。
        if (headers_sent()) {
            return;
        }

        $start = defined('LARAVEL_START')
            ? (microtime(true) - constant('LARAVEL_START'))
            : (microtime(true) - LogContext::getStart());
        $duration = $start * 1000;
        $memory = memory_get_peak_usage(true) / 1024 / 1024;

        $code = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
        $statusText = method_exists($response, 'getReasonPhrase') ? $response->getReasonPhrase() : '';

        $maxBody = $this->config->maxBodyLength;
        $body = null;
        // 统一从响应中取出 Content-Type。
        // 注意：Laravel / Symfony 的 Response 以「headers 属性」（ResponseHeaderBag 实例）暴露头信息，
        // 而【不是】 headers() 方法；method_exists($response, 'headers') 对真实 Response 恒为 false，
        // 若用该写法取 Content-Type 会得到 null，进而使 shouldCaptureBody(null) 返回 false、
        // 响应体永不采集、response_content（JSON 数据 / 视图响应）恒为空。
        // 故此处同时兼容「headers 属性」与「headers() 方法」两种形态。
        $headerBag = null;
        if (property_exists($response, 'headers') && $response->headers !== null) {
            $headerBag = $response->headers;
        } elseif (method_exists($response, 'headers')) {
            $headerBag = $response->headers();
        }
        $contentType = ($headerBag !== null && method_exists($headerBag, 'get'))
            ? $headerBag->get('content-type')
            : null;
        // 注意：此处不再限定 $response instanceof Illuminate\Http\Response，
        // 因为 Laravel 的 JSON 接口通常返回 JsonResponse（并非 Illuminate\Http\Response 子类），
        // 若仅按 Illuminate\Http\Response 判断，会导致 JSON 响应的响应体永远无法采集，
        // 进而使 response_content（需求要求 JSON 响应记录 JSON 数据）恒为 null。
        // 改为：任意具备 getContent() 且属于文本类（text/html / application/json …）的响应均可采集。
        if ($this->shouldCaptureBody($contentType) && method_exists($response, 'getContent')) {
            $body = mb_substr((string) $response->getContent(), 0, $maxBody);
        }

        // 响应内容语义化：视图页面响应记录「视图页面地址」（视图名），JSON 响应记录「JSON 数据」。
        // 其它类型（重定向、文件下载、纯文本等）不记录到 response_content，避免存储无意义的冗余内容。
        $responseContent = self::resolveResponseContent($response, $contentType, $body);

        $data = [
            'code' => $code,
            'status' => $statusText,
            'duration_ms' => round($duration, 2),
            'memory_mb' => round($memory, 2),
            'headers' => $this->redactor->maskHeaders(
                ($headerBag !== null && method_exists($headerBag, 'all')) ? $headerBag->all() : []
            ),
            'body' => $body,
            'content' => $responseContent,
        ];

        LogContext::setResponse($data);
        try {
            Context::add('response', $data);
        } catch (\Throwable) {
            // 忽略
        }

        // 向 database 通道写入一条「请求 → 响应」汇总日志（handler 内部对表缺失等异常有兜底）
        try {
            $req = LogContext::getRequest();
            $msg = sprintf(
                '%s %s → %d %s (%.1f ms, %.1f MB)',
                $req['method'] ?? '?',
                $req['path'] ?? '/',
                $code,
                $statusText,
                $data['duration_ms'],
                $data['memory_mb']
            );
            logger()->channel('database')->info($msg, ['__summary__' => true]);
        } catch (\Throwable) {
            // 数据库通道未就绪时静默跳过
        }
    }

    /**
     * 是否值得捕获响应体（仅文本类响应，避免二进制 / 大体积）。
     */
    protected function shouldCaptureBody(?string $ct): bool
    {
        if ($ct === null) {
            return false;
        }

        return (bool) preg_match('#(text/html|application/json|text/plain|application/xml|text/xml)#i', $ct);
    }

    /**
     * 解析「响应内容」语义字段（写入 logs.response_content）。
     * 规则（与需求一致）：
     *   - JSON 响应（content-type 含 application/json）：记录 JSON 数据（即响应体原文，已截断）。
     *     注意：经 captureResponse 修复，Laravel 的 JsonResponse（非 Illuminate\Http\Response 子类）现在也会
     *     被正确采集响应体，故此处可稳定收到 $body 参数；若 $body 为空（如空 JSON 响应）则返回 null。
     *   - 视图页面响应（原始内容为 Illuminate\Contracts\View\View）：记录视图页面地址（视图名，如 "users::index"）。
     *   - 其它（重定向 / 文件 / 纯文本 / 无原始内容）：返回 null，不冗余存储。
     * 声明为 public static：不依赖实例状态（仅基于入参推导），便于单元测试直接调用，
     * 也便于在需要时于其它上下文复用。
     *
     * @param  mixed  $response  响应对象
     * @param  string|null  $contentType  响应 Content-Type
     * @param  string|null  $body  已截断的响应体（JSON 场景复用）
     */
    public static function resolveResponseContent($response, ?string $contentType, ?string $body): ?string
    {
        // 1) JSON：记录 JSON 数据本身
        if ($contentType !== null && preg_match('#application/json#i', $contentType) && $body !== null && $body !== '') {
            return $body;
        }

        // 2) 视图页面：记录视图名（页面地址）
        if (method_exists($response, 'getOriginalContent')) {
            $original = $response->getOriginalContent();
            if ($original instanceof View) {
                return $original->getName();
            }
        }

        return null;
    }
}
