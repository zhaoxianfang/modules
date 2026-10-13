<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Processor;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use zxf\Modules\Logging\LogContext;

/**
 * 请求 / 响应上下文 Monolog 处理器。
 *
 * 在每条日志记录写入前，把 LogContext 中采集到的请求 / 响应信息注入到
 * $record->extra，最终由 DatabaseLogHandler 映射到数据表字段。
 *
 * - 轻量请求上下文：每条日志都附带（便于事后溯源是哪次请求产生的日志）。
 * - 完整响应上下文：仅当该记录为「请求 → 响应」汇总日志（context 含 __summary__）
 *   时才写入，避免每条日志都重复存储大体积响应体。
 *
 * @package zxf\Modules\Logging
 */
class RequestContextProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $req = LogContext::getRequest();
        $extra = $record->extra;

        if (! empty($req)) {
            $extra['request_method'] = $req['method'] ?? null;
            $extra['request_url'] = $req['url'] ?? null;
            $extra['request_path'] = $req['path'] ?? null;
            $extra['request_route'] = $req['route'] ?? null;
            $extra['request_controller'] = $req['controller'] ?? null;
            $extra['request_ip'] = $req['ip'] ?? null;
            $extra['request_user_agent'] = $req['user_agent'] ?? null;
            $extra['request_referer'] = $req['referer'] ?? null;
            $extra['request_session_id'] = $req['session_id'] ?? null;
            $extra['request_input'] = $req['input'] ?? null;
            $extra['request_headers'] = $req['headers'] ?? null;
            $extra['module_name'] = $req['module_name'] ?? null;
            $extra['is_crawler'] = $req['is_crawler'] ?? false;

            if (! empty($req['user'])) {
                $extra['user_id'] = $req['user']['id'] ?? null;
                $extra['user_type'] = $req['user']['type'] ?? null;
            }
        }

        // 完整响应信息仅汇总日志写入，避免重复存储
        if (LogContext::hasResponse() && ($record->context['__summary__'] ?? false)) {
            $resp = LogContext::getResponse();
            $extra['response_code'] = $resp['code'] ?? null;
            $extra['response_status'] = $resp['status'] ?? null;
            $extra['response_content'] = $resp['content'] ?? null;
            $extra['response_duration_ms'] = $resp['duration_ms'] ?? null;
            $extra['response_memory_mb'] = $resp['memory_mb'] ?? null;
            $extra['response_headers'] = $resp['headers'] ?? null;
            $extra['response_body'] = $resp['body'] ?? null;
        }

        return $record->with(extra: $extra);
    }
}
