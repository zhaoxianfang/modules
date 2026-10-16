<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Support;

/**
 * 机器人 / 自动化客户端探测器。
 *
 * 目标：在「无法只看 User-Agent」的现代流量环境下，尽可能准确地把
 *  ① 搜索引擎与各类爬虫、② 各开发语言的 HTTP 客户端脚本、③ 无头浏览器与自动化驱动、
 *  ④ 安全扫描 / 漏洞探测工具、⑤ 经过「脱壳伪装」（编码、零宽字符、同形字、控制符注入等）
 *     的客户端识别出来，同时**不误伤**正常浏览器流量。
 *
 * 检测分五层（自上而下，命中即加权）：
 *  L1 归一化：把「脱壳」过的 UA 还原成可比对的可读串。
 *      - HTML 实体解码（&#71; → G）
 *      - 百分号 / 双重 URL 解码（%47%6f → Go、%2547%256f → Go）
 *      - 剥离零宽与不可见字符（U+200B/200C/200D/FEFF/00AD…）
 *      - 剥离控制字符（含 CRLF，避免 CRLF 注入型伪装绕过）
 *      - 全角 / 半角统一（NFKC）
 *      - 西里尔 / 希腊同形字回写（е→e、о→o、а→a …），破解「同形字换写」伪装
 *      - 剥离插入的分隔符（g-o-h-t-p-…、g o t … 等）
 *  L2 确定性特征：命中已知 UA 词库（按分类打分，命中即判定为爬虫）。
 *  L3 伪装特征：原始串与归一化串不一致、控制符注入、超长 UA 等 —— 强烈可疑信号。
 *  L4 指纹一致性：自称浏览器却缺少浏览器必备请求头、Client Hints 与 UA 品牌互相矛盾、
 *      极简 Accept + Connection: close 等「脚本指纹」。
 *  L5 攻击探测：UA / 路径中出现的 SQL 注入、XSS、目录穿越、敏感文件探测特征。
 *
 * 判定：确定性命中（L2/L3/L5）直接置 isCrawler=true；L4 启发式按分值累加，
 *      达到阈值（默认 {@see self::DEFAULT_THRESHOLD}）亦判定为爬虫。
 *
 * 纯静态实现，不依赖任何框架门面，可在 CLI / 队列 / 单元测试中独立使用。
 */
final class BotDetector
{
    /** 默认判定阈值（启发式累加分达到即视为爬虫） */
    public const DEFAULT_THRESHOLD = 40;

    /**
     * 分类词库：类型 => 特征串列表（小写；子串匹配，命中即视为确定性识别）。
     * 说明：条目全部选用「客户端 / 工具的官方 UA 标识」，正常浏览器 UA 不会包含。
     *
     * @var array<string, string[]>
     */
    private const LIBRARY = [
        BotSignature::TYPE_SEARCH_ENGINE => [
            'googlebot', 'google-inspectiontool', 'storebot-googlebot', 'adsbot-google',
            'googleother', 'google-extended', 'bingbot', 'adidxbot', 'bingpreview',
            'baiduspider', 'yandexbot', 'yandeximages', 'yandexmobilebot', 'duckduckbot',
            'sogou', 'exabot', 'seznambot', 'teoma', 'ia_archiver', 'slurp', 'applebot',
            'petalbot', 'mail.ru_bot', 'naverbot', 'seznambot', 'qwantify', 'mojeekbot',
            'archive.org_bot', 'wayback', 'coccocbot', '360spider', 'zgrab', 'majestic12',
        ],
        BotSignature::TYPE_AI_CRAWLER => [
            'gptbot', 'chatgpt-user', 'oai-searchbot', 'claudebot', 'claude-web',
            'anthropic-ai', 'ccbot', 'perplexitybot', 'perplexity-user', 'bytespider',
            'amazonbot', 'applebot-extended', 'meta-externalagent', 'meta-externalfetcher',
            'omgili', 'omgilibot', 'diffbot', 'cohere-ai', 'youbot', 'img2dataset',
            'webzio-extended', 'pinterestbot', 'kangaroo bot', 'meltwater', 'scrapybot',
            'dataforseobot', 'exabot-mix', 'vertexbot', 'google-gemini', 'mistralai',
        ],
        BotSignature::TYPE_SEO => [
            'semrushbot', 'ahrefsbot', 'mj12bot', 'dotbot', 'rogerbot', 'screaming frog',
            'sitebulb', 'seokicks', 'serpstatbot', 'sistrix', 'linkdexbot', 'blexbot',
            'lumberseobot', 'adanybot', 'spbot', 'coccocbot', 'megaindex', 'turnitinbot',
            'semrush', 'ahrefs', 'seostar', 'pinterestbot',
        ],
        BotSignature::TYPE_MONITOR => [
            'uptimerobot', 'pingdom', 'statuscake', 'site24x7', 'newrelicpinger',
            'hetrixtools', 'betteruptime', 'updown.io', 'freshping', 'hyperping',
            'nodeping', 'statuscake', 'monitis', 'webmon', 'checkly', 'better uptime',
        ],
        BotSignature::TYPE_SOCIAL => [
            'facebookexternalhit', 'facebookcatalog', 'twitterbot', 'linkedinbot',
            'telegrambot', 'whatsapp', 'discordbot', 'slackbot', 'slack-imgproxy',
            'embedly', 'pinterest', 'tumblr', 'redditbot', 'skypeuripreview',
            'nuzzel', 'outbrain', 'vkshare', 'bitlybot', 'xing-contenttabreceiver',
            'applebot', 'discord', 'mastodon', 'bluesky', 'threads',
        ],
        BotSignature::TYPE_FEED => [
            'feedfetcher', 'feedburner', 'feedly', 'netvibes', 'newsblur',
            'feedly', 'rss', 'atom', 'bloglines', 'rssbot', 'feeder', 'newsdaemon',
        ],
        BotSignature::TYPE_SECURITY_SCANNER => [
            'sqlmap', 'nikto', 'nmap', 'masscan', 'zgrab', 'nuclei', 'wpscan',
            'dirbuster', 'gobuster', 'ffuf', 'feroxbuster', 'dirsearch', 'wfuzz',
            'burp', 'burpsuite', 'owasp', 'zaproxy', 'acunetix', 'nessus', 'qualys',
            'openvas', 'w3af', 'arachni', 'metasploit', 'havij', 'netsparker',
            'detectify', 'censys', 'shodan', 'internet-measurement', 'paloalto',
            'l9explore', 'zgrab', 'brutus', 'hydra', 'commix', 'xsser', 'wpscan',
        ],
        BotSignature::TYPE_HTTP_LIBRARY => [
            // Python
            'python-requests', 'python-urllib', 'urllib3', 'urllib/2', 'aiohttp',
            'httpx', 'pycurl', 'requests/', 'scrapy', 'pyspider', 'spider/',
            // Node.js / TypeScript
            'axios', 'node-fetch', 'undici', 'got/', 'superagent', 'request/',
            'puppeteer', 'requestlight', 'node-superagent',
            // Go
            'go-http-client', 'go-resty', 'golang',
            // Java / Kotlin / JVM
            'java/', 'apache-httpclient', 'okhttp', 'httpclient', 'jsoup',
            'jersey', 'retrofit', 'wercker', 'restsharp',
            // PHP
            'guzzle', 'guzzlehttp', 'symfony httpclient', 'zend_http_client',
            'typhoeus', 'faraday', 'httpie', 'php-curl', 'curl/', 'libcurl',
            'illuminate/http', 'laravel-http', 'lwp::simple', 'lwp-trivial',
            // Ruby
            'ruby', 'faraday-net_http', 'rest-client',
            // .NET / C#
            'winhttp', 'restsharp', 'httpwebrequest', 'microsoft.net',
            // Rust / Zig / 其它
            'reqwest', 'ureq', 'hyper/', 'dart:io', 'dart/', 'colly',
        ],
        BotSignature::TYPE_HEADLESS => [
            'headlesschrome', 'phantomjs', 'puppeteer', 'playwright', 'selenium',
            'electron', 'webdriver', 'chromedriver', 'geckodriver', 'splash',
            'htmlunit', 'jsdom', 'casperjs', 'slimerjs', 'nightmare', 'cypress',
        ],
        BotSignature::TYPE_LIBRARY_OTHER => [
            'wget', 'libwww-perl', 'lwp::simple', 'powershell', 'rest-client',
            'http_get', 'http_request', 'libnet', 'fetch/', 'busybox', 'snmpget',
        ],
    ];

    /**
     * 通用 bot 语义特征（词边界正则），命中即至少判定为爬虫。
     * 采用 \b 词边界，避免把正常单词（如 "robotics"、"-bot" 前缀）误判。
     */
    private const GENERIC_BOT_PATTERN = '/\b(bot|spider|crawl|crawler|scraper|scraping|archiver|fetcher|harvester|harvest|probe|prober|checker|checkerbot|monitor|indexer)\b/i';

    /**
     * 攻击探测特征（SQL 注入 / XSS / 目录穿越 / 敏感文件），命中即判定为攻击探测流量。
     */
    private const ATTACK_PATTERN = '/(\bunion\s+(all\s+)?select\b|\bor\s+1\s*=\s*1\b|\'\s*or\s*\'?1\'?\s*=\s*\'?1|<script\b|\.\.\/\.\.\/|\.\.%2f|etc\/passwd|etc\/shadow|boot\.ini|win\.ini|\/etc\/shadow|%2e%2e%2f|select\s+.+\s+from\s+information_schema|\bwaitfor\s+delay\b|\bbenchmark\s*\(|\bsleep\s*\(\s*\d)/i';

    /** 敏感文件 / 高频攻击路径特征（对 path 做子串匹配） */
    private const ATTACK_PATHS = [
        '/.env', '/.git/', '/.aws/', '/.svn/', '/.hg/', '/.DS_Store',
        '/vendor/phpunit/', '/wp-login.php', '/wp-admin/', '/wp-content/',
        '/phpmyadmin', '/xmlrpc.php', '/cgi-bin/', '/shell.php', '/adminer',
        '/config.php.bak', '/.ssh/', '/id_rsa', '/.DS_Store', '/actuator',
        '/solr/', '/manager/html', '/druid/', '/api/v1/../',
    ];

    /** 同形字 / 异体字符 → ASCII 回写表（破解「换字母」伪装） */
    private const HOMOGLYPHS = [
        // 西里尔小写
        "\u{0430}" => 'a', "\u{0435}" => 'e', "\u{043E}" => 'o', "\u{0440}" => 'p',
        "\u{0441}" => 'c', "\u{0445}" => 'x', "\u{0443}" => 'y', "\u{0456}" => 'i',
        "\u{0458}" => 'j', "\u{04CF}" => 'l', "\u{0433}" => 'r', "\u{043D}" => 'h',
        "\u{043A}" => 'k', "\u{043C}" => 'm', "\u{043D}" => 'h', "\u{0442}" => 't',
        "\u{0431}" => 'b', "\u{0434}" => 'd', "\u{0437}" => 'z', "\u{0432}" => 'b',
        // 希腊小写
        "\u{03B1}" => 'a', "\u{03B2}" => 'b', "\u{03B5}" => 'e', "\u{03B9}" => 'i',
        "\u{03BA}" => 'k', "\u{03BD}" => 'n', "\u{03BF}" => 'o', "\u{03C1}" => 'p',
        "\u{03C4}" => 't', "\u{03C5}" => 'u', "\u{03C7}" => 'x', "\u{03BC}" => 'm',
        // 相似符号
        "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2013}" => '-',
        "\u{2014}" => '-', "\u{2212}" => '-',
    ];

    /**
     * 综合探测入口。
     *
     * @param  string|null  $userAgent  原始 User-Agent（可为空）
     * @param  array<string, string>  $headers  小写键名的请求头（如 $request->headers->all() 已转小写）
     * @param  string|null  $path  请求路径（用于攻击探测与脚本指纹）
     * @param  int  $threshold  启发式判定阈值
     */
    public static function detect(
        ?string $userAgent,
        array $headers = [],
        ?string $path = null,
        int $threshold = self::DEFAULT_THRESHOLD,
    ): BotSignature {
        $raw = $userAgent ?? '';
        $signals = [];
        $score = 0;
        $type = null;
        $client = null;

        $lowerHeaders = self::lowercaseHeaders($headers);
        $normalized = self::normalize($raw);
        // 「保守归一化」只做编码 / 不可见字符 / 同形字层面的还原，不做分隔符合并，
        // 因此真实浏览器 UA 归一化后与原串一致，而被脱壳的 UA 一定发生变化 ——
        // 这正是判定「伪装」的可靠依据（若与激进归一化比较，Mac OS X 这类正常写法也会被误判）。
        $conservative = self::normalizeConservative($raw);

        // ---------------- L1/L3：脱壳与伪装特征 ----------------
        if ($raw === '' || trim($raw) === '') {
            // 无 UA：绝大多数是脚本 / 探测工具，少数为刻意屏蔽 UA 的客户端
            return new BotSignature(true, BotSignature::TYPE_NO_UA, 100, ['empty-user-agent'], null, $normalized);
        }

        if ($conservative !== mb_strtolower(trim($raw))) {
            $score += 45;
            $signals[] = 'obfuscated-ua（原始串与归一化串不一致：存在编码 / 零宽 / 同形字伪装）';
        }

        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $raw) === 1) {
            $score += 60;
            $signals[] = 'ua-control-chars（UA 含控制字符 / 换行，典型 CRLF 注入伪装）';
        }

        if (mb_strlen($raw) > 512) {
            $score += 25;
            $signals[] = 'ua-overlong（UA 长度异常，可能用于截断绕过）';
        }

        // ---------------- L2：确定性词库匹配 ----------------
        foreach (self::LIBRARY as $category => $tokens) {
            $hit = self::longestMatch($normalized, $tokens);
            if ($hit === null) {
                continue;
            }
            $score += 100;
            // 分类优先级：攻击 > 安全扫描 > 无头 > 搜索引擎/AI > 脚本库 > 其它
            $type = self::prioritizeType($type, $category);
            // 同类别只记一次，避免刷屏；取「最长命中」以报告最有信息量的标识
            $signals[] = 'ua-match:'.$hit.'（'.self::typeLabel($category).'）';
        }

        // 客户端标识：优先在「保守归一化」串上匹配（保留原始分隔符），
        // 这样 Go-http-client 会被如实报成 go-http-client，而不是被剥掉连字符后误记成 httpclient；
        // 若保守串匹配不到（UA 被重度脱壳），再退回激进归一化串兜底。
        $client = self::matchClient(self::normalizeConservative($raw)) ?? self::matchClient($normalized);

        // 通用 bot 语义（无明确归类时）
        if ($type === null && preg_match(self::GENERIC_BOT_PATTERN, $normalized) === 1) {
            $score += 100;
            $type = BotSignature::TYPE_UNKNOWN_BOT;
            $signals[] = 'ua-generic-bot（命中通用 bot/crawler 语义）';
        }

        // ---------------- L5：攻击探测特征 ----------------
        $haystack = $normalized.' '.(is_string($path) ? mb_strtolower($path) : '');
        if (preg_match(self::ATTACK_PATTERN, $haystack) === 1) {
            $score += 100;
            $type = BotSignature::TYPE_ATTACK_PROBE;
            $signals[] = 'attack-payload（UA / 路径中含注入或探测载荷）';
        }
        if (is_string($path)) {
            $lowerPath = mb_strtolower($path);
            foreach (self::ATTACK_PATHS as $needle) {
                if (str_contains($lowerPath, $needle)) {
                    $score += 90;
                    $type = BotSignature::TYPE_ATTACK_PROBE;
                    $signals[] = 'attack-path:'.$needle;

                    break;
                }
            }
        }

        // ---------------- L4：请求头指纹一致性（启发式） ----------------
        $score += self::scoreHeaderFingerprint($normalized, $lowerHeaders, $signals);

        // ---------------- 汇总判定 ----------------
        $isCrawler = $score >= $threshold;
        if (! $isCrawler && $type !== null) {
            $isCrawler = true; // 命中明确分类但分值未过阈值时，保守判定为爬虫
        }

        if (! $isCrawler) {
            $type = BotSignature::TYPE_HUMAN;
            $signals = [];
        } elseif ($type === null) {
            // 只有启发式证据（缺头 / CH 矛盾 / 编码伪装 / 注入）而没有明确词库命中：
            // 归类为「伪装浏览器」，明确表达「这是伪装成浏览器的脚本」而非普通人类流量。
            $type = BotSignature::TYPE_SPOOFED_BROWSER;
        }

        return new BotSignature(
            $isCrawler,
            $type ?? BotSignature::TYPE_HUMAN,
            min(100, $score),
            array_values(array_unique($signals)),
            $client,
            $normalized !== '' ? $normalized : null,
        );
    }

    /**
     * User-Agent 归一化（脱壳核心，用于词库匹配）。
     * 依次执行：HTML 实体解码 → 双重 URL 解码 → 剥离零宽/不可见字符 →
     * 剥离控制字符 → NFKC 全半角统一 → 同形字回写 → 去除字母间插入的分隔符 → 小写。
     */
    public static function normalize(string $ua): string
    {
        $s = self::normalizeConservative($ua);
        if ($s === '') {
            return '';
        }

        // 去除「字母之间插入的分隔符」伪装：如 g-o-h-t、g o t、G_o_h_t
        $s = (string) preg_replace('/(?<=[a-z])[\s._\-+|\\\\\/]+(?=[a-z])/', '', $s);

        // 统一空白并压缩
        $s = (string) preg_replace('/\s+/', ' ', $s);

        return trim($s);
    }

    /**
     * 保守归一化：只做「编码层」的还原（实体 / URL 编码 / 不可见字符 / 控制符 / 全半角 / 同形字），
     * 不做分隔符合并与空白压缩。
     *
     * 用途：与原始小写串比较即可判断 UA 是否「被做过编码伪装」，
     * 而真实浏览器的 UA（哪怕含 "Mac OS X"、"Windows NT"）在该层面完全不变，不会被误判。
     */
    public static function normalizeConservative(string $ua): string
    {
        if ($ua === '') {
            return '';
        }

        $s = html_entity_decode($ua, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 双重 URL 解码：应对 %2547%256f 之类的二次编码
        for ($i = 0; $i < 2; $i++) {
            $decoded = rawurldecode($s);
            if ($decoded === $s) {
                break;
            }
            $s = $decoded;
        }

        // 剥离零宽 / 方向控制 / 软连字符 / BOM 等不可见字符
        $s = (string) preg_replace('/[\x{00AD}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}\x{E0000}-\x{E007F}]/u', '', $s);

        // 剥离控制字符（含 \r \n \t），消除 CRLF 注入式伪装
        $s = (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $s);

        // 全角 / 半角统一（含 ｗｗｗ、带圈字符、数学字母等）
        if (function_exists('normalizer_normalize')) {
            $normalized = @normalizer_normalize($s, \Normalizer::FORM_KC);
            if (is_string($normalized) && $normalized !== '') {
                $s = $normalized;
            }
        }

        // 同形字回写
        $s = strtr($s, self::HOMOGLYPHS);

        return mb_strtolower(trim($s));
    }

    /**
     * 请求头指纹一致性打分。
     *
     * 核心思想：自称主流浏览器（Chrome / Firefox / Safari / Edge）的客户端，
     * 必然携带一组「浏览器指纹头」；缺失越多，越可能是改写了 UA 的脚本。
     *
     * 分值设计的重要取舍（避免误伤正常浏览器）：
     *  - 「缺头」类信号权重刻意压低，单独累加不足以越过阈值（40 分），
     *    因为确实存在只发 UA 的合法客户端（部分移动端、隐私浏览器、老旧内核）；
     *  - 只有「互相矛盾」（Client Hints 与 UA 品牌冲突）这类确定性矛盾证据
     *    才给到阈值以上，可单独立判。
     */
    private static function scoreHeaderFingerprint(string $normalizedUa, array $headers, array &$signals): int
    {
        $score = 0;

        $claimsBrowser = (bool) preg_match(
            '/\b(chrome|chromium|firefox|safari|edg\/?|edge|opr\/|opera|msie|trident)\b/',
            $normalizedUa
        );

        // 明显非浏览器 UA（脚本库 / 扫描器）不再叠加启发式分，避免无意义加噪
        if (! $claimsBrowser) {
            return 0;
        }

        // 1) 缺少 Accept-Language：绝大多数真实浏览器都会发送（权重压低，隐私浏览器可能省略）
        if (! isset($headers['accept-language']) || $headers['accept-language'] === '') {
            $score += 12;
            $signals[] = 'spoof-missing-accept-language（自称浏览器但缺少 Accept-Language）';
        }

        // 2) 缺少 Accept
        if (! isset($headers['accept']) || $headers['accept'] === '') {
            $score += 8;
            $signals[] = 'spoof-missing-accept（自称浏览器但缺少 Accept）';
        }

        // 3) 缺少 Accept-Encoding
        if (! isset($headers['accept-encoding']) || $headers['accept-encoding'] === '') {
            $score += 5;
            $signals[] = 'spoof-missing-accept-encoding（自称浏览器但缺少 Accept-Encoding）';
        }

        // 4) 缺少 Client Hints（sec-ch-ua / sec-fetch-*）——现代 Chrome / Edge 必带
        $hasClientHints = isset($headers['sec-ch-ua']) || isset($headers['sec-fetch-site']) || isset($headers['sec-fetch-mode']);
        $claimsChromium = str_contains($normalizedUa, 'chrome') || str_contains($normalizedUa, 'chromium') || str_contains($normalizedUa, 'edg');
        if ($claimsChromium && ! $hasClientHints) {
            $score += 10;
            $signals[] = 'spoof-missing-client-hints（自称 Chrome/Edge 但无 sec-ch-ua / sec-fetch-* 客户端提示）';
        }

        // 5) Client Hints 品牌与 UA 互相矛盾（确定性矛盾证据，可单独立判）
        $secChUa = mb_strtolower($headers['sec-ch-ua'] ?? '');
        if ($secChUa !== '') {
            $hintsChromium = str_contains($secChUa, 'chromium') || str_contains($secChUa, 'google');
            $hintsFirefox = str_contains($secChUa, 'firefox');
            $uaFirefox = str_contains($normalizedUa, 'firefox');
            $uaChromium = str_contains($normalizedUa, 'chrome') || str_contains($normalizedUa, 'edg') || str_contains($normalizedUa, 'chromium');
            if (($hintsFirefox && $uaChromium) || ($hintsChromium && $uaFirefox)) {
                $score += 50;
                $signals[] = 'spoof-client-hints-mismatch（Client Hints 品牌与 UA 声明矛盾）';
            }
        }

        // 6) 极简 Accept + Connection: close + 无 Cookie —— 典型脚本指纹（单独不足以立判）
        $accept = mb_strtolower($headers['accept'] ?? '');
        if ($accept === '*/*' && isset($headers['connection']) && mb_strtolower($headers['connection']) === 'close' && ! isset($headers['cookie'])) {
            $score += 25;
            $signals[] = 'script-fingerprint（Accept 通配 + Connection: close + 无 Cookie）';
        }

        return $score;
    }

    /**
     * 分类优先级仲裁：数值越小越优先（攻击 / 安全扫描最优先）。
     *
     * @var array<string,int>
     */
    private static function prioritizeType(?string $current, string $candidate): string
    {
        $priority = [
            BotSignature::TYPE_ATTACK_PROBE => 0,
            BotSignature::TYPE_SECURITY_SCANNER => 1,
            BotSignature::TYPE_HEADLESS => 2,
            BotSignature::TYPE_SEARCH_ENGINE => 3,
            BotSignature::TYPE_AI_CRAWLER => 4,
            BotSignature::TYPE_SEO => 5,
            BotSignature::TYPE_MONITOR => 6,
            BotSignature::TYPE_SOCIAL => 7,
            BotSignature::TYPE_FEED => 8,
            BotSignature::TYPE_HTTP_LIBRARY => 9,
            BotSignature::TYPE_LIBRARY_OTHER => 10,
        ];

        if ($current === null) {
            return $candidate;
        }

        return ($priority[$candidate] ?? 99) < ($priority[$current] ?? 99) ? $candidate : $current;
    }

    /**
     * 从归一化 UA 中提取命中的客户端标识（用于统计「哪种语言的脚本」）。
     *
     * 跨全部类别取【最长命中】的 token，避免 `Go-http-client/2.0` 被较短的
     * `httpclient` 抢先匹配（否则统计口径会把 Go 客户端错记成通用 Java 客户端）。
     */
    private static function matchClient(string $normalizedUa): ?string
    {
        return self::longestMatch($normalizedUa, array_merge(...array_values(self::LIBRARY)));
    }

    /**
     * 在给定 token 集合中找出「被包含的最长 token」，无命中返回 null。
     *
     * 取最长而非首个命中，是为了避免 `Go-http-client` 被较短的 `httpclient` 抢先匹配
     * （否则统计口径会把 Go 客户端错记成通用 Java 客户端）。
     *
     * @param  string[]  $tokens
     */
    private static function longestMatch(string $haystack, array $tokens): ?string
    {
        $best = null;
        $bestLength = 0;

        foreach ($tokens as $token) {
            $length = strlen($token);
            if ($token === '' || $length <= $bestLength) {
                continue;
            }
            if (str_contains($haystack, $token)) {
                $best = $token;
                $bestLength = $length;
            }
        }

        return $best;
    }

    /**
     * 分类中文标签（仅用于日志可读性，不影响判定）。
     */
    private static function typeLabel(string $type): string
    {
        return match ($type) {
            BotSignature::TYPE_SEARCH_ENGINE => '搜索引擎爬虫',
            BotSignature::TYPE_AI_CRAWLER => 'AI 爬虫/训练数据抓取',
            BotSignature::TYPE_SEO => 'SEO 分析爬虫',
            BotSignature::TYPE_MONITOR => '监控拨测',
            BotSignature::TYPE_SOCIAL => '社交分享抓取',
            BotSignature::TYPE_FEED => 'Feed 订阅器',
            BotSignature::TYPE_SECURITY_SCANNER => '安全扫描器',
            BotSignature::TYPE_HTTP_LIBRARY => '开发语言 HTTP 客户端',
            BotSignature::TYPE_HEADLESS => '无头浏览器/自动化',
            BotSignature::TYPE_LIBRARY_OTHER => '其它脚本客户端',
            BotSignature::TYPE_ATTACK_PROBE => '攻击探测',
            default => '未知机器人',
        };
    }

    /**
     * 请求头键名统一转小写（PHP 只在部分场景自动转小写，这里显式归一）。
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private static function lowercaseHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            $out[mb_strtolower($key)] = is_array($value)
                ? mb_strtolower(implode(',', array_map('strval', $value)))
                : mb_strtolower((string) $value);
        }

        return $out;
    }
}
