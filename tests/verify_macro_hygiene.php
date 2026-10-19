<?php

declare(strict_types=1);

/**
 * 宏工程卫生守护测试（全包通用，不针对单个宏类）。
 *
 * 本轮在向量宏与 whereHas 审计中真实踩到过两类「静默失效」缺陷，二者都不会报错、
 * 只表现为功能神秘消失或直接 OOM，因此固化为守护测试：
 *
 *  1. **宏名遮蔽**：`Macroable` 下同名宏会被框架原生方法**完全遮蔽、永不触发**。
 *     本轮 `selectVectorDistance` 正撞上 Laravel 13 的原生
 *     `Query\Builder::selectVectorDistance()` —— `DB::table(...)` 会静默走原生实现。
 *     `BaseMacro::define()` 已有 hasNativeMethod 防护，但仍有代码直接调用
 *     `Builder::macro()`（如 whereHasIn），绕过了该防护，故此处做**全包静态 + 运行时双重扫描**。
 *
 *  2. **占位式注释与残缺示例**：`@scenario 本类/成员说明。`、空回调 `function ($q) {}`
 *     这类占位物会让文档看起来「已写完」，实则零信息量；空回调示例还会让用户复制到
 *     自己的代码里得到无任何效果的链式调用。
 *
 * 运行：php tests/verify_macro_hygiene.php
 * 退出码：0 = 全 PASS，1 = 有 FAIL，2 = 宿主不可用（SKIP）。
 */
$hostRoot = getenv('MODULES_TEST_HOST') ?: '/Users/aha/www/wsf';

if (! is_file($hostRoot.'/vendor/autoload.php')) {
    fwrite(STDERR, "SKIP: 宿主 {$hostRoot} 不可用\n");
    exit(2);
}

require $hostRoot.'/vendor/autoload.php';

// Composer 的 ClassLoader 以 prepend=true 注册，shim 必须在它之后 prepend，
// 否则类会全部从宿主 vendor 的旧副本加载。
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

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

$srcDir = '/Users/aha/www/modules/src/BuilderQuery';

$probe = new ReflectionClass(\zxf\Modules\ModulesServiceProvider::class);
if (! str_starts_with((string) $probe->getFileName(), '/Users/aha/www/modules/src/')) {
    fwrite(STDERR, "FATAL: 类加载自宿主旧副本，无法验证工作区代码\n");
    exit(2);
}

$pass = 0;
$fail = 0;

function check(bool $ok, string $label, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "PASS  {$label}\n";
    } else {
        $fail++;
        echo "FAIL  {$label}".($detail !== '' ? "  ← {$detail}" : '')."\n";
    }
}

// ---------------------------------------------------------------------------
// A. 静态扫描：所有注册点声明的宏名 vs 框架原生方法
// ---------------------------------------------------------------------------

$sourceFiles = [];
foreach (glob($srcDir.'/*Macros/*.php') ?: [] as $f) {
    $sourceFiles[] = $f;
}
$macrosBuilder = $srcDir.'/MacrosBuilder.php';
if (is_file($macrosBuilder)) {
    $sourceFiles[] = $macrosBuilder;
}

check(count($sourceFiles) >= 30, '宏源文件全部可枚举', '仅找到 '.count($sourceFiles).' 个');

$nativeByBuilder = [
    'query' => [],
    'eloq' => [],
];
foreach (['query' => QueryBuilder::class, 'eloq' => EloquentBuilder::class] as $key => $class) {
    foreach ((new ReflectionClass($class))->getMethods() as $m) {
        $nativeByBuilder[$key][strtolower($m->getName())] = true;
    }
}

// 三种注册写法都要覆盖：
//   define('name', ...)                      —— BaseMacro::define（有遮蔽防护）
//   Builder::macro('name', ...)              —— Eloquent Builder
//   QueryBuilder::macro('name', ...)         —— Query Builder
// 解析 define('name', function (...) {...}, 'alias') 的宏名与可选 alias（第三参数）。
// 必须做括号配对而非正则贪婪匹配，否则闭包内部的 `}, 'xxx')` 会造成误判。
function parseDefines(string $src, string $file): array
{
    $out = [];
    $len = strlen($src);
    $offset = 0;

    while (preg_match('/\bdefine\(\s*\'([A-Za-z_][A-Za-z0-9_]*)\'/', $src, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $name = $m[1][0];
        // 正则匹配的是 `define('name'`，左括号需从 define 之后单独定位
        $start = strpos($src, '(', $m[0][1] + strlen('define'));
        if ($start === false) {
            break;
        }
        $depth = 0;
        $inStr = null;
        $i = $start;
        for (; $i < $len; $i++) {
            $ch = $src[$i];

            // 跳过注释：docblock 里满是 function(...) / {} 括号，会打乱配对计数
            if ($inStr === null && $ch === '/' && $i + 1 < $len) {
                $next = $src[$i + 1];
                if ($next === '*') {
                    $end = strpos($src, '*/', $i + 2);
                    $i = $end === false ? $len : $end + 1;

                    continue;
                }
                if ($next === '/') {
                    $end = strpos($src, "\n", $i + 2);
                    $i = $end === false ? $len : $end;

                    continue;
                }
            }

            if ($inStr !== null) {
                if ($ch === '\\') {
                    $i++;
                } elseif ($ch === $inStr) {
                    $inStr = null;
                }

                continue;
            }
            if ($ch === '\'' || $ch === '"') {
                $inStr = $ch;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
        }
        if ($depth !== 0) {
            break; // 括号未闭合，放弃解析剩余部分
        }
        $args = substr($src, $start + 1, $i - $start - 1);

        // 按顶层逗号切分第三段（需跳过字符串与嵌套括号内的逗号）
        $parts = [];
        $buf = '';
        $d = 0;
        $q = null;
        for ($j = 0, $n = strlen($args); $j < $n; $j++) {
            $ch = $args[$j];
            if ($q !== null) {
                $buf .= $ch;
                if ($ch === '\\') {
                    $buf .= $args[++$j] ?? '';
                } elseif ($ch === $q) {
                    $q = null;
                }

                continue;
            }
            if ($ch === '\'' || $ch === '"') {
                $q = $ch;
                $buf .= $ch;
            } elseif ($ch === '(' || $ch === '[') {
                $d++;
                $buf .= $ch;
            } elseif ($ch === ')' || $ch === ']') {
                $d--;
                $buf .= $ch;
            } elseif ($ch === ',' && $d === 0) {
                $parts[] = $buf;
                $buf = '';
            } else {
                $buf .= $ch;
            }
        }
        $parts[] = $buf;

        $alias = null;
        if (count($parts) >= 3) {
            $third = trim($parts[2]);
            if (preg_match('/^\'([A-Za-z_][A-Za-z0-9_]*)\'$/', $third, $am)) {
                $alias = $am[1];
            }
        }
        $out[$name] = ['alias' => $alias, 'file' => basename($file)];

        $offset = $i + 1;
    }

    return $out;
}

$declared = [];
foreach ($sourceFiles as $file) {
    $src = file_get_contents($file) ?: '';
    $base = basename($file);

    foreach (parseDefines($src, $file) as $name => $info) {
        $declared[$name]['define'][] = $base;
        if ($info['alias'] !== null) {
            $declared[$name]['alias'] = $info['alias'];
        }
    }

    // 直接 Builder::macro() 的注册目标取决于该文件的 use：
    // MacrosBuilder.php 里 `use Illuminate\Database\Eloquent\Builder`，故 Builder = Eloquent。
    // 只有该文件存在直接注册，故可安全按 eloq 判定。
    $directTarget = str_contains($base, 'MacrosBuilder') ? 'eloq' : null;
    if ($directTarget !== null && preg_match_all('/\bBuilder::macro\(\s*\'([A-Za-z_][A-Za-z0-9_]*)\'/', $src, $m)) {
        foreach ($m[1] as $name) {
            $declared[$name]['direct'][$directTarget][] = $base;
        }
    }
    if (preg_match_all('/\bQueryBuilder::macro\(\s*\'([A-Za-z_][A-Za-z0-9_]*)\'/', $src, $m)) {
        foreach ($m[1] as $name) {
            $declared[$name]['direct']['query'][] = $base;
        }
    }
}

check(count($declared) >= 150, '静态扫描到的宏名数量合理', '仅 '.count($declared).' 个（阈值 150）');

$shadowed = [];
$aliased = 0;
foreach ($declared as $name => $info) {
    $lower = strtolower($name);

    // define() 注册到双构建器；直接 ::macro() 只注册到其 use 所指的那个构建器。
    // 只有「注册目标构建器」上存在原生同名方法才算遮蔽 —— 例如
    // MacrosBuilder 用 Builder(=Eloquent)::macro 注册 whereVectorSimilarTo，
    // 而原生方法只在 Query\Builder 上，属刻意的 Eloquent 桥接，不是缺陷。
    $targets = [];
    if (isset($info['define'])) {
        $targets = ['query', 'eloq'];
    }
    foreach (($info['direct'] ?? []) as $k => $files) {
        $targets[] = $k;
    }
    $targets = array_values(array_unique($targets));

    $collides = array_values(array_filter(
        $targets,
        static fn (string $k): bool => isset($nativeByBuilder[$k][$lower])
    ));
    if ($collides === []) {
        continue;
    }

    // define() 有 hasNativeMethod 防护：原生优先 + 可选 alias 出口，这是合法设计；
    // 但若与原生同名却没给 alias，Query\Builder 用户就彻底拿不到本包能力（静默失效）。
    if (isset($info['alias'])) {
        $aliased++;
        foreach (['query', 'eloq'] as $k) {
            if (isset($nativeByBuilder[$k][strtolower($info['alias'])])) {
                $shadowed[] = sprintf("alias '%s'（%s 的出口）自身又被原生 [%s] 方法占用", $info['alias'], $name, $k);
            }
        }

        continue;
    }

    $shadowed[] = sprintf(
        '%s <- %s（与原生 [%s] 同名但未提供 alias 出口）',
        $name,
        implode(',', array_unique(array_merge($info['define'] ?? [], array_merge(...array_values($info['direct'] ?? []))))),
        implode('/', $collides)
    );
}

check(
    $shadowed === [],
    '无宏名被框架原生方法静默遮蔽（含 alias 出口校验）',
    implode(' | ', array_slice($shadowed, 0, 5))
);

check($aliased >= 4, '与原生同名的宏均提供了 alias 出口', "实际 {$aliased} 个");

// ---------------------------------------------------------------------------
// B. 运行时扫描：真实注册后的宏数组 vs 框架原生方法
// ---------------------------------------------------------------------------

$registered = 0;
foreach (glob($srcDir.'/*Macros/*.php') ?: [] as $file) {
    $class = 'zxf\\Modules\\BuilderQuery\\'.basename(dirname($file)).'\\'.basename($file, '.php');
    if (! class_exists($class)) {
        continue;
    }
    $rc = new ReflectionClass($class);
    if (! $rc->hasMethod('register')) {
        continue;
    }
    $rm = $rc->getMethod('register');
    if ($rm->getNumberOfRequiredParameters() > 0) {
        continue; // 需 ServiceProvider 的由 MacrosBuilder 内部调用，不单独注册
    }
    $rm->setAccessible(true);
    try {
        $rm->invoke(null);
        $registered++;
    } catch (Throwable $e) {
        $short = basename(str_replace('\\', '/', $class));
        check(false, "宏类可注册：{$short}", $e->getMessage());
    }
}

check($registered >= 25, '宏类注册执行成功（>=25 个）', "实际 {$registered} 个");

$runtimeShadow = [];
$macroCounts = [];
foreach (['query' => QueryBuilder::class, 'eloq' => EloquentBuilder::class] as $key => $class) {
    $rc = new ReflectionClass($class);
    $prop = $rc->getProperty('macros');
    $prop->setAccessible(true);
    $names = array_keys($prop->getValue() ?: []);
    $macroCounts[$key] = count($names);
    foreach ($names as $name) {
        if (isset($nativeByBuilder[$key][strtolower($name)])) {
            $runtimeShadow[] = "{$name}[{$key}]";
        }
    }
}

check($macroCounts['query'] >= 150, 'Query\\Builder 宏注册数合理', '实际 '.$macroCounts['query']);
check($macroCounts['eloq'] >= 150, 'Eloquent\\Builder 宏注册数合理', '实际 '.$macroCounts['eloq']);
check(
    $runtimeShadow === [],
    '无宏名被框架原生方法遮蔽（运行时扫描）',
    implode(' | ', array_slice($runtimeShadow, 0, 5))
);

// alias 不能只是「声明了」：对每个构建器，该宏的能力都必须可达
//（宏名本身可用，或被原生占用时经 alias 出口可用）。
// 注意不能要求 alias 两侧都注册 —— define() 的设计是「能用宏名就用宏名，
// 被原生占用的那一侧才注册 alias」，所以 alias 只出现在被占用的一侧是正常的。
$unreachable = [];
foreach ($declared as $name => $info) {
    $lower = strtolower($name);
    $entry = ['query' => null, 'eloq' => null];
    foreach ($entry as $k => $_) {
        $rc = new ReflectionClass($k === 'query' ? QueryBuilder::class : EloquentBuilder::class);
        $prop = $rc->getProperty('macros');
        $prop->setAccessible(true);
        $macros = $prop->getValue() ?: [];

        if (isset($macros[$name])) {
            $entry[$k] = $name;
        } elseif (isset($info['alias']) && isset($macros[$info['alias']])) {
            $entry[$k] = $info['alias'];
        } elseif (isset($nativeByBuilder[$k][$lower])) {
            // 该侧由框架原生方法提供同名能力。define() 注册时这正是「原生优先」的预期；
            // 直接 ::macro() 单侧注册时（如 MacrosBuilder 用 Eloquent Builder 注册
            // whereVectorSimilarTo），也是刻意的「Eloquent 桥接、Query 走原生」。
            $entry[$k] = '(原生方法)';
        } else {
            $entry[$k] = '(未注册且无原生同名)';
        }
    }

    foreach ($entry as $k => $via) {
        // define() 声称注册到双构建器，两侧必须都有（宏名或 alias）；漏注册一侧即静默缺能力。
        // 直接 ::macro() 只承诺注册目标那一侧，另一侧由原生方法负责，不在此列。
        if (isset($info['define']) && $via === '(未注册且无原生同名)') {
            $unreachable[] = "{$name} 在 [{$k}] 上既未注册宏、也无 alias 与原生兜底";
        }
    }
}

check(
    $unreachable === [],
    '与原生同名的宏在两个构建器上均可达（宏名或 alias）',
    implode(' | ', array_slice($unreachable, 0, 5))
);

// whereHasIn 直接用 Builder::macro() 注册、绕过了 define() 的 hasNativeMethod 防护，
// 属于「无防护注册点」，必须确认它确实落在已声明集合里（否则 A 段静态扫描会漏检它）。
check(
    isset($declared['whereHasIn']),
    'whereHasIn（绕过 define 防护的注册点）已被静态扫描覆盖'
);
check(
    ! in_array(strtolower('whereHasIn'), array_keys($nativeByBuilder['eloq']), true),
    'whereHasIn 当前未被 Eloquent\\Builder 原生方法占用'
);

// ---------------------------------------------------------------------------
// C. 占位式注释 / 残缺示例
// ---------------------------------------------------------------------------

$placeholderPatterns = [
    '占位式 @scenario' => '/@scenario\s*(本类|成员|该类|本组件|此处)[^\n]{0,20}说明[。.]/',
    '占位式 TODO 描述' => '/@(todo|example)\s*(本类|成员|该类|此处)[^\n]{0,20}(说明|待补)[。.]/',
];

$placeholderHits = [];
foreach (array_merge($sourceFiles, glob($srcDir.'/*.php') ?: []) as $file) {
    $src = file_get_contents($file) ?: '';
    foreach ($placeholderPatterns as $label => $pattern) {
        if (preg_match_all($pattern, $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$text, $offset]) {
                $line = substr_count(substr($src, 0, $offset), "\n") + 1;
                $placeholderHits[] = sprintf('%s:%d %s', basename($file), $line, trim($text));
            }
        }
    }
}

check($placeholderHits === [], '无占位式注释残留', implode(' | ', array_slice($placeholderHits, 0, 5)));

// 空回调示例：文档里的 `function ($q) {}` 会让使用者复制到无效果的链式调用。
// 仅在 docblock（/** ... */）内判定，避免误伤正常的空闭包实现分支。
$emptyCallbackHits = [];
foreach ($sourceFiles as $file) {
    $src = file_get_contents($file) ?: '';
    if (! preg_match_all('/\/\*\*(.*?)\*\//s', $src, $blocks, PREG_OFFSET_CAPTURE)) {
        continue;
    }
    foreach ($blocks[0] as $block) {
        [$text, $offset] = $block;
        $startLine = substr_count(substr($src, 0, $offset), "\n") + 1;
        // 去掉行内的 // 注释后再匹配，避免注释掉的历史代码造成误报
        $stripped = preg_replace('#^\s*\*?\s*//.*$#m', '', $text) ?? $text;
        if (preg_match_all('/function\s*\(\s*\$[A-Za-z_][A-Za-z0-9_]*\s*\)\s*:\s*[A-Za-z\\\\|?]*\s*\{\s*\}/', $stripped, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) {
                $line = $startLine + substr_count(substr($stripped, 0, $hit[1]), "\n");
                $emptyCallbackHits[] = sprintf('%s:%d %s', basename($file), $line, trim($hit[0]));
            }
        }
    }
}

check($emptyCallbackHits === [], '文档中无残缺空回调示例', implode(' | ', array_slice($emptyCallbackHits, 0, 5)));

// ---------------------------------------------------------------------------
// D. 累赘注释：@scenario 复述首行描述 / */ 前冗余空星号 / 注释掉的死代码
// ---------------------------------------------------------------------------

// @scenario 与 docblock 描述行字面量完全相同时属零增量（信息已在上方描述里），
// 本轮清理了 180 处。保留近似但不同的 @scenario（那是有独立信息的精简说明）。
$redundantScenario = [];
$trailingStar = [];
foreach (array_merge($sourceFiles, glob($srcDir.'/*.php') ?: []) as $file) {
    $lines = file($file) ?: [];
    foreach ($lines as $i => $line) {
        // D1: */ 前的空星号行
        if (trim($line) === '*/' && preg_match('#^\s*\*\s*$#', $lines[$i - 1] ?? '')) {
            $trailingStar[] = sprintf('%s:%d', basename($file), $i);
        }

        // D2: @scenario 复述
        if (! preg_match('#^\s*\*\s*@scenario\s+(.+?)\s*$#u', $line, $m)) {
            continue;
        }
        $raw = trim($m[1]);
        $norm = static fn (string $s): string => (string) preg_replace('/[\s`*\-—（）()：:，,、。]/u', '', $s);
        if (mb_strlen($norm($raw)) < 6) {
            continue;
        }
        // 向上收集同一 docblock 内的描述行（跳过空星号、空行、标签行）
        $descs = [];
        for ($j = $i - 1; $j >= 0; $j--) {
            $t = trim($lines[$j]);
            if ($t === '' || $t === '*' || $t === '*/') {
                continue;
            }
            if (str_starts_with($t, '/**')) {
                break;
            }
            if (preg_match('#^\s*\*\s*@#', $t)) {
                continue;
            }
            if (preg_match('#^\s*\*\s*(\S.*)$#u', $t, $d)) {
                $descs[] = trim($d[1]);

                continue;
            }
            break;
        }
        if (in_array($raw, $descs, true)) {
            $redundantScenario[] = sprintf('%s:%d %s', basename($file), $i + 1, mb_substr($raw, 0, 40));
        }
    }
}

check(
    $redundantScenario === [],
    '无 @scenario 复述 docblock 描述（零增量注释）',
    implode(' | ', array_slice($redundantScenario, 0, 5))
);
check(
    $trailingStar === [],
    'docblock 的 */ 前无冗余空星号行',
    implode(' | ', array_slice($trailingStar, 0, 5))
);

// 注释掉的死代码：`// if (...)` / `// }` 之类。注意排除「解释某变量为何如此」的行内说明
// （它们以 $var 开头但后半句是中文解释），故要求整行看起来就是可执行语句。
$deadCode = [];
foreach (array_merge($sourceFiles, glob($srcDir.'/*.php') ?: []) as $file) {
    foreach (file($file) ?: [] as $i => $line) {
        $t = trim($line);
        if (! preg_match('#^//\s*(.*)$#', $t, $m)) {
            continue;
        }
        $body = trim($m[1]);
        // 整行形如代码语句（而非「$var 的中文解释」）
        if (preg_match('#^(\}|\{|\$this->[A-Za-z_]+\(|return\b|if\s*\(|foreach\s*\(|for\s*\(|while\s*\(|else\b)#', $body)) {
            $deadCode[] = sprintf('%s:%d %s', basename($file), $i + 1, mb_substr($body, 0, 50));
        }
    }
}

check(
    $deadCode === [],
    '无注释掉的死代码',
    implode(' | ', array_slice($deadCode, 0, 5))
);

echo "\n结果: {$pass} PASS / {$fail} FAIL\n";

exit($fail === 0 ? 0 : 1);
