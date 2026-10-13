<?php

declare(strict_types=1);

/**
 * 数据库日志模块（zxf\Modules\Logging）运行时验证脚本。
 *
 * 覆盖核心重构点：
 *  - Support\Redactor：键名脱敏 / 自由文本脱敏 / 头脱敏 / Throwable→字符串（全模块唯一事实来源）
 *  - DatabaseLogHandler::buildAttributes：LogRecord → logs 表字段的归一化映射 + 脱敏
 *  - 构造参数顺序守卫：保证 Laravel logging.channels.database.handler_with 的位置映射不被破坏
 *    （handler_with 是按位置（而非命名）注入构造函数，顺序错乱会导致配置错位）
 *  - 架构契约：RedactorContract / LogWriter（含 QueuedDatabaseLogWriter）/ LogRecorded 事件 /
 *    LoggingConfig 强类型配置对象
 *
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
    $file = '/Users/aha/www/modules/src/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($file)) {
        require $file;

        return true;
    }

    return false;
}, true, true);

use Monolog\Level;
use Monolog\LogRecord;
use zxf\Modules\Logging\Config\LoggingConfig;
use zxf\Modules\Logging\Contracts\LogWriter;
use zxf\Modules\Logging\Contracts\RedactorContract;
use zxf\Modules\Logging\Events\LogRecorded;
use zxf\Modules\Logging\Handler\DatabaseLogHandler;
use zxf\Modules\Logging\Middleware\LogContextMiddleware;
use zxf\Modules\Logging\Support\Redactor;
use zxf\Modules\Logging\Writer\QueuedDatabaseLogWriter;

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
$r = new Redactor();

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
$fake = new class implements LogWriter {
    public array $captured = [];
    public function write(array $attributes): void
    {
        $this->captured = $attributes;
    }
};

$record = new LogRecord(
    datetime: new DateTimeImmutable(),
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
check($attrs['extra']['response_body'] === '{"token":"***"}', 'buildAttributes 响应体自由文本脱敏');
check($attrs['request_method'] === 'POST', 'buildAttributes 请求方法透传');
check($attrs['exception_class'] === 'RuntimeException', 'buildAttributes 异常类名');
check($attrs['exception_message'] === 'boom', 'buildAttributes 异常消息');
check(str_contains($attrs['file'] ?? '', ':'), 'buildAttributes 异常文件:行');
check(is_string($attrs['trace'] ?? null), 'buildAttributes 异常堆栈');
check(! isset($attrs['context']['console_to_log']), 'buildAttributes 剔除一次性控制标记 console_to_log');

// response_content 同样经过脱敏（与 response_body 一致，避免 JSON 响应泄露敏感字段）
$recMask = new LogRecord(
    datetime: new DateTimeImmutable(),
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
        datetime: new DateTimeImmutable(),
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
use Illuminate\Http\Request;

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
$viewResp = new class {
    public function getOriginalContent(): object { return new class implements \Illuminate\Contracts\View\View {
        public function getName(): string { return 'users::index'; }
        public function name(): string { return 'users::index'; }
        public function with($key, $value = null) { return $this; }
        public function getData(): array { return []; }
        public function render(): string { return ''; }
    }; }
};
$rcm = new ReflectionMethod(LogContextMiddleware::class, 'resolveResponseContent');
$rcm->setAccessible(true);
check(LogContextMiddleware::resolveResponseContent($viewResp, 'text/html; charset=UTF-8', '<html></html>') === 'users::index', 'resolveResponseContent 视图页面记录视图名');

$jsonResp = new class {
    public function getOriginalContent() { return ['ok' => 1]; }
};
check(LogContextMiddleware::resolveResponseContent($jsonResp, 'application/json', '{"ok":1}') === '{"ok":1}', 'resolveResponseContent JSON 记录数据');

$redirectResp = new class {
    public function getOriginalContent() { return null; }
};
check(LogContextMiddleware::resolveResponseContent($redirectResp, 'text/html; charset=UTF-8', '') === null, 'resolveResponseContent 重定向/无内容返回 null');

// ===================== 响应体采集（JsonResponse 修复验证） =====================
// 回归：Laravel JSON 接口返回 JsonResponse（非 Illuminate\Http\Response 子类），
// 旧实现因限定 instanceof Illuminate\Http\Response 导致 JSON 响应体永不采集、
// response_content 恒为 null。此处验证 JsonResponse 也能正确采集 JSON 数据。
$mw = new LogContextMiddleware(new Redactor());
$jsonHeaderBag = new class {
    public function get(string $k, $default = null) { return $k === 'content-type' ? 'application/json' : $default; }
    public function all(): array { return []; }
};
$jsonResp = new class($jsonHeaderBag) {
    public $headers;
    public function __construct($h) { $this->headers = $h; }
    public function getStatusCode(): int { return 200; }
    public function getReasonPhrase(): string { return 'OK'; }
    public function getContent(): string { return '{"ok":1}'; }
    public function getOriginalContent() { return ['ok' => 1]; }
};
$mwRef = new ReflectionMethod(LogContextMiddleware::class, 'captureResponse');
$mwRef->setAccessible(true);
$mwRef->invoke($mw, Request::create('/api/x'), $jsonResp);
$respCtx = \zxf\Modules\Logging\LogContext::getResponse();
check(($respCtx['content'] ?? null) === '{"ok":1}', 'captureResponse JSON(JsonResponse) 采集响应体并记录 JSON 数据');

// ===================== 构造参数顺序守卫 =====================
$rc = new ReflectionClass(DatabaseLogHandler::class);
$paramNames = array_map(static fn ($p) => $p->getName(), $rc->getConstructor()->getParameters());
$expectedHead = ['model', 'table', 'captureRequestBody', 'captureResponseBody', 'redactor', 'writer'];
$actualHead = array_slice($paramNames, 0, count($expectedHead));
check($actualHead === $expectedHead, 'DatabaseLogHandler 构造参数顺序（handler_with 位置映射）');

// 无参构造可用（Laravel 以 handler_with 传值，其余取默认）
try {
    new DatabaseLogHandler();
    check(true, 'DatabaseLogHandler 默认构造可用');
} catch (\Throwable $e) {
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
check(new QueuedDatabaseLogWriter() instanceof LogWriter, 'QueuedDatabaseLogWriter 实现 LogWriter');

$evt = new LogRecorded(['message' => 'x']);
check($evt->attributes === ['message' => 'x'] && $evt->model === null, 'LogRecorded 事件构建');

// ===================== 汇总 =====================
echo "\n结果: {$pass} PASS / {$fail} FAIL\n";

exit($fail === 0 ? 0 : 1);
