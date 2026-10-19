<?php

declare(strict_types=1);

/**
 * 本轮「全面加固」的**真实 SQL 端到端**回归。
 *
 * 与其余 verify_* 的差别：这里不是读源码做字符串断言，而是在一个真实的
 * Laravel 13 + MySQL 宿主上把本轮修复的宏真正跑一遍，验证它们
 * **能执行、结果正确、Eloquent 与 DB::table() 双路径等价**。
 *
 * 覆盖本轮修复的、此前"调用即 Fatal / 静默给错结果"的问题：
 *   A. pivot / pivotSum 等 6 个透视宏   —— 原引用未定义变量，100% Fatal
 *   B. crossTab                       —— 原 self:: 在宏闭包内解析成 Builder，100% Fatal
 *   C. whereTopNPerGroup              —— 原对已 wrap 的列二次 wrap，SQL 语法错误
 *   D. lastValue / nthValue           —— 原缺窗口帧，返回值恒等于当前行（静默给错结果）
 *   E. cursorPaginate                 —— 原匿名类 implements CursorPaginator，实例化即 Fatal
 *   F. seekPaginate 书签区间           —— 原双闭区间，跨页重复行
 *   G. 延迟关联分页                   —— 原丢失 orderBy，同页两次请求可能错序
 *   H. 绑定错位回归                   —— selectRaw 绑定 + 清 columns 后是否仍对齐
 *   I. sampleRandom / stratifiedSample —— 原清 columns 不清绑定
 *   J. 多构建器等价                   —— DB::table() 路径不得再出现 null 解引用
 *
 * 运行：php tests/verify_sql_endtoend.php
 * 退出码：0 = 全 PASS，1 = 有 FAIL，2 = 宿主/数据库不可用（SKIP）。
 */
$hostRoot = getenv('MODULES_TEST_HOST') ?: '/Users/aha/www/wsf';

if (! is_file($hostRoot.'/vendor/autoload.php')) {
    fwrite(STDERR, "SKIP: 宿主 {$hostRoot} 不可用\n");
    exit(2);
}

// 让 zxf\Modules 指向本工作区源码（宿主 vendor 下是已安装的旧副本）
//
// 注意注册顺序：Composer 的 ClassLoader 在 require autoload.php 时是以
// **prepend = true** 注册的，所以本 shim 必须在 require 之后再 prepend，
// 否则会被 Composer 反压到后面，类全部从 wsf/vendor 的旧副本加载 ——
// 那样测的就不是本工作区的代码了（曾因此把旧代码的报错当成本轮缺陷）。
require $hostRoot.'/vendor/autoload.php';

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

// 自检：确保确实加载的是工作区源码，而不是宿主里的旧副本
$probe = new ReflectionClass(\zxf\Modules\ModulesServiceProvider::class);
if (! str_starts_with((string) $probe->getFileName(), '/Users/aha/www/modules/src/')) {
    fwrite(STDERR, 'FATAL: 类加载自宿主旧副本 '.$probe->getFileName()."，无法验证工作区代码\n");
    exit(2);
}
echo "源码加载自: ".dirname((string) $probe->getFileName(), 2)."\n";

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

$pass = 0;
$fail = 0;
$skip = 0;

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

function skip(string $label, string $why): void
{
    global $skip;
    $skip++;
    echo "SKIP  {$label}  ← {$why}\n";
}

/** 捕获异常并转成断言结果，避免一处 Fatal 掩盖后续全部用例 */
function throws(string $label, callable $fn, ?string $expectClass = null): void
{
    try {
        $fn();
        check(false, $label, '未抛出预期异常');
    } catch (Throwable $e) {
        check(
            $expectClass === null || $e instanceof $expectClass,
            $label,
            '抛出 '.$e::class.': '.$e->getMessage()
        );
    }
}

$app = require $hostRoot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $driver = DB::connection()->getDriverName();
    DB::connection()->getPdo();
} catch (Throwable $e) {
    fwrite(STDERR, 'SKIP: 数据库不可用 — '.$e->getMessage()."\n");
    exit(2);
}

if ($driver !== 'mysql') {
    fwrite(STDERR, "SKIP: 本脚本依赖 MySQL 语义（当前 {$driver}）\n");
    exit(2);
}

// ──────────────────────────── 夹具 ────────────────────────────
$table = 'xbq_e2e_fixtures';

DB::statement("DROP TABLE IF EXISTS {$table}");
DB::statement(
    "CREATE TABLE {$table} (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        region VARCHAR(16) NOT NULL,
        status VARCHAR(16) NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        seq INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$rows = [
    ['A', 'pending', 10, 1], ['A', 'paid', 20, 2], ['A', 'pending', 30, 3],
    ['B', 'paid', 40, 4], ['B', 'pending', 50, 5], ['B', 'paid', 60, 6],
    ['C', 'pending', 70, 7], ['C', 'paid', 80, 8],
    // 同 region+status 下的并列 seq（构造并列排序值，检验书签区间与二级排序）
    ['A', 'paid', 5, 9], ['A', 'paid', 5, 10],
];
DB::table($table)->insert(array_map(
    static fn ($r) => array_combine(['region', 'status', 'amount', 'seq'], $r),
    $rows
));

echo "夹具就绪：{$table}（".count($rows)." 行，驱动 {$driver}）\n\n";

// 定义一个用于 Eloquent 路径的临时模型
$modelClass = 'XfqE2eFixture';
if (! class_exists($modelClass, false)) {
    eval('class '.$modelClass.' extends Illuminate\Database\Eloquent\Model {
        protected $table = "'.$table.'";
        public $timestamps = false;
        protected $guarded = [];
    }');
}

// ─────────────────────── A. pivot 家族（原 100% Fatal） ───────────────────────
echo "— A. pivot 透视家族 —\n";

try {
    $pivoted = $modelClass::query()
        ->pivot('status', ['pending', 'paid'], 'amount', 'SUM', 'region')
        ->get();

    check(count($pivoted) === 3, 'pivot 返回 3 个分组行', '实际 '.count($pivoted));

    // 列名以「所有行」的属性并集为准（首行只含本组的透视列不严谨）
    $allKeys = [];
    foreach ($pivoted as $row) {
        $allKeys = array_merge($allKeys, array_keys($row->getAttributes()));
    }
    $allKeys = array_unique($allKeys);
    check(
        in_array('pending', $allKeys, true) && in_array('paid', $allKeys, true) && in_array('region', $allKeys, true),
        'pivot 同时产生了 region / pending / paid 三个列',
        '实际列：'.implode(',', $allKeys)
    );

    // 夹具中 region=A：pending = 10+30 = 40，paid = 20+5+5 = 30
    $aRow = $pivoted->first(static fn ($r) => ($r->region ?? null) === 'A');
    check(
        $aRow !== null && (float) $aRow->pending === 40.0 && (float) $aRow->paid === 30.0,
        'A 组透视数值正确（pending=10+30=40，paid=20+5+5=30）',
        $aRow === null ? '未找到 A 组' : 'pending='.$aRow->pending.' paid='.$aRow->paid
    );
} catch (Throwable $e) {
    check(false, 'pivot 可执行', $e::class.': '.$e->getMessage());
}

// pivotSum 快捷方法（原先一并 Fatal）
try {
    $sums = $modelClass::query()->pivotSum('status', ['paid'], 'amount', 'region')->get();
    check(count($sums) === 3, 'pivotSum 可执行且返回 3 组', '实际 '.count($sums));
} catch (Throwable $e) {
    check(false, 'pivotSum 可执行', $e::class.': '.$e->getMessage());
}

// pivot 的值绑定必须落在正确位置（select 绑定不能错位）
try {
    $withBind = $modelClass::query()
        ->where('status', 'pending')
        ->pivot('status', ['pending', 'paid'], 'amount', 'SUM', 'region')
        ->get();
    check(count($withBind) === 3, 'pivot 与既有 where 条件共存（绑定未错位）', '实际 '.count($withBind));
} catch (Throwable $e) {
    check(false, 'pivot 与 where 共存', $e::class.': '.$e->getMessage());
}

// 调用方此前用过带绑定的 selectRaw —— pivot 必须清掉旧 select 绑定
try {
    $replaced = $modelClass::query()
        ->selectRaw('UPPER(?) as tag', ['zz'])
        ->pivot('status', ['paid'], 'amount', 'SUM', 'region')
        ->get();
    check(count($replaced) === 3, 'pivot 替换 select 并清掉旧 select 绑定（无绑定错位）', '实际 '.count($replaced));
} catch (Throwable $e) {
    check(false, 'pivot 覆盖带绑定的 select', $e::class.': '.$e->getMessage());
}

// ─────────────────────── B. crossTab（原 100% Fatal） ───────────────────────
echo "\n— B. crossTab 交叉表 —\n";

try {
    $ct = $modelClass::query()->crossTab('region', 'status', 'amount', 'SUM')->get();
    check(count($ct) === 3, 'crossTab 自动推断列值并返回 3 行', '实际 '.count($ct));
} catch (Throwable $e) {
    check(false, 'crossTab 可执行', $e::class.': '.$e->getMessage());
}

// ─────────────── C. whereTopNPerGroup（原二次 wrap，语法错误） ───────────────
echo "\n— C. whereTopNPerGroup —\n";

try {
    $top = $modelClass::query()
        ->whereTopNPerGroup('region', 'amount', 'desc', 1)
        ->orderBy('id')
        ->get();

    check(count($top) === 3, 'whereTopNPerGroup 每组取 1 条共 3 条', '实际 '.count($top));

    $maxA = (float) $top->first(static fn ($r) => $r->region === 'A')->amount;
    check($maxA === 30.0, 'A 组取到最大值 30', '实际 '.$maxA);
} catch (Throwable $e) {
    check(false, 'whereTopNPerGroup 可执行', $e::class.': '.$e->getMessage());
}

// ─────────── D. lastValue / nthValue（原缺窗口帧，语义反了） ───────────
echo "\n— D. 窗口值函数 —\n";

try {
    // 显式传 orderBy='amount'：宏在省略时按主键排序，那时 LAST_VALUE 的语义是
    // "按 id 序的最后一行"，而不是"分区最大值"。
    $lv = DB::table($table)
        ->orderBy('region')
        ->orderBy('amount')
        ->lastValue('amount', 'region', 'amount')
        ->get();

    $perRegionMax = ['A' => 30.0, 'B' => 60.0, 'C' => 80.0];
    $ok = true;
    $bad = '';
    foreach ($lv as $r) {
        $expect = $perRegionMax[$r->region] ?? null;
        $got = (float) $r->amount_last;
        if ($expect === null || abs($got - $expect) > 0.001) {
            $ok = false;
            $bad = "region={$r->region} 期望={$expect} 实际={$got}";
            break;
        }
    }
    check($ok, 'lastValue 返回整个分区的最大值（而非恒等于当前行）', $bad);

    // 关键判别：若没有 ROWS ... UNBOUNDED FOLLOWING，LAST_VALUE(x) 恒等于 x。
    // 不能用 where 过滤出单行来验证 —— WHERE 在窗口计算之前生效，过滤后分区只剩 1 行，
    // LAST_VALUE 无论有没有帧都等于自身。正确做法是遍历整个分区，找 last ≠ amount 的行。
    $differs = false;
    foreach ($lv as $r) {
        if ((float) $r->amount_last !== (float) $r->amount) {
            $differs = true;
            break;
        }
    }
    check($differs, 'lastValue 的值确实不等于当前行（证明窗口帧生效）');
} catch (Throwable $e) {
    check(false, 'lastValue 可执行', $e::class.': '.$e->getMessage());
}

// rankOver 必须拒绝需要实参的窗口函数（原会生成 LAG() OVER (...)）
throws(
    'rankOver 拒绝 LAG（原本会生成非法的 LAG() OVER (...)）',
    static fn () => DB::table($table)->rankOver('LAG', 'region', 'amount'),
    InvalidArgumentException::class
);
try {
    DB::table($table)->rankOver('DENSE_RANK', 'region', 'amount', 'desc')->get();
    check(true, 'rankOver 接受 DENSE_RANK');
} catch (Throwable $e) {
    check(false, 'rankOver 接受 DENSE_RANK', $e::class.': '.$e->getMessage());
}

// ─────────────── E. cursorPaginate（原匿名类 implements 接口，Fatal） ───────────────
echo "\n— E. cursorPaginate —\n";

try {
    $cp = $modelClass::query()->seekCursorPaginate(4, null, 'id', 'asc');
    check($cp instanceof Illuminate\Pagination\CursorPaginator, 'cursorPaginate 返回框架真实 CursorPaginator');
    check(count($cp->items()) === 4, 'seekCursorPaginate 首页返回 4 条', '实际 '.count($cp->items()));
    check($cp->nextCursor() !== null, 'cursorPaginate 给出了下一页游标');

    $page2 = $modelClass::query()->seekCursorPaginate(4, $cp->nextCursor(), 'id', 'asc');
    // items() 返回的是数组（不是 Collection），不能直接 pluck()
    $ids1 = array_column($cp->items(), 'id');
    $ids2 = array_column($page2->items(), 'id');
    check(
        array_intersect($ids1, $ids2) === [],
        '游标翻页不重复（相邻页无交集）',
        'page1='.implode(',', $ids1).' page2='.implode(',', $ids2)
    );
} catch (Throwable $e) {
    check(false, 'seekCursorPaginate 可执行', $e::class.': '.$e->getMessage());
}

// ─────────── F. seekPaginate 书签区间（原双闭区间 → 跨页重复） ───────────
echo "\n— F. seekPaginate 书签 —\n";

try {
    $q = $modelClass::query();
    $bookmarks = zxf\Modules\BuilderQuery\WindowMacros\FastPaginationMacro::generateBookmarks(
        $q, 'seq', 3, 'asc'
    );
    check(count($bookmarks) === 4, '10 行 / 每页 3 行 → 4 个书签', '实际 '.count($bookmarks));

    // 用书签翻页，收集所有页的 seq，验证无重复无遗漏
    $seen = [];
    foreach (array_keys($bookmarks) as $p) {
        $page = $modelClass::query()->seekPaginate(3, $bookmarks, $p, 'seq', 'asc');
        foreach ($page->items() as $item) {
            $seen[] = (int) $item->seq;
        }
    }
    check(
        count($seen) === count(array_unique($seen)),
        '书签翻页无重复行（原双闭区间会让边界行出现两次）',
        '收集 '.count($seen).' 行，去重后 '.count(array_unique($seen)).' 行'
    );
    sort($seen);
    check(
        $seen === range(1, 10),
        '书签翻页完整覆盖 1..10 无遗漏',
        '实际：'.implode(',', $seen)
    );
} catch (Throwable $e) {
    check(false, 'seekPaginate 书签翻页', $e::class.': '.$e->getMessage());
}

// ─────────── G. 延迟关联分页（原丢失 orderBy → 错序） ───────────
echo "\n— G. 延迟关联分页顺序 —\n";

try {
    $rows1 = zxf\Modules\BuilderQuery\WindowMacros\FastPaginationMacro::executeDeferredPaginate(
        $modelClass::query()->orderBy('amount', 'desc'), $table, 'id', 1, 4, ['*']
    );
    $rows2 = zxf\Modules\BuilderQuery\WindowMacros\FastPaginationMacro::executeDeferredPaginate(
        $modelClass::query()->orderBy('amount', 'desc'), $table, 'id', 1, 4, ['*']
    );

    $amounts1 = $rows1->pluck('amount')->map('floatval')->all();
    $desc = $amounts1;
    rsort($desc);
    check($amounts1 === $desc, '延迟关联保留调用方 orderBy desc', '实际：'.implode(',', $amounts1));
    check($rows1->pluck('id')->all() === $rows2->pluck('id')->all(), '同一页两次请求结果稳定（不再随机错序）');
} catch (Throwable $e) {
    check(false, '延迟关联分页顺序', $e::class.': '.$e->getMessage());
}

// ─────────── H/I. 抽样宏的绑定错位回归 ───────────
echo "\n— H. 抽样宏（清 columns 必须同步清绑定） —\n";

try {
    // 用 100%（全取）而非 50%：这里验证的是【绑定不错位 + 过滤条件被继承】，
    // 随机百分比会让行数断言随机失败，掩盖真正要测的东西。
    $sampled = $modelClass::query()
        ->select('*')                                    // selectRaw 会顶掉隐含的 *，这里显式保留
        ->selectRaw('UPPER(?) as tag', ['zz'])           // 故意留下 select 绑定
        ->orderByRaw('seq + ?', [1])                     // 故意留下 order 绑定
        ->where('region', 'A')
        ->sample(100)
        ->get();
    check(
        count($sampled) === 5 && $sampled->every(static fn ($r) => $r->region === 'A'),
        'sample 继承 where 条件且无绑定错位',
        '实际 '.count($sampled).' 行'
    );
} catch (Throwable $e) {
    check(false, 'sample 无绑定错位', $e::class.': '.$e->getMessage());
}

try {
    $strat = $modelClass::query()
        ->selectRaw('UPPER(?) as tag', ['zz'])
        ->stratifiedSample('region', 1)
        ->get();
    check(count($strat) === 3, 'stratifiedSample 每层 1 条共 3 条', '实际 '.count($strat));
} catch (Throwable $e) {
    check(false, 'stratifiedSample 无绑定错位', $e::class.': '.$e->getMessage());
}

// ─────────── J. 双构建器等价（DB::table 路径不得 null 解引用） ───────────
echo "\n— J. DB::table() 双构建器等价 —\n";

$dbPaths = [
    'sample (百分比)' => static fn ($q) => $q->sample(50),
    'sample' => static fn ($q) => $q->sample(50),
    'stratifiedSample' => static fn ($q) => $q->stratifiedSample('region', 1),
    'pivot' => static fn ($q) => $q->pivot('status', ['paid'], 'amount', 'SUM', 'region'),
    'whereTopNPerGroup' => static fn ($q) => $q->whereTopNPerGroup('region', 'amount', 'desc', 1),
    'fastPaginate(window)' => static fn ($q) => $q->fastPaginate(3, 1, null, ['strategy' => 'window']),
    'fastPaginate(deferred)' => static fn ($q) => $q->fastPaginate(3, 1, null, ['strategy' => 'deferred']),
    'lastValue' => static fn ($q) => $q->lastValue('amount', 'region'),
    'withLeafNodes' => static fn ($q) => $q->withLeafNodes('seq'),
    'copyWheres 变体 (random)' => static fn ($q) => $q->random(),
];

foreach ($dbPaths as $label => $fn) {
    try {
        $built = $fn(DB::table($table));
        // 分页类宏返回的是【已执行查询】的分页器，再链 get() 会转发到 Collection::get($key)
        // 抛 ArgumentCountError —— 这里统一执行到"取数完成"为止。
        if ($built instanceof Illuminate\Contracts\Pagination\Paginator) {
            $built->items();
        } elseif ($built instanceof Illuminate\Support\Collection) {
            $built->count();
        } else {
            $built->get();
        }
        check(true, "DB::table() 路径可用：{$label}");
    } catch (Throwable $e) {
        check(false, "DB::table() 路径可用：{$label}", $e::class.': '.$e->getMessage());
    }
}

// Eloquent 路径同样全量跑一遍，确保两侧都能用
foreach ($dbPaths as $label => $fn) {
    try {
        $built = $fn($modelClass::query());
        if ($built instanceof Illuminate\Contracts\Pagination\Paginator) {
            $built->items();
        } elseif ($built instanceof Illuminate\Support\Collection) {
            $built->count();
        } else {
            $built->get();
        }
        check(true, "Eloquent 路径可用：{$label}");
    } catch (Throwable $e) {
        check(false, "Eloquent 路径可用：{$label}", $e::class.': '.$e->getMessage());
    }
}

// ─────────── K. 注入防护（安全回归不得被本轮改动削弱） ───────────
echo "\n— K. 标识符与值注入防护 —\n";

foreach ([
    'pivot 聚合列' => static fn () => DB::table($table)->pivot('status', ['paid'], 'amount; DROP TABLE x', 'SUM', 'region'),
    'pivot 行转列列名' => static fn () => DB::table($table)->pivot('status`, (SELECT 1)', ['paid'], 'amount', 'SUM', 'region'),
    'crossTab 行列名' => static fn () => DB::table($table)->crossTab('region`, (SELECT 1)', 'status', 'amount'),
    'topN 排序列' => static fn () => DB::table($table)->whereTopNPerGroup('region', 'amount` , 1); -- ', 'desc', 1),
    'lastValue 列名' => static fn () => DB::table($table)->lastValue('amount`, (SELECT 1)', 'region'),
    'stratifiedSample 分层列' => static fn () => DB::table($table)->stratifiedSample('region`, (SELECT 1)', 1),
] as $label => $fn) {
    throws("注入被拦截：{$label}", $fn, InvalidArgumentException::class);
}

// 夹具仍在 → 说明上面没有执行破坏性 SQL
check(
    (int) DB::table($table)->count() === count($rows),
    '注入尝试均未改动数据表'
);

// ──────────────────────────── 收尾 ────────────────────────────
DB::statement("DROP TABLE IF EXISTS {$table}");

echo "\n结果: {$pass} PASS / {$fail} FAIL";
echo $skip > 0 ? " / {$skip} SKIP" : '';
echo "\n";

exit($fail === 0 ? 0 : 1);
