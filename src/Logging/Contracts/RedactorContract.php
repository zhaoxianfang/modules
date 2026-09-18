<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Contracts;

/**
 * 敏感数据脱敏契约。
 *
 * 全模块唯一的脱敏事实来源，handler 与 middleware 均依赖此抽象而非具体实现，
 * 便于替换为自定义脱敏策略（如接入公司密钥管理系统）。
 *
 * @package zxf\Modules\Logging
 */
interface RedactorContract
{
    /**
     * 处理一条日志的 context / extra：
     * 递归将无法 JSON 序列化的值（Throwable / 对象）转为可读字符串，
     * 对 key 命中的值脱敏，并对所有字符串值再做自由文本脱敏。
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function sanitizeContext(array $context): array;

    /**
     * 对任意数组（如请求入参）做键名脱敏 + 字符串自由文本脱敏。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function maskArray(array $data): array;

    /**
     * 对请求 / 响应头做脱敏。
     *
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    public function maskHeaders(array $headers): array;

    /**
     * 对自由文本中的 "key":"value" 形态做脱敏（双引号 / 单引号均处理）。
     */
    public function maskText(string $text): string;
}
