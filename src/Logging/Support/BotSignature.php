<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Support;

/**
 * 机器人 / 自动化客户端识别结果（不可变值对象）。
 *
 * 由 {@see BotDetector} 生成，供日志模块落库与统计使用：
 *  - isCrawler ：最终判定结果（布尔），直接写入 logs.is_crawler 列；
 *  - type      ：分类标识（search_engine / ai_crawler / seo / monitor / social /
 *                feed / security_scanner / http_library / headless /
 *                library_other / attack_probe / unknown_bot / no_ua / human）；
 *  - score     ：置信分（0~100），由「确定性特征」与「启发式特征」加权累加；
 *  - signals   ：命中证据链（可解释性，便于运维排查误判）；
 *  - client    ：从 UA 中解析出的「客户端 / 工具名」（如 python-requests、Go-http-client）。
 *
 * 设计要点：
 * - 全部字段只读，实例可安全跨请求复用（PHP 常驻场景）；
 * - toArray() 输出可直接 json_encode，供写入 context / extra；
 * - 未命中任何特征时 type = 'human'、isCrawler = false，确保不误伤正常浏览器访问。
 */
final class BotSignature
{
    /** 未识别为自动化客户端时的类型标识 */
    public const TYPE_HUMAN = 'human';

    /** 缺少 User-Agent（多为脚本 / 探测工具） */
    public const TYPE_NO_UA = 'no_ua';

    /** 搜索引擎爬虫 */
    public const TYPE_SEARCH_ENGINE = 'search_engine';

    /** AI / 大模型爬虫与训练数据抓取器 */
    public const TYPE_AI_CRAWLER = 'ai_crawler';

    /** SEO / 站点分析类爬虫 */
    public const TYPE_SEO = 'seo';

    /** 可用性监控 / 拨测 */
    public const TYPE_MONITOR = 'monitor';

    /** 社交平台分享 / 预览抓取 */
    public const TYPE_SOCIAL = 'social';

    /** RSS / Feed 订阅器 */
    public const TYPE_FEED = 'feed';

    /** 安全扫描器 / 漏洞探测工具 */
    public const TYPE_SECURITY_SCANNER = 'security_scanner';

    /** 各语言 HTTP 客户端库（Python / Node / Go / Java / PHP / Ruby / .NET / Rust 等） */
    public const TYPE_HTTP_LIBRARY = 'http_library';

    /** 无头浏览器 / 自动化驱动 */
    public const TYPE_HEADLESS = 'headless';

    /** 其它自动化脚本 / 系统命令行客户端 */
    public const TYPE_LIBRARY_OTHER = 'library_other';

    /** 攻击探测请求（SQL 注入 / 目录穿越 / 敏感文件探测等特征） */
    public const TYPE_ATTACK_PROBE = 'attack_probe';

    /** 命中通用 bot / crawler 语义但无具体归类 */
    public const TYPE_UNKNOWN_BOT = 'unknown_bot';

    /** 伪装成浏览器但请求头指纹自相矛盾 / 被脱壳处理（高级伪装的脚本请求） */
    public const TYPE_SPOOFED_BROWSER = 'spoofed_browser';

    /**
     * @param  bool  $isCrawler  是否判定为机器人 / 自动化请求
     * @param  string  $type  分类标识（取值见本类常量）
     * @param  int  $score  置信分（0~100）
     * @param  string[]  $signals  命中证据（人类可读）
     * @param  string|null  $client  解析出的客户端 / 工具名
     * @param  string|null  $normalizedUa  归一化后的 User-Agent（供审计）
     */
    public function __construct(
        public readonly bool $isCrawler,
        public readonly string $type = self::TYPE_HUMAN,
        public readonly int $score = 0,
        public readonly array $signals = [],
        public readonly ?string $client = null,
        public readonly ?string $normalizedUa = null,
    ) {}

    /**
     * 「普通人类浏览」签名（默认返回，无任何特征命中）。
     */
    public static function human(): self
    {
        return new self(false, self::TYPE_HUMAN, 0, [], null, null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'is_crawler' => $this->isCrawler,
            'type' => $this->type,
            'score' => $this->score,
            'client' => $this->client,
            'signals' => $this->signals,
            'normalized_ua' => $this->normalizedUa,
        ];
    }
}
