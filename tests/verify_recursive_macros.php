<?php

declare(strict_types=1);

/**
 * 递归 CTE 宏 / JSON 宏 运行时验证脚本
 *
 * 覆盖本轮修复的三类 P0 缺陷：
 *  1. WithRecursiveMacro：newQuery() 丢弃调用方条件 + 手动追加不存在的 where 绑定
 *     → 断言：占位符数量 == 绑定数量，且调用方的 where 条件被保留。
 *  2. AdvancedJsonMacro / RegexMacro：where(DB::raw($sql)) 被 Laravel 降级为
 *     "IS NOT NULL" → 断言：SQL 中确实出现目标函数且不含 "is not null"。
 *  3. AdvancedJsonMacro：update()->addBinding(..., 'update') 双重致命
 *     （update 返回 int，且 'update' 非合法绑定键）
 *     → 断言：UPDATE 编译后 SET 段占位符先于 WHERE 段，且绑定顺序与之匹配。
 *
 * 运行：php tests/verify_recursive_macros.php
 * 退出码：0=全 PASS，1=有 FAIL。
 */

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use zxf\Modules\BuilderQuery\WindowMacros\StringFunctionsMacro;

// ---- 关键：优先加载本包源码 ----
// wsf/vendor/zxf/modules 是本包的旧副本，且其 helper.php / trace bootstrap 会在
// vendor/autoload.php 阶段就 require 进来，导致旧版类被抢先加载（此后 setPsr4 无效）。
// composer 自身以 prepend=true 注册加载器，因此这里必须在 require autoload
// **之前**（覆盖 files 阶段）与 **之后**（重新插到 composer 之前）各注册一次。
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

$loader = require '/Users/aha/www/wsf/vendor/autoload.php';
$loader->setPsr4('zxf\\Modules\\', '/Users/aha/www/modules/src/');

// composer 以 prepend 注册，会挤掉上面的加载器，故再注册一次
$registerPackageAutoloader();

$container = new Container();
\Illuminate\Support\Facades\Facade::setFacadeApplication($container);

// Laravel 13 的 getDefaultQueryGrammar() 固定返回通用 Grammar，需手动注入 MySQL grammar
$connection = new Connection(new \PDO('sqlite::memory:'), 'default', '', ['driver' => 'mysql']);
$connection->setQueryGrammar(new \Illuminate\Database\Query\Grammars\MySqlGrammar($connection));

$resolver = new class ($connection) implements ConnectionResolverInterface {
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

    public function setDefaultConnection($name)
    {
    }
};
Model::setConnectionResolver($resolver);
Model::setEventDispatcher(new \Illuminate\Events\Dispatcher($container));

// 本测试使用最小容器，未注册 DatabaseManager；宏内部与断言中用到的 DB::raw()
// 仅需返回 Expression 对象，故绑定一个轻量桩件即可。
$container->instance('db', new class {
    public function raw($value)
    {
        return new \Illuminate\Database\Query\Expression($value);
    }
});

// 注册宏（先确认参与验证的类确实来自本包源码，否则验证的是旧副本）
foreach ([
    \zxf\Modules\BuilderQuery\WindowMacros\WithRecursiveMacro::class,
    \zxf\Modules\BuilderQuery\WindowMacros\AdvancedJsonMacro::class,
    \zxf\Modules\BuilderQuery\WindowMacros\RegexMacro::class,
    StringFunctionsMacro::class,
] as $verifiedClass) {
    $file = (new ReflectionClass($verifiedClass))->getFileName();
    if (! str_starts_with($file, '/Users/aha/www/modules/')) {
        echo "FATAL {$verifiedClass} 来自非本包源码: {$file}\n";
        exit(2);
    }
}

StringFunctionsMacro::register();
\zxf\Modules\BuilderQuery\WindowMacros\WithRecursiveMacro::register();
\zxf\Modules\BuilderQuery\WindowMacros\AdvancedJsonMacro::register();
\zxf\Modules\BuilderQuery\WindowMacros\RegexMacro::register();

class RecursiveVerifyModel extends Model
{
    protected $table = 'categories';

    protected $guarded = [];
}

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

function ok(string $name): void
{
    $GLOBALS['pass']++;
    echo "PASS  {$name}\n";
}

function bad(string $name, string $detail): void
{
    $GLOBALS['fail']++;
    echo "FAIL  {$name}\n  {$detail}\n";
}

/**
 * 核心断言：SQL 中 ? 占位符数量必须与绑定数量严格一致
 * （否则 PDO 抛 Invalid parameter number）
 */
function assertConsistent(string $name, object $query): void
{
    $sql = $query->toSql();
    $bindings = $query->getBindings();
    $placeholders = substr_count($sql, '?');

    if ($placeholders !== count($bindings)) {
        bad($name, "占位符 {$placeholders} 个 vs 绑定 ".count($bindings)." 个\n  SQL: {$sql}\n  绑定: ".json_encode($bindings));

        return;
    }

    ok("{$name} [占位符 {$placeholders} = 绑定 ".count($bindings).']');
}

function assertContains(string $name, string $haystack, string $needle): void
{
    if (str_contains($haystack, $needle)) {
        ok($name);
    } else {
        bad($name, "SQL 未包含 \"{$needle}\"\n  SQL: {$haystack}");
    }
}

function assertNotContains(string $name, string $haystack, string $needle): void
{
    if (! str_contains($haystack, $needle)) {
        ok($name);
    } else {
        bad($name, "SQL 不应包含 \"{$needle}\"\n  SQL: {$haystack}");
    }
}

$M = RecursiveVerifyModel::class;

echo "=== 一、递归 CTE 宏：绑定一致性 + 调用方条件保留 ===\n";

// 1. withAllChildren（无前置条件）
assertConsistent('withAllChildren 基础', $M::query()->withAllChildren(3, 'pid', 10, true));

// 2. withAllChildren（带前置 where —— 旧实现会丢弃条件且绑定错乱）
$q = $M::query()->where('status', 1)->withAllChildren(3, 'pid', 10, true);
assertConsistent('withAllChildren + where(status)', $q);
assertContains('withAllChildren 保留 where 条件', $q->toSql(), '`status` = ?');

// 3. 多条件 + order + limit
$q = $M::query()->where('status', 1)->where('type', '>', 2)->orderBy('id')->limit(5)->withAllChildren(3);
assertConsistent('withAllChildren + 多条件/order/limit', $q);
assertContains('withAllChildren 保留 order', $q->toSql(), 'order by `id` asc');

// 4. withAllParents
assertConsistent('withAllParents 基础', $M::query()->withAllParents(8, 'pid', 10, true));
assertConsistent('withAllParents + where', $M::query()->where('status', 1)->withAllParents(8));

// 5. withNthParent / withNthChildren
assertConsistent('withNthParent', $M::query()->withNthParent(10, 2));
assertConsistent('withNthParent + where', $M::query()->where('status', 1)->withNthParent(10, 2));
assertConsistent('withNthChildren', $M::query()->withNthChildren(1, 2));

// 6. withFullPath（含 conditions 与 ids 两组绑定）
assertConsistent('withFullPath 基础', $M::query()->withFullPath());
assertConsistent('withFullPath + conditions', $M::query()->withFullPath([], ['status' => 1]));
assertConsistent('withFullPath + ids + conditions', $M::query()->withFullPath([1, 2, 3], ['status' => 1]));
assertConsistent('withFullPath + 调用方 where', $M::query()->where('tenant_id', 9)->withFullPath([1], ['status' => 1]));

// 7. withBreadcrumbs / withPathLength
assertConsistent('withBreadcrumbs', $M::query()->withBreadcrumbs(5));
assertConsistent('withBreadcrumbs + where', $M::query()->where('status', 1)->withBreadcrumbs(5));
assertConsistent('withPathLength', $M::query()->withPathLength(5));
assertConsistent('withPathLength + where', $M::query()->where('status', 1)->withPathLength(5));

// 8. withSiblings（旧实现手动追加 where 绑定，必然错乱）
assertConsistent('withSiblings 基础', $M::query()->withSiblings(3));
$q = $M::query()->where('status', 1)->withSiblings(3);
assertConsistent('withSiblings + where', $q);
assertContains('withSiblings 保留 where 条件', $q->toSql(), '`status` = ?');
assertConsistent('withSiblings 排除自身 + where', $M::query()->where('status', 1)->withSiblings(3, 'pid', false));

// 9. withNearestAncestor
assertConsistent('withNearestAncestor', $M::query()->withNearestAncestor(3, 7));
assertConsistent('withNearestAncestor + where', $M::query()->where('status', 1)->withNearestAncestor(3, 7));

// 10. withTree
assertConsistent('withTree 基础', $M::query()->withTree());
assertConsistent('withTree 指定根节点', $M::query()->withTree(0));
assertConsistent('withTree + where', $M::query()->where('status', 1)->withTree(0));

// 11. withDescendantsCount（聚合，须覆盖 select）
$q = $M::query()->where('status', 1)->withDescendantsCount(3);
assertConsistent('withDescendantsCount + where', $q);
assertContains('withDescendantsCount 输出聚合列', $q->toSql(), 'COUNT(*) - 1 AS descendants_count');

// 12. recursiveQuery（高级自定义）
$q = $M::query()->where('status', 1)->recursiveQuery(
    fn ($q, $withTable) => "SELECT `id`, `pid`, 0 AS `depth` FROM `categories` WHERE `pid` IS NULL",
    fn ($q, $withTable) => "SELECT t.`id`, t.`pid`, r.`depth` FROM `categories` t JOIN `{$withTable}` r ON t.`pid` = r.`id`",
    ['*'],
    5
);
assertConsistent('recursiveQuery + where', $q);

echo "\n=== 二、JSON / 正则宏：whereRaw 语义修复 ===\n";

// whereJsonArrayContainsAny / All：修复前生成 "is not null"，语义全错
$q = $M::query()->whereJsonArrayContainsAny('tags', ['php', 'mysql']);
assertConsistent('whereJsonArrayContainsAny 绑定一致', $q);
assertContains('whereJsonArrayContainsAny 使用 JSON_CONTAINS', $q->toSql(), 'JSON_CONTAINS(');
assertNotContains('whereJsonArrayContainsAny 不再误判为 IS NOT NULL', $q->toSql(), 'is not null');

$q = $M::query()->whereJsonArrayContainsAll('tags', ['php', 'mysql']);
assertConsistent('whereJsonArrayContainsAll 绑定一致', $q);
assertContains('whereJsonArrayContainsAll 使用 JSON_CONTAINS', $q->toSql(), 'JSON_CONTAINS(');
assertNotContains('whereJsonArrayContainsAll 不再误判为 IS NOT NULL', $q->toSql(), 'is not null');

$q = $M::query()->whereJsonArrayContainsAny('meta', ['a'], '$.tags');
assertConsistent('whereJsonArrayContainsAny 带 path', $q);

// whereRegexpAny
$q = $M::query()->whereRegexpAny('name', ['^a', 'b$']);
assertConsistent('whereRegexpAny 绑定一致', $q);
assertContains('whereRegexpAny 使用 REGEXP_LIKE', $q->toSql(), 'REGEXP_LIKE(');
assertNotContains('whereRegexpAny 不再误判为 IS NOT NULL', $q->toSql(), 'is not null');

echo "\n=== 三、JSON 更新宏：UPDATE 绑定顺序 ===\n";

/**
 * 模拟 AdvancedJsonMacro 的 update 宏执行路径，验证绑定顺序。
 * UPDATE 编译后绑定顺序为：values(SET 段非 Expression 值) → join → where。
 */
function assertUpdateOrder(string $name, array $setBindings, string $expr): void
{
    $base = (new \Illuminate\Database\Query\Builder($GLOBALS['connection']))
        ->from('articles')
        ->where('id', 1);

    // 宏的写法：SET 段 RAW 表达式的绑定借道 join 通道
    $base->addBinding($setBindings, 'join');

    $values = ['tags' => DB::raw($expr)];
    $sql = $base->getGrammar()->compileUpdate($base, $values);

    // 与 Builder::update() 的真实链路一致：prepareBindingsForUpdate() 之后还要
    // 经 cleanBindings() 过滤掉 Expression（RAW 表达式不占占位符）。
    $bindings = array_values(array_filter(
        $base->getGrammar()->prepareBindingsForUpdate($base->getRawBindings(), $values),
        static fn ($binding): bool => ! $binding instanceof \Illuminate\Database\Query\Expression
    ));

    $setPos = strpos($sql, 'set');
    $wherePos = strpos($sql, 'where');
    $firstSetPlaceholder = strpos($sql, '?', (int) $setPos);
    $wherePlaceholder = strpos($sql, '?', (int) $wherePos);

    if ($firstSetPlaceholder === false || $wherePlaceholder === false) {
        bad($name, "SQL 缺少占位符: {$sql}");

        return;
    }

    if ($firstSetPlaceholder > $wherePlaceholder) {
        bad($name, "SET 段占位符位置({$firstSetPlaceholder}) 晚于 WHERE 段({$wherePlaceholder}): {$sql}");

        return;
    }

    // 期望绑定顺序：SET 段绑定 → where 绑定
    $expected = array_merge($setBindings, [1]);
    if ($bindings !== $expected) {
        bad($name, '绑定顺序不符，期望 '.json_encode($expected).'，实际 '.json_encode($bindings)."\n  SQL: {$sql}");

        return;
    }

    ok("{$name} [SET 绑定 ".count($setBindings).' 个 → where 绑定 1 个]');
}

assertUpdateOrder('appendToJsonArray 绑定顺序', ['new-tag'], "JSON_ARRAY_APPEND(`tags`, '$', ?)");
assertUpdateOrder('setJsonValue 绑定顺序', ['$.name', '"x"'], 'JSON_SET(`meta`, ?, ?)');
assertUpdateOrder('mergeJson 绑定顺序', ['{"a":1}'], 'JSON_MERGE_PATCH(`meta`, ?)');
assertUpdateOrder('removeJsonKey 绑定顺序', ['$.a', '$.b'], 'JSON_REMOVE(JSON_REMOVE(`meta`, ?), ?)');

echo "\n========================================\n";
printf("结果: %d PASS / %d FAIL\n", $GLOBALS['pass'], $GLOBALS['fail']);
exit($GLOBALS['fail'] > 0 ? 1 : 0);
