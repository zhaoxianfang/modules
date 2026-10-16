<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Listener;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Throwable;
use zxf\Modules\Logging\LogContext;
use zxf\Modules\Logging\Middleware\LogContextMiddleware;

/**
 * RequestHandled 兜底监听：保证「每个 HTTP 请求必定有且仅有一条请求日志」。
 *
 * 正常工作路径由 {@see LogContextMiddleware} 在请求结束时写出请求日志；但以下情况会漏写：
 *  - 中间件采集响应信息时抛异常（已 try/catch，此时需要补写）；
 *  - 宿主应用把路由放在了未在 logging.db.middleware_groups 中声明的中间件组里；
 *  - 中间件被宿主应用以其它方式提前 return。
 *
 * 本监听在 HTTP 内核完成请求后触发，与正常路径通过 {@see LogContext::requestLogWritten()}
 * 互斥：正常路径已写 → 立即返回；未写 → 用 RequestHandled 携带的响应补写。
 *
 * 另一个关键判断：若 {@see LogContext::isActive()} 为 false（说明中间件压根没跑到），
 * 说明该应用并未启用本模块的请求日志能力，此时不应凭空造一条日志，直接返回。
 */
class FlushRequestLog
{
    public function handle(RequestHandled $event): void
    {
        try {
            if (LogContext::requestLogWritten() || LogContext::isSkipped()) {
                return;
            }

            // 中间件未参与本次请求 → 不补写
            if (! LogContext::isActive()) {
                return;
            }

            /** @var LogContextMiddleware $middleware */
            $middleware = app(LogContextMiddleware::class);
            $middleware->finishForSafetyNet($event->request, $event->response);
        } catch (Throwable) {
            // 兜底逻辑自身失败绝不影响主流程
        }
    }
}
