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
 * 注入规则：
 * - 轻量请求上下文（方法 / URL / 路径 / 路由 / 控制器 / IP / UA / 来源 / 会话 /
 *   模块名 / 机器人识别结果 / 认证用户）：每条日志都附带，便于事后溯源是哪次请求产生的日志。
 *   这批数据在中间件里已脱敏完毕并压平为 {@see LogContext::baseExtra()}，此处只做数组合并，
 *   不做任何重复的脱敏或上下文解析，因此单条日志的注入开销几乎可忽略。
 * - 大体积载荷（请求入参 / 请求头）与完整响应上下文（状态码 / 耗时 / 响应体等）：
 *   **只注入到请求日志那一条**（携带 {@see LogContext::REQUEST_LOG_FLAG} 标记）。
 *   业务日志不重复存储这些大字段，既省 CPU 也显著降低 logs 表体积。
 *
 * 安全与健壮性：
 * - 仅当 LogContext 处于「有效请求上下文」时才注入（LogContext::isActive() 会排除
 *   CLI / 队列 worker），彻底避免长驻进程下把上一次 HTTP 请求的数据串到队列日志里；
 * - 通过 $record->with(extra: ...) 生成新记录，不修改 readonly 的 context/message；
 * - 合并时以 baseExtra 为准，业务代码伪造的同名 extra 键会被覆盖（防止伪造 request_ip
 *   等审计字段）；但业务自有键不会丢失。
 */
class RequestContextProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        // 无有效请求上下文（CLI、队列 worker、尚未进入中间件）→ 原样返回，零开销
        if (! LogContext::isActive()) {
            return $record;
        }

        return $this->inject($record);
    }

    /**
     * 把请求 / 响应上下文注入到 extra（不做上下文有效性判断，便于独立测试与复用）。
     *
     * 拆为独立方法的原因：{@see self::__invoke()} 开头有一道 LogContext::isActive() 门禁，
     * 而该门禁在 CLI（测试与 artisan）下恒为 false，导致注入逻辑无法被测试覆盖。
     */
    public function inject(LogRecord $record): LogRecord
    {
        $base = LogContext::baseExtra();
        if ($base === []) {
            return $record;
        }

        // 只有「请求日志」那一行才追加大体积载荷与响应上下文
        if (LogContext::hasResponse()
            && (bool) ($record->context[LogContext::REQUEST_LOG_FLAG] ?? false)) {
            $resp = LogContext::getResponse();

            $base += LogContext::payloadExtra();
            $base['response_code'] = $resp['code'] ?? null;
            $base['response_status'] = $resp['status'] ?? null;
            $base['response_content'] = $resp['content'] ?? null;
            $base['response_duration_ms'] = $resp['duration_ms'] ?? null;
            $base['response_memory_mb'] = $resp['memory_mb'] ?? null;
            $base['response_headers'] = $resp['headers'] ?? null;
            $base['response_body'] = $resp['body'] ?? null;
        }

        // base 为准（覆盖业务伪造的同名审计字段），业务自有键保留在后面
        return $record->with(extra: $base + $record->extra);
    }
}
