<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Handler;

use Illuminate\Log\Logger;
use zxf\Modules\Logging\Processor\RequestContextProcessor;

/**
 * database 日志通道的 tap 处理器。
 * Laravel logging 的 tap 机制：在通道创建后被调用，签名为
 * __invoke(Logger $logger, ...$params)。这里仅 Web 模式下向日志通道追加
 * RequestContextProcessor（把请求 / 响应上下文注入每条日志的 extra）。
 */
class DatabaseLogTap
{
    public function __invoke(Logger $logger, ...$params): void
    {
        // 仅 Web 模式挂载请求上下文处理器（CLI 无请求上下文）
        if (PHP_SAPI === 'cli') {
            return;
        }

        try {
            $monolog = $logger->getLogger();
            // 幂等保护：若通道被重建（配置缓存刷新 / 重入），避免重复追加同一处理器导致
            // 请求上下文被重复注入（虽为幂等覆盖，但徒增开销）。
            foreach ($monolog->getProcessors() as $existing) {
                if ($existing instanceof RequestContextProcessor) {
                    return;
                }
            }
            $monolog->pushProcessor(new RequestContextProcessor);
        } catch (\Throwable) {
            // 忽略
        }
    }
}
