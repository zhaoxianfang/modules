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
$loader->setPsr4('zxf\\Modules\\', '/Users/aha/www/modules/src/');

use Monolog\Level;
use Monolog\LogRecord;
use zxf\Modules\Logging\Config\LoggingConfig;
use zxf\Modules\Logging\Contracts\LogWriter;
use zxf\Modules\Logging\Contracts\RedactorContract;
use zxf\Modules\Logging\Events\LogRecorded;
use zxf\Modules\Logging\Handler\DatabaseLogHandler;
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

check($attrs['level_name'] === 'ERROR', 'buildAttributes level_name');
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
