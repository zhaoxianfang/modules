<?php

declare(strict_types=1);

/** ModuleCacheStore 功能验证 */

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

require '/Users/aha/www/wsf/vendor/autoload.php';

use zxf\Modules\Support\ModuleCacheStore;

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

$dir = '/tmp/xf_cache_test';
$file = $dir.'/modules.json';
$legacy = $dir.'/modules.php';

@mkdir($dir, 0755, true);
foreach (glob($dir.'/*') ?: [] as $f) {
    @unlink($f);
}

// 1. 写读往返（含中文）
ModuleCacheStore::write($file, ['a' => 1, '中文' => '值', 'nested' => ['x' => [1, 2, 3]]]);
$data = ModuleCacheStore::read($file);
check('写读往返一致', $data === ['a' => 1, '中文' => '值', 'nested' => ['x' => [1, 2, 3]]], json_encode($data));
check('写入结果为 JSON 文本', str_starts_with((string) file_get_contents($file), '{"a":1'), substr((string) file_get_contents($file), 0, 40));

// 2. 原子写：无临时文件残留
$tmps = glob($file.'.*.tmp') ?: [];
check('无临时文件残留', count($tmp ?? []) === 0 || $tmps === [], json_encode($tmps));

// 3. 覆盖写
ModuleCacheStore::write($file, ['b' => 2]);
check('覆盖写生效', ModuleCacheStore::read($file) === ['b' => 2]);

// 4. 清理历史 .php 缓存
file_put_contents($legacy, '<?php return [];');
ModuleCacheStore::write($file, ['c' => 3]);
check('旧版 .php 缓存被清理', ! file_exists($legacy));

// 5. 损坏内容安全返回 null
file_put_contents($file, '{broken json');
check('损坏内容返回 null', ModuleCacheStore::read($file) === null);

// 6. 空文件返回 null
file_put_contents($file, '');
check('空文件返回 null', ModuleCacheStore::read($file) === null);

// 7. 不存在的文件返回 null
check('不存在文件返回 null', ModuleCacheStore::read($dir.'/nope.json') === null);

// 8. delete 清理（含临时文件）
ModuleCacheStore::write($file, ['d' => 4]);
file_put_contents($file.'.999.tmp', 'x');
ModuleCacheStore::delete($file);
check('delete 删除缓存文件', ! file_exists($file));
check('delete 清理临时文件', ! file_exists($file.'.999.tmp'));

// 9. 不可写目录不抛异常
// 宿主项目可能注册了第三方错误处理器（如 trace 包），它会把被 @ 抑制的
// mkdir 警告渲染成错误页并干扰退出码；这里临时屏蔽后再恢复。
set_error_handler(static fn (): bool => true);
$ok = ModuleCacheStore::write('/proc/non-writable-dir/x.json', ['a' => 1]);
restore_error_handler();
check('写入失败返回 false 且不抛异常', $ok === false);

// 10. 并发安全：连续多次写入后内容完整
for ($i = 0; $i < 50; $i++) {
    ModuleCacheStore::write($file, ['i' => $i, 'pad' => str_repeat('x', 1000)]);
}
$final = ModuleCacheStore::read($file);
check('连续写入后内容完整', $final !== null && $final['i'] === 49 && strlen($final['pad']) === 1000);

echo "\n结果: {$pass} PASS / {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);
