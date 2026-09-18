<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Support;

use Throwable;
use zxf\Modules\Logging\Contracts\RedactorContract;

/**
 * 敏感数据脱敏器（全模块唯一事实来源，实现 RedactorContract）。
 *
 * 此前敏感键名散落在 DatabaseLogHandler（SENSITIVE_KEYS / SENSITIVE_STRING_KEYS）
 * 与 LogContextMiddleware（maskSensitive / maskHeaders）三处，极易出现口径不一致、
 * 新增密钥只在一处生效的疏漏。现收敛到此处单一实现，handler 与 middleware 共用。
 *
 * 统一负责三类脱敏：
 * 1. 数组按「键名」脱敏：key 命中敏感名单（password / token / secret …）的值替换为 ***；
 * 2. 自由文本脱敏：在任意字符串里识别 "key":"value" / 'key':'value' 形态并替换其值；
 * 3. 请求 / 响应头脱敏：Authorization / Cookie / CSRF 等敏感头直接置位。
 *
 * 此外，为避免单条超大字段（如超大表单值、超大入参）导致落库时数据过长异常或
 * 占用过多内存 / 存储，所有字符串值会被截断到 maxFieldLength（默认 20000 字符）。
 *
 * 设计要点：
 * - 构造时传入应用层追加的敏感键名（来自 config('logging.db.sensitive_keys')）；
 *   为空则使用 DEFAULT_KEYS。DEFAULT_KEYS 始终生效，用户键名会被【合并】而非覆盖，
 *   确保核心密钥永不被意外放行。
 * - 自由文本脱敏正则在构造时预编译一次，避免每条日志重复拼接，提升吞吐。
 * - 不依赖任何框架门面，可脱离 Laravel 独立单元测试。
 *
 * @package zxf\Modules\Logging
 */
final class Redactor implements RedactorContract
{
    /** 默认敏感字段键名（始终生效，不可被用户配置清空） */
    public const DEFAULT_KEYS = [
        'password', 'password_confirmation', 'new_password', 'current_password',
        'passwd', 'pwd', 'passphrase',
        'token', 'secret', 'authorization', 'api_key', 'apikey', 'access_token',
        'refresh_token', 'client_secret', 'private_key', 'csrf_token', '_token',
    ];

    /** 默认需脱敏的请求 / 响应头（小写） */
    public const HEADER_KEYS = [
        'authorization', 'cookie', 'set-cookie', 'x-csrf-token', 'x-xsrf-token',
    ];

    /** 脱敏替换值 */
    public const MASK = '***';

    /** 合并后的敏感键名（小写、去重） */
    private array $keys;

    /** 预编译的自由文本脱敏正则（双引号 / 单引号各一套） */
    private string $doublePattern;
    private string $singlePattern;

    /** 单字段最大长度（字符），超出截断，防止超长数据导致落库异常 / 资源浪费 */
    private int $maxFieldLength;

    /**
     * @param string[] $sensitiveKeys   应用层追加的敏感键名（与 DEFAULT_KEYS 合并）
     * @param int       $maxFieldLength  单字段最大长度（0 表示不截断）
     */
    public function __construct(array $sensitiveKeys = [], int $maxFieldLength = 20000)
    {
        $this->keys = array_values(array_unique(
            array_map('strtolower', array_merge(self::DEFAULT_KEYS, $sensitiveKeys))
        ));
        $this->maxFieldLength = $maxFieldLength;

        $alt = implode('|', array_map('preg_quote', $this->keys));
        $this->doublePattern = '/("(?:'.$alt.')"\s*:\s*")([^"]*)(")/i';
        $this->singlePattern = "/('(?:".$alt.")'\s*:\s*')([^']*)(')/i";
    }

    /**
     * 处理一条日志的 context / extra：
     * 递归将无法 JSON 序列化的值（Throwable / 对象）转为可读字符串，
     * 对 key 命中的值脱敏，并对所有字符串值再做自由文本脱敏 + 超长截断。
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function sanitizeContext(array $context): array
    {
        $walk = function (&$value, string|int $key) use (&$walk): void {
            if (is_array($value)) {
                foreach ($value as $k => &$v) {
                    $walk($v, $k);
                }

                return;
            }
            if ($value instanceof Throwable) {
                $value = sprintf('[%s] %s', get_class($value), $value->getMessage());

                return;
            }
            if (is_object($value)) {
                $value = sprintf('[object] %s', get_class($value));

                return;
            }
            if (is_string($key) && in_array(strtolower($key), $this->keys, true)) {
                $value = self::MASK;

                return;
            }
            if (is_string($value)) {
                $value = $this->truncate($this->maskText($value));
            }
        };

        $walk($context, '');

        return $context;
    }

    /**
     * 对任意数组（如请求入参）做键名脱敏 + 字符串自由文本脱敏 + 超长截断。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function maskArray(array $data): array
    {
        return $this->sanitizeContext($data);
    }

    /**
     * 对请求 / 响应头做脱敏。
     *
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    public function maskHeaders(array $headers): array
    {
        $mask = array_map('strtolower', self::HEADER_KEYS);
        $out = [];
        foreach ($headers as $k => $v) {
            $out[$k] = in_array(strtolower((string) $k), $mask, true)
                ? [self::MASK]
                : $v;
        }

        return $out;
    }

    /**
     * 对自由文本中的 "key":"value" 形态做脱敏（双引号 / 单引号均处理）。
     */
    public function maskText(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $text = (string) preg_replace($this->doublePattern, '$1'.self::MASK.'$3', $text);
        $text = (string) preg_replace($this->singlePattern, '$1'.self::MASK.'$3', $text);

        return $text;
    }

    /**
     * 单字段长度截断，防止超长数据导致落库异常或占用过多资源。
     */
    private function truncate(string $text): string
    {
        if ($this->maxFieldLength > 0 && mb_strlen($text, 'UTF-8') > $this->maxFieldLength) {
            return mb_substr($text, 0, $this->maxFieldLength, 'UTF-8');
        }

        return $text;
    }
}
