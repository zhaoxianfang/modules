<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Middleware;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log as LogFacade;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use zxf\Modules\Logging\Config\LoggingConfig;
use zxf\Modules\Logging\Contracts\RedactorContract;
use zxf\Modules\Logging\LogContext;
use zxf\Modules\Logging\Support\BotDetector;
use zxf\Modules\Logging\Support\BotSignature;
use zxf\Modules\Logging\Support\Redactor;

/**
 * 请求 / 响应上下文中间件（同时是「每个 HTTP 请求写一条请求日志」的执行者）。
 *
 * 生命周期：
 *  - 请求开始：LogContext::reset()，采集丰富请求信息（方法 / URL / 路由 / 控制器 / IP / UA /
 *             来源 / 会话 / 认证用户 / 入参 / 请求头）+ 机器人识别结果，写入 LogContext
 *             并同步进 Context 门面；同时把**已脱敏**的上下文压平为两组 extra 片段
 *             （轻量标量 + 大体积载荷），供后续每条日志零成本注入；
 *  - 请求期间：业务代码的 Log::xxx() 由 DatabaseLogHandler 逐条落库；
 *  - 请求结束：采集最终响应（状态码 / 短语 / 耗时 / 峰值内存 / 响应头 / 响应体），
 *             然后向 database 通道写入一条带 __request__ 标记的**请求日志**。
 *
 * 关键设计：
 *  1. 「每请求一条请求日志」由 LogContext::claimRequestLog() 做幂等守卫，
 *     配合 RequestHandled 兜底，既保证异常请求也会落库，又绝不会重复写两条；
 *  2. 同步采集响应（而非 app()->terminating()），避免 Octane / Swoole 常驻进程下回调跨请求累积；
 *  3. 异常路径（$next 抛出）同样走 finish()，保证 5xx 请求也有日志；
 *  4. 命中 logging.db.skip_paths 名单（健康检查 / 调试面板 / Livewire 轮询等）时置跳过标记，
 *     请求日志与业务日志一并不落库；
 *  5. 脱敏统一交由 Redactor（全模块唯一事实来源），避免与落库侧口径不一致。
 *
 * 仅 HTTP 模式生效；CLI 无请求上下文，database 通道默认不落库（见 DatabaseLogHandler）。
 */
class LogContextMiddleware
{
    private LoggingConfig $config;

    private RedactorContract $redactor;

    // 注意：类型必须是【契约】而不是 Redactor 具体类。
    // 若写成 `?Redactor`，容器会优先 make(Redactor::class) 解析成功（final 类 + 全默认参数），
    // 使下面的 ?? 分支永不执行，自定义 sensitive_keys / max_field_length 被静默丢弃。
    // 用契约则与 LoggingServiceProvider 注册的单例天然对齐，同时保留手工 new 的兜底。
    public function __construct(?RedactorContract $redactor = null)
    {
        $this->config = LoggingConfig::fromConfig();
        $this->redactor = $redactor ?? new Redactor($this->config->sensitiveKeys, $this->config->maxFieldLength);
    }

    /**
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // 每次请求开始时重置上下文（含去重集合），避免 Octane / Swoole 常驻进程跨请求串味
        LogContext::reset();
        LogContext::setStart(microtime(true));

        // 跳过名单：命中则请求日志与业务日志一律不落库
        LogContext::setSkipped($this->config->isSkippedPath($request->path()));

        // 构建请求上下文：任何失败都绝不影响主业务流程（只是少记录一些上下文），
        // 故整体包裹 try/catch，确保日志采集异常不会中断正常请求。
        try {
            [$context, $baseExtra, $payloadExtra] = $this->buildRequestContext($request);
            LogContext::setRequest($context, $baseExtra, $payloadExtra);
        } catch (Throwable) {
            LogContext::setRequest([], [], []);
        }

        // 同步到 Laravel Context 门面（其他日志通道 / 处理器可共享同一上下文）
        try {
            Context::add('request', LogContext::getRequest());
        } catch (Throwable) {
            // Context 门面不可用时静默跳过（不影响主流程）
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // 异常路径同样要落库（5xx 请求更需要日志），且此处的落库对主流程无副作用
            $this->finish($request, null, $e);

            throw $e;
        }

        $this->finish($request, $response, null);

        return $response;
    }

    /**
     * 兜底入口：由 RequestHandled 监听在「中间件未写出请求日志」时调用。
     *
     * 与 handle() 中的 finish() 共用同一套幂等守卫与逻辑，因此：
     *  - 正常路径已写 → 本方法直接返回（不会出现第二条日志）；
     *  - 中间件漏写 → 用 RequestHandled 携带的最终响应补写。
     */
    public function finishForSafetyNet(Request $request, mixed $response): void
    {
        $this->finish($request, $response, null);
    }

    /**
     * 请求结束处理：采集响应 → 写入本请求唯一的请求日志。
     *
     * @param  mixed  $response  正常响应（任意具备 getStatusCode()/headers 的响应对象）；异常路径为 null
     * @param  Throwable|null  $error  下游抛出的异常（若存在）
     */
    protected function finish(Request $request, mixed $response, ?Throwable $error): void
    {
        // 跳过名单命中的请求：不采集、不落库，也必须让兜底监听判定为「已处理」，
        // 否则 RequestHandled 会凭空造出一条与「跳过」语义矛盾的记录。
        if (LogContext::isSkipped()) {
            LogContext::claimRequestLog();

            return;
        }

        // 幂等守卫：中间件 + RequestHandled 兜底，两处都调用也只会写一条
        if (LogContext::requestLogWritten()) {
            return;
        }

        try {
            $this->captureResponse($request, $response, $error);

            // 幂等标记的占用交给落库处理器（write() 内 claimRequestLog）：
            // 只有真正走到落库才置位。若在这里先置位，一旦 captureResponse() 抛异常，
            // 汇总行一条都没写，兜底又会因「已写」直接返回，
            // 该请求的日志彻底消失且无任何痕迹。
        } catch (Throwable) {
            // 采集失败不影响主流程；由 RequestHandled 兜底再试一次
        }
    }

    /**
     * 构建请求上下文（Laravel 13+ 丰富的请求信息）。
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     *                                                                                          [原始上下文, 轻量 extra 片段, 大体积载荷 extra 片段]
     */
    protected function buildRequestContext(Request $request): array
    {
        $route = $request->route();
        $routeName = $route?->getName();
        $controller = $route?->getActionName(); // "App\Http\Controllers\X@method" 或 "Closure"

        // 会话 ID：仅在「会话已启动」时读取，绝不主动调用 session()->getId() 去强制启动会话。
        //
        // 关键点：session()->getId() 内部会启动会话，从而强制为「本不需要会话」的请求
        // （如 stateless API、纯接口调用）创建一个会话；框架随后在响应体已发送后
        // 保存该会话并设置 Set-Cookie，触发 "Cannot modify header information" 警告。
        // hasSession() 仅判断会话实例是否已存在，不会启动会话；
        // 从而避免为无会话请求强制创建/保存会话，从源头消除上述警告。
        $sessionId = null;
        try {
            if ($request->hasSession()) {
                $sessionId = $request->session()->getId();
            }
        } catch (Throwable) {
            // 无会话（如 stateless API）时忽略
        }

        $user = null;
        try {
            $u = $request->user();
            if ($u !== null) {
                $user = [
                    'id' => $u->getAuthIdentifier(),
                    'type' => $u::class,
                ];
            }
        } catch (Throwable) {
            // 未认证时忽略
        }

        // 模块名称：优先使用宿主应用提供的 module_name() 助手（由 zxf/modules 的 helper.php 注入，
        // 返回当前路由所属的小写蛇形模块名）。传入 true 取小写下划线形式。
        $moduleName = null;
        try {
            if (function_exists('module_name')) {
                $moduleName = module_name(true) ?: null;
            }
        } catch (Throwable) {
            $moduleName = null;
        }

        // 机器人 / 自动化客户端识别（含脱壳伪装与各语言 HTTP 脚本指纹）
        $bot = $this->detectBot($request);

        // 仅采集请求体入参（不含 URL 查询串），并对敏感字段脱敏。
        // 文件上传走 multipart，此时 request->request 通常为空，
        // $_POST 为空即可避免把整个上传内容读入内存。
        $input = [];
        $headers = [];
        if ($this->config->captureRequestBody) {
            if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $input = $this->redactor->maskArray($request->request->all());
            }
            $headers = $this->redactor->maskHeaders($request->headers->all());
        }

        $context = [
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
            'is_crawler' => $bot->isCrawler,
            'bot' => $bot,
            'user' => $user,
            'input' => $input,
            'headers' => $headers,
        ];

        // 轻量片段：每条日志都注入（纯标量，数组合并成本极低）
        $baseExtra = [
            'request_method' => $context['method'],
            // fullUrl() 含 query string。?token= / ?password= / ?api_key= 这类凭证若在这里
            // 存明文，落库侧的脱敏只是"最终补偿"，而**绕过中间件写出的其它日志**
            // （stack 通道的 line/json formatter 会直接拿到 baseExtra 的未脱敏值）
            // 就会把凭证写进日志文件。旁路泄露比主路更隐蔽，因此源头就脱敏。
            'request_url' => $this->redactor->maskText((string) $context['url']),
            'request_path' => $context['path'],
            'request_route' => $context['route'],
            'request_controller' => $context['controller'],
            'request_ip' => $context['ip'],
            'request_user_agent' => $context['user_agent'],
            // referer 同样可能携带 ?token=
            'request_referer' => $context['referer'] === null ? null : $this->redactor->maskText((string) $context['referer']),
            'request_session_id' => $sessionId,
            'module_name' => $moduleName,
            'is_crawler' => $bot->isCrawler,
            'bot_type' => $bot->type,
            'bot_score' => $bot->score,
            'bot_client' => $bot->client,
        ];

        if ($user !== null) {
            $baseExtra['user_id'] = $user['id'];
            $baseExtra['user_type'] = $user['type'];
        }

        return [$context, $baseExtra, [
            'request_input' => $input === [] ? null : $input,
            'request_headers' => $headers === [] ? null : $headers,
        ]];
    }

    /**
     * 机器人 / 自动化客户端识别（可被应用层覆盖以接入自有规则）。
     */
    protected function detectBot(Request $request): BotSignature
    {
        $cached = LogContext::bot();
        if ($cached !== null) {
            return $cached;
        }

        if (! $this->config->botEnabled) {
            $signature = new BotSignature(false, BotSignature::TYPE_HUMAN, 0, ['bot-detection-disabled']);
            LogContext::setBot($signature);

            return $signature;
        }

        try {
            $signature = BotDetector::detect(
                $request->userAgent(),
                $request->headers->all(),
                $request->path(),
                $this->config->botThreshold,
            );
        } catch (Throwable) {
            $signature = BotSignature::human();
        }

        // 配置允许把「无 User-Agent」的请求当作普通访问（部分内部调用会刻意不带 UA）
        if (! $this->config->botMarkUnknownUa && $signature->type === BotSignature::TYPE_NO_UA) {
            $signature = BotSignature::human();
        }

        LogContext::setBot($signature);

        return $signature;
    }

    /**
     * 是否为爬虫 / 机器人请求（保留兼容入口，内部统一走 BotDetector）。
     *
     * @param  Request  $request  当前请求
     */
    public static function detectCrawler(Request $request): bool
    {
        try {
            return self::detectBotSignature($request)->isCrawler;
        } catch (Throwable) {
            // 极端情况下（如 headers 结构异常）保守按爬虫处理，绝不抛错影响主流程
            return true;
        }
    }

    /**
     * 返回完整的机器人识别结果（供应用层复用）。
     */
    public static function detectBotSignature(Request $request): BotSignature
    {
        try {
            return BotDetector::detect(
                $request->userAgent(),
                $request->headers->all(),
                $request->path(),
            );
        } catch (Throwable) {
            return BotSignature::human();
        }
    }

    /**
     * 捕获最终响应结果（Laravel 13+ 的响应信息）。
     *
     * @param  mixed  $response  响应对象（Symfony Response / 任意兼容对象 / null）
     * @param  Throwable|null  $error  下游抛出的异常（若存在）
     */
    protected function captureResponse(Request $request, mixed $response, ?Throwable $error = null): void
    {
        // 守卫：若响应内容已经发送（headers_sent = true），则不再采集响应。
        // 此时任何日志采集都无意义，且可避免在「输出已开始」的状态下再次触碰会话 / 触发
        // "Cannot modify header information - headers already sent" 类问题。
        //
        // 必须排除 CLI / phpdbg：这两个 SAPI 不使用 HTTP 头，但 headers_sent()
        // 在**任何输出之后**都会返回 true。artisan 命令、队列 worker、测试脚本
        // 一旦打印过内容（进度条、提示、FAIL 信息……），不加区分的判断会让
        // 该守卫把后续所有响应采集全部吞掉。
        if (! LogContext::isCliSapi() && headers_sent()) {
            return;
        }

        // 耗时：优先用中间件记录的请求开始时间；
        // 都拿不到时返回 0（而不是用「当前时间戳 × 1000」——
        // 那会得到 1.7e12 这种量级的值，远超 unsignedInteger 列上限 4294967295，
        // PostgreSQL / MySQL 会直接拒绝写入，日志整体丢失）。
        $startedAt = LogContext::getStart();
        if ($startedAt <= 0.0 && defined('LARAVEL_START')) {
            $startedAt = (float) constant('LARAVEL_START');
        }
        $duration = $startedAt > 0.0 ? (microtime(true) - $startedAt) * 1000 : 0.0;
        $duration = max(0.0, min($duration, 4294967295.0));
        $memory = memory_get_peak_usage(true) / 1024 / 1024;

        $code = self::resolveStatusCode($response, $error);
        $statusText = self::resolveReasonPhrase($response, $code);

        $maxBody = $this->config->maxBodyLength;
        $body = null;

        // 统一从响应中取出 Content-Type。
        // Laravel / Symfony 的 Response 以「headers 属性」（ResponseHeaderBag 实例）暴露头信息，
        // 而**不是** headers() 方法；method_exists($response, 'headers') 对真实 Response 恒为 false，
        // 若用该写法取 Content-Type 会得到 null，进而使 shouldCaptureBody(null) 返回 false、
        // 响应体永不采集、response_content（JSON 数据 / 视图响应）恒为空。
        // 故此处同时兼容「headers 属性」与「headers() 方法」两种形态。
        $headerBag = self::resolveHeaderBag($response);
        $contentType = ($headerBag !== null && method_exists($headerBag, 'get'))
            ? $headerBag->get('content-type')
            : null;

        // 此处**不**按 Illuminate\Http\Response 收窄类型：
        // Laravel 的 JSON 接口通常返回 JsonResponse（并非 Illuminate\Http\Response 子类），
        // 若仅按 Illuminate\Http\Response 判断，会导致 JSON 响应的响应体永远无法采集，
        // 使 response_content（JSON 数据）恒为 null。
        // 任意具备 getContent() 且属于文本类（text/html / application/json …）的响应均可采集。
        if ($this->config->captureResponseBody
            && $this->shouldCaptureBody($contentType)
            && $response !== null
            && method_exists($response, 'getContent')
        ) {
            $raw = $response->getContent();
            $body = is_string($raw) ? mb_substr($raw, 0, $maxBody) : null;
        }

        // 响应内容语义化：视图页面响应记录「视图页面地址」（视图名），JSON 响应记录「JSON 数据」。
        // 其它类型（重定向、文件下载、纯文本等）不记录到 response_content，避免存储无意义的冗余内容。
        $responseContent = self::resolveResponseContent($response, $contentType, $body);

        $data = [
            'code' => $code,
            'status' => $statusText,
            'duration_ms' => (int) round($duration),
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
        } catch (Throwable) {
            // 忽略
        }

        // 向 database 通道写入本次请求的**请求日志**：DatabaseLogHandler 识别到
        // __request__ 标记后应用「每请求恰好一条」的幂等守卫，并把这行落库。
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
            LogFacade::channel('database')->info($msg, [LogContext::REQUEST_LOG_FLAG => true]);
        } catch (Throwable) {
            // 数据库通道未就绪时静默跳过（由 RequestHandled 兜底再试一次）
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

        return (bool) preg_match('#(text/html|application/json|text/plain|application/xml|text/xml|text/csv)#i', $ct);
    }

    /**
     * 解析「响应内容」语义字段（写入 logs.response_content）。
     * 规则：
     *   - JSON 响应（content-type 含 application/json）：记录 JSON 数据（即响应体原文，已截断）。
     *   - 视图页面响应（原始内容为 Illuminate\Contracts\View\View）：记录视图页面地址（视图名）。
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
        if (is_object($response) && method_exists($response, 'getOriginalContent')) {
            $original = $response->getOriginalContent();
            if ($original instanceof View) {
                return $original->getName();
            }
        }

        return null;
    }

    /**
     * 解析响应状态码（异常路径下从 HttpException 取状态码）。
     */
    private static function resolveStatusCode(mixed $response, ?Throwable $error): int
    {
        if ($response !== null && method_exists($response, 'getStatusCode')) {
            return (int) $response->getStatusCode();
        }

        if ($error instanceof HttpExceptionInterface) {
            return (int) $error->getStatusCode();
        }

        return $error !== null ? 500 : 200;
    }

    /**
     * 解析状态短语（无响应对象时使用标准 HTTP 短语表）。
     */
    private static function resolveReasonPhrase(mixed $response, int $code): string
    {
        if ($response !== null && method_exists($response, 'getReasonPhrase')) {
            $phrase = (string) $response->getReasonPhrase();

            return $phrase !== '' ? mb_substr($phrase, 0, 50) : self::phrase($code);
        }

        return self::phrase($code);
    }

    /**
     * 标准 HTTP 状态短语（避免依赖 Symfony Response 构造）。
     *
     * @var array<int, string>
     */
    private const PHRASES = [
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content',
        301 => 'Moved Permanently', 302 => 'Found', 304 => 'Not Modified',
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
        405 => 'Method Not Allowed', 408 => 'Request Timeout', 409 => 'Conflict',
        413 => 'Payload Too Large', 415 => 'Unsupported Media Type', 418 => "I'm a teapot",
        419 => 'Page Expired', 422 => 'Unprocessable Entity', 429 => 'Too Many Requests',
        500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway',
        503 => 'Service Unavailable', 504 => 'Gateway Timeout',
    ];

    private static function phrase(int $code): string
    {
        return self::PHRASES[$code] ?? 'Unknown';
    }

    /**
     * 取响应头容器（兼容 ResponseHeaderBag 属性与 headers() 方法两种形态）。
     *
     * @return mixed
     */
    private static function resolveHeaderBag(mixed $response)
    {
        if ($response === null) {
            return null;
        }

        // 形参是 mixed，因此 $response 可能是**数组或标量**：
        //  - property_exists() 对数组/标量会抛 TypeError
        //    （PHP 8: property_exists(): Argument #1 ($object_or_class) must be
        //     of type object|string, array given）
        //  - 数组上做 `$response->headers` 同样致命
        // 即便 is_object() 通过，读取未声明的 public 属性也可能触发
        // 魔术 __get 而产生副作用。
        //
        // 现在按「对象 → 属性 → 方法」的顺序做完整类型收敛，
        // 任何非对象输入直接返回 null，绝不抛异常。
        if (! is_object($response)) {
            return null;
        }

        // ① 属性式（Symfony Response 的 $response->headers 是 HeaderBag 实例）
        if (property_exists($response, 'headers')) {
            $bag = $response->headers ?? null;

            if ($bag !== null && ! is_callable($bag)) {
                return $bag;
            }
        }

        // ② 方法式（Laravel JsonResponse::headers() 等）
        if (method_exists($response, 'headers')) {
            try {
                $bag = $response->headers();

                if ($bag !== null) {
                    return $bag;
                }
            } catch (Throwable) {
                // 读取响应头失败不应影响日志写入
            }
        }

        // ③ 兜底：Symfony Response::headers 可能通过 __get 暴露
        if (method_exists($response, '__get') || property_exists($response, 'headers')) {
            try {
                $bag = $response->headers ?? null;

                return is_object($bag) || is_array($bag) ? $bag : null;
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
