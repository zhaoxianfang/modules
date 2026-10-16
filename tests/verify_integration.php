<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use zxf\Modules\Contracts\RepositoryInterface;
use zxf\Modules\Logging\Contracts\LogWriter;
use zxf\Modules\Logging\Contracts\RedactorContract;
use zxf\Modules\Logging\LoggingServiceProvider;
use zxf\Modules\Logging\Support\Redactor;
use zxf\Modules\ModulesServiceProvider;
use zxf\Modules\Support\ModuleContext;

/**
 * 真实 Laravel 宿主集成验证（跑在 /Users/aha/www/wsf 的完整 Application 上）。
 *
 * 与 verify_boot_chain.php（裸容器）互补，本脚本验证：
 *  1. 包在**完整 Foundation Application** 下可正常 register/boot；
 *  2. 完整应用下 config('modules.*') / config('logging.db.*') 均可用；
 *  3. 配置被缓存（configurationIsCached()）时，logging.db 配置**不丢失**
 *     —— Laravel 的 mergeConfigFrom 在配置被缓存时整段跳过；
 *  4. 配置文件纯净度：return 之前只有 <?php 与 use；
 *  5. 包 helper 全部可用且无副作用。
 *
 * 运行：php tests/verify_integration.php
 * 退出码：0 = 全 PASS，1 = 有 FAIL，2 = FATAL。
 */
$hostRoot = '/Users/aha/www/wsf';

// 宿主可能已安装本包的旧副本，这里强制让 zxf\Modules 指向源码目录
spl_autoload_register(static function (string $class) use ($hostRoot): void {
    $prefix = 'zxf\\Modules\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $file = '/Users/aha/www/modules/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($file)) {
        require $file;
    }
}, true, true);

require $hostRoot.'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'zxf\\Modules\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $file = '/Users/aha/www/modules/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($file)) {
        require $file;
    }
}, true, true);

// 模拟 composer autoload.files（包内 helper.php 先于配置加载）
require_once '/Users/aha/www/modules/src/helper.php';

$pass = 0;
$fail = 0;

function check(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "PASS  {$name}\n";
    } else {
        $fail++;
        echo "FAIL  {$name} {$detail}\n";
    }
}

// ======================================================================
echo "--- 1. 完整 Laravel 应用下启动 ---\n";

/** @var Application $app */
$app = require $hostRoot.'/bootstrap/app.php';
Facade::setFacadeApplication($app);
$app->make(Kernel::class)->bootstrap();

check('宿主 Application 已创建', $app instanceof Application);
check('容器支持 Foundation 路径助手', ModuleContext::supportsFoundationPaths());

// 记录宿主是否已注册本包（vendor 自动发现）
$hostProvider = 'zxf\\Modules\\ModulesServiceProvider';
$alreadyRegistered = $app->getProvider($hostProvider) !== null;

$threw = false;
$err = '';
try {
    // 显式再注册一次本包（模拟宿主使用源码版）
    $app->register(new ModulesServiceProvider($app));
} catch (Throwable $e) {
    $threw = true;
    $err = $e::class.': '.$e->getMessage();
}
check('完整应用下 register() 不抛异常', ! $threw, $err);
check('完整应用下不出现 files 绑定缺失', ! str_contains($err, '[files] does not exist'), $err);

// ======================================================================
echo "\n--- 2. 配置可读性 ---\n";

$modulesCfg = $app['config']->get('modules');
check('config("modules") 为数组', is_array($modulesCfg), get_debug_type($modulesCfg));
check('modules.path 非空字符串', is_string($modulesCfg['path'] ?? null) && $modulesCfg['path'] !== '', var_export($modulesCfg['path'] ?? null, true));
check('modules.namespace 为 Modules', ($modulesCfg['namespace'] ?? null) === 'Modules', var_export($modulesCfg['namespace'] ?? null, true));
check('modules.path 指向真实目录语义（base_path/Modules）',
    str_ends_with((string) ($modulesCfg['path'] ?? ''), '/Modules'),
    var_export($modulesCfg['path'] ?? null, true));
check('modules.cache.path 非空', is_string($modulesCfg['cache']['path'] ?? null) && $modulesCfg['cache']['path'] !== '');
check('modules.assets 非空', is_string($modulesCfg['assets'] ?? null) && $modulesCfg['assets'] !== '');

// 模块仓库可用
$repo = $app->make(RepositoryInterface::class);
check('RepositoryInterface 可解析', $repo instanceof RepositoryInterface, $repo::class);
$paths = (new ReflectionProperty($repo, 'paths'));
$paths->setAccessible(true);
check('Repository 扫描路径非空', is_array($paths->getValue($repo)) && $paths->getValue($repo) !== [], json_encode($paths->getValue($repo)));

// ======================================================================
echo "\n--- 3. 日志配置（config:cache 免疫）---\n";

$db = $app['config']->get('logging.db');
check('config("logging.db") 为数组', is_array($db), get_debug_type($db));
// 注意：sensitive_keys 按设计默认为 []（应用层「追加」项），
// 真正生效的敏感键由 Redactor 内置 DEFAULT_KEYS + HEADER_KEYS 兜底，
// 因此这里断言的是「键存在且为数组」，敏感键是否真的生效在下方对
// Redactor 实例单独断言。
check('logging.db.sensitive_keys 存在且为数组（应用层追加项）',
    array_key_exists('sensitive_keys', (array) $db) && is_array($db['sensitive_keys']),
    json_encode($db['sensitive_keys'] ?? null));
check('logging.db.max_field_length 为正整数',
    is_int($db['max_field_length'] ?? null) && $db['max_field_length'] > 0,
    var_export($db['max_field_length'] ?? null, true));
check('logging.db.queue 为布尔', is_bool($db['queue'] ?? null));
check('logging.channels.database 存在且为数组',
    is_array($app['config']->get('logging.channels.database')),
    get_debug_type($app['config']->get('logging.channels.database')));
// 注意：宿主应用可自行声明 channels.database（如 driver=monolog），
// 那是**合法的用户覆盖**，本包只在应用未声明时才补齐，故不断言 driver 值。

// Redactor 契约应绑定成功（否则脱敏静默失效）
$redactor = $app->make(RedactorContract::class);
check('RedactorContract 可解析', $redactor instanceof Redactor, $redactor::class);

// 脱敏真实生效验证：内置 DEFAULT_KEYS 必须覆盖 password / Cookie 等
$probe = $redactor->maskArray([
    'password' => 'super-secret',
    'nested' => ['token' => 'abc'],
    'safe' => 'keep-me',
]);
check('内置 DEFAULT_KEYS 覆盖 password', ($probe['password'] ?? null) === Redactor::MASK, json_encode($probe['password'] ?? null, JSON_UNESCAPED_UNICODE));
check('嵌套 token 被脱敏', (($probe['nested']['token'] ?? null) === Redactor::MASK), json_encode($probe['nested'] ?? null, JSON_UNESCAPED_UNICODE));
check('非敏感字段保持原值', ($probe['safe'] ?? null) === 'keep-me');

$hdr = $redactor->maskHeaders(['Cookie' => 'sid=1', 'User-Agent' => 'UA']);
check('内置 HEADER_KEYS 覆盖 Cookie', ($hdr['Cookie'][0] ?? null) === Redactor::MASK);
check('User-Agent 不被脱敏', ($hdr['User-Agent'] ?? null) === 'UA');

$writer = $app->make(LogWriter::class);
check('LogWriter 可解析', $writer instanceof LogWriter, $writer::class);

// ======================================================================
echo "\n--- 4. 模拟 config:cache 场景（mergeConfigFrom 被跳过）---\n";

/**
 * 直接复现 Laravel 的行为：
 *   ServiceProvider::mergeConfigFrom() 在 configurationIsCached() 为真时整段跳过。
 * 这里用「全新配置仓库 + 不调用 mergeConfigFrom」模拟，
 * 验证 ensureDbConfigPresent 是否能独立把 logging.db 补齐。
 */
$fakeConfig = new Repository([
    'logging' => [
        'channels' => [
            'stack' => ['driver' => 'stack', 'channels' => ['single']],
        ],
    ],
]);
$fakeApp = new class($fakeConfig) extends Application
{
    public function __construct(private mixed $cfg) {}

    public function make($abstract, array $parameters = [])
    {
        if ($abstract === 'config') {
            return $this->cfg;
        }

        return null;
    }
};

$lp = new LoggingServiceProvider($fakeApp);
$channelConfig = require '/Users/aha/www/modules/src/../config/logging.php';

$ensure = new ReflectionMethod($lp, 'ensureDbConfigPresent');
$ensure->setAccessible(true);
$ensure->invoke($lp, $channelConfig);

$filled = $fakeConfig->get('logging.db');
check('缓存场景下 logging.db 被补齐为数组', is_array($filled), get_debug_type($filled));
check('缓存场景下 sensitive_keys 键仍被补齐（即使值为空数组）',
    array_key_exists('sensitive_keys', (array) $filled),
    json_encode($filled['sensitive_keys'] ?? null));
check('缓存场景下 max_field_length 仍被补齐',
    ($filled['max_field_length'] ?? null) === 20000,
    var_export($filled['max_field_length'] ?? null, true));
check('缓存场景下不覆盖应用自有 channels',
    isset($fakeConfig->get('logging.channels')['stack']),
    '应用配置被破坏');

// 应用已显式声明的键不被覆盖
$fakeConfig2 = new Repository([
    'logging' => ['db' => ['max_body_length' => 111]],
]);
$fakeApp2 = new class($fakeConfig2) extends Application
{
    public function __construct(private mixed $cfg) {}

    public function make($abstract, array $parameters = [])
    {
        return $abstract === 'config' ? $this->cfg : null;
    }
};
$lp2 = new LoggingServiceProvider($fakeApp2);
$ensure2 = new ReflectionMethod($lp2, 'ensureDbConfigPresent');
$ensure2->setAccessible(true);
$ensure2->invoke($lp2, $channelConfig);
check('应用显式声明的键不被包默认值覆盖',
    ($fakeConfig2->get('logging.db.max_body_length')) === 111,
    var_export($fakeConfig2->get('logging.db.max_body_length'), true));

// ======================================================================
echo "\n--- 5. 配置文件纯净度 ---\n";

foreach (['modules.php', 'logging.php'] as $cfgName) {
    $file = '/Users/aha/www/modules/config/'.$cfgName;
    $src = (string) file_get_contents($file);
    $pos = strpos($src, "\nreturn [");
    $head = $pos === false ? $src : substr($src, 0, $pos);

    $stripped = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $head);
    $codeLines = [];

    foreach (preg_split('/\R/', $stripped) ?: [] as $l) {
        $l = trim($l);
        if ($l !== '') {
            $codeLines[] = $l;
        }
    }

    $bad = array_values(array_filter(
        $codeLines,
        static fn (string $l): bool => $l !== '<?php' && ! str_starts_with($l, 'use ')
    ));

    check("config/{$cfgName}：return 之前只有 <?php 与 use", $bad === [], json_encode($bad, JSON_UNESCAPED_UNICODE));
    check("config/{$cfgName}：无 \$_ 前缀变量", ! preg_match('/\$_/', $head));
}

// ======================================================================
echo "\n--- 6. 包 helper 可用性 ---\n";

check('module_name() 可调用且返回字符串', is_string(module_name()));
check('module_config() 可调用', module_config('nonexistent.key', 'fallback') === 'fallback');
// module_path() 在无模块上下文时按契约抛 RuntimeException（既有行为），
// 这里断言「有明确异常」而不是「一定返回字符串」。
$pathThrew = false;
try {
    module_path();
} catch (RuntimeException) {
    $pathThrew = true;
}
check('module_path() 无模块上下文时抛出明确异常（契约一致）', $pathThrew);
// 有模块上下文时必须返回字符串
$allNames = array_values(array_map(static fn ($m): string => $m->getName(), app('modules')->allEnabled()));
check('宿主存在已启用模块', $allNames !== [], implode(',', $allNames));
if ($allNames !== []) {
    check('module_path(\'\', 显式模块) 返回字符串', is_string(module_path('', $allNames[0])));
}
check('module_asset() 可调用', is_string(module_asset('x.css')));
// module_view() 需要已注册视图命名空间的模块；在本宿主没有对应 hint 时
// 按契约抛 InvalidArgumentException，属既有设计（非缺陷），这里断言异常明确。
$viewOk = false;
$viewThrew = false;
try {
    $viewOk = module_view('x') instanceof View;
} catch (InvalidArgumentException) {
    $viewThrew = true;
}
check('module_view() 在无 hint 时给出明确 InvalidArgumentException', $viewOk || $viewThrew);
check('xmf_base_path 与原生等价（完整容器下）',
    xmf_base_path('Modules') === base_path('Modules'),
    xmf_base_path('Modules').' vs '.base_path('Modules'));
check('xmf_storage_path 与原生等价',
    xmf_storage_path('framework/cache/modules') === storage_path('framework/cache/modules'));
check('xmf_public_path 与原生等价', xmf_public_path('modules') === public_path('modules'));
check('xmf_config_path 与原生等价', xmf_config_path('x.php') === config_path('x.php'));
check('xmf_resource_path 与原生等价', xmf_resource_path('stubs/modules') === resource_path('stubs/modules'));

// ======================================================================
echo "\n--- 7. 无 PHP 弃用/警告输出 ---\n";

$warnings = [];
set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
    $warnings[] = $msg;

    return true;
}, E_ALL);

// 触发一批典型路径
module_name();
$app->make(RepositoryInterface::class);
$app->make(RedactorContract::class);
is_array(config('modules'));
is_array(config('logging.db'));

restore_error_handler();

$deprecations = array_values(array_filter(
    $warnings,
    static fn (string $m): bool => str_contains($m, 'Deprecated') || str_contains($m, 'deprecated')
));
$relevant = array_values(array_filter(
    $warnings,
    static fn (string $m): bool => ! str_contains($m, 'xf_make_test') && ! str_contains($m, 'xf_boot')
));

check('无 PHP 弃用警告', $deprecations === [], implode(' | ', $deprecations));
check('无其它 PHP 警告/通知', $relevant === [], implode(' | ', array_slice($relevant, 0, 5)));

// ======================================================================
echo "\n结果: {$pass} PASS / {$fail} FAIL\n";

exit($fail === 0 ? 0 : 1);
