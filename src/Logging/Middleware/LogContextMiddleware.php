<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Context;
use zxf\Modules\Logging\Config\LoggingConfig;
use zxf\Modules\Logging\LogContext;
use zxf\Modules\Logging\Support\Redactor;

/**
 * 请求 / 响应上下文中间件。
 *
 * - 请求开始：采集丰富请求信息（方法 / URL / 路由 / 控制器 / IP / UA / 来源 /
 *   会话 / 认证用户 / 入参 / 请求头），写入 LogContext，并同步进 Laravel 13 的
 *   Context 门面（Context::add），供所有日志通道共享。
 * - 请求结束（app()->terminating()）：采集最终响应结果（状态码 / 状态短语 /
 *   耗时 / 峰值内存 / 响应头 / 响应体），写入 LogContext，并向 database 通道
 *   写入一条「请求 → 响应」汇总日志，保证 logs 数据表中天然带有完整响应信息。
 *
 * 脱敏统一交由 Redactor（全模块唯一事实来源），避免与落库侧口径不一致。
 * 仅 HTTP 模式生效；CLI 无请求上下文，数据库通道也不会落库（见 DatabaseLogHandler）。
 *
 * @package zxf\Modules\Logging
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
        // 每次请求开始时重置上下文（含去重集合），避免 Octane / Swoole 常驻进程跨请求串味
        LogContext::reset();

        LogContext::setStart(microtime(true));
        LogContext::setRequest($this->buildRequestContext($request));

        // 同步到 Laravel 13 Context（其他日志通道 / 处理器可共享同一上下文）
        try {
            Context::add('request', LogContext::getRequest());
        } catch (\Throwable) {
            // Context 门面不可用时静默跳过（不影响主流程）
        }

        $response = $next($request);

        // 终止阶段捕获最终响应（响应已发送给客户端之后）
        app()->terminating(function () use ($request, $response) {
            $this->captureResponse($request, $response);
        });

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

        $sessionId = null;
        try {
            $sessionId = session()->getId();
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
            'user' => $user,
            'input' => $input,
            'headers' => $this->redactor->maskHeaders($request->headers->all()),
        ];
    }

    /**
     * 捕获最终响应结果（Laravel 13+ 的响应信息）。
     */
    protected function captureResponse(Request $request, $response): void
    {
        $start = defined('LARAVEL_START')
            ? (microtime(true) - constant('LARAVEL_START'))
            : (microtime(true) - LogContext::getStart());
        $duration = $start * 1000;
        $memory = memory_get_peak_usage(true) / 1024 / 1024;

        $code = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
        $statusText = method_exists($response, 'getReasonPhrase') ? $response->getReasonPhrase() : '';

        $maxBody = $this->config->maxBodyLength;
        $body = null;
        $contentType = method_exists($response, 'headers') ? $response->headers->get('content-type') : null;
        if ($response instanceof Response && $this->shouldCaptureBody($contentType)) {
            $body = mb_substr((string) $response->getContent(), 0, $maxBody);
        }

        $data = [
            'code' => $code,
            'status' => $statusText,
            'duration_ms' => round($duration, 2),
            'memory_mb' => round($memory, 2),
            'headers' => $this->redactor->maskHeaders(
                method_exists($response, 'headers') ? $response->headers->all() : []
            ),
            'body' => $body,
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
}
