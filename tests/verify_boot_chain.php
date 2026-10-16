<?php

declare(strict_types=1);

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Response;
use zxf\Modules\BuilderQuery\Concerns\SqlSecurity as S;
use zxf\Modules\BuilderQuery\WindowMacros\FastCountMacro;
use zxf\Modules\BuilderQuery\WindowMacros\IndexHintMacro;
use zxf\Modules\BuilderQuery\WindowMacros\RandomMacro;
use zxf\Modules\Commands\Concerns\EscapesConsoleOutput;
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Logging\LoggingServiceProvider;
use zxf\Modules\Logging\Middleware\LogContextMiddleware;
use zxf\Modules\Logging\Models\Log;
use zxf\Modules\Logging\Support\Redactor;
use zxf\Modules\ModulesServiceProvider;
use zxf\Modules\Repository;
use zxf\Modules\Support\ModuleContext;
use zxf\Modules\Support\ModuleLoader;
use zxf\Modules\Support\StubGenerator;

/**
 * 启动链路 / 核心加载 / 索引提示 的运行时验证脚本。
 *
 * 验证重点：
 *  A. ModulesServiceProvider::$app['files'] 在最小容器下的致命解析
 *  B. Repository 构造函数在 strict_types 下的类型收敛 + 路径元素过滤
 *  C. ModuleLoader 的幂等守卫（重复 boot 不再二次 require 路由）
 *  D. ModuleContext 请求作用域缓存与全量缓存的区别
 *  E. SqlSecurity 别名严格校验（一次性覆盖全包约 50 处别名点）
 *  F. IndexHintMacro 的 $kind/$for 枚举白名单（SQL 注入面）
 *  G. ChunkedListMacro 的 $boolean（orWhereChunkedIn 曾退化为 AND）
 *  H. Redactor 的 HEADER_KEYS 死常量（Cookie 明文入库）
 *  I. LogRecordFactory 列清单缓存 TTL（常驻 worker 新列被静默丢弃）
 *
 * 运行：php tests/verify_boot_chain.php（依赖宿主 vendor/autoload.php，默认 /Users/aha/www/wsf）
 * 退出码：0 = 全 PASS，1 = 有 FAIL，2 = FATAL。
 */

// ---------- 优先加载本包源码 ----------
$registerPackageAutoloader = static function (): void {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'zxf\\Modules\\';
        if (! str_starts_with($class, $prefix)) {
            return;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = '/Users/aha/www/modules/src/'.$relative.'.php';
        if (is_file($file)) {
            require $file;
        }
    }, true, true);
};
$registerPackageAutoloader();
require '/Users/aha/www/wsf/vendor/autoload.php';
$registerPackageAutoloader();

// 模拟 composer autoload.files：包内 helper.php 由 autoload.files 先行加载，
// config/modules.php 里的 xmf_* 安全助手正是从这里来的。
require_once '/Users/aha/www/modules/src/helper.php';

// ---------- 断言助手 ----------
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

function callProtected(object $obj, string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);

    return $ref->invokeArgs($obj, $args);
}

function readProp(object $obj, string $prop): mixed
{
    $ref = new ReflectionProperty($obj, $prop);
    $ref->setAccessible(true);

    return $ref->getValue($obj);
}

// ======================================================================
echo "--- A. 最小容器下不再因 \$app['files'] 而崩 ---\n";

// 关键：先装容器，再看是否会因为缺少 'files' 绑定而抛
//     Target class [files] does not exist.
$container = new Container;
Container::setInstance($container);
Facade::setFacadeApplication($container);

// 故意**不**绑定 files / files 门面可用的文件系统服务
$container->instance('config', new ConfigRepository([
    'modules' => [
        'path' => null,          // 故意为 null：验证不会因 null 路径而 TypeError
        'namespace' => null,     // 故意为 null：验证 namespace 被收敛为 'Modules'
        'scan_paths' => [null, '', 123],  // 混入非法元素
        'sort_by_priority' => '0',
    ],
]));

$provider = new ModulesServiceProvider($container);

$threw = false;
$err = '';
try {
    $provider->register();
} catch (Throwable $e) {
    $threw = true;
    $err = $e::class.': '.$e->getMessage();
}
check('register() 在缺少 files 绑定时不再抛 Target class [files] does not exist', ! $threw, $err);
check('异常信息里不含 files 绑定缺失', ! str_contains($err, '[files] does not exist'), $err);

// boot() 是问题现场（每次请求都会走），同样验证一次
$threw = false;
$err = '';
try {
    $provider->boot();
} catch (Throwable $e) {
    $threw = true;
    $err = $e::class.': '.$e->getMessage();
}
check('boot() 在缺少 files 绑定时不抛', ! $threw, $err);

// resolveFilesystem 的三级回退
$rm = new ReflectionMethod($provider, 'resolveFilesystem');
$rm->setAccessible(true);
$fs = $rm->invoke($provider, $container);
check('resolveFilesystem 在无绑定时返回 Filesystem 实例', $fs instanceof Filesystem);

// 已绑定 'files' 时应优先复用容器实例
$bound = new Filesystem;
$container->instance('files', $bound);
$fs2 = $rm->invoke($provider, $container);
check('resolveFilesystem 优先复用容器中已绑定的 files', $fs2 === $bound);

// ======================================================================
echo "\n--- B. Repository 类型收敛与路径元素过滤 ---\n";

$container->instance('config', new ConfigRepository([
    'modules' => [
        'path' => null,
        'namespace' => null,
        'sort_by_priority' => '0',
        'cache' => ['path' => null, 'ttl' => null],
    ],
]));

$threw = false;
$err = '';
$repo = null;
try {
    // 全部传 null / 混合类型：strict_types 下旧实现会 TypeError
    $repo = new Repository(null, [null, '', '  ', 123, '/tmp/xf_boot/Modules/Demo'], null);
} catch (Throwable $e) {
    $threw = true;
    $err = $e::class.': '.$e->getMessage();
}
check('Repository 在全 null 配置下不抛 TypeError', ! $threw, $err);

if ($repo instanceof Repository) {
    $paths = readProp($repo, 'paths');
    check('路径中的 null/空串/数字被过滤', $paths === ['/tmp/xf_boot/Modules/Demo'], json_encode($paths));

    $ns = readProp($repo, 'namespace');
    check('namespace 为 null 时收敛为 Modules', $ns === 'Modules', var_export($ns, true));

    $sortBy = readProp($repo, 'sortByPriority');
    check("sort_by_priority='0' 被收敛为 false", $sortBy === false, var_export($sortBy, true));

    $files = readProp($repo, 'files');
    check('未注入 Filesystem 时自建实例', $files instanceof Filesystem);
}

// paths 全部非法时应兜底而不是空数组
$repo2 = new Repository(null, [null, '', 123], 'Modules');
$paths2 = readProp($repo2, 'paths');
check('路径全非法时兜底为默认 Modules 目录（非空）', ! empty($paths2), json_encode($paths2));

// ======================================================================
echo "\n--- C. ModuleLoader 幂等守卫 ---\n";

// 用一个可计数的假模块验证「同一次 boot 只加载一遍」
final class CountingModule implements ModuleInterface
{
    public int $discoverCalls = 0;

    public function getName(): string
    {
        return 'Demo';
    }

    public function getLowerName(): string
    {
        return 'demo';
    }

    public function getStudlyName(): string
    {
        return 'Demo';
    }

    public function getSnakeName(): string
    {
        return 'demo';
    }

    public function getSlugName(): string
    {
        return 'demo';
    }

    public function getCamelName(): string
    {
        return 'demo';
    }

    public function getLowerCamelName(): string
    {
        return 'demo';
    }

    public function getPath(?string $path = null): string
    {
        return '/tmp/xf_boot/Modules/Demo'.($path ? '/'.$path : '');
    }

    public function getNamespace(): string
    {
        return 'Modules';
    }

    public function getClassNamespace(): string
    {
        return 'Modules\\Demo';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function getDescription(): string
    {
        return 'test';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getAuthor(): string
    {
        return 'test';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getConfigPath(): string
    {
        return $this->getPath('Config');
    }

    public function getRoutesPath(): string
    {
        return $this->getPath('Routes');
    }

    public function getProvidersPath(): string
    {
        return $this->getPath('Providers');
    }

    public function getCommandsPath(): string
    {
        return $this->getPath('Console/Commands');
    }

    public function getViewsPath(): string
    {
        return $this->getPath('Resources/views');
    }

    public function getMigrationsPath(): string
    {
        return $this->getPath('Database/Migrations');
    }

    public function getControllersPath(): string
    {
        return $this->getPath('Http/Controllers');
    }

    public function getSeedersPath(): string
    {
        return $this->getPath('Database/Seeders');
    }

    public function getLangPath(): string
    {
        return $this->getPath('Resources/lang');
    }

    public function hasRoute(string $route): bool
    {
        return false;
    }

    public function getRouteFiles(): array
    {
        return [];
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function getModuleConfig(): array
    {
        return [];
    }

    public function getServiceProviderClass(): ?string
    {
        return null;
    }

    public function getLaravelProviders(): array
    {
        return [];
    }

    public function getLaravelAliases(): array
    {
        return [];
    }

    public function initialize(): void {}

    public function clearCache(): void {}
}

// 记录 discoverAll 的调用次数：ModuleLoader 内部会 new ModuleAutoDiscovery($module)，
// 直接验证 loadModule() 的幂等守卫（用反射预置 loadedSet）。
$loader = new ModuleLoader(new Repository(new Filesystem, ['/tmp/xf_boot/Modules'], 'Modules'));
$mod = new CountingModule;

$refSet = new ReflectionProperty($loader, 'loadedSet');
$refSet->setAccessible(true);
$check1 = $refSet->getValue($loader);
check('ModuleLoader 存在 loadedSet 幂等守卫属性', is_array($check1));

// 模块根目录不存在 → discoverAll 会自行短路，不会真去读磁盘
$loader->loadModule($mod);
$loader->loadModule($mod);
$loader->loadModule($mod);
check('重复 loadModule() 不抛异常（幂等）', true);

// reload() 必须清空守卫，否则变成空操作
$refLoaded = new ReflectionProperty($loader, 'loadedModules');
$refLoaded->setAccessible(true);
$refLoaded->setValue($loader, ['Demo']);
callProtected($loader, 'reload');
$setAfter = $refSet->getValue($loader);
check('reload() 会重置 loadedModules', readProp($loader, 'loadedModules') === [], json_encode(readProp($loader, 'loadedModules')));
check('reload() 后守卫集合不残留旧模块名', ! isset($setAfter['Demo']));

// ======================================================================
echo "\n--- D. ModuleContext 请求作用域缓存 ---\n";

check('ModuleContext::flushRequestScopedCache 存在',
    method_exists(ModuleContext::class, 'flushRequestScopedCache'));

ModuleContext::setConfig('demo.foo', 'v1');
check('setConfig 后 getConfig 生效', ModuleContext::getConfig('demo.foo') === 'v1');

// flushRequestScopedCache 只清请求作用域，不动 config 仓库
ModuleContext::flushRequestScopedCache();
check('flushRequestScopedCache 后 config 仓库仍可读', ModuleContext::getConfig('demo.foo') === 'v1');

// ModulesServiceProvider 应把清理钩子挂到 terminating
$src = (string) file_get_contents('/Users/aha/www/modules/src/ModulesServiceProvider.php');
check('provider 已注册 terminating 清理钩子', str_contains($src, 'registerTerminatingCleanup'));
check('terminating 钩子调用 flushRequestScopedCache', str_contains($src, 'ModuleContext::flushRequestScopedCache()'));

// 旧的 repository 定向清理不应被误用为请求级清理
check('clearCache() 仍清空 repository（保留原语义）',
    str_contains((string) file_get_contents('/Users/aha/www/modules/src/Support/ModuleContext.php'), 'self::$repository = null;'));

/**
 * 去掉注释与文档块，只留可执行代码
 * （源码里大量注释引用了旧的错误写法，直接 str_contains 会误判）
 */
function codeOnly(string $php): string
{
    $php = preg_replace('#/\*.*?\*/#s', '', $php);
    $php = preg_replace('#//[^\n]*#', '', $php);

    return (string) $php;
}

// 路径默认值惰性化：不得再出现「默认参数里直接调 base_path()」
$ctxSrc = codeOnly((string) file_get_contents('/Users/aha/www/modules/src/Support/ModuleContext.php'));
check('ModuleContext 不再把 base_path() 放在默认参数里',
    ! str_contains($ctxSrc, "getConfig('modules.path', base_path("),
    '仍是急切求值');
check('ModuleContext 改用能力感知 basePath()',
    str_contains($ctxSrc, 'self::basePath('));

// ======================================================================
echo "\n--- E. SqlSecurity 别名严格校验 ---\n";

check('assertValidAlias 存在', method_exists(S::class, 'assertValidAlias'));
check('合法别名通过', S::assertValidAlias('total_cnt') === 'total_cnt');
check('已 wrap 的别名通过', S::assertValidAlias('`total_cnt`') === '`total_cnt`');

$threw = false;
try {
    S::assertValidAlias('t.col');
} catch (InvalidArgumentException) {
    $threw = true;
}
check('带点别名被拒绝', $threw);

// 类型别名为 alias 时自动走严格规则（覆盖全包约 50 处调用点）
$threw = false;
try {
    S::assertValidIdentifier('t.col', 'alias');
} catch (InvalidArgumentException) {
    $threw = true;
}
check("assertValidIdentifier(\$x,'alias') 走严格规则", $threw);

check('非 alias 类型的限定列名仍放行（tbl.col）',
    S::assertValidIdentifier('users.id', 'column') === 'users.id');

$threw = false;
try {
    S::assertValidAlias('1bad');
} catch (InvalidArgumentException) {
    $threw = true;
}
check('以数字开头的别名被拒绝', $threw);

$threw = false;
try {
    S::assertValidAlias('bad name');
} catch (InvalidArgumentException) {
    $threw = true;
}
check('含空格的别名被拒绝', $threw);

// ======================================================================
echo "\n--- F. IndexHintMacro 枚举白名单（SQL 注入面）---\n";

$src = file_get_contents('/Users/aha/www/modules/src/BuilderQuery/WindowMacros/IndexHintMacro.php');
check('IndexHintMacro 含 assertHintKeyword 校验', str_contains((string) $src, 'assertHintKeyword'));
check('kind 白名单为 use/force/ignore', str_contains((string) $src, "['use', 'force', 'ignore']"));
check('for 白名单为 join/order/group', str_contains((string) $src, "['', 'join', 'order', 'group']"));
check('宏入口已调用 kind 校验', str_contains((string) $src, 'assertHintKeyword($kind'));
check('宏入口已调用 for 校验', str_contains((string) $src, 'assertHintKeyword($for'));

// 直接用反射验证白名单函数
$assertKeyword = new ReflectionMethod(IndexHintMacro::class, 'assertHintKeyword');
$assertKeyword->setAccessible(true);
check('合法 kind 通过',
    $assertKeyword->invoke(null, 'USE', ['use', 'force', 'ignore'], 'kind') === 'use');

$threw = false;
try {
    $assertKeyword->invoke(null, 'ORDER BY 1) -- ', ['use', 'force', 'ignore'], 'kind');
} catch (InvalidArgumentException) {
    $threw = true;
}
check('注入型 kind 被拒绝', $threw);

$threw = false;
try {
    $assertKeyword->invoke(null, 'ORDER BY 1) -- ', ['', 'join', 'order', 'group'], 'for');
} catch (InvalidArgumentException) {
    $threw = true;
}
check('注入型 for 被拒绝', $threw);

$threw = false;
try {
    $assertKeyword->invoke(null, 'bogus', ['use', 'force', 'ignore'], 'kind');
} catch (InvalidArgumentException) {
    $threw = true;
}
check('未知 kind 被拒绝', $threw);

// ======================================================================
echo "\n--- G. ChunkedListMacro 的 \$boolean ---\n";

$chunkSrc = file_get_contents('/Users/aha/www/modules/src/BuilderQuery/WindowMacros/ChunkedListMacro.php');
check('ChunkedListMacro 单块分支带 $boolean', str_contains((string) $chunkSrc, 'orWhereIn($column, $chunks[0])'));
check('ChunkedListMacro 校验 $boolean 取值',
    str_contains((string) $chunkSrc, "in_array(\$boolean, ['and', 'or'], true)"));

// ======================================================================
echo "\n--- H. Redactor 请求头脱敏（Cookie 泄漏）---\n";

$container2 = new Container;
Container::setInstance($container2);
Facade::setFacadeApplication($container2);

$redactor = new Redactor;

$masked = $redactor->maskHeaders([
    'Cookie' => 'laravel_session=abc123; XSRF-TOKEN=def456',
    'Set-Cookie' => 'session=xyz',
    'X-Csrf-Token' => 'tok',
    'Authorization' => 'Bearer secret.jwt.value',
    'User-Agent' => 'Mozilla/5.0',
    'Accept' => 'application/json',
]);

check('Cookie 被脱敏', ($masked['Cookie'][0] ?? '') === Redactor::MASK, json_encode($masked['Cookie'] ?? null));
check('Set-Cookie 被脱敏', ($masked['Set-Cookie'][0] ?? '') === Redactor::MASK);
check('X-Csrf-Token 被脱敏', ($masked['X-Csrf-Token'][0] ?? '') === Redactor::MASK);
check('Authorization 被脱敏', ($masked['Authorization'][0] ?? '') === Redactor::MASK);
check('User-Agent 不被脱敏', $masked['User-Agent'] === 'Mozilla/5.0', json_encode($masked['User-Agent'] ?? null));
check('Accept 不被脱敏', $masked['Accept'] === 'application/json');

// 关键回归：HEADER_KEYS 不再是死常量
$redSrc = file_get_contents('/Users/aha/www/modules/src/Logging/Support/Redactor.php');
check('HEADER_KEYS 已接入判定（非死常量）',
    str_contains((string) $redSrc, 'self::HEADER_KEYS'),
    'HEADER_KEYS 仍未被引用');

// 落库前的纵深防御
$handlerSrc = file_get_contents('/Users/aha/www/modules/src/Logging/Handler/DatabaseLogHandler.php');
check('DatabaseLogHandler 落库前再脱敏 request_headers',
    str_contains((string) $handlerSrc, "maskHeaders((array) (\$extra['request_headers']"));
check('DatabaseLogHandler 落库前再脱敏 response_headers',
    str_contains((string) $handlerSrc, "maskHeaders((array) (\$extra['response_headers']"));
check('response_memory_mb 键名与生产者一致',
    str_contains((string) $handlerSrc, "\$extra['response_memory_mb'] ?? null"),
    '键名仍不一致');

// ======================================================================
echo "\n--- I. LogRecordFactory 列清单缓存 TTL ---\n";

$factorySrc = file_get_contents('/Users/aha/www/modules/src/Logging/Writer/LogRecordFactory.php');
check('LogRecordFactory 有 TTL 判定', str_contains((string) $factorySrc, 'expires_at'));
check('LogRecordFactory 有 cacheTtl() 配置读取',
    str_contains((string) $factorySrc, "config('logging.db.column_cache_ttl'"));

// ======================================================================
echo "\n--- J. 请求日志幂等守卫（采集失败时兜底必须可达）---\n";

$mwSrc = codeOnly((string) file_get_contents('/Users/aha/www/modules/src/Logging/Middleware/LogContextMiddleware.php'));
$finishPos = strpos($mwSrc, 'protected function finish(');
$finishBody = $finishPos === false ? '' : substr($mwSrc, $finishPos, 1500);

check('finish() 先判断跳过名单再进采集', str_contains($finishBody, 'LogContext::isSkipped()'));
check('finish() 在采集前检查幂等标记', str_contains($finishBody, 'LogContext::requestLogWritten()'));
check('finish() 在跳过分支占用幂等标记（阻止兜底凭空造记录）',
    (bool) preg_match(
        '/isSkipped\(\)[\s\S]{0,200}?claimRequestLog\(\)/',
        $finishBody
    ));
// 幂等标记的占用必须交给落库处理器（write() 内 claimRequestLog），
// 中间件若自己先置位，一旦 captureResponse() 抛异常，兜底监听就会因「已写」直接返回，
// 该请求的日志彻底消失且无任何痕迹。
check('finish() 不自行占用幂等标记（采集失败可由兜底补写）',
    ! preg_match('/captureResponse\([\s\S]*?claimRequestLog\(\)/', $finishBody));

// ======================================================================
echo "\n--- K. RouteMakeCommand 路由 stub 双前缀回归 ---\n";

$routeSrc = file_get_contents('/Users/aha/www/modules/src/Commands/RouteMakeCommand.php');
check('路由 stub 不再出现 route/route 双前缀',
    ! str_contains((string) $routeSrc, "getStubPath('route/'.\$stubFile)"),
    '仍是双前缀');
check('路由 stub 直接使用 route/{type}.stub',
    str_contains((string) $routeSrc, 'getStubPath($stubFile)'));
check('--type 已收紧为不含斜杠的点号白名单',
    str_contains((string) $routeSrc, "preg_match('/^[a-zA-Z0-9_-]+$/', \$type)"));

// 包内确实存在 web/api/admin 三种路由 stub
$routeStubDir = '/Users/aha/www/modules/src/Commands/stubs/route';
check('包内存在 web.stub', is_file($routeStubDir.'/web.stub'));
check('包内存在 api.stub', is_file($routeStubDir.'/api.stub'));
check('包内存在 admin.stub', is_file($routeStubDir.'/admin.stub'));

// ======================================================================
echo "\n--- L. 属性路由 / 路由异常隔离 ---\n";

$discSrc = codeOnly((string) file_get_contents('/Users/aha/www/modules/src/Support/ModuleAutoDiscovery.php'));
check('已移除不存在的 Router::attribute() 调用',
    ! str_contains($discSrc, '->attribute($prefix)'),
    '仍在调用不存在的 API');
check('属性路由改用标准 Route::group',
    str_contains($discSrc, "group(['prefix' => \$prefix]"));
check('registerRoutes 已无整段循环共用 try',
    ! preg_match('/try \{\s*foreach \(\$this->discovered\[.routes.\]/', $discSrc),
    '仍存在整段循环共用 try');
check('路由文件名已做 basename 过滤',
    str_contains($discSrc, 'basename((string) $filename)'));
check('registerConfigs 已加 try/catch',
    preg_match('/protected function registerConfigs\(\): void\s*\{\s*foreach.*?try \{/s', $discSrc) === 1);

// ======================================================================
echo "\n--- M. module:publish 退出码语义 ---\n";

$pubSrc = file_get_contents('/Users/aha/www/modules/src/Commands/ModulePublishCommand.php');
check('publishUserGuide 返回 ?bool（三态）',
    str_contains((string) $pubSrc, 'protected function publishUserGuide(bool $force): ?bool'));
check('publishConfigFile 返回 ?bool（三态）',
    str_contains((string) $pubSrc, 'protected function publishConfigFile(bool $force): ?bool'));
check('已存在时返回 null（跳过≠失败）',
    preg_match('/file_exists\(\$guidePath\) && ! \$force\).*?return null;/s', (string) $pubSrc) === 1);

$dbgSrc = file_get_contents('/Users/aha/www/modules/src/Commands/ModuleDebugCommandsCommand.php');
check('debugModule 返回 bool', str_contains((string) $dbgSrc, 'protected function debugModule(string $moduleName): bool'));
check('模块不存在时返回 FAILURE',
    str_contains((string) $dbgSrc, 'return false;'));

// ======================================================================
echo "\n--- N. 命令与路径安全回归 ---\n";

$mkSrc = file_get_contents('/Users/aha/www/modules/src/Commands/ModuleMakeCommand.php');
check('module:make 校验模块名（防 ../ 越界删除）',
    str_contains((string) $mkSrc, "preg_match('/^[A-Za-z][A-Za-z0-9]*$/', \$name)"));
check('module:make 有「必须位于 Modules 根内」兜底校验',
    str_contains((string) $mkSrc, '超出了模块根目录'));
check('module:make 检查 File::put 返回值（不再假成功）',
    str_contains((string) $mkSrc, 'File::put($fullPath, $content) === false'));

$langSrc = codeOnly((string) file_get_contents('/Users/aha/www/modules/src/Commands/ModuleCheckLangCommand.php'));
check('module:check-lang 拒绝 --path 中的 ..',
    str_contains($langSrc, "if (\$segment === '..')"));
check('module:check-lang 有路径边界校验 isPathInside',
    str_contains($langSrc, 'isPathInside'));
check('module:check-lang 不再用 getRequire 执行语言文件',
    ! str_contains($langSrc, 'getRequire'),
    '仍在用 getRequire');

// helper 两级缓存一致性
$helperSrc = file_get_contents('/Users/aha/www/modules/src/helper.php');
check('module_set_config 已同步清理 Module 实例缓存',
    str_contains((string) $helperSrc, 'clearCache()'));

// ======================================================================
echo "\n--- O. 配置文件纯净度（return 之前不得有可执行语句）---\n";

/** 取出配置文件 return [...] 之前的内容 */
function beforeReturn(string $file): string
{
    $src = (string) file_get_contents($file);
    $pos = strpos($src, "\nreturn [");

    return $pos === false ? $src : substr($src, 0, $pos);
}

foreach (['modules.php', 'logging.php'] as $cfgName) {
    $cfg = '/Users/aha/www/modules/config/'.$cfgName;
    $head = beforeReturn($cfg);

    // 去掉块注释、行注释后，剩下的必须只有 <?php 与 use 语句
    $stripped = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $head);
    $lines = [];

    foreach (preg_split('/\R/', $stripped) ?: [] as $line) {
        $line = trim($line);

        if ($line !== '') {
            $lines[] = $line;
        }
    }

    $unexpected = array_values(array_filter(
        $lines,
        static fn (string $l): bool => $l !== '<?php' && ! str_starts_with($l, 'use ')
    ));

    check("config/{$cfgName}：return 之前只有 <?php 与 use 语句",
        $unexpected === [],
        json_encode($unexpected, JSON_UNESCAPED_UNICODE));

    // 绝不允许出现 $_ 前缀变量
    check("config/{$cfgName}：无 \$_ 前缀变量", ! preg_match('/\$_/', $head), '存在 $_ 变量');

    // 绝不允许在 return 之前出现赋值语句
    check("config/{$cfgName}：return 之前无可执行赋值", ! preg_match('/^\s*\$/m', $stripped), '存在变量赋值');

    // 必须能被独立加载并返回数组
    check("config/{$cfgName} 可正常加载且返回数组", is_array(@include $cfg));
}

// src/ 与 config/ 全目录不得再有 $_ 前缀变量
$underscoreVars = [];

foreach (['src', 'config'] as $sub) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator('/Users/aha/www/modules/'.$sub, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $f) {
        if (! $f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }

        $no = 0;

        foreach (preg_split('/\R/', (string) file_get_contents($f->getPathname())) ?: [] as $line) {
            $code = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $line);

            if (preg_match('/\$_[a-zA-Z]/', $code)) {
                $underscoreVars[] = $f->getPathname().':'.($no + 1);
            }

            $no++;
        }
    }
}

check('src/ 与 config/ 全目录无 $_ 前缀变量', $underscoreVars === [], implode(', ', array_slice($underscoreVars, 0, 5)));

// 安全路径助手必须由包内 helper.php 提供（composer autoload.files）
check('helper.php 提供 xmf_base_path', function_exists('xmf_base_path'));
check('helper.php 提供 xmf_storage_path', function_exists('xmf_storage_path'));
check('helper.php 提供 xmf_config_path', function_exists('xmf_config_path'));
check('helper.php 提供 xmf_resource_path', function_exists('xmf_resource_path'));
check('helper.php 提供 xmf_public_path', function_exists('xmf_public_path'));
check('helper.php 提供 xmf_supports_foundation_paths', function_exists('xmf_supports_foundation_paths'));
check('xmf_base_path 在最小容器下不抛异常', is_string(xmf_base_path('Modules')));
check('xmf_public_path 在最小容器下不抛异常', is_string(xmf_public_path('modules')));

// ======================================================================
echo "\n--- P. config:cache 免疫（mergeConfigFrom 被跳过时不丢配置）---\n";

$logProviderSrc = codeOnly((string) file_get_contents('/Users/aha/www/modules/src/Logging/LoggingServiceProvider.php'));
check('LoggingServiceProvider 有 ensureDbConfigPresent', str_contains($logProviderSrc, 'ensureDbConfigPresent'));
check('LoggingServiceProvider 有递归补齐 fillMissingKeys', str_contains($logProviderSrc, 'fillMissingKeys'));
check('补齐逻辑不覆盖应用已声明的键',
    str_contains($logProviderSrc, 'array_key_exists($key, $current)'),
    '会覆盖用户配置');

$lpInstance = new LoggingServiceProvider($container);
$rmFill = new ReflectionMethod($lpInstance, 'fillMissingKeys');
$rmFill->setAccessible(true);

$merged = $rmFill->invoke($lpInstance, ['max_body_length' => 999], [
    'max_body_length' => 20000,
    'queue' => ['connection' => 'default', 'after_commit' => false],
    'sensitive_keys' => ['password'],
]);

check('已声明的键被保留（不被包默认值覆盖）', ($merged['max_body_length'] ?? null) === 999);
check('缺失的顶层键被补齐', ($merged['sensitive_keys'] ?? null) === ['password']);
check('缺失的嵌套键被递归补齐',
    is_array($merged['queue'] ?? null) && ($merged['queue']['connection'] ?? null) === 'default',
    json_encode($merged['queue'] ?? null, JSON_UNESCAPED_UNICODE));

// ======================================================================
echo "\n--- Q. 响应头解析的类型安全 ---\n";

$rb = (new ReflectionClass(LogContextMiddleware::class))->getMethod('resolveHeaderBag');
$rb->setAccessible(true);

check('resolveHeaderBag 对数组输入返回 null 而非抛异常', $rb->invoke(null, ['headers' => ['X-Test' => '1']]) === null);
check('resolveHeaderBag 对标量输入返回 null', $rb->invoke(null, 'string') === null);
check('resolveHeaderBag 对 null 返回 null', $rb->invoke(null, null) === null);

$rbBag = $rb->invoke(null, new Response('ok'));
check('resolveHeaderBag 对 Symfony Response 返回 HeaderBag',
    $rbBag instanceof HeaderBag,
    is_object($rbBag) ? $rbBag::class : var_export($rbBag, true));

// ======================================================================
echo "\n--- R. switch -> match 规范化 ---\n";

$rmSrc = codeOnly((string) file_get_contents('/Users/aha/www/modules/src/BuilderQuery/WindowMacros/RandomMacro.php'));
check('RandomMacro::copyWheres 已改用 match', str_contains($rmSrc, 'match ($method)'));
check('RandomMacro 不再有 switch 语句', ! str_contains($rmSrc, 'switch ('));
check('match 保留未知类型的异常语义',
    preg_match('/default\s*=>\s*throw new InvalidArgumentException/', $rmSrc) === 1);

// ======================================================================
echo "\n--- S. BuilderQuery 连接保持与双构建器兼容 ---\n";

// 真实连接：sqlite 内存库 + MySQL grammar（目录视图查询必然失败 → 正好走回退分支）
$probeConn = new Connection(
    new PDO('sqlite::memory:'),
    'default',
    '',
    ['driver' => 'mysql']
);
$probeConn->setQueryGrammar(new MySqlGrammar($probeConn));

// 建表并塞几行数据，让精确 COUNT 回退分支有确定结果可断言
$probeConn->statement('CREATE TABLE probe_tbl (id INTEGER PRIMARY KEY, grp TEXT)');
for ($i = 1; $i <= 3; $i++) {
    $probeConn->insert('INSERT INTO probe_tbl (id, grp) VALUES (?, ?)', [$i, 'g'.($i % 2)]);
}

$container->instance('db', new class($probeConn)
{
    private Connection $conn;

    public function __construct(Connection $conn)
    {
        $this->conn = $conn;
    }

    public function table($table, $as = null)
    {
        return $this->conn->table($table, $as);
    }

    public function raw($value)
    {
        return new Expression($value);
    }

    public function getFacadeRoot()
    {
        return $this;
    }

    public function getConnection($name = null)
    {
        return $this;
    }

    public function connection($name = null)
    {
        return $this->conn;
    }
});

// 前面的段落切换过 Facade 应用容器，这里必须切回承载 'db' 绑定的那个
Facade::setFacadeApplication($container);

Model::setConnectionResolver(new class($probeConn) implements ConnectionResolverInterface
{
    private Connection $conn;

    public function __construct(Connection $conn)
    {
        $this->conn = $conn;
    }

    public function connection($name = null)
    {
        return $this->conn;
    }

    public function getDefaultConnection()
    {
        return 'default';
    }

    public function setDefaultConnection($name) {}
});

$baseMacro = 'zxf\\Modules\\BuilderQuery\\Concerns\\BaseMacro';
FastCountMacro::register();
RandomMacro::register();

// connOf 必须在两条构建器路径上都还原出原连接，否则宏层会落到 DB 门面默认库
$probeTable = $probeConn->table('probe_tbl');
check('connOf(Query\\Builder) 还原原连接',
    $baseMacro::connOf($probeTable) === $probeConn);

$probeModel = new class extends Model
{
    protected $table = 'probe_tbl';

    public $timestamps = false;
};
check('connOf(Eloquent\\Builder) 还原原连接',
    $baseMacro::connOf($probeModel->newQuery()) === $probeConn);

check('freshQuery 复用原连接（不回落 DB 门面默认库）',
    $baseMacro::freshQuery($probeTable)->getConnection() === $probeConn);

// approximateCount：目录视图不可用时必须回退精确计数，且永不为 0 / 负数
$approx = $probeTable->approximateCount();
check('approximateCount 返回正整数（不回退成 0 / 负数）',
    is_int($approx) && $approx > 0,
    '实际返回 '.var_export($approx, true));

// random / groupRandom 在 Query\Builder（DB::table()）路径上不再致命
$randomSql = null;
$randomThrew = '';
try {
    $randomSql = $probeTable->random(5)->toSql();
} catch (Throwable $e) {
    $randomThrew = $e::class.': '.$e->getMessage();
}
check('random() 在 Query\\Builder 上不致命', $randomSql !== null, $randomThrew);
check('random() 在 Query\\Builder 上生成随机排序',
    $randomSql !== null && str_contains(strtolower((string) $randomSql), 'order by rand'));

$groupRandomSql = null;
$groupRandomThrew = '';
try {
    $groupRandomSql = $probeTable->groupRandom('grp', 5, 'id')->toSql();
} catch (Throwable $e) {
    $groupRandomThrew = $e::class.': '.$e->getMessage();
}
check('groupRandom() 在 Query\\Builder 上不致命', $groupRandomSql !== null, $groupRandomThrew);

// ======================================================================
echo "\n--- T. 迁移命令的选项健壮性与退出码语义 ---\n";

$migrateSrc = codeOnly((string) file_get_contents('/Users/aha/www/modules/src/Commands/MigrateCommand.php'));
check('module:migrate --path 分支改用 safeOption 读取 --database',
    str_contains($migrateSrc, "safeOption('database')"),
    '未声明的选项被直接 option() 读取会抛 InvalidArgumentException');
check('module:migrate 不再裸调 $this->option(\'database\')',
    ! str_contains($migrateSrc, "\$this->option('database')"));
check('module:migrate --path 模式忽略 --seed/--seeder 时有显式提示',
    str_contains($migrateSrc, '--path 模式不执行数据填充'));
check('module:migrate --path 模式只跑一次（不套模块循环）',
    str_contains($migrateSrc, '跳过模块循环'));

$statusSrc = codeOnly((string) file_get_contents('/Users/aha/www/modules/src/Commands/MigrateStatusCommand.php'));
check('migrate-status 无模块名时按返回值决定退出码',
    str_contains($statusSrc, '? Command::SUCCESS') && str_contains($statusSrc, ': Command::FAILURE'));
check('migrate-status 全量分支不再恒返回 SUCCESS（死代码已接回）',
    preg_match('/showAllModulesStatus\(\$customPath\)\s*\?\s*Command::SUCCESS/', $statusSrc) === 1);
check('showAllModulesStatus 声明 bool 返回类型',
    preg_match('/function showAllModulesStatus\([^)]*\)\s*:\s*bool/', $statusSrc) === 1);
check('showAllModulesStatus 在失败分支返回 false',
    preg_match('/function showAllModulesStatus[\s\S]*?return false;/', $statusSrc) === 1);

$freshSrc = codeOnly((string) file_get_contents('/Users/aha/www/modules/src/Commands/MigrateFreshCommand.php'));
check('migrate-fresh 表名正则覆盖 Schema::connection()->create()',
    str_contains($freshSrc, 'Schema::(?:connection')
    && str_contains($freshSrc, '(?:create|drop|dropIfExists|table|rename)'));
check('migrate-fresh 表单残留时不清 migrations 记录',
    str_contains($freshSrc, '已保留 migrations 表记录'));
check('migrate-fresh --force 不再硬编码 true',
    ! str_contains($freshSrc, "'--force' => true"));

// ======================================================================
echo "\n--- U. 路径安全与缓存竞态 ---\n";

$repoSrc = codeOnly((string) file_get_contents('/Users/aha/www/modules/src/Repository.php'));
check('缓存 TTL 探测对 filemtime 的 TOCTOU 竞态做了静默处理',
    str_contains($repoSrc, '$mtime = @filemtime($cacheFile)'));
check('Repository 在 filemtime 失败时显式返回 false（不继续用陈旧缓存）',
    preg_match('/@filemtime\(\$cacheFile\)\s*;\s*if \(\$mtime === false\) \{\s*return false;/', $repoSrc) === 1);

$stubRef = new ReflectionClass(StubGenerator::class);
check('StubGenerator 提供公共的路径穿越校验', $stubRef->hasMethod('assertNoTraversal'));

$traversalRejected = 0;
foreach (['../evil', 'a/../../evil', '..\\evil', "ok\0/evil"] as $badPath) {
    try {
        $stubRef->getMethod('assertNoTraversal')->invoke(null, $badPath);
    } catch (InvalidArgumentException) {
        $traversalRejected++;
    }
}
check('assertNoTraversal 拒绝 .. 段与 NUL 字节', $traversalRejected === 4, "只拦下 {$traversalRejected}/4");

foreach (['Database/Migrations/x.php', '2026_01_01_000000_create_x_table.php'] as $goodPath) {
    $passed = true;
    try {
        $stubRef->getMethod('assertNoTraversal')->invoke(null, $goodPath);
    } catch (Throwable) {
        $passed = false;
    }
    check("assertNoTraversal 放行合法路径 {$goodPath}", $passed);
}

$stubGenSrc = codeOnly((string) file_get_contents('/Users/aha/www/modules/src/Support/StubGenerator.php'));
check('generateFromPath 写入口已接路径穿越校验',
    preg_match('/function generateFromPath[\s\S]*?self::assertNoTraversal\(\$destination\)/', $stubGenSrc) === 1);
check('generateDirectories 写入口已接路径穿越校验',
    preg_match('/function generateDirectories[\s\S]*?self::assertNoTraversal\(\$path\)/', $stubGenSrc) === 1);

// ======================================================================
echo "\n--- V. Log::scopeSearch 的 SQLite LIKE 转义 ---\n";

// SQLite 的 LIKE 没有默认转义字符，不声明 ESCAPE 时 % 与 _ 仍按通配符解释，
// 关键字检索会返回错误结果集，且可被构造为全表扫描。
// 注意必须用 driver=sqlite 的连接（上面的 $probeConn 配置的是 mysql），
// 否则 scopeSearch 的 sqlite 分支根本不会被命中。
$sqliteConn = new Connection(
    new PDO('sqlite::memory:'),
    'default',
    '',
    ['driver' => 'sqlite']
);
check('scopeSearch 走 sqlite 分支的前提是驱动声明正确',
    $sqliteConn->getDriverName() === 'sqlite');

Model::setConnectionResolver(new class($sqliteConn) implements ConnectionResolverInterface
{
    private Connection $conn;

    public function __construct(Connection $conn)
    {
        $this->conn = $conn;
    }

    public function connection($name = null)
    {
        return $this->conn;
    }

    public function getDefaultConnection()
    {
        return 'default';
    }

    public function setDefaultConnection($name) {}
});

$sqliteConn->statement('CREATE TABLE logs (id INTEGER PRIMARY KEY, message TEXT, module_name TEXT)');
foreach (['timeout on host a', '磁盘 100% 满', '磁盘 1000% 满', 'disk_usage high', 'x_y z'] as $msg) {
    $sqliteConn->insert('INSERT INTO logs (message, module_name) VALUES (?, ?)', [$msg, 'sys']);
}

$logModel = new class extends Log
{
    protected $table = 'logs';
};

$search = static fn (string $term) => $logModel->newQuery()->search($term)->get()->pluck('message')->all();

// 含 % 的关键字必须只匹配字面量 '%'
check('scopeSearch 精确匹配字面量 %', $search('100%') === ['磁盘 100% 满']);
// 含 _ 的关键字必须只匹配字面量 '_'
check('scopeSearch 精确匹配字面量 _', $search('x_y') === ['x_y z']);
check('scopeSearch 下划线不被当通配符', $search('disk_usage') === ['disk_usage high']);
check('scopeSearch 普通关键字正常', $search('timeout') === ['timeout on host a']);
check('scopeSearch 空串不附加条件',
    $search('') === $logModel->newQuery()->get()->pluck('message')->all());

$logQuery = $logModel->newQuery()->search('100%');
check('SQLite 分支显式声明 ESCAPE', str_contains($logQuery->toSql(), 'ESCAPE'));

// ======================================================================
echo "\n--- W. 控制台输出转义（格式标记注入）---\n";

// Symfony 的 OutputFormatter 把 <tag> 当样式标记：
// 未知标记直接抛 InvalidArgumentException: Unknown style，把命令打成非零退出。
$escaper = new class
{
    use EscapesConsoleOutput;

    public function run(mixed $v): string
    {
        return $this->esc($v);
    }
};

$malicious = '<info>x</info><foo bar>';
$escaped = $escaper->run($malicious);
// OutputFormatter::escape 的语义是给尖括号加反斜杠（\< \>），
// 因此断言「没有**未被转义**的尖括号」，而不是断言尖括号消失。
check('esc() 转义了所有尖括号',
    ! preg_match('/(?<!\\\\)[<>]/', $escaped),
    $escaped);
check('esc() 输出与 OutputFormatter::escape 一致',
    $escaped === OutputFormatter::escape($malicious));

$renderable = true;
try {
    (new OutputFormatter)->format($escaped);
} catch (Throwable) {
    $renderable = false;
}
check('esc() 输出可被 OutputFormatter 安全渲染（不再抛 Unknown style）', $renderable);
check('esc() 对 null / bool 不抛异常',
    $escaper->run(null) === '' && $escaper->run(true) === '1');
check('esc() 对数组安全降级为 JSON',
    ! str_contains($escaper->run(['a' => '<b>']), '<b>'));

// 所有「回显用户输入」的命令都必须接入该 trait
$cmdDir = '/Users/aha/www/modules/src/Commands';
$missingTrait = [];
$unescapedEcho = [];

foreach ((array) glob($cmdDir.'/*.php') as $cmdFile) {
    $src = (string) file_get_contents((string) $cmdFile);
    $base = basename((string) $cmdFile);

    if (! str_contains($src, 'extends Command') && ! str_contains($src, 'extends AbstractMakeCommand')) {
        continue;
    }

    // 抽象基类不直接执行，豁免
    if (str_starts_with($base, 'Abstract')) {
        continue;
    }

    $isDirect = str_contains($src, 'extends Command');

    if ($isDirect && ! str_contains($src, 'use EscapesConsoleOutput;')) {
        $missingTrait[] = $base;
    }

    // 输出语句里仍在裸插值的用户输入变量
    foreach (preg_split('/\R/', $src) as $lineNo => $line) {
        if (! preg_match('/\$this->(?:components->)?(?:error|warn|info|line|comment)\s*\(/', (string) $line)) {
            continue;
        }
        if (str_contains((string) $line, '$this->esc')) {
            continue;
        }
        if (preg_match('/\{\$(moduleName|module|routeName|viewName|migrationName|type|customPath|modulePath|className|seeder|fullClass|filePath)\}/', (string) $line)) {
            $unescapedEcho[] = $base.':'.($lineNo + 1);
        }
    }
}

check('所有直接继承 Command 的命令都接入 EscapesConsoleOutput',
    $missingTrait === [],
    implode(', ', $missingTrait));
check('用户输入在输出前一律经 esc() 转义',
    $unescapedEcho === [],
    implode(', ', array_slice($unescapedEcho, 0, 8)));

// ======================================================================
echo "\n结果: {$pass} PASS / {$fail} FAIL\n";

exit($fail === 0 ? 0 : 1);
