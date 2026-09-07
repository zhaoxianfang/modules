<?php

declare(strict_types=1);

/**
 * StringFunctionsMacro 运行时验证脚本
 *
 * 在 wsf（Laravel 13.21.1）环境验证 29 个字符串函数宏：
 *  - SQL 生成正确性（MySQL grammar wrap 反引号）
 *  - 字面值参数绑定（无注入点）
 *  - 非法列名 / 方向 / 运算符 / 模式 / 填充位置拒绝
 *  - assertMysql 驱动守卫（SQLite 上抛异常）
 *
 * 运行方式：php tests/verify_string_macros.php（依赖宿主 Laravel 项目的
 * vendor/autoload.php，本脚本默认指向 /Users/aha/www/wsf，可按环境调整）。
 * 退出码：0=全 PASS，1=有 FAIL，2=FATAL（类未从本包源码加载）。
 */

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use zxf\Modules\BuilderQuery\WindowMacros\StringFunctionsMacro;

// ---------- 引导：wsf autoload + 本包源码 ----------
// 注意：wsf/vendor/zxf/modules 是本包旧副本，且其 helper.php 会在 autoload 阶段被
// require（trace bootstrap 等），导致旧版类抢先加载、setPsr4 无法覆盖。
// 因此这里直接注册从本包加载的 StringFunctionsMacro（该类在 setPsr4 之后首次加载）。
$loader = require '/Users/aha/www/wsf/vendor/autoload.php';
$loader->setPsr4('zxf\\Modules\\', '/Users/aha/www/modules/src/');

$container = new Container();
\Illuminate\Support\Facades\Facade::setFacadeApplication($container);

// Laravel 13 的 getDefaultQueryGrammar() 固定返回通用 Grammar（由连接器工厂注入），
// 独立脚本必须手动注入 MySqlGrammar 以获得反引号 wrap 行为。
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

// ---------- 注册字符串函数宏（验证源文件来自本包） ----------
$sfFile = (new ReflectionClass(StringFunctionsMacro::class))->getFileName();
if (! str_starts_with($sfFile, '/Users/aha/www/modules/')) {
    echo "FATAL StringFunctionsMacro 来自非本包源码: {$sfFile}\n";
    exit(2);
}
StringFunctionsMacro::register();

class StringMacroVerifyModel extends Model
{
    protected $table = 'articles';

    protected $guarded = [];
}

// ---------- 断言工具 ----------
$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

function check(string $name, $actual, $expected): void
{
    $actual = is_array($actual) ? json_encode($actual) : (string) $actual;
    $expected = is_array($expected) ? json_encode($expected) : (string) $expected;
    if ($actual === $expected) {
        $GLOBALS['pass']++;
        echo "PASS  {$name}\n";
    } else {
        $GLOBALS['fail']++;
        echo "FAIL  {$name}\n  expected: {$expected}\n  actual  : {$actual}\n";
    }
}

function checkThrows(string $name, callable $fn, string $msgContains = ''): void
{
    try {
        $fn();
        $GLOBALS['fail']++;
        echo "FAIL  {$name} (未抛出异常)\n";
    } catch (InvalidArgumentException $e) {
        if ($msgContains === '' || str_contains($e->getMessage(), $msgContains)) {
            $GLOBALS['pass']++;
            echo "PASS  {$name} [{$e->getMessage()}]\n";
        } else {
            $GLOBALS['fail']++;
            echo "FAIL  {$name} (异常消息不符: {$e->getMessage()})\n";
        }
    } catch (Throwable $e) {
        $GLOBALS['fail']++;
        echo "FAIL  {$name} (异常类型不符: ".get_class($e).": {$e->getMessage()})\n";
    }
}

// ---------- 1. LOCATE 分词系列 ----------
check('whereLocate SQL', StringMacroVerifyModel::query()->whereLocate('title', 'Laravel')->toSql(),
    'select * from `articles` where LOCATE(?, `title`) > 0');
check('whereLocate bindings', StringMacroVerifyModel::query()->whereLocate('title', 'Laravel')->getBindings(), ['Laravel']);

check('orWhereLocate 链式 SQL', StringMacroVerifyModel::query()->whereLocate('title', 'MySQL')->orWhereLocate('description', '数据库')->toSql(),
    'select * from `articles` where LOCATE(?, `title`) > 0 or LOCATE(?, `description`) > 0');
check('orWhereLocate bindings', StringMacroVerifyModel::query()->whereLocate('title', 'MySQL')->orWhereLocate('description', '数据库')->getBindings(),
    ['MySQL', '数据库']);

check('whereLocateAll SQL', StringMacroVerifyModel::query()->whereLocateAll('content', ['Laravel', 'MySQL'])->toSql(),
    'select * from `articles` where LOCATE(?, `content`) > 0 and LOCATE(?, `content`) > 0');
check('whereLocateAll bindings', StringMacroVerifyModel::query()->whereLocateAll('content', ['Laravel', 'MySQL'])->getBindings(),
    ['Laravel', 'MySQL']);

check('whereLocateAny SQL(嵌套OR分组)', StringMacroVerifyModel::query()->whereLocateAny('content', ['Laravel', 'MySQL'])->toSql(),
    'select * from `articles` where (LOCATE(?, `content`) > 0 or LOCATE(?, `content`) > 0)');
check('whereLocateAny bindings', StringMacroVerifyModel::query()->whereLocateAny('content', ['Laravel', 'MySQL'])->getBindings(),
    ['Laravel', 'MySQL']);

check('单引号注入被绑定化', StringMacroVerifyModel::query()->whereLocate('title', "a' OR '1'='1")->getBindings(), ["a' OR '1'='1"]);

// ---------- 2. CHAR_LENGTH 系列 ----------
check('orderByCharLength SQL', StringMacroVerifyModel::query()->orderByCharLength('name')->toSql(),
    'select * from `articles` order by CHAR_LENGTH(`name`) ASC');
check('orderByDescCharLength SQL', StringMacroVerifyModel::query()->orderByDescCharLength('title')->toSql(),
    'select * from `articles` order by CHAR_LENGTH(`title`) DESC');
check('whereCharLength SQL', StringMacroVerifyModel::query()->whereCharLength('name', '>=', 4)->toSql(),
    'select * from `articles` where CHAR_LENGTH(`name`) >= ?');
check('whereCharLength bindings', StringMacroVerifyModel::query()->whereCharLength('name', '>=', 4)->getBindings(), [4]);
check('orWhereCharLength 链式 SQL', StringMacroVerifyModel::query()->whereCharLength('name', '>=', 4)->orWhereCharLength('name', '=', 3)->toSql(),
    'select * from `articles` where CHAR_LENGTH(`name`) >= ? or CHAR_LENGTH(`name`) = ?');

check('orderByNatural SQL', StringMacroVerifyModel::query()->orderByNatural('tag')->toSql(),
    'select * from `articles` order by CAST(REGEXP_REPLACE(`tag`, \'[^0-9]\', \'\') AS UNSIGNED) ASC, `tag` ASC');
check('orderByNatural desc', StringMacroVerifyModel::query()->orderByNatural('tag', 'desc')->toSql(),
    'select * from `articles` order by CAST(REGEXP_REPLACE(`tag`, \'[^0-9]\', \'\') AS UNSIGNED) DESC, `tag` DESC');

// ---------- 3. FIELD / FIND_IN_SET 系列 ----------
check('fieldOrderBy SQL', StringMacroVerifyModel::query()->fieldOrderBy('status', ['pending', 'done'], 'asc')->toSql(),
    'select * from `articles` order by FIELD(`status`, ?, ?) ASC');
check('fieldOrderBy bindings', StringMacroVerifyModel::query()->fieldOrderBy('status', ['pending', 'done'])->getBindings(),
    ['pending', 'done']);

check('whereFindInSet SQL', StringMacroVerifyModel::query()->whereFindInSet('tags', 'php')->toSql(),
    'select * from `articles` where FIND_IN_SET(?, `tags`) > 0');
check('whereFindInSet 数值绑定', StringMacroVerifyModel::query()->whereFindInSet('recommend_ids', 5)->getBindings(), ['5']);
check('orWhereFindInSet SQL', StringMacroVerifyModel::query()->whereFindInSet('categories', 'news')->orWhereFindInSet('tags', 'hot')->toSql(),
    'select * from `articles` where FIND_IN_SET(?, `categories`) > 0 or FIND_IN_SET(?, `tags`) > 0');
check('whereFindInSetAny SQL', StringMacroVerifyModel::query()->whereFindInSetAny('tags', ['php', 'go'])->toSql(),
    'select * from `articles` where (FIND_IN_SET(?, `tags`) > 0 or FIND_IN_SET(?, `tags`) > 0)');
check('whereFindInSetAll SQL', StringMacroVerifyModel::query()->whereFindInSetAll('tags', ['php', 'mysql'])->toSql(),
    'select * from `articles` where FIND_IN_SET(?, `tags`) > 0 and FIND_IN_SET(?, `tags`) > 0');

// ---------- 4. CONCAT_WS / SUBSTRING_INDEX / GROUP_CONCAT ----------
check('whereConcatLike SQL', StringMacroVerifyModel::query()->whereConcatLike(['name', 'phone'], '张')->toSql(),
    'select * from `articles` where CONCAT_WS(\' \', `name`, `phone`) LIKE ?');
check('whereConcatLike bindings', StringMacroVerifyModel::query()->whereConcatLike(['name', 'phone'], '张')->getBindings(), ['%张%']);
check('orWhereConcatLike SQL', StringMacroVerifyModel::query()->whereLocate('remark', '加急')->orWhereConcatLike(['title', 'content'], '催单')->toSql(),
    'select * from `articles` where LOCATE(?, `remark`) > 0 or CONCAT_WS(\' \', `title`, `content`) LIKE ?');

check('substringIndex SQL', StringMacroVerifyModel::query()->substringIndex('email', '@', 1, 'username_part')->toSql(),
    'select SUBSTRING_INDEX(`email`, ?, ?) AS `username_part` from `articles`');
check('substringIndex bindings', StringMacroVerifyModel::query()->substringIndex('email', '@', 1, 'username_part')->getBindings(), ['@', 1]);
check('substringIndex 负count', StringMacroVerifyModel::query()->substringIndex('ip', '.', -1, 'last_segment')->toSql(),
    'select SUBSTRING_INDEX(`ip`, ?, ?) AS `last_segment` from `articles`');

check('groupConcat SQL', StringMacroVerifyModel::query()->groupConcat('title', 'titles')->toSql(),
    'select GROUP_CONCAT(`title` SEPARATOR ?) AS `titles` from `articles`');
check('groupConcat bindings', StringMacroVerifyModel::query()->groupConcat('title', 'titles')->getBindings(), [',']);
check('groupConcat distinct+自定义分隔符', StringMacroVerifyModel::query()->groupConcat('product_name', 'names', '|', true)->toSql(),
    'select GROUP_CONCAT(DISTINCT `product_name` SEPARATOR ?) AS `names` from `articles`');

// ---------- 5. 全文检索 ----------
check('whereFullText SQL', StringMacroVerifyModel::query()->whereFullText(['title', 'body'], 'Laravel')->toSql(),
    'select * from `articles` where MATCH(`title`, `body`) AGAINST (? IN NATURAL LANGUAGE MODE) > 0');
check('whereFullText bindings', StringMacroVerifyModel::query()->whereFullText(['title', 'body'], 'Laravel')->getBindings(), ['Laravel']);
check('whereFullText expansion', StringMacroVerifyModel::query()->whereFullText('body', '优化', 'expansion')->toSql(),
    'select * from `articles` where MATCH(`body`) AGAINST (? WITH QUERY EXPANSION) > 0');
check('whereFullTextBoolean SQL', StringMacroVerifyModel::query()->whereFullTextBoolean('title', '+Laravel -Vue')->toSql(),
    'select * from `articles` where MATCH(`title`) AGAINST (? IN BOOLEAN MODE) > 0');
check('orderByFullTextRelevance SQL', StringMacroVerifyModel::query()->orderByFullTextRelevance(['title', 'body'], 'Laravel')->toSql(),
    'select * from `articles` order by MATCH(`title`, `body`) AGAINST (? IN NATURAL LANGUAGE MODE) DESC');
check('orderByFullTextRelevance asc', StringMacroVerifyModel::query()->orderByFullTextRelevance('title', 'Laravel', 'natural', 'asc')->toSql(),
    'select * from `articles` order by MATCH(`title`) AGAINST (? IN NATURAL LANGUAGE MODE) ASC');

// ---------- 6. SOUNDEX ----------
check('whereSoundex SQL', StringMacroVerifyModel::query()->whereSoundex('last_name', 'Smith')->toSql(),
    'select * from `articles` where SOUNDEX(`last_name`) = SOUNDEX(?)');
check('orWhereSoundex SQL', StringMacroVerifyModel::query()->whereSoundex('first_name', 'John')->orWhereSoundex('last_name', 'Jonson')->toSql(),
    'select * from `articles` where SOUNDEX(`first_name`) = SOUNDEX(?) or SOUNDEX(`last_name`) = SOUNDEX(?)');

// ---------- 7. 字符串变换 ----------
check('replaceString SQL', StringMacroVerifyModel::query()->replaceString('phone', '138', '***', 'masked')->toSql(),
    'select REPLACE(`phone`, ?, ?) AS `masked` from `articles`');
check('trimString SQL', StringMacroVerifyModel::query()->trimString('name', 'clean')->toSql(),
    'select TRIM(`name`) AS `clean` from `articles`');
check('lowerString SQL', StringMacroVerifyModel::query()->lowerString('email', 'el')->toSql(),
    'select LOWER(`email`) AS `el` from `articles`');
check('upperString SQL', StringMacroVerifyModel::query()->upperString('code', 'cu')->toSql(),
    'select UPPER(`code`) AS `cu` from `articles`');
check('reverseString SQL', StringMacroVerifyModel::query()->reverseString('phone', 'rp')->toSql(),
    'select REVERSE(`phone`) AS `rp` from `articles`');
check('padString left SQL', StringMacroVerifyModel::query()->padString('order_no', 8, '0', 'left', 'pn')->toSql(),
    'select LPAD(`order_no`, ?, ?) AS `pn` from `articles`');
check('padString right SQL', StringMacroVerifyModel::query()->padString('order_no', 8, '0', 'right', 'pn')->toSql(),
    'select RPAD(`order_no`, ?, ?) AS `pn` from `articles`');
check('padString bindings', StringMacroVerifyModel::query()->padString('order_no', 8, '0', 'left', 'pn')->getBindings(), [8, '0']);

// ---------- 8. 注入守卫（应抛异常） ----------
checkThrows('非法列名注入', fn () => StringMacroVerifyModel::query()->whereLocate("title' OR '1'='1", 'x'), '非法的 SQL column标识符');
checkThrows('列名含空格', fn () => StringMacroVerifyModel::query()->whereLocate('a b', 'x'), '非法的 SQL column标识符');
checkThrows('非法排序方向', fn () => StringMacroVerifyModel::query()->orderByCharLength('name', 'sideways'), '非法的排序方向');
checkThrows('非法运算符', fn () => StringMacroVerifyModel::query()->whereCharLength('name', '==>', 4), '非法的比较运算符');
checkThrows('非法全文模式', fn () => StringMacroVerifyModel::query()->whereFullText('title', 'x', 'badmode'), '非法的全文检索模式');
checkThrows('padString 非法位置', fn () => StringMacroVerifyModel::query()->padString('no', 8, '0', 'middle'), '填充位置必须为 left 或 right');
checkThrows('非法别名', fn () => StringMacroVerifyModel::query()->substringIndex('email', '@', 1, 'a b'), '非法的 SQL alias标识符');
checkThrows('非法多列拼接字段', fn () => StringMacroVerifyModel::query()->whereConcatLike(['name; DROP'], 'x'), '非法的 SQL column标识符');

// ---------- 9. assertMysql 驱动守卫 ----------
$sqliteConnection = new Connection(new \PDO('sqlite::memory:'), 'sqlite', '', ['driver' => 'sqlite']);
$sqliteQuery = new \Illuminate\Database\Query\Builder($sqliteConnection);
checkThrows('SQLite 上调用 whereLocate 被拒绝', fn () => StringFunctionsMacro::whereLocate($sqliteQuery, 'title', 'x'), '仅支持 MySQL 8.0+');
checkThrows('SQLite 上调用 whereFullText 被拒绝', fn () => StringFunctionsMacro::whereFullText($sqliteQuery, 'title', 'x'), '仅支持 MySQL 8.0+');

// ---------- 汇总 ----------
echo "\n========================================\n";
printf("结果: %d PASS / %d FAIL\n", $GLOBALS['pass'], $GLOBALS['fail']);
exit($GLOBALS['fail'] > 0 ? 1 : 0);
