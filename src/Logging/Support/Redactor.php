<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Support;

use Throwable;
use zxf\Modules\Logging\Contracts\RedactorContract;

/**
 * 敏感数据脱敏器（全模块唯一事实来源，实现 RedactorContract）。
 *
 * 与 LogContextMiddleware（maskSensitive / maskHeaders）三处，极易出现口径不一致、
 * 新增密钥只在一处生效的疏漏。现收敛到此处单一实现，handler 与 middleware 共用。
 *
 * 统一负责五类脱敏：
 *  1. 数组按「键名」脱敏：key 命中敏感名单（password / token / secret …）的值替换为 ***；
 *     键名匹配支持「后缀式」命中（如 user_password、X-Auth-Token、myApiKey 同样被识别），
 *     防止攻击者通过改字段名绕过。
 *  2. 自由文本脱敏：识别 "key":"value" / 'key':'value' / key=value / key: value 四种形态；
 *  3. 凭证形态脱敏：JWT（xxx.yyy.zzz）、Authorization: Bearer/Basic/Token xxx 一律打码；
 *  4. 请求 / 响应头脱敏：Authorization / Cookie / X-Api-Key 等敏感头直接置位；
 *  5. 超长字段截断：所有字符串值截断到 maxFieldLength（默认 20000 字符）。
 *
 * 设计要点：
 * - 构造时传入应用层追加的敏感键名（来自 config('logging.db.sensitive_keys')）；
 *   为空则使用 DEFAULT_KEYS。DEFAULT_KEYS 始终生效，用户键名会被【合并】而非覆盖，
 *   确保核心密钥永不被意外放行。
 * - 所有正则与「键名 → 归一化」映射在构造时预编译/预处理一次，避免每条日志重复拼接；
 * - 递归深度受限（MAX_DEPTH），防止深嵌套入参造成栈溢出；
 * - 不依赖任何框架门面，可脱离 Laravel 独立单元测试。
 */
final class Redactor implements RedactorContract
{
    /** 默认敏感字段键名（始终生效，不可被用户配置清空） */
    public const DEFAULT_KEYS = [
        'password', 'password_confirmation', 'new_password', 'current_password',
        'passwd', 'pwd', 'passphrase', 'pass', 'credential', 'credentials',
        'token', 'secret', 'authorization', 'api_key', 'apikey', 'access_token',
        'refresh_token', 'id_token', 'client_secret', 'private_key', 'public_key',
        'csrf_token', '_token', 'session_id', 'jwt', 'signature', 'salt',
        // 会话标识：Laravel 实际 cookie 名为「{APP_NAME}_session」（默认 laravel_session），
        // 不含 'session_id' 字样，若只列 session_id 则 laravel_session=<sid> 会明文入库
        'session', 'sessionid', 'laravel_session', 'phpsessid', 'jsessionid',
        'card_number', 'cardnumber', 'cvv', 'cvc', 'id_card', 'idcard',
        'bank_account', 'bankaccount', 'access_key', 'secret_key', 'app_secret',
        'remember_token', 'otp', 'mfa_code', 'sms_code', 'captcha', 'auth_code',
        // 个人可识别信息：日志表通常无脱敏保留期策略，不脱敏等同长期留存 PII
        'phone', 'mobile', 'tel', 'telephone', 'email', 'mail',
        'id_no', 'idno', 'id_number', 'real_name', 'realname', 'bank_card', 'bankcard',
    ];

    /** 默认需脱敏的请求 / 响应头（小写） */
    public const HEADER_KEYS = [
        'authorization', 'proxy-authorization', 'www-authenticate', 'cookie', 'set-cookie',
        'x-csrf-token', 'x-xsrf-token', 'csrf-token', 'x-request-token',
        'x-api-key', 'api-key', 'apikey', 'x-auth-token', 'x-access-token',
        'x-amz-security-token', 'authentication-info', 'proxy-authenticate',
        'x-forwarded-authorization', 'x-hub-signature', 'x-hub-signature-256',
    ];

    /** 脱敏替换值 */
    public const MASK = '***';

    /** 递归深度上限（超出后原样返回深层结构，保护调用栈） */
    private const MAX_DEPTH = 12;

    /** 合并后的敏感键名（小写、去重） */
    private array $keys;

    /** 敏感键名的「归一化形式」集合（去除 - / _ 后比较，覆盖 X-Api-Key、user_password 等变体） */
    private array $normalizedKeys;

    /** 预编译的自由文本脱敏正则：双引号 / 单引号 / 无引号 key=value */
    private string $doublePattern;

    private string $singlePattern;

    private string $bareKeyValuePattern;

    /** 预编译的 JWT / Authorization 凭证正则 */
    private string $jwtPattern;

    private string $authTokenPattern;

    /** 单字段最大长度（字符），超出截断，防止超长数据导致落库异常 / 资源浪费 */
    private int $maxFieldLength;

    /**
     * 仅用于「请求头」判定的敏感键名（小写 → true）
     *
     * DEFAULT_KEYS 里没有 cookie / set-cookie，若请求头只按 DEFAULT_KEYS 判定，
     *     Cookie: laravel_session=...; XSRF-TOKEN=...
     * 会以**明文**整条写进 system_logs 表的 request_headers 列 ——
     * 会话 Cookie 本身就是可直接复用的凭证，泄漏等于把登录态送进日志表。
     * 因此请求头一律按「DEFAULT_KEYS ∪ HEADER_KEYS」的口径处理。
     *
     * @var array<string, bool>
     */
    private array $headerKeys;

    /**
     * @param  string[]  $sensitiveKeys  应用层追加的敏感键名（与 DEFAULT_KEYS 合并）
     * @param  int  $maxFieldLength  单字段最大长度（0 表示不截断）
     */
    public function __construct(array $sensitiveKeys = [], int $maxFieldLength = 20000)
    {
        $merged = [];
        foreach (array_merge(self::DEFAULT_KEYS, $sensitiveKeys) as $key) {
            if (is_string($key) && $key !== '') {
                $merged[mb_strtolower($key)] = true;
            }
        }
        $this->keys = array_keys($merged);

        // 请求头专用敏感键名单（cookie / set-cookie / x-csrf-token 等不在 DEFAULT_KEYS 中）
        $headerMerged = [];
        foreach (array_merge(self::DEFAULT_KEYS, self::HEADER_KEYS, $sensitiveKeys) as $key) {
            if (is_string($key) && $key !== '') {
                $headerMerged[mb_strtolower($key)] = true;
            }
        }
        $this->headerKeys = $headerMerged;

        $normalized = [];
        foreach ($this->keys as $key) {
            $normalized[self::normalizeKey($key)] = true;
        }
        $this->normalizedKeys = array_keys($normalized);

        $this->maxFieldLength = max(0, $maxFieldLength);

        $this->compile();
    }

    /**
     * 按当前 $keys / $headerKeys / $maxFieldLength 编译全部正则。
     *
     * 构造函数调用一次；常驻进程（Octane / queue worker）里配置变更后
     * 可调用 {@see self::recompile()} 重新编译，无需重建实例或重启进程。
     */
    private function compile(): void
    {
        // 转义后按长度降序排列，保证 "password" 不会先于 "password_confirmation" 被匹配
        $quoted = [];
        foreach ($this->keys as $key) {
            $quoted[] = preg_quote($key, '/');
        }
        usort($quoted, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $alt = implode('|', $quoted);

        // 「无引号形态」的键名额外支持连字符 / 下划线互换（api-key、api_key、x-api-key 等），
        // 并允许任意层级的字段前缀（user_password、remember_token），
        // 避免攻击者仅通过改写字段分隔符或加前缀即可绕过脱敏。
        $flex = [];
        foreach ($this->keys as $key) {
            $flex[] = str_replace('_', '[_-]?', preg_quote($key, '/'));
        }
        usort($flex, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $flexAlt = implode('|', $flex);

        // 1) "key": "value"（允许值中出现转义引号）
        $this->doublePattern = '/("(?:'.$alt.')"\s*:\s*")((?:\\\\.|[^"\\\\])*)(")/i';
        // 2) 'key': 'value'
        $this->singlePattern = "/('(?:".$alt.")'\s*:\s*')((?:\\\\.|[^'\\\\])*)(')/i";
        // 3) key=value / key: value（无引号形态，覆盖 query string、表单、命令输出等）
        //    - (?:[a-z0-9]+[_-])* 允许 user_password / x-api-key 这类带前缀的写法
        //      （该前缀单独捕获并在替换时原样回填，确保脱敏不会篡改非敏感文本）
        //    - (?!["']) 排除已被双/单引号规则覆盖的 JSON 形态，避免重复处理
        //    - (?!Bearer|Basic|Token|Digest|ApiKey\b) 避免把认证方案名当成凭证值打码成 "*** ***"
        $this->bareKeyValuePattern = '/((?:[a-z0-9]+[_-])*)('.$flexAlt.')(\s*[=:]\s*)(?!["\'])(?!(?:Bearer|Basic|Token|Digest|ApiKey)\b)([^\s&,;"\'<>\)\]\}]{1,2048})/i';

        // 4) JWT（header.payload.signature）
        $this->jwtPattern = '/\beyJ[A-Za-z0-9_\-]{6,}\.[A-Za-z0-9_\-]{4,}\.[A-Za-z0-9_\-]*/';
        // 5) Authorization: Bearer / Basic / Token xxx
        $this->authTokenPattern = '/\b(Bearer|Basic|Token|Digest|ApiKey)\s+([A-Za-z0-9\-._~+\/=]{8,})/i';
    }

    /**
     * 按当前 $keys / $headerKeys / $maxFieldLength 重新编译所有派生结构。
     *
     * 构造函数只调用一次；常驻进程（Octane / queue worker）里配置变更后
     * 可调用本方法重编译，无需重建 Redactor 实例或重启进程。
     */
    public function recompile(): void
    {
        $this->compile();
    }

    /**
     * 释放编译期缓存。
     * 仅用于可重复构造的场景（如长驻 worker 显式回收），常规使用无需调用。
     */
    public function releaseCompiled(): void
    {
        $this->keys = [];
        $this->headerKeys = [];
        $this->normalizedKeys = [];
        $this->doublePattern = '';
        $this->singlePattern = '';
        $this->bareKeyValuePattern = '';
        $this->jwtPattern = '';
        $this->authTokenPattern = '';
        $this->maxFieldLength = 0;
    }

    /**
     * 处理一条日志的 context / extra：
     * 递归将无法 JSON 序列化的值（Throwable / 对象）转为可读字符串，
     * 对 key 命中的值脱敏，并对所有字符串值再做自由文本脱敏 + 超长截断。
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function sanitizeContext(array $context): array
    {
        $this->walk($context, '', 0);

        return $context;
    }

    /**
     * 对任意数组（如请求入参）做键名脱敏 + 字符串自由文本脱敏 + 超长截断。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function maskArray(array $data): array
    {
        return $this->sanitizeContext($data);
    }

    /**
     * 对请求 / 响应头做脱敏。
     * 同时额外屏蔽「值形似凭证」的头（如 X-Custom-Token），防止自定义头名绕过名单。
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    public function maskHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $name = mb_strtolower((string) $k);

            // 请求头按 HEADER_KEYS 专用名单判定（cookie / set-cookie / x-csrf-token 等
            // 不在 DEFAULT_KEYS 中，只查 DEFAULT_KEYS 会导致会话 Cookie 明文入库）。
            $isHeaderSensitive = isset($this->headerKeys[$name])
                // 归一化后再比一次，覆盖 X-Api-Key / api_key 等写法变体
                || $this->isSensitiveKey($name);

            if ($isHeaderSensitive) {
                $out[$k] = [self::MASK];

                continue;
            }

            if (is_array($v)) {
                $out[$k] = array_map(fn (mixed $item): mixed => is_string($item) ? $this->maskText($item) : $item, $v);
            } elseif (is_string($v)) {
                $out[$k] = $this->maskText($v);
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * 对自由文本做脱敏：
     *  - "key":"value" / 'key':'value' / key=value / key: value
     *  - JWT 与 Authorization: Bearer|Basic|Token 凭证
     *
     * @param  bool  $truncate  是否按 maxFieldLength 截断（maskText 场景默认不截断，由调用方控制）
     */
    public function maskText(string $text, bool $truncate = false): string
    {
        if ($text === '') {
            return $text;
        }

        // 先处理凭证形态（JWT / Bearer），再处理无引号的 key=value，
        // 最后才做双/单引号形态 —— 顺序保证 "password":"x" 这类不会被裸规则先行误吞，
        // 也保证 Authorization: Bearer <jwt> 整体被正确识别而不是被拆成两段。
        $text = (string) preg_replace($this->jwtPattern, self::MASK, $text);
        $text = (string) preg_replace($this->authTokenPattern, '$1 '.self::MASK, $text);
        $text = (string) preg_replace($this->bareKeyValuePattern, '$1$2$3'.self::MASK, $text);
        $text = (string) preg_replace($this->doublePattern, '$1'.self::MASK.'$3', $text);
        $text = (string) preg_replace($this->singlePattern, '$1'.self::MASK.'$3', $text);

        return $truncate ? $this->truncate($text) : $text;
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

    /**
     * 递归脱敏核心。
     *
     * @param  array<string|int, mixed>  $data
     */
    private function walk(array &$data, string|int $key, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            $data = '[深度超限]';

            return;
        }

        foreach ($data as $k => &$value) {
            if (is_array($value)) {
                $this->walk($value, is_string($k) ? $k : $key, $depth + 1);

                continue;
            }

            if ($value instanceof Throwable) {
                $value = sprintf('[%s] %s', $value::class, $this->maskText($value->getMessage()));

                continue;
            }

            if ($value instanceof \JsonSerializable) {
                $value = $this->walkValue($value->jsonSerialize(), $k, $depth);

                continue;
            }

            if (is_object($value)) {
                $value = '[object] '.$value::class;

                continue;
            }

            if (is_resource($value)) {
                $value = '[resource]';

                continue;
            }

            if (is_string($k) && $this->isSensitiveKey($k)) {
                $value = self::MASK;

                continue;
            }

            if (is_string($value)) {
                $value = $this->truncate($this->maskText($value));
            }
        }
        unset($value);
    }

    /**
     * 处理单个标量值（含 JsonSerializable 解包后的结果）。
     */
    private function walkValue(mixed $value, string|int $key, int $depth): mixed
    {
        if (is_array($value)) {
            $walked = $value;
            $this->walk($walked, $key, $depth + 1);

            return $walked;
        }

        if ($value instanceof Throwable) {
            return sprintf('[%s] %s', $value::class, $this->maskText($value->getMessage()));
        }

        if (is_object($value)) {
            return '[object] '.$value::class;
        }

        if (is_string($key) && $this->isSensitiveKey($key)) {
            return self::MASK;
        }

        return is_string($value) ? $this->truncate($this->maskText($value)) : $value;
    }

    /**
     * 键名是否为敏感键。
     * 匹配策略：① 归一化后完全相等；② 归一化后以敏感键结尾（覆盖 user_password / myApiKey）。
     */
    private function isSensitiveKey(string $key): bool
    {
        $normalized = self::normalizeKey($key);
        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, $this->normalizedKeys, true)) {
            return true;
        }

        foreach ($this->normalizedKeys as $sensitive) {
            // 至少 3 个字符才做后缀匹配，避免 "id"/"ip" 之类过短键造成大面积误伤
            if (strlen($sensitive) < 3) {
                continue;
            }
            if (str_ends_with($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 键名归一化：小写 + 移除分隔符与非字母数字字符，用于等价比较。
     */
    private static function normalizeKey(string $key): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($key));
    }
}
