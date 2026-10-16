<?php

declare(strict_types=1);

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Monolog\Level;
use Monolog\LogRecord;
use zxf\Modules\Logging\Config\LoggingConfig;
use zxf\Modules\Logging\Contracts\LogWriter;
use zxf\Modules\Logging\Contracts\RedactorContract;
use zxf\Modules\Logging\Events\LogRecorded;
use zxf\Modules\Logging\Handler\DatabaseLogHandler;
use zxf\Modules\Logging\LogContext;
use zxf\Modules\Logging\Middleware\LogContextMiddleware;
use zxf\Modules\Logging\Processor\RequestContextProcessor;
use zxf\Modules\Logging\Support\BotDetector;
use zxf\Modules\Logging\Support\BotSignature;
use zxf\Modules\Logging\Support\Redactor;
use zxf\Modules\Logging\Writer\LogRecordFactory;
use zxf\Modules\Logging\Writer\QueuedDatabaseLogWriter;

/**
 * 数据库日志模块（zxf\Modules\Logging）运行时验证脚本。
 * 覆盖核心重构点：
 *  - Support\Redactor：键名脱敏 / 自由文本脱敏 / 头脱敏 / Throwable→字符串（全模块唯一事实来源）
 *  - DatabaseLogHandler::buildAttributes：LogRecord → logs 表字段的归一化映射 + 脱敏
 *  - 构造参数顺序守卫：保证 Laravel logging.channels.database.handler_with 的位置映射不被破坏
 *    （handler_with 是按位置（而非命名）注入构造函数，顺序错乱会导致配置错位）
 *  - 架构契约：RedactorContract / LogWriter（含 QueuedDatabaseLogWriter）/ LogRecorded 事件 /
 *    LoggingConfig 强类型配置对象
 * 运行：php tests/verify_logging.php
 * 依赖宿主 Laravel 项目的 vendor/autoload.php（默认 /Users/aha/www/wsf），仅用于加载类，
 * 不依赖完整的 Laravel 启动（Redactor / buildAttributes / 配置对象 均不触发门面）。
 * 退出码：0=全 PASS，1=有 FAIL。
 */
$loader = require '/Users/aha/www/wsf/vendor/autoload.php';
// 优先从本仓库 src 加载 zxf\Modules\* 类（覆盖 wsf vendor 中的 zxf/modules 旧副本）。
// 直接在 Composer 之前注册一个 prepended 自动加载器：命中本仓库源码即 require 并短路，
// 使 Composer 的 ClassLoader 不再介入，确保测试始终针对当前源码。
spl_autoload_register(function (string $class): bool {
    if (strncmp($class, 'zxf\\Modules\\', 12) !== 0) {
        return false;
    }
    $rel = substr($class, 12);
    $file = '/Users/aha/www/modules/src/'.str_replace('\\', '/', $rel).'.php';
    if (is_file($file)) {
        require $file;

        return true;
    }

    return false;
}, true, true);

$pass = 0;
$fail = 0;

function check(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
    } else {
        $fail++;
        echo "  FAIL  {$label}\n";
    }
}

// ===================== Redactor =====================
$r = new Redactor;

$maskedArr = $r->maskArray([
    'password' => 'hunter2',
    'username' => 'bob',
    'nested' => ['token' => 'abc'],
]);
check($maskedArr['password'] === Redactor::MASK, 'Redactor.maskArray 键名脱敏');
check($maskedArr['username'] === 'bob', 'Redactor.maskArray 非敏感键不变');
check($maskedArr['nested']['token'] === Redactor::MASK, 'Redactor.maskArray 嵌套键名脱敏');

$txt = '{"password":"secret","foo":"bar"}';
check($r->maskText($txt) === '{"password":"***","foo":"bar"}', 'Redactor.maskText 双引号自由文本');
$single = "{'token':'xyz'}";
check($r->maskText($single) === "{'token':'***'}", 'Redactor.maskText 单引号自由文本');

$hdrs = ['authorization' => ['Bearer xyz'], 'x-custom' => ['ok']];
$mh = $r->maskHeaders($hdrs);
check($mh['authorization'] === [Redactor::MASK], 'Redactor.maskHeaders 敏感头脱敏');
check($mh['x-custom'] === ['ok'], 'Redactor.maskHeaders 非敏感头不变');

$sc = $r->sanitizeContext(['e' => new RuntimeException('boom'), 'password' => 'p']);
check(is_string($sc['e']) && str_contains($sc['e'], 'RuntimeException'), 'Redactor.sanitizeContext Throwable→字符串');
check($sc['password'] === Redactor::MASK, 'Redactor.sanitizeContext 键名脱敏');

// 用户键名与 DEFAULT_KEYS 合并而非覆盖
$r2 = new Redactor(['credit_card']);
$sc2 = $r2->maskArray(['credit_card' => '1234', 'password' => 'p']);
check($sc2['credit_card'] === Redactor::MASK, 'Redactor 用户键名生效');
check($sc2['password'] === Redactor::MASK, 'Redactor DEFAULT_KEYS 仍生效（合并而非覆盖）');

// 超长字段截断（防止数据过长导致落库异常 / 资源浪费）
$longVal = str_repeat('X', 50000);
$trunc = $r->sanitizeContext(['note' => $longVal, 'pwd' => str_repeat('Y', 50000)]);
check(mb_strlen($trunc['note'], 'UTF-8') <= 20000, 'Redactor 超长字段截断(note)');
check($trunc['pwd'] === Redactor::MASK, 'Redactor 敏感字段优先脱敏(不泄露、不截断)');

// ===================== DatabaseLogHandler::buildAttributes =====================
$fake = new class implements LogWriter
{
    public array $captured = [];

    public function write(array $attributes): void
    {
        $this->captured = $attributes;
    }
};

$record = new LogRecord(
    datetime: new DateTimeImmutable,
    channel: 'test',
    level: Level::Error,
    message: 'login failed',
    context: [
        'exception' => new RuntimeException('boom'),
        'token' => 'secret123',
        'console_to_log' => true,
    ],
    extra: [
        'request_method' => 'POST',
        'password' => 'hunter2',
        'response_body' => '{"token":"abc"}',
        'request_path' => '/login',
        'module_name' => 'users',
        'is_crawler' => true,
        'response_content' => '{"ok":1}',
    ],
);

$handler = new DatabaseLogHandler(
    model: null,
    table: null,
    captureRequestBody: true,
    captureResponseBody: true,
    redactor: new Redactor(['token', 'password']),
    writer: $fake,
);

$ref = new ReflectionMethod($handler, 'buildAttributes');
$ref->setAccessible(true);
$attrs = $ref->invoke($handler, $record);

check($attrs['level'] === 'error', 'buildAttributes level 归一化为 PSR 小写键');
check(! array_key_exists('level_name', $attrs), 'buildAttributes 已移除冗余 level_name 字段');
check($attrs['module_name'] === 'users', 'buildAttributes module_name 透传');
check($attrs['is_crawler'] === true, 'buildAttributes is_crawler 透传');
check($attrs['response_content'] === '{"ok":1}', 'buildAttributes response_content 透传');
check($attrs['channel'] === 'test', 'buildAttributes channel');
check($attrs['context']['token'] === Redactor::MASK, 'buildAttributes context 敏感键脱敏');
check($attrs['extra']['password'] === Redactor::MASK, 'buildAttributes extra 敏感键脱敏');
// 预脱敏键（request_* / response_* 等）已有独立列，不再重复塞进 extra 列：
// 既省一次递归脱敏，也让 logs 表体积减半。
check(! array_key_exists('response_body', $attrs['extra']), 'extra 列不再重复存放 response_body');
check(! array_key_exists('request_url', $attrs['extra']), 'extra 列不再重复存放 request_url');
check($attrs['response_body'] === '{"token":"***"}', 'buildAttributes 响应体自由文本脱敏（独立列）');
check($attrs['request_method'] === 'POST', 'buildAttributes 请求方法透传');
check($attrs['exception_class'] === 'RuntimeException', 'buildAttributes 异常类名');
check($attrs['exception_message'] === 'boom', 'buildAttributes 异常消息');
check(str_contains($attrs['file'] ?? '', ':'), 'buildAttributes 异常文件:行');
check(is_string($attrs['trace'] ?? null), 'buildAttributes 异常堆栈');
check(! isset($attrs['context']['console_to_log']), 'buildAttributes 剔除一次性控制标记 console_to_log');

// response_content 同样经过脱敏（与 response_body 一致，避免 JSON 响应泄露敏感字段）
$recMask = new LogRecord(
    datetime: new DateTimeImmutable,
    channel: 't',
    level: Level::Info,
    message: 'm',
    context: [],
    extra: ['response_content' => '{"token":"abc"}', 'module_name' => 'x', 'is_crawler' => false],
);
$attrsMask = $ref->invoke($handler, $recMask);
check($attrsMask['response_content'] === '{"token":"***"}', 'buildAttributes response_content 脱敏(JSON 敏感键)');

// 其它等级的 level 归一化（info / warning / critical）
foreach ([Level::Info, Level::Warning, Level::Critical] as $lv) {
    $lvlRecord = new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 't',
        level: $lv,
        message: 'm',
        context: [],
        extra: [],
    );
    $a = $ref->invoke($handler, $lvlRecord);
    $expect = strtolower($lv->name);
    check($a['level'] === $expect, 'buildAttributes level 归一化 '.strtoupper($lv->name).' -> '.$expect);
}

// ===================== 爬虫检测 =====================
$reqWithUa = static function (string $ua): Request {
    return Request::create('/', 'GET', [], [], [], $ua === '' ? [] : ['HTTP_USER_AGENT' => $ua]);
};
check(LogContextMiddleware::detectCrawler($reqWithUa('Mozilla/5.0 (compatible; Googlebot/2.1)')) === true, 'detectCrawler Googlebot');
check(LogContextMiddleware::detectCrawler($reqWithUa('curl/8.4.0')) === true, 'detectCrawler curl');
$emptyReq = Request::create('/');
$emptyReq->headers->remove('user-agent');
check(LogContextMiddleware::detectCrawler($emptyReq) === true, 'detectCrawler 空 UA');
check(LogContextMiddleware::detectCrawler($reqWithUa('Mozilla/5.0 (Macintosh) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36')) === false, 'detectCrawler 普通浏览器');

// ===================== 响应内容语义解析 =====================
$viewResp = new class
{
    public function getOriginalContent(): object
    {
        return new class implements View
        {
            public function getName(): string
            {
                return 'users::index';
            }

            public function name(): string
            {
                return 'users::index';
            }

            public function with($key, $value = null)
            {
                return $this;
            }

            public function getData(): array
            {
                return [];
            }

            public function render(): string
            {
                return '';
            }
        };
    }
};
$rcm = new ReflectionMethod(LogContextMiddleware::class, 'resolveResponseContent');
$rcm->setAccessible(true);
check(LogContextMiddleware::resolveResponseContent($viewResp, 'text/html; charset=UTF-8', '<html></html>') === 'users::index', 'resolveResponseContent 视图页面记录视图名');

$jsonResp = new class
{
    public function getOriginalContent()
    {
        return ['ok' => 1];
    }
};
check(LogContextMiddleware::resolveResponseContent($jsonResp, 'application/json', '{"ok":1}') === '{"ok":1}', 'resolveResponseContent JSON 记录数据');

$redirectResp = new class
{
    public function getOriginalContent()
    {
        return null;
    }
};
check(LogContextMiddleware::resolveResponseContent($redirectResp, 'text/html; charset=UTF-8', '') === null, 'resolveResponseContent 重定向/无内容返回 null');

// ===================== 响应体采集（JsonResponse 修复验证） =====================
// 回归：Laravel JSON 接口返回 JsonResponse（非 Illuminate\Http\Response 子类），
// 旧实现因限定 instanceof Illuminate\Http\Response 导致 JSON 响应体永不采集、
// response_content 恒为 null。此处验证 JsonResponse 也能正确采集 JSON 数据。
$mw = new LogContextMiddleware(new Redactor);
$jsonHeaderBag = new class
{
    public function get(string $k, $default = null)
    {
        return $k === 'content-type' ? 'application/json' : $default;
    }

    public function all(): array
    {
        return [];
    }
};
$jsonResp = new class($jsonHeaderBag)
{
    public $headers;

    public function __construct($h)
    {
        $this->headers = $h;
    }

    public function getStatusCode(): int
    {
        return 200;
    }

    public function getReasonPhrase(): string
    {
        return 'OK';
    }

    public function getContent(): string
    {
        return '{"ok":1}';
    }

    public function getOriginalContent()
    {
        return ['ok' => 1];
    }
};
$mwRef = new ReflectionMethod(LogContextMiddleware::class, 'captureResponse');
$mwRef->setAccessible(true);
$mwRef->invoke($mw, Request::create('/api/x'), $jsonResp);
$respCtx = LogContext::getResponse();
check(($respCtx['content'] ?? null) === '{"ok":1}', 'captureResponse JSON(JsonResponse) 采集响应体并记录 JSON 数据');

// ===================== 构造参数顺序守卫 =====================
$rc = new ReflectionClass(DatabaseLogHandler::class);
$paramNames = array_map(static fn ($p) => $p->getName(), $rc->getConstructor()->getParameters());
$expectedHead = ['model', 'table', 'captureRequestBody', 'captureResponseBody', 'redactor', 'writer'];
$actualHead = array_slice($paramNames, 0, count($expectedHead));
check($actualHead === $expectedHead, 'DatabaseLogHandler 构造参数顺序（handler_with 位置映射）');

// 无参构造可用（Laravel 以 handler_with 传值，其余取默认）
try {
    new DatabaseLogHandler;
    check(true, 'DatabaseLogHandler 默认构造可用');
} catch (Throwable $e) {
    check(false, 'DatabaseLogHandler 默认构造可用: '.$e->getMessage());
}

// ===================== 架构契约 / 配置对象 =====================
$cfg = LoggingConfig::fromConfig();
check($cfg->logCli === false, 'LoggingConfig 默认 logCli=false');
check($cfg->maxBodyLength === 20000, 'LoggingConfig 默认 maxBodyLength=20000');
check($cfg->queue === false, 'LoggingConfig 默认 queue=false');
check($cfg->captureRequestBody === true, 'LoggingConfig 默认 captureRequestBody=true');
check($cfg->captureResponseBody === true, 'LoggingConfig 默认 captureResponseBody=true');

check($r instanceof RedactorContract, 'Redactor 实现 RedactorContract');
check(new QueuedDatabaseLogWriter instanceof LogWriter, 'QueuedDatabaseLogWriter 实现 LogWriter');

$evt = new LogRecorded(['message' => 'x']);
check($evt->attributes === ['message' => 'x'] && $evt->model === null, 'LogRecorded 事件构建');

// ===================== 新增：Redactor 高级脱敏形态 =====================
check($r->maskText('user_password=abc123&remember=1') === 'user_password=***&remember=1', 'Redactor 无引号 key=value 且保留前缀');
check($r->maskText('X-Api-Key: AKIAIOSFODNN7EXAMPLE') === 'X-Api-Key: ***', 'Redactor 连字符变体键名(X-Api-Key)');
check($r->maskText('Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.sig1234') === 'Authorization: Bearer ***', 'Redactor JWT 打码');
check($r->maskText('normal log line, nothing secret here') === 'normal log line, nothing secret here', 'Redactor 普通文本不误伤');
$mh2 = $r->maskHeaders(['X-Api-Key' => 'k', 'User-Agent' => 'curl/8.0', 'X-Custom' => 'v']);
check($mh2['X-Api-Key'] === [Redactor::MASK], 'Redactor.maskHeaders X-Api-Key 脱敏');
check($mh2['X-Custom'] === 'v', 'Redactor.maskHeaders 普通头不变');
$deep = ['a' => 1];
for ($i = 0; $i < 40; $i++) {
    $deep = ['n' => $deep];
}
$deepOut = $r->sanitizeContext(['root' => $deep]);
check(is_array($deepOut), 'Redactor 超深嵌套不崩溃（深度受限）');

// ===================== 新增：BotDetector 爬虫 / 脚本 / 伪装检测 =====================
$chromeUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
$chromeHeaders = [
    'accept' => 'text/html,application/xhtml+xml,*/*;q=0.8',
    'accept-language' => 'zh-CN,zh;q=0.9',
    'accept-encoding' => 'gzip, deflate, br',
    'sec-ch-ua' => '"Chromium";v="120", "Google Chrome";v="120"',
    'sec-fetch-site' => 'none',
];

$sigHuman = BotDetector::detect($chromeUa, $chromeHeaders, '/');
check($sigHuman->isCrawler === false && $sigHuman->type === BotSignature::TYPE_HUMAN, 'BotDetector 真实 Chrome 判定为 human');
check(BotDetector::detect('', [], '/')->type === BotSignature::TYPE_NO_UA, 'BotDetector 空 UA 归类 no_ua');

$search = BotDetector::detect('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', [], '/');
check($search->isCrawler && $search->type === BotSignature::TYPE_SEARCH_ENGINE, 'BotDetector Googlebot → search_engine');

$ai = BotDetector::detect('Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2', [], '/');
check($ai->isCrawler && $ai->type === BotSignature::TYPE_AI_CRAWLER, 'BotDetector GPTBot → ai_crawler');

$scan = BotDetector::detect('sqlmap/1.7.2#stable (https://sqlmap.org)', [], '/index.php?id=1');
check($scan->isCrawler && $scan->type === BotSignature::TYPE_SECURITY_SCANNER, 'BotDetector sqlmap → security_scanner');

// 各语言 HTTP 客户端
foreach ([
    'python-requests/2.31.0' => 'python-requests',
    'axios/1.6.7' => 'axios',
    'Go-http-client/2.0' => 'go-http-client',
    'okhttp/4.9.3' => 'okhttp',
    'GuzzleHttp/7.8' => 'guzzlehttp',
    'Scrapy/2.11.0' => 'scrapy',
    'python-urllib3/1.26' => 'python-urllib',
] as $ua => $expectedClient) {
    $s = BotDetector::detect($ua, [], '/api');
    check($s->isCrawler && $s->type === BotSignature::TYPE_HTTP_LIBRARY, "BotDetector 识别 HTTP 客户端 [{$ua}]");
    check($s->client === $expectedClient, "BotDetector 客户端标识 [{$ua}] → {$expectedClient}（实际 ".((string) $s->client).'）');
}

// 命令行 / 通用脚本客户端（归入 library_other）
$wget = BotDetector::detect('Wget/1.21.3', [], '/api');
check($wget->isCrawler && $wget->type === BotSignature::TYPE_LIBRARY_OTHER && $wget->client === 'wget', 'BotDetector Wget → library_other');

$headless = BotDetector::detect('Mozilla/5.0 (X11; Linux x86_64) HeadlessChrome/120.0.0.0 Safari/537.36', [], '/');
check($headless->isCrawler && $headless->type === BotSignature::TYPE_HEADLESS, 'BotDetector HeadlessChrome → headless');

// 高级「脱壳 / 伪装」识别
$obfuscations = [
    "G\u{200B}o-http-client/1.1" => '零宽字符拆分',
    '%47%6f%2dhttp%2dclient%2f1.1' => 'URL 编码',
    '%2547%256f%252dhttp%252dclient' => '双重 URL 编码',
    '&#71;o-http-client/1.1' => 'HTML 实体编码',
    "pyth\u{043E}n-rerequests/1.0" => '西里尔同形字',
    "\u{FF50}\u{FF59}\u{FF54}\u{FF48}\u{FF4F}\u{FF4E}-requests/1.0" => '全角字符',
];
foreach ($obfuscations as $ua => $label) {
    $s = BotDetector::detect($ua, [], '/api');
    check($s->isCrawler && $s->type === BotSignature::TYPE_HTTP_LIBRARY, "BotDetector 脱壳识别：{$label}");
}

// CRLF 注入型伪装
$crlf = BotDetector::detect("Mozilla/5.0\r\nX-Injected: 1", [], '/');
check($crlf->isCrawler && $crlf->type === BotSignature::TYPE_SPOOFED_BROWSER, 'BotDetector CRLF 注入伪装 → spoofed_browser');

// 请求头指纹矛盾（自称 Chrome 却送 Firefox Client Hints）
$spoof = BotDetector::detect($chromeUa, ['accept' => 'text/html', 'accept-language' => 'zh-CN', 'accept-encoding' => 'gzip', 'sec-ch-ua' => '"Firefox";v="130"'], '/');
check($spoof->isCrawler && $spoof->type === BotSignature::TYPE_SPOOFED_BROWSER, 'BotDetector Client Hints 与 UA 矛盾 → spoofed_browser');

// 攻击探测（敏感文件路径 / 注入载荷）
check(BotDetector::detect($chromeUa, $chromeHeaders, '/.env')->type === BotSignature::TYPE_ATTACK_PROBE, 'BotDetector 敏感文件探测 /.env → attack_probe');
check(BotDetector::detect("' OR '1'='1", [], '/')->type === BotSignature::TYPE_ATTACK_PROBE, 'BotDetector SQL 注入载荷 → attack_probe');
check(BotDetector::detect('python-requests/2.31', [], '/wp-login.php')->type === BotSignature::TYPE_ATTACK_PROBE, 'BotDetector 后台登录探测 → attack_probe');

// 移动端 / 微信内置浏览器不应被误判
$safariMobile = BotDetector::detect(
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
    ['accept' => 'text/html', 'accept-language' => 'zh-CN', 'accept-encoding' => 'gzip, deflate'],
    '/'
);
check($safariMobile->isCrawler === false, 'BotDetector Safari 移动端不误判');
$wechat = BotDetector::detect(
    'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 MicroMessenger/8.0.40 NetType/WIFI Language/zh_CN',
    ['accept' => '*/*', 'accept-language' => 'zh-CN', 'accept-encoding' => 'gzip, deflate'],
    '/'
);
check($wechat->isCrawler === false, 'BotDetector 微信内置浏览器不误判');

// ===================== 请求日志幂等与跳过标记 =====================
LogContext::reset();
check(LogContext::claimRequestLog() === true, 'LogContext.claimRequestLog 首次抢占成功');
check(LogContext::claimRequestLog() === false, 'LogContext.claimRequestLog 二次抢占失败（保证每请求仅一条请求日志）');
check(LogContext::requestLogWritten() === true, 'LogContext.requestLogWritten 已标记');

LogContext::setSkipped(true);
check(LogContext::isSkipped() === true, 'LogContext.setSkipped 命中 skip_paths 生效');
LogContext::setSkipped(false);

LogContext::reset();
check(LogContext::requestLogWritten() === false, 'LogContext.reset 清除请求日志标记');
check(LogContext::isSkipped() === false, 'LogContext.reset 清除跳过标记');
check(LogContext::baseExtra() === [] && LogContext::payloadExtra() === [], 'LogContext.reset 清除预计算片段');

$botSig = BotDetector::detect('python-requests/2.31', [], '/api');
LogContext::setBot($botSig);
check(LogContext::bot() === $botSig, 'LogContext 机器人结果可缓存复用');
LogContext::reset();
check(LogContext::bot() === null, 'LogContext.reset 清除机器人缓存');

// 表存在性缓存：进程级且带 TTL，reset() 不应清空它
LogContext::setLogsTableExists(true, 60);
LogContext::reset();
check(LogContext::logsTableExists() === true, '表存在性缓存是进程级：reset() 不清空');
check(LogContext::logsTableExists(60) === true, '表存在性缓存未过期时直接命中');
check(LogContext::logsTableExists(-1) === true, 'maxAge<=0 表示不做过期判断');
LogContext::setLogsTableExists(false, 1);
sleep(2);
check(LogContext::logsTableExists(1) === null, '表存在性缓存过期后返回 null（触发重新探测）');
LogContext::flushLogsTableCache();
check(LogContext::logsTableExists() === null, 'flushLogsTableCache 强制清空');

// ===================== 预计算 extra 片段 =====================
LogContext::setRequest(
    ['method' => 'GET', 'path' => '/x'],
    ['request_method' => 'GET', 'request_path' => '/x', 'is_crawler' => false],
    ['request_input' => ['a' => 1], 'request_headers' => ['host' => ['t']]]
);
check(LogContext::baseExtra()['request_path'] === '/x', 'baseExtra 保存轻量片段');
check(LogContext::payloadExtra()['request_input'] === ['a' => 1], 'payloadExtra 保存大体积载荷');
check(LogContext::getRequest()['method'] === 'GET', 'getRequest 返回原始上下文');
LogContext::reset();

// 预脱敏键清单必须覆盖请求 / 响应两侧的全部列
foreach (['module_name', 'request_input', 'request_headers', 'request_url', 'response_body', 'response_code'] as $key) {
    check(in_array($key, LogContext::PRE_REDACTED_KEYS, true), "PRE_REDACTED_KEYS 覆盖 {$key}");
}

// ===================== RequestContextProcessor 注入策略 =====================
$processor = new RequestContextProcessor;
LogContext::setRequest(
    ['method' => 'GET', 'path' => '/orders'],
    ['request_method' => 'GET', 'request_path' => '/orders', 'request_ip' => '10.0.0.1'],
    ['request_input' => ['q' => 'shoes'], 'request_headers' => ['cookie' => ['x=1']]]
);
LogContext::setResponse(['code' => 200, 'duration_ms' => 12, 'body' => 'ok']);

$bizRecord = new LogRecord(
    datetime: new DateTimeImmutable,
    channel: 'database',
    level: Level::Info,
    message: '业务日志',
    context: [],
    extra: ['biz_key' => 'v'],
);
$bizOut = $processor->inject($bizRecord);
check($bizOut->extra['request_path'] === '/orders', '业务日志注入轻量请求上下文');
check(($bizOut->extra['biz_key'] ?? null) === 'v', '业务日志保留业务自有 extra');
check(! array_key_exists('request_input', $bizOut->extra), '业务日志不重复携带 request_input');
check(! array_key_exists('request_headers', $bizOut->extra), '业务日志不重复携带 request_headers');
check(! array_key_exists('response_body', $bizOut->extra), '业务日志不重复携带 response_body');
check(! array_key_exists('response_code', $bizOut->extra), '业务日志不重复携带响应上下文');

$reqRecord = new LogRecord(
    datetime: new DateTimeImmutable,
    channel: 'database',
    level: Level::Info,
    message: 'GET /orders → 200',
    context: [LogContext::REQUEST_LOG_FLAG => true],
    extra: [],
);
$reqOut = $processor->inject($reqRecord);
check(($reqOut->extra['request_input'] ?? null) === ['q' => 'shoes'], '请求日志携带 request_input');
check(($reqOut->extra['response_code'] ?? null) === 200, '请求日志携带响应上下文');
check(($reqOut->extra['response_body'] ?? null) === 'ok', '请求日志携带响应体');

// 伪造的审计字段必须被 base 覆盖
$fakeRecord = new LogRecord(
    datetime: new DateTimeImmutable,
    channel: 'database',
    level: Level::Info,
    message: 'm',
    context: [],
    extra: ['request_ip' => '6.6.6.6'],
);
check($processor->inject($fakeRecord)->extra['request_ip'] === '10.0.0.1', '业务伪造的 request_ip 被真实上下文覆盖');

// CLI / 队列下 __invoke 必须保持原样（避免把上一个请求的数据串进队列日志）
check($processor($bizRecord) === $bizRecord, 'CLI 下 RequestContextProcessor 不注入任何上下文');
LogContext::reset();

// ===================== 预脱敏键跳过二次脱敏 =====================
$handlerSrc = (string) file_get_contents('/Users/aha/www/modules/src/Logging/Handler/DatabaseLogHandler.php');
check(str_contains($handlerSrc, 'in_array($key, LogContext::PRE_REDACTED_KEYS, true)'),
    '落库处理器对预脱敏键不再二次 sanitize');
check(str_contains($handlerSrc, 'foreach ($preRedacted as $key => $value)'),
    '落库处理器把摘出的键补回属性数组');
check(str_contains($handlerSrc, 'if (! array_key_exists($key, $attributes))'),
    '补回时不覆盖已完成脱敏的列（避免 response_body 白脱敏）');
check(str_contains($handlerSrc, '$record->context[LogContext::REQUEST_LOG_FLAG]'),
    '落库处理器按 __request__ 标记分流');
check((bool) preg_match('/REQUEST_LOG_FLAG[\s\S]{0,200}?claimRequestLog\(\)/', $handlerSrc),
    '落库处理器对请求日志使用 claimRequestLog 幂等守卫');
check(str_contains($handlerSrc, 'if (LogContext::isSkipped())'),
    '落库处理器对 skip_paths 请求直接跳过');
check(! str_contains($handlerSrc, 'LogAggregator'), '落库处理器不再引用已删除的聚合器');

// ===================== 模块内不得残留聚合语义 =====================
$loggingFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/Users/aha/www/modules/src/Logging'));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $loggingFiles[] = (string) $f->getPathname();
    }
}
$aggLeftovers = [];
foreach ($loggingFiles as $lf) {
    $body = (string) file_get_contents($lf);
    foreach (['LogAggregator', 'aggregate_per_request', 'aggregatePerRequest',
        'max_aggregated_records', 'maxAggregatedRecords',
        'max_aggregated_messages', 'maxAggregatedMessages',
        'summaryWritten', 'claimSummary', '__summary__'] as $needle) {
        if (str_contains($body, $needle)) {
            $aggLeftovers[] = basename($lf).': '.$needle;
        }
    }
}
check($aggLeftovers === [], '日志模块内无任何聚合语义残留：'.implode('; ', array_slice($aggLeftovers, 0, 5)));
check(! is_file('/Users/aha/www/modules/src/Logging/Support/LogAggregator.php'),
    'LogAggregator 类文件已删除');
check($cfg->burstThrottle === false, '聚合相关配置已移除，burst_throttle 默认关闭');
check(! property_exists(LoggingConfig::class, 'aggregatePerRequest'), 'LoggingConfig 不再声明聚合开关');
check(! property_exists(LoggingConfig::class, 'maxAggregatedRecords'), 'LoggingConfig 不再声明聚合缓冲上限');

// ===================== 新增：LogRecordFactory 列过滤 =====================
$factoryRef = new ReflectionClass(LogRecordFactory::class);
check($factoryRef->hasMethod('make'), 'LogRecordFactory 提供统一模型构造入口');
check($factoryRef->hasMethod('columns'), 'LogRecordFactory 提供列清单探测');
LogRecordFactory::flushCache();
check(LogRecordFactory::columns('') === null, 'LogRecordFactory 空表名不做探测');

// ===================== 落库前的统一脱敏 =====================

// A1 / A2：session id 与含 query string 的完整 URL 都必须在落库前脱敏
$recUrl = new LogRecord(
    datetime: new DateTimeImmutable,
    channel: 'database',
    level: Level::Info,
    message: 'm',
    context: [],
    extra: [
        'request_url' => 'https://app.test/pay?token=SECRETTOKEN123&password=p@ss',
        'request_referer' => 'https://app.test/a?api_key=SECRETKEY456',
        'request_session_id' => 'laravel_session=SECRETSID789; XSRF-TOKEN=SECRETTOKEN123',
    ],
);
$attrsUrl = $ref->invoke($handler, $recUrl);
check(! str_contains((string) $attrsUrl['request_url'], 'SECRETTOKEN123'), 'request_url 的 query 凭证已脱敏');
check(! str_contains((string) $attrsUrl['request_url'], 'p@ss'), 'request_url 的 password 参数已脱敏');
check(! str_contains((string) $attrsUrl['request_referer'], 'SECRETKEY456'), 'request_referer 已脱敏');
check(! str_contains((string) $attrsUrl['request_session_id'], 'SECRETSID789'), 'request_session_id 已脱敏');
check(! str_contains((string) $attrsUrl['request_session_id'], 'SECRETTOKEN123'), 'request_session_id 内嵌 XSRF 已脱敏');
check($attrsUrl['request_url'] !== null && $attrsUrl['request_url'] !== '', '脱敏后 request_url 仍保留可排查的结构');

// headers 走请求头专用名单：即使生产者未经中间件，落库前也要脱敏
$recHdr = new LogRecord(
    datetime: new DateTimeImmutable,
    channel: 'database',
    level: Level::Info,
    message: 'm',
    context: [],
    extra: ['request_headers' => ['cookie' => ['laravel_session=SECRETHDR000'], 'x-api-key' => ['RAWKEY000']]],
);
$attrsHdr = $ref->invoke($handler, $recHdr);
check(
    ! str_contains(json_encode($attrsHdr['request_headers'] ?? [], JSON_UNESCAPED_UNICODE), 'SECRETHDR000'),
    '落库前 request_headers 的 Cookie 二次脱敏'
);
check(
    ! str_contains(json_encode($attrsHdr['request_headers'] ?? [], JSON_UNESCAPED_UNICODE), 'RAWKEY000'),
    '落库前 request_headers 的 X-Api-Key 二次脱敏'
);

// 空串归一为 null，避免写入空字符串列
$recEmpty = new LogRecord(
    datetime: new DateTimeImmutable,
    channel: 'database',
    level: Level::Info,
    message: 'm',
    context: [],
    extra: ['request_url' => '', 'request_session_id' => ''],
);
$attrsEmpty = $ref->invoke($handler, $recEmpty);
check($attrsEmpty['request_url'] === null, '空 request_url 归一为 null');
check($attrsEmpty['request_session_id'] === null, '空 request_session_id 归一为 null');

// A5：默认脱敏名单必须覆盖常见 PII 键
$pii = new Redactor;
$piiMasked = $pii->maskArray([
    'phone' => '13800000000',
    'email' => 'a@b.test',
    'id_no' => '310101199001011234',
    'real_name' => '张三',
    'bank_card' => '6222021234567890',
]);
$piiLeaked = array_filter($piiMasked, static fn ($v, $k) => $k !== 'x' && $v !== Redactor::MASK, ARRAY_FILTER_USE_BOTH);
check($piiLeaked === [], 'Redactor 默认名单覆盖 phone/email/id_no/real_name/bank_card');

// ===================== 时长列的整数契约 =====================

// B4：duration_ms 落的是整型列（unsignedInteger），浮点字符串会让 PostgreSQL 拒绝写入
$mwSrc = (string) file_get_contents('/Users/aha/www/modules/src/Logging/Middleware/LogContextMiddleware.php');
check(
    str_contains($mwSrc, "'duration_ms' => (int) round(\$duration)"),
    'duration_ms 转为 int（PostgreSQL 整型列不接受 "12.34"）'
);
check(! preg_match("/'duration_ms'\s*=>\s*round\(/", $mwSrc), 'duration_ms 不再直接落 round() 的浮点值');

// ===================== 去重与幂等守卫的相对顺序 =====================

// 请求日志走 claimRequestLog()（每请求一条），业务日志走去重集合（逐条落库）；
// 两条路径必须互不干扰：请求日志不得被业务去重集合误伤，业务日志不得被请求日志守卫吞掉。
$dedupePos = strpos($handlerSrc, '$key = $this->dedupeKey($record);');
$claimPos = strpos($handlerSrc, 'LogContext::claimRequestLog()');
check($claimPos !== false && $dedupePos !== false && $claimPos < $dedupePos, '请求日志幂等守卫先于业务去重判断');
check(
    (bool) preg_match('/else \{\s*\/\/[^\n]*\n\s*\$key = \$this->dedupeKey/', $handlerSrc),
    '业务日志走去重分支（与请求日志守卫互斥）'
);

// ===================== 汇总 =====================
echo "\n结果: {$pass} PASS / {$fail} FAIL\n";

exit($fail === 0 ? 0 : 1);
