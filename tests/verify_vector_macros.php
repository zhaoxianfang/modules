<?php

declare(strict_types=1);

/**
 * 向量宏回归测试（src/BuilderQuery/WindowMacros/VectorMacro.php）
 *
 * 重点不是断言「某个库能算出距离」（各版本能力差异极大），而是断言宏的**契约**在任何后端下都成立：
 *  1. 宏确实注册到 Eloquent\Builder 与 Query\Builder 双构建器（未被框架原生方法遮蔽）；
 *  2. 不支持的后端抛**本包明确异常**并给出可执行修复方案，而不是生成注定报
 *     "FUNCTION ... does not exist" 的 SQL（这正是本轮修复的核心问题）；
 *  3. 维度/文本表达式**不被**距离能力误伤（MySQL 社区版有 VECTOR_DIM / VECTOR_TO_STRING）；
 *  4. 列名/别名/度量/向量元素全部经白名单校验，注入尝试被拦截；
 *  5. 向量字面量格式正确（**不得出现科学计数法**，否则 pgvector / MySQL 解析失败）；
 *  6. 隐含的 `select *` 不被 addWindowSelect 顶掉。
 *
 * 若当前连接具备距离计算能力（PG+pgvector / MariaDB 11.7+ / MySQL HeatWave），
 * 会额外在真实表上建表并真实执行一次距离查询，做端到端验证。
 *
 * 运行：php tests/verify_vector_macros.php
 * 退出码：0 = 全 PASS，1 = 有 FAIL，2 = 宿主不可用（SKIP）。
 */
$hostRoot = getenv('MODULES_TEST_HOST') ?: '/Users/aha/www/wsf';

if (! is_file($hostRoot.'/vendor/autoload.php')) {
    fwrite(STDERR, "SKIP: 宿主 {$hostRoot} 不可用\n");
    exit(2);
}

require $hostRoot.'/vendor/autoload.php';

// 注意：Composer 的 ClassLoader 在 require autoload.php 时以 prepend=true 注册，
// 因此 shim 必须在它之后 prepend，否则类会全部从宿主 vendor 的旧副本加载
// （曾因此把旧代码的报错当成本轮缺陷）。
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

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;
use zxf\Modules\BuilderQuery\WindowMacros\VectorMacro;

$probe = new ReflectionClass(\zxf\Modules\ModulesServiceProvider::class);
if (! str_starts_with((string) $probe->getFileName(), '/Users/aha/www/modules/src/')) {
    fwrite(STDERR, "FATAL: 类加载自宿主旧副本，无法验证工作区代码\n");
    exit(2);
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

function throwsMsg(string $label, callable $fn, string $mustContain, ?string $expectClass = null): void
{
    try {
        $fn();
        check(false, $label, '未抛出预期异常');
    } catch (Throwable $e) {
        if ($expectClass !== null && ! $e instanceof $expectClass) {
            check(false, $label, '异常类型为 '.$e::class.'，期望 '.$expectClass);

            return;
        }
        check(
            str_contains($e->getMessage(), $mustContain),
            $label,
            '异常消息缺少关键信息：'.substr(str_replace("\n", ' ', $e->getMessage()), 0, 90)
        );
    }
}

$backend = BaseMacro::vectorBackend(DB::table('users'));
$hasDistance = in_array($backend, [
    BaseMacro::VECTOR_BACKEND_PGSQL,
    BaseMacro::VECTOR_BACKEND_MARIADB,
    BaseMacro::VECTOR_BACKEND_HEATWAVE,
], true);

echo '源码: '.dirname((string) $probe->getFileName(), 2)."\n";
echo "后端判定: {$backend}（驱动 {$driver}，距离能力 ".($hasDistance ? '有' : '无')."）\n";

// ── A. 双构建器注册 ─────────────────────────────────────────────
echo "\n— A. 宏注册（双构建器） —\n";

$macros = [
    'addVectorDistance', 'addVectorSimilarity', 'whereVectorDistance',
    'orderByVectorSimilarity', 'vectorKnn', 'addVectorDimension',
    'whereVectorDimension', 'addVectorText', 'addVectorNormalized', 'vectorSearchParams',
];

$qMacros = (new ReflectionClass(QueryBuilder::class))->getStaticPropertyValue('macros');
$eMacros = (new ReflectionClass(EloquentBuilder::class))->getStaticPropertyValue('macros');

foreach ($macros as $name) {
    check(
        isset($qMacros[$name], $eMacros[$name]),
        "宏已注册到双构建器：{$name}",
        'Query='.(isset($qMacros[$name]) ? 'Y' : 'n').' Eloquent='.(isset($eMacros[$name]) ? 'Y' : 'n')
    );
}

// 与框架原生方法同名的宏会被 Macroable 遮蔽、永不触发（上一轮的核心教训）
$shadowed = [];
foreach ($macros as $name) {
    foreach ([QueryBuilder::class, EloquentBuilder::class] as $class) {
        if (! method_exists($class, $name)) {
            continue;
        }
        if ((new ReflectionMethod($class, $name))->getDeclaringClass()->getName() === $class) {
            $shadowed[] = $class.'::'.$name;
        }
    }
}
check($shadowed === [], '无宏与框架原生方法同名（否则永不触发）', implode(', ', $shadowed));

// ── B. 后端判定缓存 ─────────────────────────────────────────────
echo "\n— B. 后端判定 —\n";

check(
    $backend !== BaseMacro::VECTOR_BACKEND_NONE || $driver !== 'mysql',
    '后端判定返回合法枚举值',
    $backend
);

$start = hrtime(true);
BaseMacro::vectorBackend(DB::table('users'));
BaseMacro::vectorBackend(DB::table('users'));
$cachedMs = (hrtime(true) - $start) / 1e6;
check($cachedMs < 50.0, '后端探测结果已缓存（二次调用 < 50ms）', sprintf('%.2f ms', $cachedMs));

// ── C. 无距离能力时的异常契约 ───────────────────────────────────
echo "\n— C. 无距离能力时的异常契约 —\n";

if (! $hasDistance) {
    // 社区版/无向量能力：必须抛本包异常（消息含可执行迁移方案），
    // 而不是框架原生那句 "only supported by Postgres and MariaDB"
    throwsMsg(
        '距离类宏抛本包异常而非框架原生错误',
        static fn () => DB::table('docs')->addVectorDistance('embedding', [1, 2, 3], 'cosine'),
        'DISTANCE()',
        InvalidArgumentException::class
    );
    throwsMsg(
        '异常消息给出可执行迁移方案',
        static fn () => DB::table('docs')->vectorKnn('embedding', [1, 2, 3], 5),
        'pgvector',
        InvalidArgumentException::class
    );
    throwsMsg(
        'vectorSearchParams 在非 PG 后端明确拒绝',
        static fn () => DB::table('docs')->vectorSearchParams(['hnsw_ef_search' => 100]),
        '仅支持 PostgreSQL',
        InvalidArgumentException::class
    );
    throwsMsg(
        'addVectorNormalized 拒绝并指引应用层归一化',
        static fn () => DB::table('docs')->addVectorNormalized('embedding'),
        '归一化',
        InvalidArgumentException::class
    );
} else {
    check(true, '当前后端具备距离能力，跳过社区版异常契约断言');
}

// ── D. 维度/文本：不得被距离能力误伤 ───────────────────────────
echo "\n— D. 维度与文本表达式（不依赖距离能力） —\n";

if ($backend === BaseMacro::VECTOR_BACKEND_PGSQL) {
    check(str_contains(DB::table('users')->addVectorDimension('embedding', 'dim')->toSql(), 'vector_dims'), 'PG 维度表达式用 vector_dims()');
    check(str_contains(DB::table('users')->addVectorText('embedding', 'vt')->toSql(), '::text'), 'PG 文本表达式用 ::text');
} elseif (in_array($backend, [
    BaseMacro::VECTOR_BACKEND_MARIADB,
    BaseMacro::VECTOR_BACKEND_HEATWAVE,
    BaseMacro::VECTOR_BACKEND_COMMUNITY,
], true)) {
    // 实测 Oracle MySQL 9.3 社区版具备 VECTOR_DIM / VECTOR_TO_STRING / STRING_TO_VECTOR，
    // 这些宏不应被「距离能力」的守卫误伤 —— 这是本轮修掉的一个误伤。
    $dimSql = DB::table('users')->addVectorDimension('embedding', 'dim')->toSql();
    check(str_contains($dimSql, 'VECTOR_DIM'), 'MySQL 系维度表达式用 VECTOR_DIM()', $dimSql);
    check(str_contains($dimSql, '`embedding`'), '维度表达式对列名做了 grammar wrap', $dimSql);

    $whereDim = DB::table('users')->whereVectorDimension('embedding', 1536);
    check(str_contains($whereDim->toSql(), 'VECTOR_DIM') && str_contains($whereDim->toSql(), '= ?'), 'whereVectorDimension 生成带绑定的条件', $whereDim->toSql());
    check($whereDim->getBindings() === [1536], 'whereVectorDimension 的绑定值正确', json_encode($whereDim->getBindings()));

    if ($backend === BaseMacro::VECTOR_BACKEND_MARIADB) {
        check(str_contains(DB::table('users')->addVectorText('embedding', 'vt')->toSql(), 'VEC_ToText'), 'MariaDB 文本表达式用 VEC_ToText()');
    } elseif (in_array($backend, [BaseMacro::VECTOR_BACKEND_HEATWAVE, BaseMacro::VECTOR_BACKEND_COMMUNITY], true)) {
        check(str_contains(DB::table('users')->addVectorText('embedding', 'vt')->toSql(), 'VECTOR_TO_STRING'), 'Oracle MySQL 文本表达式用 VECTOR_TO_STRING()');
    }
} else {
    check(true, '当前驱动无向量类型能力，跳过维度/文本断言');
}

// ── E. SQL 生成与绑定对齐（需距离能力的后端） ───────────────────
echo "\n— E. SQL 生成与绑定对齐 —\n";

if ($hasDistance) {
    $q1 = DB::table('docs')->addVectorDistance('embedding', [1, 2, 3], 'cosine', 'd');
    check(str_contains($q1->toSql(), 'select *'), 'addVectorDistance 保留了隐含的 select *', $q1->toSql());
    check($q1->getBindings() === ['[1,2,3]'], 'addVectorDistance 的绑定值已就位', json_encode($q1->getBindings()));

    $q2 = DB::table('docs')->whereVectorDistance('embedding', [1, 2, 3], 0.4, 'cosine');
    check($q2->getBindings() === ['[1,2,3]', 0.4], 'whereVectorDistance 绑定顺序为 [向量, 阈值]', json_encode($q2->getBindings()));

    $q3 = DB::table('docs')->orderByVectorSimilarity('embedding', [1, 2, 3], 'cosine');
    check($q3->getBindings() === ['[1,2,3]'], 'orderByVectorSimilarity 的绑定挂在 order 组', json_encode($q3->getBindings()));

    $q4 = DB::table('docs')->vectorKnn('embedding', [1, 2, 3], 7, 'cosine');
    check(str_contains(strtolower($q4->toSql()), 'limit 7'), 'vectorKnn 自动附加 limit', $q4->toSql());

    check(
        DB::table('docs')->whereVectorDistance('embedding', [1, 2, 3], null, 'cosine')->getWheres() === [],
        'whereVectorDistance 传 null 阈值时不追加条件'
    );
} else {
    check(true, '当前后端无距离能力，跳过 SQL 生成断言');
}

// ── F. 度量与入参校验 ───────────────────────────────────────────
echo "\n— F. 度量与入参校验 —\n";

throwsMsg(
    '非法度量被拒绝并列出允许值',
    static fn () => DB::table('docs')->addVectorDistance('embedding', [1, 2], 'haversine'),
    '允许值',
    InvalidArgumentException::class
);

foreach (['cosine', 'cos', 'l2', 'euclidean', 'inner', 'ip', 'dot', 'manhattan', 'l1'] as $alias) {
    try {
        BaseMacro::normalizeVectorMetric($alias);
        check(true, "度量别名可归一化：{$alias}");
    } catch (Throwable $e) {
        check(false, "度量别名可归一化：{$alias}", $e->getMessage());
    }
}

if ($hasDistance) {
    // 只有在该后端**本身支持距离**时，才谈得上「这个度量不被支持」；
    // 在完全没有距离能力的库（如 MySQL 社区版）上，更前置也更准确的错误是
    // 「该库没有距离函数」，此时断言 L1 专属消息是错的。
    $unsupportedL1 = in_array($backend, [
        BaseMacro::VECTOR_BACKEND_MARIADB,
        BaseMacro::VECTOR_BACKEND_HEATWAVE,
    ], true);

    if ($unsupportedL1) {
        throwsMsg(
            'MySQL/MariaDB/HeatWave 的 L1 度量被明确拒绝',
            static fn () => DB::table('docs')->addVectorDistance('embedding', [1, 2], 'l1'),
            'L1',
            InvalidArgumentException::class
        );
    } else {
        check(true, '当前后端（pgvector）支持 L1 运算符，跳过 L1 拒绝断言');
    }
}

throwsMsg('空向量数组被拒绝', static fn () => DB::table('docs')->addVectorDistance('embedding', [], 'cosine'), '空数组', InvalidArgumentException::class);
throwsMsg('含非数值元素的向量被拒绝', static fn () => DB::table('docs')->addVectorDistance('embedding', [1, 'abc', 3], 'cosine'), '不是数值', InvalidArgumentException::class);
throwsMsg('含 INF 的向量被拒绝', static fn () => DB::table('docs')->addVectorDistance('embedding', [1, INF, 3], 'cosine'), 'INF', InvalidArgumentException::class);
throwsMsg('vectorKnn 的 limit < 1 被拒绝', static fn () => DB::table('docs')->vectorKnn('embedding', [1, 2], 0), '>= 1', InvalidArgumentException::class);
throwsMsg('whereVectorDimension 的维度 < 1 被拒绝', static fn () => DB::table('docs')->whereVectorDimension('embedding', 0), '>= 1', InvalidArgumentException::class);

// ── G. 向量字面量格式 ───────────────────────────────────────────
echo "\n— G. 向量字面量格式 —\n";

$cases = [
    [[1, 2, 3], '[1,2,3]'],
    [[1.5, 2.0, 0.25], '[1.5,2,0.25]'],
    [[1, '2', '3.5'], '[1,2,3.5]'],
    [[0.00001, 1], '[0.00001,1]'],   // 关键：不能变成 1.0E-5
    [[-3, 2.75], '[-3,2.75]'],
];

foreach ($cases as [$input, $expected]) {
    $actual = BaseMacro::normalizeVectorLiteral($input);
    check($actual === $expected, '字面量格式化正确：'.json_encode($input), "期望 {$expected}，实际 {$actual}");
    check(
        ! str_contains($actual, 'E') && ! str_contains($actual, 'e'),
        "字面量不含指数形式：{$actual}"
    );
}

check(BaseMacro::normalizeVectorLiteral('[0.1, 0.2]') === '[0.1, 0.2]', '字符串字面量原样透传（去掉首尾空白）');
throwsMsg('不合法的字符串字面量被拒绝', static fn () => BaseMacro::normalizeVectorLiteral('1,2,3'), '[x,y,z]', InvalidArgumentException::class);

// ── H. 注入防护 ─────────────────────────────────────────────────
echo "\n— H. 注入防护 —\n";

throwsMsg('列名注入被拦截', static fn () => DB::table('docs')->addVectorDistance('embedding`, (SELECT 1)', [1, 2], 'cosine'), '标识符', InvalidArgumentException::class);
throwsMsg('别名注入被拦截', static fn () => DB::table('docs')->addVectorDistance('embedding', [1, 2], 'cosine', 'd`, (SELECT 1)'), '标识符', InvalidArgumentException::class);
throwsMsg('维度列名注入被拦截', static fn () => DB::table('docs')->whereVectorDimension('embedding`, (SELECT 1)', 3), '标识符', InvalidArgumentException::class);
throwsMsg('文本列名注入被拦截', static fn () => DB::table('docs')->addVectorText('embedding`, (SELECT 1)'), '标识符', InvalidArgumentException::class);
throwsMsg(
    '度量名无法用于注入（函数名位置走白名单）',
    static fn () => DB::table('docs')->addVectorDistance('embedding', [1, 2], 'cosine); DROP TABLE x; --'),
    '非法的向量度量方式',
    InvalidArgumentException::class
);

// ── I. 索引建议 ─────────────────────────────────────────────────
echo "\n— I. 索引建议 —\n";

$advice = VectorMacro::indexAdvice(DB::table('users'), 'cosine', 'embedding');
check(is_array($advice) && isset($advice['ddl'], $advice['note']), 'indexAdvice 返回 ddl + note');

if ($backend === BaseMacro::VECTOR_BACKEND_PGSQL) {
    $l2 = VectorMacro::indexAdvice(DB::table('users'), 'l2', 'embedding');
    check(str_contains($l2['ddl'], 'vector_l2_ops'), 'PG 的 l2 建议使用 vector_l2_ops', $l2['ddl']);
    check(str_contains($l2['note'], 'ORDER BY'), 'PG 索引建议强调 ORDER BY 运算符须与 opclass 匹配（静默退化的头号原因）');
    check(
        str_contains(VectorMacro::indexAdvice(DB::table('users'), 'inner', 'embedding')['ddl'], 'vector_ip_ops'),
        'PG 的 inner 建议使用 vector_ip_ops'
    );
} else {
    check($advice['note'] !== '', '当前后端也给出了索引注意事项', json_encode($advice, JSON_UNESCAPED_UNICODE));
}

throwsMsg('indexAdvice 的非法度量被拒绝', static fn () => VectorMacro::indexAdvice(DB::table('users'), 'bogus'), '允许值', InvalidArgumentException::class);

// ── J. 端到端真实执行 ───────────────────────────────────────────
echo "\n— J. 端到端真实执行 —\n";

if (! $hasDistance) {
    check(true, '当前后端无距离能力，跳过端到端执行（契约断言已覆盖）');
} else {
    $table = 'xbq_vec_fixture';
    $colType = $backend === BaseMacro::VECTOR_BACKEND_PGSQL ? 'vector(3)' : 'VECTOR(3)';
    $fromText = BaseMacro::vectorFromLiteralFragment(DB::table($table), [1, 0, 0])[0];

    try {
        DB::statement("DROP TABLE IF EXISTS {$table}");
        DB::statement("CREATE TABLE {$table} (id INT PRIMARY KEY, label VARCHAR(16) NOT NULL, embedding {$colType})");

        $id = 0;
        foreach ([['A', [1, 0, 0]], ['B', [0, 1, 0]], ['C', [0.9, 0.1, 0]]] as [$label, $vec]) {
            $id++;
            DB::statement(
                "INSERT INTO {$table} (id, label, embedding) VALUES (?, ?, {$fromText})",
                [$id, $label, BaseMacro::normalizeVectorLiteral($vec)]
            );
        }
        check(true, '向量夹具表写入成功（3 行）');

        $rows = DB::table($table)
            ->addVectorDistance('embedding', [1, 0, 0], 'cosine', 'd')
            ->orderByVectorSimilarity('embedding', [1, 0, 0], 'cosine')
            ->get();

        check(count($rows) === 3, '距离查询真实执行并返回 3 行', '实际 '.count($rows));
        check($rows->pluck('label')->first() === 'A', '最相似的行排在首位', '实际顺序：'.$rows->pluck('label')->implode(','));
        check(abs((float) $rows->first()->d) < 0.01, '查询向量自身的距离约为 0', '实际 '.$rows->first()->d);

        $knn = DB::table($table)->vectorKnn('embedding', [1, 0, 0], 2, 'cosine')->get();
        check(
            count($knn) === 2 && $knn->pluck('label')->first() === 'A',
            'vectorKnn 返回 Top-2 且最相似在首位',
            '实际 '.$knn->pluck('label')->implode(',')
        );

        $dimmed = DB::table($table)->addVectorDimension('embedding', 'dim')->first();
        check((int) $dimmed->dim === 3, 'addVectorDimension 真实返回正确维度 3', '实际 '.$dimmed->dim);

        $filtered = DB::table($table)->whereVectorDimension('embedding', 3)->get();
        check(count($filtered) === 3, 'whereVectorDimension 真实筛选出 3 行', '实际 '.count($filtered));

        $textRow = DB::table($table)->addVectorText('embedding', 'vt')->first();
        check(str_contains((string) $textRow->vt, ','), 'addVectorText 真实返回向量文本', (string) $textRow->vt);
    } catch (Throwable $e) {
        check(false, '端到端向量查询可执行', $e::class.': '.$e->getMessage());
    } finally {
        try {
            DB::statement("DROP TABLE IF EXISTS {$table}");
        } catch (Throwable) {
            // 清理失败不影响结论
        }
    }
}

echo "\n结果: {$pass} PASS / {$fail} FAIL\n";

exit($fail === 0 ? 0 : 1);
