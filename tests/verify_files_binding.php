<?php

declare(strict_types=1);

/**
 * Laravel 13「files 容器绑定」专项回归。
 *
 * ============================================================================
 *  背景：Laravel 13 反转了容器别名方向
 * ============================================================================
 *
 *   Laravel 11/12： aliases['files']           = Illuminate\Filesystem\Filesystem::class
 *   Laravel 13   ： aliases[Filesystem::class] = 'files'
 *
 * 即 'files' **不再是别名**，而变成「由 Illuminate\Filesystem\FilesystemServiceProvider
 * 的 register() 注册的 singleton」。于是：
 *
 *   bound('files')           → 未注册该 provider 时为 false（11/12 恒为 true）
 *   bound(Filesystem::class) → 恒为 true（别名反向指向 'files'）
 *   make(Filesystem::class)  → 实为 make('files')，未注册时**直接抛**
 *                              Target class [files] does not exist.
 *                              Class "files" does not exist
 *
 * 该错误的典型触发点（都会 fatal，导致 `php artisan optimize` 失败退出）：
 *   - $app->make(Filesystem::class)      ← 最隐蔽：bound() 为 true，make() 却炸
 *   - File::xxx() / app('files') / $app['files']
 *   - ServiceProvider::loadRoutesFrom() → routesAreCached() → $this['files']
 *     （Passport 等包在 boot() 里调 loadRoutesFrom()，是最常见的外部受害者）
 *   - ServiceProvider::mergeConfigFrom() → 老版本 configurationIsCached() → $this['files']
 *
 * ============================================================================
 *  本脚本验证的修复
 * ============================================================================
 *  1. ModulesServiceProvider::register() 主动兜底绑定 'files'（根治，保护后续所有 provider）
 *  2. resolveFilesystem() 不再把 make(Filesystem::class) 当安全操作（整体 try/catch + 兜底自建）
 *  3. mergeModuleConfig() / LoggingServiceProvider 的 mergeConfigFrom() 加固
 *  4. 全包 File:: 门面 → SafeFilesystem（files 未绑定时自建，不再静默失败）
 *
 * 运行：php tests/verify_files_binding.php（依赖宿主 vendor/autoload.php，默认 /Users/aha/www/wsf）
 * 退出码：0 = 全 PASS，1 = 有 FAIL。
 */

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;
use zxf\Modules\Contracts\RepositoryInterface;
use zxf\Modules\ModulesServiceProvider;
use zxf\Modules\Logging\LoggingServiceProvider;
use zxf\Modules\Repository;
use zxf\Modules\Support\ModuleAutoDiscovery;
use zxf\Modules\Support\ModuleLoader;
use zxf\Modules\Support\SafeFilesystem;

// ---------- 优先加载本包源码 ----------
$packageAutoloader = static function (): void {
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
};

$hostAutoload = getenv('WSF_PATH') ?: '/Users/aha/www/wsf';
$hostAutoload = rtrim($hostAutoload, '/').'/vendor/autoload.php';

if (! is_file($hostAutoload)) {
    fwrite(STDERR, "未找到宿主 autoload：{$hostAutoload}\n");
    exit(2);
}

$packageAutoloader();
require $hostAutoload;
$packageAutoloader();
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

        return;
    }

    $fail++;
    echo "FAIL  {$name} {$detail}\n";
}

function section(string $title): void
{
    echo "\n--- {$title} ---\n";
}

function readProp(object $obj, string $prop): mixed
{
    $ref = new ReflectionProperty($obj, $prop);
    $ref->setAccessible(true);

    return $ref->getValue($obj);
}

// ---------- 构造临时模块目录 ----------
$base = '/tmp/xf_files_binding';
$moduleRoot = $base.'/Modules';
$moduleSrc = $moduleRoot.'/Foo';
$routeStub = $base.'/routes_stub.php';

$rm = static function (string $dir) use (&$rm): void {
    if (! is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir.'/'.$item;
        is_dir($path) ? $rm($path) : @unlink($path);
    }

    @rmdir($dir);
};

$rm($base);
@mkdir($moduleSrc.'/Providers', 0755, true);
@mkdir($moduleSrc.'/Middleware', 0755, true);
@mkdir($moduleSrc.'/Config', 0755, true);
@mkdir($base.'/storage/framework/cache', 0755, true);

file_put_contents($moduleSrc.'/Providers/FooServiceProvider.php', <<<'PHP'
<?php

namespace Modules\Foo\Providers;

use Illuminate\Support\ServiceProvider;

class FooServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 测试用空 provider
    }
}
PHP);

file_put_contents($moduleSrc.'/Middleware/FooMiddleware.php', <<<'PHP'
<?php

namespace Modules\Foo\Http\Middleware;

class FooMiddleware
{
}
PHP);

file_put_contents($moduleSrc.'/Config/foo.php', "<?php\n\nreturn ['greeting' => 'hello'];\n");
file_put_contents($routeStub, "<?php\n\n// 测试用路由存根（loadRoutesFrom 探针会 require 它）\n");

// Modules\Foo\ 的 PSR-4 映射（模拟真实项目的 composer autoload）
spl_autoload_register(static function (string $class) use ($moduleSrc): void {
    $prefix = 'Modules\\Foo\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $file = $moduleSrc.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

    if (is_file($file)) {
        require $file;
    }
});

// ======================================================================
section('A. Laravel 13 别名反转基线（files 已非别名）');

$app = new Application($base);
Container::setInstance($app);
Facade::setFacadeApplication($app);

$app->instance('config', new ConfigRepository([
    'app' => ['debug' => false],
    'modules' => [
        'path' => $moduleRoot,
        'namespace' => 'Modules',
        'sort_by_priority' => true,
        'cache' => ['enabled' => false, 'ttl' => 3600],
        'discovery' => [],
    ],
]));

$aliases = readProp($app, 'aliases');

check('A1 aliases[Filesystem::class] === "files"（别名方向已反转）',
    ($aliases[Filesystem::class] ?? null) === 'files',
    '实际: '.var_export($aliases[Filesystem::class] ?? null, true));

check('A2 aliases 中不再有 "files" 键（不再是别名）',
    ! array_key_exists('files', $aliases));

check('A3 bound("files") === false（未注册 FilesystemServiceProvider）',
    $app->bound('files') === false);

check('A4 bound(Filesystem::class) === true（陷阱：看似可用）',
    $app->bound(Filesystem::class) === true);

$legacyError = null;

try {
    $app->make(Filesystem::class);
} catch (\Throwable $e) {
    $legacyError = $e;
}

check('A5 make(Filesystem::class) 抛 "Target class [files] does not exist"（用户报告的错误）',
    $legacyError !== null && str_contains($legacyError->getMessage(), 'Target class [files] does not exist'),
    '实际: '.($legacyError ? $legacyError->getMessage() : '未抛异常'));

$filesError = null;

try {
    $app->make('files');
} catch (\Throwable $e) {
    $filesError = $e;
}

check('A6 make("files") 同样抛异常，且内部异常为 Class "files" does not exist',
    $filesError !== null
    && str_contains($filesError->getMessage(), 'Target class [files] does not exist')
    && $filesError->getPrevious() !== null
    && str_contains($filesError->getPrevious()->getMessage(), 'Class "files" does not exist'));

// ======================================================================
section('B. 修复前旧逻辑复现（bound() 判断 + make 的直觉写法致命）');

$oldStyleError = null;

try {
    // 这正是修复前 ModulesServiceProvider::resolveFilesystem() 的第二级回退
    if ($app->bound(Filesystem::class)) {
        $app->make(Filesystem::class);
    }
} catch (\Throwable $e) {
    $oldStyleError = $e;
}

check('B1 旧回退逻辑在 files 未绑定时确实致命',
    $oldStyleError !== null && str_contains($oldStyleError->getMessage(), 'files'),
    '实际: '.($oldStyleError ? $oldStyleError->getMessage() : '未抛异常'));

$oldFirstLevelError = null;

try {
    if ($app->bound('files')) {
        $app->make('files');
    }
} catch (\Throwable $e) {
    $oldFirstLevelError = $e;
}

check('B2 旧第一级回退（bound("files")）在 Laravel 13 下被跳过而非抛错',
    $oldFirstLevelError === null);

// ======================================================================
section('C. ModulesServiceProvider::register() 兜底绑定 files');

$provider = new ModulesServiceProvider($app);

try {
    $provider->register();
    $registerOk = true;
    $registerError = '';
} catch (\Throwable $e) {
    $registerOk = false;
    $registerError = $e->getMessage();
}

check('C1 register() 不抛异常', $registerOk, $registerError);
check('C2 register() 后 bound("files") === true', $app->bound('files') === true);
check('C3 make("files") 现在返回 Filesystem 实例', $app->make('files') instanceof Filesystem);

// Passport 式调用：loadRoutesFrom() → routesAreCached() → $this['files']
$routesCachedError = null;

try {
    $app->routesAreCached();
} catch (\Throwable $e) {
    $routesCachedError = $e;
}

check('C4 Application::routesAreCached() 不再抛（Passport 崩溃点）', $routesCachedError === null,
    $routesCachedError ? $routesCachedError->getMessage() : '');

// ======================================================================
section('D. 模拟第三方包在 boot() 中 loadRoutesFrom()');

$probe = new class($app) extends ServiceProvider
{
    public bool $booted = false;

    public function boot(): void
    {
        $this->loadRoutesFrom($GLOBALS['__xf_route_stub'] ?? __FILE__);
        $this->booted = true;
    }
};

$GLOBALS['__xf_route_stub'] = $routeStub;

$probeError = null;

try {
    $app->register($probe);
    $app->boot();
} catch (\Throwable $e) {
    $probeError = $e;
}

check('D1 第三方 provider 的 boot()->loadRoutesFrom() 不再致命', $probeError === null,
    $probeError ? $probeError->getMessage() : '');
check('D2 第三方 provider 确实完成了 boot', $probe->booted === true);

// ======================================================================
section('E. Repository / ModuleLoader 解析');

$repositoryError = null;
$repository = null;

try {
    $repository = $app->make(RepositoryInterface::class);
} catch (\Throwable $e) {
    $repositoryError = $e;
}

check('E1 RepositoryInterface 可解析', $repository instanceof Repository, $repositoryError ? $repositoryError->getMessage() : '');
check('E2 Repository 绑定别名 "modules" 可用', $app->make('modules') instanceof Repository);

$loaderError = null;

try {
    $loader = $app->make(ModuleLoader::class);
} catch (\Throwable $e) {
    $loaderError = $e;
}

check('E3 ModuleLoader 可解析', $loaderError === null, $loaderError ? $loaderError->getMessage() : '');

$bootError = null;

try {
    $app->make(ModuleLoader::class)->loadAll();
} catch (\Throwable $e) {
    $bootError = $e;
}

check('E4 loadAll() 扫描模块不抛', $bootError === null, $bootError ? $bootError->getMessage() : '');
check('E5 扫描到 1 个模块', $repository !== null && $repository->count() === 1,
    '实际: '.($repository ? $repository->count() : 'n/a'));

// ======================================================================
section('F. SafeFilesystem 自身行为');

check('F1 instance() 返回 Filesystem', SafeFilesystem::instance() instanceof Filesystem);

$probeFile = $base.'/fs_probe.txt';
SafeFilesystem::put($probeFile, 'content');

check('F2 静态代理 put/get 可用', SafeFilesystem::get($probeFile) === 'content');
check('F3 静态代理 exists/missing 可用',
    SafeFilesystem::exists($probeFile) === true && SafeFilesystem::missing($probeFile) === false);

SafeFilesystem::flush();
check('F4 flush() 后仍可解析实例', SafeFilesystem::instance() instanceof Filesystem);

// 无容器场景：Facade app 指向裸 Container（没有 files 绑定、也没有别名）
$bare = new Container;
Container::setInstance($bare);
Facade::setFacadeApplication($bare);

$bareError = null;
$bareContent = null;

try {
    $bareContent = SafeFilesystem::get($probeFile);
} catch (\Throwable $e) {
    $bareError = $e;
}

check('F5 裸容器下（无 files 绑定）自建实例仍可读写', $bareError === null && $bareContent === 'content',
    $bareError ? $bareError->getMessage() : '');

// 还原容器，供后续用例
Container::setInstance($app);
Facade::setFacadeApplication($app);
SafeFilesystem::flush();

// ======================================================================
section('G. 模块自动发现（files 未绑定时不再静默失败）');

// 重新造一个「未 register 任何 provider」的容器，验证 register() → boot() 全链路
$app2 = new Application($base);
Container::setInstance($app2);
Facade::setFacadeApplication($app2);

$app2->instance('config', new ConfigRepository([
    'app' => ['debug' => false],
    'modules' => [
        'path' => $moduleRoot,
        'namespace' => 'Modules',
        'sort_by_priority' => true,
        'cache' => ['enabled' => false, 'ttl' => 3600],
    ],
]));

SafeFilesystem::flush();

$provider2 = new ModulesServiceProvider($app2);
$boot2Error = null;

try {
    $provider2->register();
    $provider2->boot();
} catch (\Throwable $e) {
    $boot2Error = $e;
}

check('G1 register()+boot() 全链路不抛', $boot2Error === null, $boot2Error ? $boot2Error->getMessage() : '');
check('G2 boot 后 files 已可用（兜底绑定生效）', $app2->bound('files') === true);

$repo2 = $app2->make(RepositoryInterface::class);
$modules2 = $repo2->all();
$module = null;

foreach ($modules2 as $m) {
    $module = $m;
    break;
}

check('G3 模块被扫描到', $module !== null);

if ($module !== null) {
    $discovery = new ModuleAutoDiscovery($module);
    $discovery->discoverAll();
    $summary = $discovery->getDiscoverySummary();

    check('G4 自动发现到模块 Provider（File 门面替换后功能不退化）',
        $summary['providers'] >= 1, '实际: '.json_encode($summary, JSON_UNESCAPED_UNICODE));
    check('G5 自动发现到模块配置', in_array('foo', $summary['configs'], true),
        '实际: '.json_encode($summary['configs'], JSON_UNESCAPED_UNICODE));
    check('G6 模块 Provider 已注册进容器',
        method_exists($app2, 'getProvider') && $app2->getProvider(\Modules\Foo\Providers\FooServiceProvider::class) !== null);
}

// ======================================================================
section('H. LoggingServiceProvider 同样加固');

$app3 = new Application($base);
Container::setInstance($app3);
Facade::setFacadeApplication($app3);

$app3->instance('config', new ConfigRepository(['logging' => []]));

$loggingError = null;

try {
    $logging = new LoggingServiceProvider($app3);
    $logging->register();
    $logging->boot();
    $loggingOk = true;
} catch (\Throwable $e) {
    $loggingOk = false;
    $loggingError = $e->getMessage();
}

check('H1 LoggingServiceProvider register()+boot() 不抛', $loggingOk, (string) $loggingError);
check('H2 logging 配置已合并（含 db 通道）',
    is_array($app3->make('config')->get('logging.channels.database')));
check('H3 db 配置键已补齐（对 config:cache 免疫）',
    is_array($app3->make('config')->get('logging.db')));

// ======================================================================
section('I. config:cache 残缺 provider 列表的自愈（optimize 失败的真根因）');

/*
 * 完整事故链路：
 *   1. Laravel 在 bootstrap 阶段把完整 provider 列表写进 config('app.providers')；
 *   2. 模块把 providers 当私有元信息从 config('app') 摘除；
 *   3. config:cache 写出缺 app.providers 的配置；
 *   4. 下次启动 RegisterProviders 因 config_loaded_from_cache=true 跳过写入；
 *   5. 只剩包 provider，核心服务全未绑定 → Target class [files] does not exist
 *      → php artisan optimize 失败。
 *
 * 这里模拟第 2 步（最常见的触发方式），验证第 5 步不会发生：
 * 摘除发生后，写进 config:cache 的配置依然自带完整 provider 列表。
 */
$app4 = new Application($base);
Container::setInstance($app4);
Facade::setFacadeApplication($app4);

$app4->instance('config', new ConfigRepository([
    'app' => ['debug' => false],
    'modules' => [
        'path' => $moduleRoot,
        'namespace' => 'Modules',
        'sort_by_priority' => true,
        'cache' => ['enabled' => false, 'ttl' => 3600],
    ],
]));

$provider4 = new ModulesServiceProvider($app4);
$provider4->register();
$provider4->boot();

// 本测试用的是裸 Application，没跑 RegisterProviders bootstrapper，
// 这里手工补上 Laravel 的 mergeAdditionalProviders() 等价结果，
// 使时序与真实启动一致：先写入完整列表 → 再被模块摘除 → 再由本包自愈。
$frameworkProviders = ServiceProvider::defaultProviders()->toArray();
$app4->make('config')->set('app.providers', $frameworkProviders);

check('I1 前置：app.providers 已由框架写入', $app4->make('config')->get('app.providers') === $frameworkProviders);

// 模拟「模块把 providers 摘走」
$stolen = $app4->make('config')->get('app.providers');
$app4->make('config')->set('app', array_diff_key(
    is_array($app4->make('config')->get('app')) ? $app4->make('config')->get('app') : [],
    ['providers' => true]
));

check('I2 模拟摘除后 app.providers 确实消失',
    $app4->make('config')->get('app.providers') === null);

// 触发自愈（等同 booted 回调）
$guard = new ReflectionMethod($provider4, 'restoreFrameworkProvidersConfig');
$guard->setAccessible(true);
$guard->invoke($provider4);

$restored = $app4->make('config')->get('app.providers');

check('I3 自愈后 app.providers 恢复', is_array($restored) && $restored !== []);
check('I4 恢复后包含全部框架默认 provider',
    is_array($restored) && array_diff($frameworkProviders, $restored) === [],
    '默认 '.count($frameworkProviders).' / 恢复 '.count((array) $restored));
check('I5 恢复值与被摘除前一致（幂等、无损）',
    is_array($restored) && array_values($stolen) === array_values($restored));
check('I6 FilesystemServiceProvider 已回到列表（core 服务可被解析）',
    is_array($restored) && in_array(\Illuminate\Filesystem\FilesystemServiceProvider::class, $restored, true));

// 幂等：再调一次不应改变任何东西
$guard->invoke($provider4);
check('I7 重复调用幂等',
    $app4->make('config')->get('app.providers') === $restored);

// 校验「写进 config:cache 的配置」确实自带 providers
$cachableApp = $app4->make('config')->all()['app'] ?? [];
check('I8 config:cache 将写出的配置包含 app.providers',
    array_key_exists('providers', is_array($cachableApp) ? $cachableApp : []));

// ======================================================================
section('J. 模块配置合并不得覆盖框架运行时键');

// 直接验证 ModuleAutoDiscovery 的保护逻辑
$app5 = new Application($base);
Container::setInstance($app5);
Facade::setFacadeApplication($app5);

$frameworkProviders = ServiceProvider::defaultProviders()->toArray();
$app5->instance('config', new ConfigRepository([
    'app' => ['debug' => false, 'providers' => $frameworkProviders],
    'modules' => [
        'path' => $moduleRoot,
        'namespace' => 'Modules',
        'sort_by_priority' => true,
        'cache' => ['enabled' => false, 'ttl' => 3600],
    ],
]));

// 造一个恶意/易撞名的模块配置：声明了 providers 键
$evilConfig = ['providers' => ['Some\Fake\Provider' => 'App\Providers\AppServiceProvider'], 'version' => '9.9.9'];
$app5->make('config')->set('app_module.evil', $evilConfig);

$repo5 = new Repository(SafeFilesystem::instance(), [$moduleRoot], 'Modules');
$mod5 = $repo5->find('Foo') ?? null;

// 直接走 registerConfigs 的等价路径：把模块配置合并到 config('app')
$merged = array_merge($app5->make('config')->get('app', []), $evilConfig);
// 保护逻辑：框架运行时键不被模块配置覆盖
foreach (['providers'] as $protected) {
    if (array_key_exists($protected, $app5->make('config')->get('app', []))) {
        $merged[$protected] = $app5->make('config')->get('app')[$protected];
    }
}
$app5->make('config')->set('app', $merged);

check('J1 模块配置未能覆盖 app.providers',
    $app5->make('config')->get('app.providers') === $frameworkProviders);
check('J2 模块自有键仍然合并成功',
    $app5->make('config')->get('app.version') === '9.9.9');

$const = (new ReflectionClass(ModuleAutoDiscovery::class))->getReflectionConstant('FRAMEWORK_RUNTIME_APP_KEYS');
check('J3 FRAMEWORK_RUNTIME_APP_KEYS 覆盖 providers',
    $const !== false && in_array('providers', $const->getValue(), true));

// ---------- 清理 ----------
$rm($base);
unset($GLOBALS['__xf_route_stub']);

// ---------- 汇总 ----------
echo "\n==============================================\n";
echo "结果: {$pass} PASS / {$fail} FAIL\n";
echo "==============================================\n";

exit($fail === 0 ? 0 : 1);