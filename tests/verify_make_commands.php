<?php

declare(strict_types=1);

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Database\Schema\Builder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use zxf\Modules\Commands\AbstractMakeCommand;
use zxf\Modules\Commands\MigrateFreshCommand;
use zxf\Modules\Commands\MigrationMakeCommand;
use zxf\Modules\Commands\ModelMakeCommand;
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Support\StubGenerator;

/**
 * module:make-* 生成器命令与 StubGenerator 的运行时验证脚本。
 *
 * 验证重点：
 *  1. AbstractMakeCommand::targetPath 拒绝 ".."（否则可写到模块目录之外）
 *  2. StubGenerator 真正遵守 config 中的 stubs.override / stubs.strict
 *  3. StubGenerator::getStubPath 自定义目录缺文件时回退包内目录
 *  4. StubGenerator::getFullPath 拒绝路径穿越；generate() 对非法路径抛出明确异常
 *  5. MigrationMakeCommand 迁移名 / --path 校验，拒绝穿越；--realpath 无 --path 时明确报错而非 TypeError
 *  6. MigrationMakeCommand 迁移 stub 走 StubGenerator（支持发布自定义 stub）
 *  7. MigrateFreshCommand::collectModuleTables 只能解析出本模块迁移文件里的表
 *  8. ModelMakeCommand：跨库探测生效、注释/属性/字段名正确转义、子目录命名空间合法、软删除 trait 生成
 *
 * 运行：php tests/verify_make_commands.php（依赖宿主 vendor/autoload.php，默认 /Users/aha/www/wsf）
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

/** 调用 protected 方法 */
function callProtected(object $obj, string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);

    return $ref->invokeArgs($obj, $args);
}

// ---------- 轻量模块桩 ----------
$root = '/tmp/xf_make_test/Modules/Demo';

final class TestModule implements ModuleInterface
{
    public function __construct(private string $root) {}

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
        return $path === null ? $this->root : $this->root.'/'.ltrim($path, '/');
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

/** 暴露受保护方法的测试用命令 */
final class ProbeMakeCommand extends AbstractMakeCommand
{
    protected $signature = 'probe:make';

    protected $description = 'probe';

    public function useModule(ModuleInterface $module): void
    {
        $this->module = $module;
    }

    public function probeTargetPath(string $rel): string
    {
        return $this->targetPath($rel);
    }

    public function probeSafe(string $rel): bool
    {
        return $this->isSafeRelativePath($rel);
    }

    public function probeEntityName(?string $name): bool
    {
        return $this->isValidEntityName($name);
    }

    public function probeWriteStub(StubGenerator $gen, string $rel): int
    {
        return $this->writeStub($gen, 'migration/create.stub', $rel, '迁移', true);
    }

    public function errorOut(string $s): void {}

    // 脱离 artisan 运行时 Command::$output 为 null，此处屏蔽输出避免噪声
    public function error($string, $verbosity = null): void {}

    public function warn($string, $verbosity = null): void {}

    public function info($string, $verbosity = null): void {}

    public function line($string, $style = null, $verbosity = null): void {}
}

final class ProbeMigrationCommand extends MigrationMakeCommand
{
    public function useModule(ModuleInterface $module): void
    {
        $this->module = $module;
    }

    public function probeValidName(string $name): bool
    {
        return $this->isValidMigrationName($name);
    }

    public function probeParentSegment(string $path): bool
    {
        return $this->containsParentSegment($path);
    }

    public function probeMigrationPath(ModuleInterface $module, array $options): ?string
    {
        $this->setOptionOverrides($options);

        $ref = new ReflectionMethod($this, 'getMigrationPath');
        $ref->setAccessible(true);

        return $ref->invoke($this, $module);
    }

    /** @var array<string, mixed> */
    private array $overrides = [];

    public function setOptionOverrides(array $options): void
    {
        $this->overrides = $options;
    }

    public function option($key = null)
    {
        return array_key_exists($key, $this->overrides) ? $this->overrides[$key] : false;
    }

    public function error($string, $verbosity = null): void {}

    public function warn($string, $verbosity = null): void {}
}

final class ProbeFreshCommand extends MigrateFreshCommand
{
    public function probeCollectTables(ModuleInterface $module): array
    {
        return $this->collectModuleTables($module);
    }

    public function probeCollectNames(ModuleInterface $module): array
    {
        return $this->collectModuleMigrationNames($module);
    }
}

// ======================================================================
echo "--- 1. AbstractMakeCommand 路径穿越防护 ---\n";

$module = new TestModule($root);
$probe = new ProbeMakeCommand;
$probe->useModule($module);

check('正常相对路径被放行', $probe->probeSafe('Http/Controllers/Foo.php') === true);
check('正常绝对路径被放行', $probe->probeSafe('/tmp/x/y.php') === true);
check('含 .. 的相对路径被拒绝', $probe->probeSafe('../../config/app.php') === false);
check('含 .. 的 Windows 路径被拒绝', $probe->probeSafe('..\\..\\evil.php') === false);
check('含 NUL 字节的路径被拒绝', $probe->probeSafe("Foo\0.php") === false);
check('空路径被拒绝', $probe->probeSafe('') === false);
check('名字里含两个点但不是路径段的被放行', $probe->probeSafe('Foo.bar.php') === true);

// targetPath 越界必须抛异常而不是静默写到模块外
$threw = false;
try {
    $probe->probeTargetPath('../evil.php');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('targetPath("..") 抛出 InvalidArgumentException', $threw);

check('targetPath 正常路径落在模块目录内', str_starts_with(
    $probe->probeTargetPath('Models/Post.php'),
    $root
));

// 未解析模块时仍应给出明确的 RuntimeException
$threw = false;
try {
    (new ProbeMakeCommand)->probeTargetPath('Models/Post.php');
} catch (RuntimeException $e) {
    $threw = true;
}
check('未解析模块时 targetPath 抛 RuntimeException', $threw);

// 实体名校验（ViewMake / RouteMake 等命令的前置拦截）
check('实体名 posts.index 合法', $probe->probeEntityName('posts.index') === true);
check('实体名 admin_v2 合法', $probe->probeEntityName('admin_v2') === true);
check('实体名 admin/setting 被拒', $probe->probeEntityName('admin/setting') === false);
check('实体名 ../../evil 被拒', $probe->probeEntityName('../../evil') === false);
check('实体名为 null 被拒', $probe->probeEntityName(null) === false);
check('实体名为空被拒', $probe->probeEntityName('') === false);

// writeStub 遇到穿越路径应返回 FAILURE 而不是抛出异常（不甩栈回溯给用户）
$genGuard = new StubGenerator('Demo', $root, 'Modules');
$rc = $probe->probeWriteStub($genGuard, '../../evil_migration.php');
check('writeStub 对穿越路径返回 FAILURE（无异常）', $rc === Command::FAILURE, (string) $rc);
check('writeStub 穿越未落文件', ! file_exists('/tmp/xf_make_test/Modules/evil_migration.php'));

// ======================================================================
echo "\n--- 2. StubGenerator 遵守 config(stubs.*) ---\n";

$container = new Container;
Container::setInstance($container);
$container->instance('config', new ConfigRepository([
    'modules' => [
        'path' => '/tmp/xf_make_test/Modules',
        'namespace' => 'Modules',
        'stubs' => [
            // 故意指向一个空目录，验证「自定义目录没有该文件时回退包内」
            'path' => '/tmp/xf_make_test/empty-stubs',
            'override' => false,
            'strict' => false,
        ],
    ],
]));
// StubGenerator 内部使用 File 门面读取 stub 内容，需绑定 'files' 并设置门面应用实例
$container->instance('files', new Filesystem);
Facade::setFacadeApplication($container);
@mkdir('/tmp/xf_make_test/empty-stubs', 0755, true);
// 清掉上一轮遗留的自定义 stub，保证「缺失时回退包内」这条断言可重复运行
foreach (glob('/tmp/xf_make_test/empty-stubs/**/*') ?: [] as $stale) {
    if (is_file($stale)) {
        @unlink($stale);
    }
}
@rmdir('/tmp/xf_make_test/empty-stubs/migration');

$pkgStubs = realpath(__DIR__.'/../src/Commands/stubs');

/** 把含 /./ /../ 的路径规范化，便于与 realpath 结果比较 */
function norm(string $p): string
{
    $parts = [];

    foreach (explode('/', str_replace('\\', '/', $p)) as $seg) {
        if ($seg === '.' || $seg === '') {
            if ($parts === []) {
                $parts[] = '';
            }

            continue;
        }
        if ($seg === '..') {
            array_pop($parts);

            continue;
        }
        $parts[] = $seg;
    }

    return implode('/', $parts);
}

check('包内 stub 目录存在', $pkgStubs !== false);

$gen = new StubGenerator('Demo', '/tmp/xf_make_test/Modules/Demo', 'Modules');

check('override=false 时 getStubPath 回退包内目录',
    norm($gen->getStubPath('migration/create.stub')) === $pkgStubs.'/migration/create.stub',
    $gen->getStubPath('migration/create.stub'));
check('strict=false 时 isStrictMode 为 false', $gen->isStrictMode() === false);

// 打开 override 后，自定义目录缺文件仍要回退包内
$container->instance('config', new ConfigRepository([
    'modules' => [
        'path' => '/tmp/xf_make_test/Modules',
        'namespace' => 'Modules',
        'stubs' => ['path' => '/tmp/xf_make_test/empty-stubs', 'override' => true, 'strict' => true],
    ],
]));
$gen2 = new StubGenerator('Demo', '/tmp/xf_make_test/Modules/Demo', 'Modules');
check('override=true + 自定义目录缺文件 → 回退包内目录',
    norm($gen2->getStubPath('migration/create.stub')) === $pkgStubs.'/migration/create.stub',
    $gen2->getStubPath('migration/create.stub'));
check('strict=true 时 isStrictMode 为 true', $gen2->isStrictMode() === true);

// 自定义目录里放了同名 stub 时应优先使用
@mkdir('/tmp/xf_make_test/empty-stubs/migration', 0755, true);
file_put_contents('/tmp/xf_make_test/empty-stubs/migration/create.stub', 'CUSTOM');
$gen3 = new StubGenerator('Demo', '/tmp/xf_make_test/Modules/Demo', 'Modules');
check('自定义目录存在同名 stub 时优先使用自定义',
    $gen3->getStubPath('migration/create.stub') === '/tmp/xf_make_test/empty-stubs/migration/create.stub',
    $gen3->getStubPath('migration/create.stub'));

// ======================================================================
echo "\n--- 3. StubGenerator 路径穿越防护 ---\n";

$threw = false;
try {
    $gen2->getFullPath('../../evil.php');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('getFullPath 拒绝 ..', $threw);

$threw = false;
try {
    $gen2->getFullPath('a/../../b.php');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('getFullPath 拒绝中途的 ..', $threw);

check('getFullPath 正常相对路径拼接模块目录',
    $gen2->getFullPath('Models/Post.php') === '/tmp/xf_make_test/Modules/Demo/Models/Post.php');
check('getFullPath 绝对路径原样返回',
    $gen2->getFullPath('/tmp/abs/x.php') === '/tmp/abs/x.php');

$threw = false;
try {
    $gen2->generate('migration/create.stub', '../../evil.php', true);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('generate 对穿越路径抛出明确异常（而非静默 false）', $threw);
check('generate 穿越未在模块外落文件', ! file_exists('/tmp/xf_make_test/Modules/evil.php'));

// ======================================================================
echo "\n--- 4. MigrationMakeCommand 名称与路径校验 ---\n";

$probeMig = new ProbeMigrationCommand;

check('合法迁移名 create_posts_table', $probeMig->probeValidName('create_posts_table') === true);
check('合法迁移名 add-slug-to-posts', $probeMig->probeValidName('add-slug-to-posts') === true);
check('非法迁移名含穿越', $probeMig->probeValidName('../../evil') === false);
check('非法迁移名含斜杠', $probeMig->probeValidName('a/b') === false);
check('非法迁移名含反斜杠', $probeMig->probeValidName('a\\b') === false);
check('非法迁移名含点', $probeMig->probeValidName('evil.php') === false);
check('非法迁移名为空', $probeMig->probeValidName('') === false);
check('非法迁移名以下划线开头', $probeMig->probeValidName('_leading') === false);

check('containsParentSegment 识别 ..', $probeMig->probeParentSegment('a/../../b') === true);
check('containsParentSegment 放行普通路径', $probeMig->probeParentSegment('Database/Migrations') === false);

// --realpath 缺少 --path：必须返回 null（撞上 : string 返回类型会 TypeError）
$probeMig->setOptionOverrides(['path' => null, 'realpath' => true, 'fullpath' => false]);
$res = $probeMig->probeMigrationPath($module, ['path' => null, 'realpath' => true, 'fullpath' => false]);
check('--realpath 缺少 --path 时返回 null（不再 TypeError）', $res === null);

// --path 相对模块根目录，而不是相对 CWD
$probeMig->setOptionOverrides(['path' => 'Database/Extra', 'realpath' => false, 'fullpath' => false]);
$res = $probeMig->probeMigrationPath($module, ['path' => 'Database/Extra', 'realpath' => false, 'fullpath' => false]);
check('--path 相对模块根目录解析',
    $res === $root.'/Database/Extra',
    var_export($res, true));

// --fullpath --path=x 也必须落在模块内
$probeMig->setOptionOverrides(['path' => 'Database/Extra', 'realpath' => false, 'fullpath' => true]);
$res = $probeMig->probeMigrationPath($module, ['path' => 'Database/Extra', 'realpath' => false, 'fullpath' => true]);
check('--fullpath --path 仍落在模块目录内',
    $res === $root.'/Database/Extra',
    var_export($res, true));

// --path 含 .. 必须被拒
$probeMig->setOptionOverrides(['path' => '../../../evil', 'realpath' => false, 'fullpath' => false]);
$res = $probeMig->probeMigrationPath($module, ['path' => '../../../evil', 'realpath' => false, 'fullpath' => false]);
check('--path 含 .. 被拒绝', $res === null, var_export($res, true));

// 默认：模块 Database/Migrations
$probeMig->setOptionOverrides(['path' => null, 'realpath' => false, 'fullpath' => false]);
$res = $probeMig->probeMigrationPath($module, ['path' => null, 'realpath' => false, 'fullpath' => false]);
check('默认迁移目录为模块 Database/Migrations',
    $res === $root.'/Database/Migrations',
    var_export($res, true));

// --realpath + 绝对路径
$abs = '/tmp/xf_make_test/abs_migrations';
$probeMig->setOptionOverrides(['path' => $abs, 'realpath' => true, 'fullpath' => false]);
$res = $probeMig->probeMigrationPath($module, ['path' => $abs, 'realpath' => true, 'fullpath' => false]);
check('--realpath 使用绝对路径', $res === $abs, var_export($res, true));

// 迁移 stub 走 StubGenerator（支持发布自定义 stub）
// 先移除自定义 stub，确保此处验证的是「缺失时回退包内」这条链路
@unlink('/tmp/xf_make_test/empty-stubs/migration/create.stub');
$probeMig->useModule($module);
check('迁移 create stub 路径走 StubGenerator 回退链',
    norm(callProtected($probeMig, 'getStubPath', ['create'])) === $pkgStubs.'/migration/create.stub',
    callProtected($probeMig, 'getStubPath', ['create']));

// ======================================================================
echo "\n--- 5. MigrateFreshCommand 模块表名解析 ---\n";

$moduleDir = '/tmp/xf_make_test/Modules/Demo';
@mkdir($moduleDir.'/Database/Migrations', 0755, true);
foreach (glob($moduleDir.'/Database/Migrations/*.php') ?: [] as $f) {
    @unlink($f);
}

file_put_contents($moduleDir.'/Database/Migrations/2026_01_01_000001_create_posts_table.php', <<<'PHP'
<?php
return new class extends Illuminate\Database\Migrations\Migration {
    public function up(): void {
        Schema::create('posts', function ($t) {});
        Schema::create('post_tag', function ($t) {});
    }
    public function down(): void {
        Schema::dropIfExists('post_tag');
        Schema::dropIfExists('posts');
    }
};
PHP);

file_put_contents($moduleDir.'/Database/Migrations/2026_01_02_000002_add_slug_to_posts_table.php', <<<'PHP'
<?php
return new class extends Illuminate\Database\Migrations\Migration {
    public function up(): void {
        Schema::table('posts', function ($t) {});
    }
};
PHP);

// 另一个模块的表，不应被本模块解析出
@mkdir('/tmp/xf_make_test/Modules/Other/Database/Migrations', 0755, true);
file_put_contents('/tmp/xf_make_test/Modules/Other/Database/Migrations/2026_01_01_000001_create_users_table.php', <<<'PHP'
<?php
return new class extends Illuminate\Database\Migrations\Migration {
    public function up(): void { Schema::create('users', function ($t) {}); }
};
PHP);

$probeFresh = new ProbeFreshCommand;
$tables = $probeFresh->probeCollectTables($module);

check('解析出本模块的 2 张表', count($tables) === 2, json_encode($tables));
check('解析结果包含 posts', in_array('posts', $tables, true), json_encode($tables));
check('解析结果包含 post_tag', in_array('post_tag', $tables, true), json_encode($tables));
check('不包含其它模块的表 users', ! in_array('users', $tables, true), json_encode($tables));

$names = $probeFresh->probeCollectNames($module);
check('收集到 2 个迁移名', count($names) === 2, json_encode($names));
check('迁移名含时间戳前缀',
    in_array('2026_01_01_000001_create_posts_table', $names, true),
    json_encode($names));

// 关键回归断言：本包绝不能再出现单模块模式调用 migrate:fresh 的代码路径
$src = (string) file_get_contents(__DIR__.'/../src/Commands/MigrateFreshCommand.php');
$start = (int) strpos($src, 'protected function migrateModule(');
$end = (int) strpos($src, 'protected function collectModuleTables(');
$migrateModuleBody = substr($src, $start, $end - $start);
check('单模块模式不再调用 migrate:fresh（仅 migrate）',
    ! str_contains($migrateModuleBody, "'migrate:fresh'"),
    'migrateModule() 内出现了 migrate:fresh');
check('单模块模式只调用 migrate', str_contains($migrateModuleBody, "\$this->call('migrate'"));
// 全模块模式仍应保留 migrate:fresh（那才是它的正确语义）
$allStart = (int) strpos($src, 'protected function freshAllModules(');
check('全模块模式仍使用 migrate:fresh',
    str_contains(substr($src, $allStart), "\$this->call('migrate:fresh'"));

// ======================================================================
echo "\n--- 6. ModelMakeCommand 生成物正确性 ---\n";

$modelCmd = new ModelMakeCommand;

$columns = [
    ['name' => 'id', 'type' => 'bigint', 'null' => false, 'key' => 'PRI', 'default' => null, 'extra' => 'auto_increment', 'comment' => ''],
    ['name' => 'title', 'type' => 'varchar(255)', 'null' => false, 'key' => '', 'default' => '', 'extra' => '', 'comment' => '标题 */ 含注释终止符'],
    ['name' => 'body', 'type' => 'text', 'null' => true, 'key' => '', 'default' => 'it\'s ok', 'extra' => '', 'comment' => ''],
    ['name' => 'deleted_at', 'type' => 'timestamp', 'null' => true, 'key' => '', 'default' => null, 'extra' => '', 'comment' => ''],
    ['name' => 'created_at', 'type' => 'timestamp', 'null' => true, 'key' => '', 'default' => null, 'extra' => '', 'comment' => ''],
    ['name' => 'updated_at', 'type' => 'timestamp', 'null' => true, 'key' => '', 'default' => null, 'extra' => '', 'comment' => ''],
];

$content = callProtected($modelCmd, 'generateModelContent', [
    'Demo', 'Post', 'Modules', 'Models', 'posts', $columns,
]);

// 生成物必须是合法 PHP
$tmpFile = '/tmp/xf_make_test/Post.php';
file_put_contents($tmpFile, $content);
exec('php -l '.escapeshellarg($tmpFile).' 2>&1', $out, $code);
check('生成物 PHP 语法正确', $code === 0, implode("\n", $out));

// 直接把用户输入拼进 targetPath() 的命令必须前置实体名校验（避免异常栈回溯）
foreach (['ViewMakeCommand', 'RouteMakeCommand'] as $cmd) {
    $srcCmd = (string) file_get_contents(__DIR__.'/../src/Commands/'.$cmd.'.php');
    check("{$cmd} 已加入实体名前置校验", str_contains($srcCmd, 'isValidEntityName'));
}

// 路由 stub 也必须走 StubGenerator 回退链（支持发布自定义模板）
$routeSrc = (string) file_get_contents(__DIR__.'/../src/Commands/RouteMakeCommand.php');
check('RouteMakeCommand 路由 stub 走 StubGenerator',
    ! str_contains($routeSrc, "__DIR__.'/stubs/'"),
    'RouteMakeCommand 仍硬编码包内 stub 目录');

check('生成 $table 属性', str_contains($content, "protected \$table = 'posts';"));
check('检测到时间戳', str_contains($content, 'public $timestamps = true;'));
check('生成 SoftDeletes import', str_contains($content, 'use Illuminate\Database\Eloquent\SoftDeletes;'));
check('生成 SoftDeletes trait', str_contains($content, 'use SoftDeletes;'));
// 只在 $fillable 那一行上做断言（casts() 里本来就含 'created_at' 等键）
preg_match('/protected \$fillable = \[(.*?)\];/s', $content, $fm);
$fillableBody = $fm[1] ?? '';
check('主键不进入 $fillable', ! str_contains($fillableBody, "'id'"), $fillableBody);
check('时间戳不进入 $fillable', ! str_contains($fillableBody, "'created_at'"), $fillableBody);
check('软删除字段不进入 $fillable', ! str_contains($fillableBody, "'deleted_at'"), $fillableBody);
check('业务字段进入 $fillable', str_contains($fillableBody, "'title'"), $fillableBody);
check('deleted_at 被转义为数组键', str_contains($content, "'deleted_at' => 'datetime:Y-m-d H:i:s'"));
check('含单引号的默认值被正确转义', ! str_contains($content, "=> 'it's ok'"));
check('含注释终止符的注释未破坏 docblock', str_contains($content, '*\\/'));
check('PHPDoc 含 Carbon 类型', str_contains($content, '@property Carbon|null $deleted_at'));

// 子目录命名空间：'Models/Admin' 不得生成非法 namespace
$content2 = callProtected($modelCmd, 'generateModelContent', [
    'Demo', 'Post', 'Modules', 'Models/Admin', 'posts', [],
]);
file_put_contents($tmpFile, $content2);
exec('php -l '.escapeshellarg($tmpFile).' 2>&1', $out2, $code2);
check('子目录模型生成物 PHP 语法正确', $code2 === 0, implode("\n", $out2));
check('子目录命名空间用反斜杠分隔',
    str_contains($content2, 'namespace Modules\Demo\Models\Admin;'),
    substr($content2, 0, 200));

// 无 deleted_at 时不应生成 SoftDeletes
$noSoft = array_values(array_filter($columns, fn ($c) => $c['name'] !== 'deleted_at'));
$content3 = callProtected($modelCmd, 'generateModelContent', [
    'Demo', 'Tag', 'Modules', 'Models', 'tags', $noSoft,
]);
check('无 deleted_at 时不生成 SoftDeletes', ! str_contains($content3, 'SoftDeletes'));

// 缺 type 键的列不应触发 Undefined array key
$weird = [['name' => 'x']];
$content4 = callProtected($modelCmd, 'generateModelContent', [
    'Demo', 'Weird', 'Modules', 'Models', 'weirds', $weird,
]);
file_put_contents($tmpFile, $content4);
exec('php -l '.escapeshellarg($tmpFile).' 2>&1', $out4, $code4);
check('缺 type/nullable 键的列不产生语法/警告问题', $code4 === 0, implode("\n", $out4));

// Schema 门面探测：getFacadeRoot 上确实存在 getColumns
$container->instance('config', new ConfigRepository(['modules' => []]));
$app = new Container;
Container::setInstance($app);
check(
    '门面上不存在 getColumns 真实方法（历史缺陷的根因）',
    method_exists(Schema::class, 'getColumns') === false
);
check(
    '门面的解析目标（Builder）上存在 getColumns（修复后走这条分支）',
    method_exists(Builder::class, 'getColumns') === true
);

// 清理
array_map('unlink', glob($moduleDir.'/Database/Migrations/*.php') ?: []);
@rmdir($moduleDir.'/Database/Migrations');

// ======================================================================
echo "\n结果: {$pass} PASS / {$fail} FAIL\n";

exit($fail === 0 ? 0 : 1);
