<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;
use zxf\Modules\BuilderQuery\MacrosBuilder;
use zxf\Modules\BuilderQuery\WhereHasMacros\Concerns\BaseWhereHas;
use zxf\Modules\BuilderQuery\WhereHasMacros\WhereHasIn;
use zxf\Modules\BuilderQuery\WhereHasMacros\WhereHasJoin;
use zxf\Modules\BuilderQuery\WhereHasMacros\WhereHasLeftJoin;
use zxf\Modules\BuilderQuery\WhereHasMacros\WhereHasNotIn;

/**
 * whereHas 系列宏的运行时验证脚本（在 wsf Laravel 13 宿主环境验证生成的 SQL 正确性）。
 *
 * 验证重点：
 *  1. HasManyThrough / HasOneThrough 正确降级到原生 whereHas（外层 IN 列取错会让 SQL 必然出错）
 *  2. 多态类型列不被重复限定（重复限定会生成 images.images.imageable_type，永远报错）
 *  3. LEFT / RIGHT JOIN：关联等值条件在 ON、业务筛选在 WHERE（否则语义被降级为 INNER JOIN）
 *  4. CROSS JOIN：条件下沉到 WHERE（PostgreSQL 下 ON 属语法错误）
 *  5. 嵌套关联 'a.b' 在 JOIN 家族不再被静默丢弃
 *  6. whereHasNotIn 的 NULL 陷阱已加 IS NOT NULL 守卫
 *  7. 多态别名（morphMap 注册的 alias）能被正确解析，不再被静默跳过
 *
 * 运行：php tests/verify_wherehas_macros.php（依赖宿主 vendor/autoload.php，默认 /Users/aha/www/wsf）
 * 退出码：0 = 全 PASS，1 = 有 FAIL，2 = FATAL。
 */

// ---------- 优先加载本包源码（见 verify_recursive_macros.php 的同类说明） ----------
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

function check(string $name, mixed $actual, mixed $expected): void
{
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
        echo "PASS  {$name}\n";

        return;
    }
    $fail++;
    echo "FAIL  {$name}\n";
    echo '        期望: '.var_export($expected, true)."\n";
    echo '        实际: '.var_export($actual, true)."\n";
}

function checkContains(string $name, string $haystack, string $needle): void
{
    global $pass, $fail;
    if (str_contains($haystack, $needle)) {
        $pass++;
        echo "PASS  {$name}\n";

        return;
    }
    $fail++;
    echo "FAIL  {$name}\n";
    echo "        期望包含: {$needle}\n";
    echo "        实际:      {$haystack}\n";
}

function checkNotContains(string $name, string $haystack, string $needle): void
{
    global $pass, $fail;
    if (! str_contains($haystack, $needle)) {
        $pass++;
        echo "PASS  {$name}\n";

        return;
    }
    $fail++;
    echo "FAIL  {$name}\n";
    echo "        不应包含: {$needle}\n";
    echo "        实际:      {$haystack}\n";
}

function checkThrows(string $name, callable $fn, string $needle = ''): void
{
    global $pass, $fail;
    try {
        $fn();
        $fail++;
        echo "FAIL  {$name} (未抛出异常)\n";
    } catch (InvalidArgumentException $e) {
        if ($needle === '' || str_contains($e->getMessage(), $needle)) {
            $pass++;
            echo "PASS  {$name}\n";
        } else {
            $fail++;
            echo "FAIL  {$name} (消息不符: {$e->getMessage()})\n";
        }
    } catch (Throwable $e) {
        $fail++;
        echo "FAIL  {$name} (异常类型不符: ".$e::class.': '.$e->getMessage().")\n";
    }
}

// ---------- 连接（MySqlGrammar 以获得反引号 wrap） ----------
$container = new Container;
Facade::setFacadeApplication($container);
// app() 辅助函数走 Container::getInstance()，必须显式设置，否则 app('config') 会失败
Container::setInstance($container);

// 关联解析链路会读取 config('database.*')（跨库关联的库名前缀），
// 这里注入一个最小配置仓库，避免出现 "Target class [config] does not exist"。
$container->instance('config', new Repository([
    'database' => [
        'default' => 'default',
        'connections' => [
            'default' => ['driver' => 'mysql', 'database' => 'demo'],
        ],
    ],
]));

$connection = new Connection(new PDO('sqlite::memory:'), 'default', '', ['driver' => 'mysql']);
$connection->setQueryGrammar(new MySqlGrammar($connection));
$resolver = new class($connection) implements ConnectionResolverInterface
{
    private Connection $conn;

    public function __construct(Connection $conn)
    {
        $this->conn = $conn;
    }

    public function connection($name = null): Connection
    {
        return $this->conn;
    }

    public function getDefaultConnection(): string
    {
        return 'default';
    }

    public function setDefaultConnection($name)
    {
        return 'default';
    }
};
Model::setConnectionResolver($resolver);
Model::setEventDispatcher(new Dispatcher($container));

// ---------- 测试模型 ----------
class WHUser extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public function orders(): HasMany
    {
        return $this->hasMany(WHOrder::class, 'user_id', 'id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(WHProfile::class, 'profile_id', 'id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(WHCountry::class, 'country_id', 'id');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(WHComment::class, 'commentable');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(WHTag::class, 'taggables', 'taggable_id', 'tag_id');
    }

    /** HasManyThrough：users.country_id → countries.id → posts.country_id */
    public function postsViaCountry(): HasManyThrough
    {
        return $this->hasManyThrough(WHPost::class, WHCountry::class, 'id', 'country_id');
    }
}

class WHOrder extends Model
{
    protected $table = 'orders';

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(WHUser::class, 'user_id', 'id');
    }
}

class WHProfile extends Model
{
    protected $table = 'profiles';

    protected $guarded = [];
}

class WHCountry extends Model
{
    protected $table = 'countries';

    protected $guarded = [];
}

class WHPost extends Model
{
    protected $table = 'posts';

    protected $guarded = [];
}

class WHComment extends Model
{
    protected $table = 'comments';

    protected $guarded = [];

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}

class WHTag extends Model
{
    protected $table = 'tags';

    protected $guarded = [];
}

/** 持有 morph type 列的模型（用于直接测试 whereHasMorphIn） */
class WHTicket extends Model
{
    protected $table = 'tickets';

    protected $guarded = [];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}

// 注册多态别名（验证 alias 解析）
Relation::morphMap(['user' => WHUser::class]);

// MacrosBuilder::register() 需要一个 ServiceProvider（部分宏会从中取 app/config）；
// 本脚本不启动完整 Laravel 应用，这里提供一个最小匿名 Provider 即可。
$provider = new class($container) extends ServiceProvider {};
MacrosBuilder::register($provider);

// ---------- 1. whereHasIn ----------
$sql = WHUser::query()->whereHasIn('orders')->toSql();
checkContains('whereHasIn HasMany 输出相关子查询 + 外层主键 IN', $sql, '`users`.`id` in (select distinct `orders`.`user_id` from `orders`');
checkContains('whereHasIn HasMany 保持相关子查询（外层列出现在子查询 where 中）', $sql, 'where `users`.`id` = `orders`.`user_id`');

checkContains(
    'whereHasIn BelongsTo 外层用主表外键',
    WHUser::query()->whereHasIn('profile')->toSql(),
    '`users`.`profile_id` in (select distinct `profiles`.`id` from `profiles`'
);

checkContains(
    'whereHasIn 回调条件进入子查询',
    WHUser::query()->whereHasIn('orders', fn ($q) => $q->where('status', 'paid'))->toSql(),
    '`status` = ?'
);

checkContains(
    'whereHasIn BelongsToMany 走中间表外键',
    WHUser::query()->whereHasIn('tags')->toSql(),
    '`users`.`id` in (select distinct `taggables`.`taggable_id` from `tags` inner join `taggables` on `tags`.`id` = `taggables`.`tag_id`'
);

checkContains(
    'whereHasIn MorphMany 带多态类型过滤',
    WHUser::query()->whereHasIn('comments')->toSql(),
    '`comments`.`commentable_type` = ?'
);
checkNotContains(
    'whereHasIn MorphMany 多态列不重复限定（修复 1：table.table.type）',
    WHUser::query()->whereHasIn('comments')->toSql(),
    'comments`.`comments'
);

// HasManyThrough 降级为原生 whereHas（修复 1）
$throughSql = WHUser::query()->whereHasIn('postsViaCountry')->toSql();
checkContains('whereHasIn HasManyThrough 降级为 exists 子查询', $throughSql, 'exists (select * from `posts`');
checkNotContains('whereHasIn HasManyThrough 不再使用错误的 farParent 本地键', $throughSql, '`countries`.`id` in (select distinct');

// MorphTo 明确报错
checkThrows(
    'whereHasIn 遇 MorphTo 给出明确提示',
    fn () => WHUser::query()->whereHasIn('comments.commentable'),
    'whereHasIn 不支持 MorphTo'
);

// ---------- 2. whereHasNotIn（NULL 陷阱修复） ----------
$notInSql = WHUser::query()->whereHasNotIn('orders')->toSql();
checkContains('whereHasNotIn 使用 not in', $notInSql, '`users`.`id` not in (select distinct');
checkContains('whereHasNotIn 追加 IS NOT NULL 守卫（修复 NOT IN 的 NULL 陷阱）', $notInSql, '`users`.`id` is not null');

// ---------- 3. whereHasJoin / LeftJoin / RightJoin / CrossJoin ----------
$joinSql = WHUser::query()->whereHasJoin('orders')->toSql();
checkContains('whereHasJoin 生成 inner join', $joinSql, 'inner join `orders` on `orders`.`user_id` = `users`.`id`');

$joinFiltered = WHUser::query()->whereHasJoin('orders', fn ($q) => $q->where('status', 'paid'))->toSql();
checkContains('whereHasJoin 回调条件在 where 中', $joinFiltered, 'on `orders`.`user_id` = `users`.`id` where ((`status` = ?))');
checkNotContains('whereHasJoin 回调条件不得混入 on 子句', $joinFiltered, 'on `orders`.`user_id` = `users`.`id` and');

$leftSql = WHUser::query()->whereHasLeftJoin('orders')->toSql();
checkContains('whereHasLeftJoin 生成 left join', $leftSql, 'left join `orders` on `orders`.`user_id` = `users`.`id`');

$leftFiltered = WHUser::query()->whereHasLeftJoin('orders', fn ($q) => $q->where('status', 'paid'))->toSql();
checkContains('whereHasLeftJoin 等值条件仍在 on（保留未匹配主表记录的关键）', $leftFiltered, 'left join `orders` on `orders`.`user_id` = `users`.`id`');
checkContains('whereHasLeftJoin 业务筛选在 where', $leftFiltered, 'where ((`status` = ?))');

$rightSql = WHUser::query()->whereHasRightJoin('profile')->toSql();
checkContains('whereHasRightJoin 生成 right join', $rightSql, 'right join `profiles` on `users`.`profile_id` = `profiles`.`id`');

$crossSql = WHUser::query()->whereHasCrossJoin('orders')->toSql();
checkContains('whereHasCrossJoin 生成 cross join', $crossSql, 'cross join `orders`');
checkContains('whereHasCrossJoin 等值条件下沉到 where（修复 PostgreSQL 语法错误）', $crossSql, 'where (`orders`.`user_id` = `users`.`id`)');

// ---------- 4. 嵌套关联（'a.b' 在 JOIN 家族不再被静默丢弃） ----------
$nestedJoin = WHUser::query()->whereHasJoin('orders.user')->toSql();
checkContains('whereHasJoin 支持点号嵌套：orders 的等值条件', $nestedJoin, '`orders`.`user_id` = `users`.`id`');
checkContains('whereHasJoin 支持点号嵌套：user 的等值条件（未被消费就会被静默丢弃）', $nestedJoin, 'inner join `users` on `orders`.`user_id` = `users`.`id`');

$nestedIn = WHUser::query()->whereHasIn('orders.user')->toSql();
checkContains('whereHasIn 支持点号嵌套', $nestedIn, '`orders`.`user_id` in (select distinct `users`.`id`');

// ---------- 5. 多态：whereHasMorphIn（修复 2 / 3 / 4） ----------
$morphSql = WHTicket::query()->whereHasMorphIn('subject', [WHUser::class])->toSql();
checkNotContains(
    'whereHasMorphIn 多态类型列不重复限定（重复限定必然报未知列）',
    $morphSql,
    '`.`tickets`.`subject_type'
);
checkContains('whereHasMorphIn 含多态类型等值条件', $morphSql, '`tickets`.`subject_type` = ?');

$morphAliasSql = WHTicket::query()->whereHasMorphIn('subject', ['user'])->toSql();
checkContains('whereHasMorphIn 支持已注册的 morphMap 别名（修复 3）', $morphAliasSql, 'subject_type');

checkThrows(
    'whereHasMorphIn 非 morphTo 关系给出明确提示（修复 4）',
    fn () => WHUser::query()->whereHasMorphIn('orders', [WHUser::class]),
    '只支持 morphTo 关系'
);

// ---------- 6. 链式与幂等 ----------
$chain = WHUser::query()
    ->whereHasJoin('orders', fn ($q) => $q->where('status', 'paid'))
    ->where('active', 1)
    ->orderByDesc('users.id')
    ->limit(10)
    ->toSql();
checkContains('whereHasJoin 可继续链式调用', $chain, 'order by `users`.`id` desc limit 10');

$twice = WHUser::query()->whereHasJoin('orders')->whereHasJoin('orders')->toSql();
check('whereHasJoin 同表同条件重复调用只 JOIN 一次', substr_count($twice, 'inner join `orders`'), 1);

$twiceDiff = WHUser::query()->whereHasJoin('orders')->whereHasJoin('profile')->toSql();
check('whereHasJoin 不同关联分别 JOIN', substr_count($twiceDiff, 'join `'), 2);

// 数组形式关联名
$arraySql = WHUser::query()->whereHasIn(['orders', 'tags'])->toSql();
checkContains('whereHasIn 支持数组关联名（AND 组合）', $arraySql, 'and `users`.`id` in (select distinct `taggables`.`taggable_id`');

check('BaseWhereHas 抽象基类可被继承', is_subclass_of(WhereHasIn::class, BaseWhereHas::class), true);
check('whereHasNotIn 继承 whereHasIn', is_subclass_of(WhereHasNotIn::class, WhereHasIn::class), true);
check('LEFT JOIN 变体继承 whereHasJoin', is_subclass_of(WhereHasLeftJoin::class, WhereHasJoin::class), true);

// ---------- 7. 不支持的关系类型给出可操作提示 ----------
checkThrows(
    'whereHasJoin 遇 BelongsToMany 提示改用 whereHasIn',
    fn () => WHUser::query()->whereHasJoin('tags'),
    'whereHasIn'
);

// ---------- 汇总 ----------
echo "\n结果: {$pass} PASS / {$fail} FAIL\n";

exit($fail === 0 ? 0 : 1);
