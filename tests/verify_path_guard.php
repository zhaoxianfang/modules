<?php

declare(strict_types=1);

/**
 * 路径安全闸门（PathGuard）专项回归。
 *
 * 覆盖的真实事故：
 *   `<Modules>/Blog` 若是**符号链接**，`is_dir()` 为真、字面路径也在模块根之下，
 *   但 `Illuminate\Filesystem\Filesystem::deleteDirectory()` 内部是
 *   `new FilesystemIterator($directory)` —— 对软链目录会遍历并**删除链接目标的内容**。
 *   于是 `module:make Blog --force` 可能删掉 Modules 之外的整棵目录树。
 *
 * 运行：php tests/verify_path_guard.php（依赖宿主 vendor/autoload.php，默认 /Users/aha/www/wsf）
 * 退出码：0 = 全 PASS，1 = 有 FAIL。
 */

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use zxf\Modules\Support\PathGuard;

// ---------- 优先加载本包源码 ----------
$packageAutoloader = static function (): void {
    spl_autoload_register(static function (string $class): void {
        if (! str_starts_with($class, 'zxf\\Modules\\')) {
            return;
        }
        $file = '/Users/aha/www/modules/src/'.str_replace('\\', '/', substr($class, 11)).'.php';
        if (is_file($file)) {
            require $file;
        }
    }, true, true);
};

$host = rtrim(getenv('WSF_PATH') ?: '/Users/aha/www/wsf', '/').'/vendor/autoload.php';

if (! is_file($host)) {
    fwrite(STDERR, "未找到宿主 autoload：{$host}\n");
    exit(2);
}

$packageAutoloader();
require $host;
$packageAutoloader();
require_once '/Users/aha/www/modules/src/helper.php';

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

// ---------- 准备夹具 ----------
$base = '/tmp/xf_path_guard';
$modulesRoot = $base.'/Modules';
$outside = $base.'/Outside';

$rm = static function (string $dir) use (&$rm): void {
    if (! is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $p = $dir.'/'.$item;
        if (is_link($p)) {
            @unlink($p);
        } elseif (is_dir($p)) {
            $rm($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
};

$rm($base);
@mkdir($modulesRoot.'/Blog', 0755, true);
@mkdir($outside.'/precious', 0755, true);
file_put_contents($modulesRoot.'/Blog/keep.txt', 'keep');
file_put_contents($outside.'/precious/data.txt', 'must-not-be-deleted');

// 造一个「指向外部目录」的符号链接，模拟最危险的逃逸形态
$linkPath = $modulesRoot.'/Linked';
@symlink($outside, $linkPath);

$app = new Application($base);
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('config', new ConfigRepository([
    'modules' => ['path' => $modulesRoot, 'scan_paths' => []],
]));

$guard = new class
{
    use PathGuard;

    public function check(string $path, $roots, string $label = '目标路径'): ?string
    {
        return $this->guardAgainstEscape($path, $roots, $label);
    }

    public function inside(string $a, string $b): bool
    {
        return $this->isPathInside($a, $b);
    }

    public function roots(): array
    {
        return $this->trustedModuleRoots();
    }
};

// ======================================================================
echo "--- A. 夹具前提 ---\n";

check('A1 模块目录存在', is_dir($modulesRoot.'/Blog'));
check('A2 符号链接已建立', is_link($linkPath));
check('A3 软链解析后确实指向外部目录',
    is_dir($linkPath) && realpath($linkPath) === realpath($outside));

// ======================================================================
echo "\n--- B. 正常路径必须放行（不能误伤正常使用） ---\n";

check('B1 已存在的普通模块目录放行',
    $guard->check($modulesRoot.'/Blog', [$modulesRoot]) === null);
check('B2 尚未创建的新模块目录放行',
    $guard->check($modulesRoot.'/NewModule', [$modulesRoot]) === null);
check('B3 尾部多斜杠不误判',
    $guard->check($modulesRoot.'/Blog/', [$modulesRoot]) === null);
check('B4 反斜杠分隔符不误判（Windows 兼容）',
    $guard->check(str_replace('/', '\\', $modulesRoot.'/Blog'), [$modulesRoot]) === null);
check('B5 多个可信根时命中其一即放行',
    $guard->check($modulesRoot.'/Blog', ['/nonexistent', $modulesRoot]) === null);

// ======================================================================
echo "\n--- C. 越界路径必须拦截 ---\n";

$reason = $guard->check($outside, [$modulesRoot]);
check('C1 外部目录被拒绝', $reason !== null, '实际: '.var_export($reason, true));

$reason = $guard->check($modulesRoot.'/../../etc', [$modulesRoot]);
check('C2 ../ 穿越被拒绝', $reason !== null);
check('C3 拒绝理由含「超出受信任」', $reason !== null && str_contains($reason, '超出受信任'),
    '实际: '.var_export($reason, true));

// ======================================================================
echo "\n--- D. 符号链接逃逸必须拦截（本脚本的核心断言） ---\n";

$reason = $guard->check($linkPath, [$modulesRoot]);
check('D1 指向外部的软链被拒绝', $reason !== null, '实际: '.var_export($reason, true));
check('D2 拒绝理由明确指出是符号链接',
    $reason !== null && str_contains($reason, '符号链接'), '实际: '.var_export($reason, true));

check('D3 外部文件此刻仍存在（尚未被删除）', is_file($outside.'/precious/data.txt'));

// 证明「字面比较挡不住软链」这一前提，确保闸门确实补上了缺口
$literalInside = str_starts_with(
    str_replace('\\', '/', $linkPath).'/',
    str_replace('\\', '/', $modulesRoot).'/'
);
check('D4 字面前缀比较确实会误判为「安全」（闸门的必要性成立）', $literalInside);

// ======================================================================
echo "\n--- E. trustedModuleRoots 取自配置 ---\n";

$roots = $guard->roots();
check('E1 返回配置的 modules.path', in_array($modulesRoot, $roots, true),
    '实际: '.json_encode($roots, JSON_UNESCAPED_UNICODE));

$app->make('config')->set('modules.scan_paths', [$base.'/Extra']);
$roots = $guard->roots();
check('E2 scan_paths 也纳入可信根', in_array($base.'/Extra', $roots, true));

// ======================================================================
echo "\n--- F. isPathInside（展示用前缀判断） ---\n";

check('F1 子目录判断为在内', $guard->inside($modulesRoot.'/Blog', $modulesRoot));
check('F2 相等也算在内', $guard->inside($modulesRoot, $modulesRoot));
// isPathInside 是「不做 realpath 的前缀判断」（仅用于展示），
// 因此不能用来判 ../ —— 那是 guardAgainstEscape 的职责。
check('F3 兄弟目录（前缀相似）判为在外', ! $guard->inside($modulesRoot.'Extra', $modulesRoot));
check('F4 ../ 场景由闸门而非前缀判断处理',
    $guard->check($modulesRoot.'/Blog/../Blog', [$modulesRoot]) === null
    && $guard->check($modulesRoot.'/Blog/../../Outside', [$modulesRoot]) !== null);

// ---------- 清理 ----------
$rm($base);

// ---------- 汇总 ----------
echo "\n==============================================\n";
echo "结果: {$pass} PASS / {$fail} FAIL\n";
echo "==============================================\n";

exit($fail === 0 ? 0 : 1);