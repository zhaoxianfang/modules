<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use zxf\Modules\BuilderQuery\Concerns\BaseMacro;

/**
 * 递归查询宏类 - 树形结构数据处理的完整解决方案
 * 提供 MySQL 8.0+ / PostgreSQL 15+ 通用 WITH RECURSIVE 递归 CTE 的封装，支持：
 * - 上下级递归查询（withAllChildren/withAllParents）
 * - 路径追踪（withBreadcrumbs/withFullPath）
 * - 层级关系判断（isParentOf/isChildOf）
 * - 树形构建（withTree/withRoot/withLeafNodes）
 * 安全性：所有表名 / 列名 / 别名均经白名单校验并由 grammar wrap（适配 MySQL 反引号
 * 与 PostgreSQL 双引号），字面值走参数绑定；递归 CTE 为双方言标准语法（WITH RECURSIVE）。
 *
 * @version 1.13.9
 *
 * @requires PHP 8.3+, Laravel 11+ / 12+ / 13+, MySQL 8.0+ / PostgreSQL 15+
 */
class WithRecursiveMacro extends BaseMacro
{
    /**
     * @var mixed 根节点值，默认0，可设为null表示根节点parent_id为NULL
     */
    protected static mixed $rootValue = 0;

    /**
     * @var int 默认最大递归深度，防止无限递归
     */
    protected static int $defaultMaxDepth = 100;

    /**
     * 设置根节点值
     *
     * @param  mixed  $value  根节点标识值，null表示使用IS NULL判断
     */
    public static function setRootValue(mixed $value): void
    {
        WithRecursiveMacro::$rootValue = $value;
    }

    /**
     *  获取当前根节点值
     */
    public static function getRootValue(): mixed
    {
        return WithRecursiveMacro::$rootValue;
    }

    /**
     *  设置默认最大递归深度
     */
    public static function setDefaultMaxDepth(int $depth): void
    {
        WithRecursiveMacro::$defaultMaxDepth = max(1, $depth);
    }

    /**
     *  获取默认最大递归深度
     */
    public static function getDefaultMaxDepth(): int
    {
        return WithRecursiveMacro::$defaultMaxDepth;
    }

    /**
     *  生成唯一的WITH临时表名
     */
    public static function generateWithTableName(): string
    {
        return 'recursive_'.Str::random(8);
    }

    /**
     * 注册所有递归查询宏
     * 分类：1.上下级递归 2.路径导航 3.关系判断 4.树形操作 5.高级查询
     */
    public static function register(): void
    {
        // ========== 1. 上下级递归查询 ==========

        /**
         *  获取所有后代子节点（向下递归）
         *
         * @example
         * Model::query()->withAllChildren('col', 'val')->get();
         */
        static::define('withAllChildren', function (int $id, string $pidColumn = 'pid', int $maxDepth = 100, bool $includeSelf = false): Builder|\Illuminate\Database\Query\Builder {
            return WithRecursiveMacro::buildRecursiveQuery($this, $id, $pidColumn, 'children', $maxDepth, $includeSelf);
        });

        /**
         *  获取所有祖先父节点（向上递归）
         *
         * @example
         * Model::query()->withAllParents('col', 'val')->get();
         */
        static::define('withAllParents', function (int $id, string $pidColumn = 'pid', int $maxDepth = 100, bool $includeSelf = false): Builder|\Illuminate\Database\Query\Builder {
            return WithRecursiveMacro::buildRecursiveQuery($this, $id, $pidColumn, 'parents', $maxDepth, $includeSelf);
        });

        /**
         *  获取第N级父节点（0=自身, 1=父节点, 2=祖父）
         *
         * @example
         * Model::query()->withNthParent('col', 'val')->get();
         */
        static::define('withNthParent', function (int $id, int $n, string $pidColumn = 'pid'): Builder|\Illuminate\Database\Query\Builder {
            return WithRecursiveMacro::findNthLevelRelation($this, $id, $pidColumn, 'parents', $n);
        });

        /**
         *  获取第N级子节点（1=子节点, 2=孙节点）
         *
         * @example
         * Model::query()->withNthChildren('col', 'val')->get();
         */
        static::define('withNthChildren', function (int $id, int $n, string $pidColumn = 'pid'): Builder|\Illuminate\Database\Query\Builder {
            return WithRecursiveMacro::findNthLevelRelation($this, $id, $pidColumn, 'children', $n);
        });

        // ========== 2. 路径与导航 ==========

        /**
         *  获取完整路径信息（返回absolute_path, path_ids, depth字段）
         *
         * @example
         * Model::query()->withFullPath('col', 'val')->get();
         */
        static::define('withFullPath', function (array $ids = [], array $conditions = [], string $pidColumn = 'pid', string $nameColumn = 'name', string $pathSeparator = ' > '): Builder|\Illuminate\Database\Query\Builder {
            return WithRecursiveMacro::findNodePaths($this, $ids, $conditions, $pidColumn, $nameColumn, $pathSeparator);
        });

        /**
         *  获取面包屑路径（从根到当前节点，按depth升序）
         *
         * @example
         * Model::query()->withBreadcrumbs('col', 'val')->get();
         */
        static::define('withBreadcrumbs', function (int $id, string $pidColumn = 'pid', string $nameColumn = 'name'): Builder|\Illuminate\Database\Query\Builder {
            return WithRecursiveMacro::findBreadcrumbs($this, $id, $pidColumn, $nameColumn);
        });

        /**
         *  获取节点到根节点的路径长度
         *
         * @example
         * Model::query()->withPathLength('col', 'val')->get();
         */
        static::define('withPathLength', function (int $id, string $pidColumn = 'pid'): Builder|\Illuminate\Database\Query\Builder {
            return WithRecursiveMacro::findPathLength($this, $id, $pidColumn);
        });

        // ========== 3. 关系判断 ==========

        /**
         *  判断parentId是否为childId的祖先节点（strict=true只检查直接父节点）
         *
         * @example
         * Model::query()->isParentOf('col', 'val')->get();
         */
        static::define('isParentOf', function (int $parentId, int $childId, string $pidColumn = 'pid', bool $strict = false): bool {
            return WithRecursiveMacro::checkIsParent($this, $parentId, $childId, $pidColumn, $strict);
        });

        /**
         *  判断childId是否为parentId的后代节点
         *
         * @example
         * Model::query()->isChildOf('col', 'val')->get();
         */
        static::define('isChildOf', function (int $childId, int $parentId, string $pidColumn = 'pid', bool $strict = false): bool {
            return WithRecursiveMacro::checkIsParent($this, $parentId, $childId, $pidColumn, $strict);
        });

        /**
         *  查找两个节点的最近公共祖先（LCA）
         *
         * @example
         * Model::query()->withNearestAncestor('col', 'val')->get();
         */
        static::define('withNearestAncestor', function (int $id1, int $id2, string $pidColumn = 'pid'): Builder|\Illuminate\Database\Query\Builder {
            return WithRecursiveMacro::findNearestAncestor($this, $id1, $id2, $pidColumn);
        });

        // ========== 4. 树形结构操作 ==========

        /**
         *  获取同级节点（兄弟节点），includeSelf控制是否包含自身
         *
         * @example
         * Model::query()->withSiblings('col', 'val')->get();
         */
        static::define('withSiblings', function (int $id, string $pidColumn = 'pid', bool $includeSelf = false): Builder|\Illuminate\Database\Query\Builder {
            return WithRecursiveMacro::findSiblings($this, $id, $pidColumn, $includeSelf);
        });

        /**
         *  构建完整树形结构，返回包含tree_path, path_ids, depth字段
         *
         * @example
         * Model::query()->withTree('col', 'val')->get();
         */
        static::define('withTree', function (?int $pid = null, string $pidColumn = 'pid', string $nameColumn = 'name', int $maxDepth = 100, string $pathSeparator = ' > '): Builder|\Illuminate\Database\Query\Builder {
            return WithRecursiveMacro::buildTreeQuery($this, $pid, $pidColumn, $nameColumn, $maxDepth, $pathSeparator);
        });

        /**
         *  获取所有根节点（parent_id等于rootValue或为null）
         *
         * @example
         * Model::query()->withRoot('col', 'val')->get();
         */
        static::define('withRoot', function (string $pidColumn = 'pid'): Builder|\Illuminate\Database\Query\Builder {
            $rootValue = WithRecursiveMacro::getRootValue();

            return $rootValue === null
                ? $this->whereNull($pidColumn)
                : $this->where($pidColumn, $rootValue);
        });

        /**
         *  获取所有叶子节点（没有子节点的节点）
         *
         * @example
         * Model::query()->withLeafNodes('col', 'val')->get();
         */
        static::define('withLeafNodes', function (string $pidColumn = 'pid'): Builder|\Illuminate\Database\Query\Builder {
            $model = BaseMacro::modelOf($this);
            $table = BaseMacro::resolveTable($this);
            $primaryKey = BaseMacro::keyName($this);

            return $this->whereNotExists(function ($query) use ($table, $primaryKey, $pidColumn) {
                $query->select(DB::raw(1))
                    ->from("{$table} as children")
                    ->whereColumn("children.{$pidColumn}", "{$table}.{$primaryKey}");
            });
        });

        /**
         *  获取指定节点的后代节点总数（不含自身）
         *
         * @example
         * Model::query()->withDescendantsCount('col', 'val')->get();
         */
        static::define('withDescendantsCount', function (int $id, string $pidColumn = 'pid'): Builder|\Illuminate\Database\Query\Builder {
            return WithRecursiveMacro::getDescendantsCount($this, $id, $pidColumn);
        });

        // ========== 5. 高级递归查询 ==========

        /**
         *  通用递归CTE查询，支持自定义基础查询和递归查询回调
         *
         * @example
         * Model::query()->recursiveQuery('col', 'val')->get();
         */
        static::define('recursiveQuery', function (callable $baseQuery, callable $recursiveQuery, array $columns = ['*'], int $maxDepth = 100, string $depthColumn = 'depth'): Builder|\Illuminate\Database\Query\Builder {
            return WithRecursiveMacro::buildGenericRecursiveQuery($this, $baseQuery, $recursiveQuery, $columns, $maxDepth, $depthColumn);
        });

        /**
         *  重置递归查询状态
         *
         * @example
         * Model::query()->resetRecursive('col', 'val')->get();
         */
        static::define('resetRecursive', function (): Builder|\Illuminate\Database\Query\Builder {
            return WithRecursiveMacro::resetRecursiveQuery($this);
        });
    }

    /**
     * 构建通用递归查询
     * 支持向上（parents）和向下（children）两个方向的递归查询
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query  查询构建器实例
     * @param  int  $id  起始节点ID
     * @param  string  $pidColumn  父ID字段名
     * @param  string  $direction  递归方向：'children'=向下，'parents'=向上
     * @param  int  $maxDepth  最大递归深度
     * @param  bool  $includeSelf  是否包含起始节点
     */
    public static function buildRecursiveQuery(
        Builder|\Illuminate\Database\Query\Builder $query,
        int $id,
        string $pidColumn,
        string $direction,
        int $maxDepth,
        bool $includeSelf = false
    ): Builder|\Illuminate\Database\Query\Builder {
        $table = BaseMacro::resolveTable($query);
        $primaryKey = BaseMacro::keyName($query);
        $pidColumn = BaseMacro::assertValidIdentifier($pidColumn, 'pidColumn');
        $wTable = BaseMacro::wrapIdentifier($query, $table);
        $wPk = BaseMacro::wrapIdentifier($query, $primaryKey);
        $wPid = BaseMacro::wrapIdentifier($query, $pidColumn);
        $withTable = WithRecursiveMacro::generateWithTableName();
        $wWith = BaseMacro::wrapIdentifier($query, $withTable);

        // 构建递归连接条件：向下=子节点.parent_id = 父节点.id，向上=父节点.id = 子节点.parent_id
        $joinCondition = $direction === 'children'
            ? "t.{$wPid} = r.{$wPk}"
            : "t.{$wPk} = r.{$wPid}";

        // 构建递归CTE查询（WITH RECURSIVE 为双方言标准语法）
        $recursiveQuery = "WITH RECURSIVE {$wWith} AS (
            -- 锚定成员：起始节点
            SELECT *, 0 AS depth
            FROM {$wTable}
            WHERE {$wPk} = ?

            UNION ALL

            -- 递归成员：根据方向向上或向下递归
            SELECT t.*, r.depth + 1
            FROM {$wTable} t
            JOIN {$wWith} r ON {$joinCondition}
            WHERE r.depth < ?
        )
        SELECT * FROM {$wWith}".($includeSelf ? '' : " WHERE {$wPk} != ?");

        // 绑定参数
        $bindings = $includeSelf ? [$id, $maxDepth] : [$id, $maxDepth, $id];

        // 复用原查询对象：保留调用方已设置的 select / where / order / limit 等全部条件，
        // 仅将数据源替换为递归 CTE 派生表。绑定顺序 select → from → where 与编译后 SQL 顺序一致，
        // 因此 CTE 内部的占位符绑定统一走 from 通道。
        BaseMacro::queryOf($query)->from(new Expression("({$recursiveQuery}) as {$wWith}"));
        $query->addBinding($bindings, 'from');

        return $query;
    }

    /**
     * 查找第N层级的关系节点
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query  查询构建器实例
     * @param  int  $id  起始节点ID
     * @param  string  $pidColumn  父ID字段名
     * @param  string  $direction  递归方向：'children' 或 'parents'
     * @param  int  $n  目标层级（0=自身，1=直接父/子，2=祖父/孙）
     */
    public static function findNthLevelRelation(
        Builder|\Illuminate\Database\Query\Builder $query,
        int $id,
        string $pidColumn,
        string $direction,
        int $n
    ): Builder|\Illuminate\Database\Query\Builder {
        $table = BaseMacro::resolveTable($query);
        $primaryKey = BaseMacro::keyName($query);
        $pidColumn = BaseMacro::assertValidIdentifier($pidColumn, 'pidColumn');
        $wTable = BaseMacro::wrapIdentifier($query, $table);
        $wPk = BaseMacro::wrapIdentifier($query, $primaryKey);
        $wPid = BaseMacro::wrapIdentifier($query, $pidColumn);
        $withTable = WithRecursiveMacro::generateWithTableName();
        $wWith = BaseMacro::wrapIdentifier($query, $withTable);

        $joinCondition = $direction === 'children'
            ? "t.{$wPid} = r.{$wPk}"
            : "t.{$wPk} = r.{$wPid}";

        $recursiveQuery = "WITH RECURSIVE {$wWith} AS (
            SELECT *, 0 AS relative_level FROM {$wTable} WHERE {$wPk} = ?
            UNION ALL
            SELECT t.*, r.relative_level + 1
            FROM {$wTable} t JOIN {$wWith} r ON {$joinCondition}
            WHERE r.relative_level < ?
        ) SELECT * FROM {$wWith} WHERE relative_level = ?";

        // 复用原查询对象：保留调用方已设置的 select / where / order / limit 等全部条件，
        // 仅将数据源替换为递归 CTE 派生表。绑定顺序 select → from → where 与编译后 SQL 顺序一致，
        // 因此 CTE 内部的占位符绑定统一走 from 通道。
        BaseMacro::queryOf($query)->from(new Expression("({$recursiveQuery}) as {$wWith}"));
        $query->addBinding([$id, $n, $n], 'from');

        return $query;
    }

    /**
     * 查找节点路径信息
     * 返回包含absolute_path（名称路径）、path_ids（ID链）、depth（深度）字段的结果
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query  查询构建器实例
     * @param  array  $ids  目标节点ID数组
     * @param  array  $conditions  额外筛选条件（如 ['status' => 1]）
     * @param  string  $pidColumn  父ID字段名
     * @param  string  $nameColumn  名称字段名
     * @param  string  $pathSeparator  路径分隔符
     */
    public static function findNodePaths(
        Builder|\Illuminate\Database\Query\Builder $query,
        array $ids = [],
        array $conditions = [],
        string $pidColumn = 'pid',
        string $nameColumn = 'name',
        string $pathSeparator = ' > '
    ): Builder|\Illuminate\Database\Query\Builder {
        $table = BaseMacro::resolveTable($query);
        $primaryKey = BaseMacro::keyName($query);
        $pidColumn = BaseMacro::assertValidIdentifier($pidColumn, 'pidColumn');
        $nameColumn = BaseMacro::assertValidIdentifier($nameColumn, 'nameColumn');
        $wTable = BaseMacro::wrapIdentifier($query, $table);
        $wPk = BaseMacro::wrapIdentifier($query, $primaryKey);
        $wPid = BaseMacro::wrapIdentifier($query, $pidColumn);
        $wName = BaseMacro::wrapIdentifier($query, $nameColumn);
        $withTable = WithRecursiveMacro::generateWithTableName();
        $wWith = BaseMacro::wrapIdentifier($query, $withTable);
        // MySQL 使用 CHAR(n) 进行定长转换，PostgreSQL 使用 text（避免 bpchar 补空格污染路径串）
        $castPk = BaseMacro::isPostgres($query) ? 'text' : 'CHAR(200)';

        $pathSeparator = addcslashes($pathSeparator, "'\\");

        // 构建额外筛选条件
        $whereConditions = '';
        $bindings = [];

        if (! empty($conditions)) {
            $whereParts = [];
            $tempBindings = [];
            foreach ($conditions as $key => $value) {
                $wKey = BaseMacro::wrapIdentifier($query, BaseMacro::assertValidIdentifier((string) $key, 'condition'));
                $whereParts[] = "{$wTable}.{$wKey} = ?";
                $tempBindings[] = $value;
            }
            // 条件需要绑定两次：锚定查询和递归查询各一次
            $bindings = array_merge($tempBindings, $tempBindings);
            $whereConditions = implode(' AND ', $whereParts);
        }

        // 构建ID筛选条件
        $idCondition = '';
        if (! empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $idCondition = "WHERE {$wWith}.{$wPk} IN ({$placeholders})";
            $bindings = array_merge($bindings, $ids);
        }

        // 构建根节点条件
        $rootCondition = WithRecursiveMacro::buildRootCondition($query, $table, $pidColumn, $primaryKey);

        // 构建递归CTE查询
        $recursiveQuery = "WITH RECURSIVE {$wWith} AS (
            -- 锚定成员：从根节点开始
            SELECT *,
                   {$wTable}.{$wName} AS absolute_path,
                   CAST({$wTable}.{$wPk} AS {$castPk}) AS path_ids,
                   0 AS depth
            FROM {$wTable}
            WHERE ({$rootCondition})".
            (! empty($whereConditions) ? " AND {$whereConditions}" : '')."

            UNION ALL

            -- 递归成员：向下查找子节点
            SELECT t.*,
                   CONCAT(r.absolute_path, '{$pathSeparator}', t.{$wName}) AS absolute_path,
                   CONCAT(r.path_ids, ',', t.{$wPk}) AS path_ids,
                   r.depth + 1
            FROM {$wTable} t
            JOIN {$wWith} r ON t.{$wPid} = r.{$wPk}".
            (! empty($whereConditions) ? ' WHERE '.str_replace("{$wTable}.", 't.', $whereConditions) : '')."
        )
        SELECT {$wWith}.* FROM {$wWith} {$idCondition}
        ORDER BY path_ids";

        // 复用原查询对象（保留调用方条件），仅替换数据源为递归 CTE 派生表
        BaseMacro::queryOf($query)->from(new Expression("({$recursiveQuery}) as {$wWith}"));

        if (! empty($bindings)) {
            $query->addBinding($bindings, 'from');
        }

        return $query;
    }

    /**
     * 查找面包屑路径（从根节点到当前节点的祖先链）
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query  查询构建器实例
     * @param  int  $id  目标节点ID
     * @param  string  $pidColumn  父ID字段名
     * @param  string  $nameColumn  名称字段名
     */
    public static function findBreadcrumbs(
        Builder|\Illuminate\Database\Query\Builder $query,
        int $id,
        string $pidColumn = 'pid',
        string $nameColumn = 'name'
    ): Builder|\Illuminate\Database\Query\Builder {
        $table = BaseMacro::resolveTable($query);
        $primaryKey = BaseMacro::keyName($query);
        $pidColumn = BaseMacro::assertValidIdentifier($pidColumn, 'pidColumn');
        $wTable = BaseMacro::wrapIdentifier($query, $table);
        $wPk = BaseMacro::wrapIdentifier($query, $primaryKey);
        $wPid = BaseMacro::wrapIdentifier($query, $pidColumn);
        $withTable = WithRecursiveMacro::generateWithTableName();
        $wWith = BaseMacro::wrapIdentifier($query, $withTable);

        // 向上递归直到根节点，然后按depth降序排列（从根到当前）
        $recursiveQuery = "WITH RECURSIVE {$wWith} AS (
            SELECT *, 0 AS depth FROM {$wTable} WHERE {$wPk} = ?
            UNION ALL
            SELECT t.*, r.depth + 1
            FROM {$wTable} t JOIN {$wWith} r ON t.{$wPk} = r.{$wPid}
        ) SELECT * FROM {$wWith} ORDER BY depth DESC";

        // 复用原查询对象：保留调用方已设置的 select / where / order / limit 等全部条件，
        // 仅将数据源替换为递归 CTE 派生表。绑定顺序 select → from → where 与编译后 SQL 顺序一致，
        // 因此 CTE 内部的占位符绑定统一走 from 通道。
        BaseMacro::queryOf($query)->from(new Expression("({$recursiveQuery}) as {$wWith}"));
        $query->addBinding([$id], 'from');

        return $query;
    }

    /**
     * 查找路径长度（节点到根节点的深度）
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query  查询构建器实例
     * @param  int  $id  目标节点ID
     * @param  string  $pidColumn  父ID字段名
     */
    public static function findPathLength(
        Builder|\Illuminate\Database\Query\Builder $query,
        int $id,
        string $pidColumn = 'pid'
    ): Builder|\Illuminate\Database\Query\Builder {
        $table = BaseMacro::resolveTable($query);
        $primaryKey = BaseMacro::keyName($query);
        $pidColumn = BaseMacro::assertValidIdentifier($pidColumn, 'pidColumn');
        $wTable = BaseMacro::wrapIdentifier($query, $table);
        $wPk = BaseMacro::wrapIdentifier($query, $primaryKey);
        $wPid = BaseMacro::wrapIdentifier($query, $pidColumn);
        $withTable = WithRecursiveMacro::generateWithTableName();
        $wWith = BaseMacro::wrapIdentifier($query, $withTable);

        $recursiveQuery = "WITH RECURSIVE {$wWith} AS (
            SELECT *, 0 AS path_length FROM {$wTable} WHERE {$wPk} = ?
            UNION ALL
            SELECT t.*, r.path_length + 1
            FROM {$wTable} t JOIN {$wWith} r ON t.{$wPk} = r.{$wPid}
        ) SELECT path_length FROM {$wWith} ORDER BY path_length DESC LIMIT 1";

        // 复用原查询对象：保留调用方已设置的 select / where / order / limit 等全部条件，
        // 仅将数据源替换为递归 CTE 派生表。绑定顺序 select → from → where 与编译后 SQL 顺序一致，
        // 因此 CTE 内部的占位符绑定统一走 from 通道。
        BaseMacro::queryOf($query)->from(new Expression("({$recursiveQuery}) as {$wWith}"));
        $query->addBinding([$id], 'from');

        return $query;
    }

    /**
     * 检查是否为祖先节点
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query  查询构建器实例
     * @param  int  $parentId  可能的祖先节点ID
     * @param  int  $childId  目标节点ID
     * @param  string  $pidColumn  父ID字段名
     * @param  bool  $strict  是否严格模式（只检查直接父节点）
     */
    public static function checkIsParent(
        Builder|\Illuminate\Database\Query\Builder $query,
        int $parentId,
        int $childId,
        string $pidColumn,
        bool $strict = false
    ): bool {
        $table = BaseMacro::resolveTable($query);
        $primaryKey = BaseMacro::keyName($query);
        $pidColumn = BaseMacro::assertValidIdentifier($pidColumn, 'pidColumn');
        $wTable = BaseMacro::wrapIdentifier($query, $table);
        $wPk = BaseMacro::wrapIdentifier($query, $primaryKey);
        $wPid = BaseMacro::wrapIdentifier($query, $pidColumn);

        // 严格模式：只检查直接父节点
        if ($strict) {
            $result = $query->getConnection()->selectOne(
                "SELECT {$wPid} FROM {$wTable} WHERE {$wPk} = ?",
                [$childId]
            );

            return $result && ($result->{$pidColumn} == $parentId);
        }

        // 非严格模式：递归检查祖先链
        $withTable = WithRecursiveMacro::generateWithTableName();
        $wWith = BaseMacro::wrapIdentifier($query, $withTable);
        $recursiveQuery = "WITH RECURSIVE {$wWith} AS (
            SELECT * FROM {$wTable} WHERE {$wPk} = ?
            UNION ALL
            SELECT t.* FROM {$wTable} t
            JOIN {$wWith} r ON t.{$wPk} = r.{$wPid}
            WHERE t.{$wPk} != r.{$wPk}
        ) SELECT COUNT(*) AS count FROM {$wWith} WHERE {$wPk} = ?";

        $result = $query->getConnection()->selectOne($recursiveQuery, [$childId, $parentId]);

        return ($result->count ?? 0) > 0;
    }

    /**
     * 查找最近公共祖先（LCA）
     * 使用两个CTE分别向上查找两个节点的祖先链，然后取交集按距离排序
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query  查询构建器实例
     * @param  int  $id1  第一个节点ID
     * @param  int  $id2  第二个节点ID
     * @param  string  $pidColumn  父ID字段名
     */
    public static function findNearestAncestor(
        Builder|\Illuminate\Database\Query\Builder $query,
        int $id1,
        int $id2,
        string $pidColumn = 'pid'
    ): Builder|\Illuminate\Database\Query\Builder {
        $table = BaseMacro::resolveTable($query);
        $primaryKey = BaseMacro::keyName($query);
        $pidColumn = BaseMacro::assertValidIdentifier($pidColumn, 'pidColumn');
        $wTable = BaseMacro::wrapIdentifier($query, $table);
        $wPk = BaseMacro::wrapIdentifier($query, $primaryKey);
        $wPid = BaseMacro::wrapIdentifier($query, $pidColumn);
        $wt1 = WithRecursiveMacro::generateWithTableName();
        $wt2 = WithRecursiveMacro::generateWithTableName();
        $wWt1 = BaseMacro::wrapIdentifier($query, $wt1);
        $wWt2 = BaseMacro::wrapIdentifier($query, $wt2);

        // 两个独立的CTE分别查找两个节点的祖先链
        $recursiveQuery = "WITH RECURSIVE {$wWt1} AS (
            SELECT {$wPk}, {$wPid}, 0 AS depth FROM {$wTable} WHERE {$wPk} = ?
            UNION ALL
            SELECT t.{$wPk}, t.{$wPid}, r.depth + 1
            FROM {$wTable} t JOIN {$wWt1} r ON t.{$wPk} = r.{$wPid}
        ), {$wWt2} AS (
            SELECT {$wPk}, {$wPid}, 0 AS depth FROM {$wTable} WHERE {$wPk} = ?
            UNION ALL
            SELECT t.{$wPk}, t.{$wPid}, r.depth + 1
            FROM {$wTable} t JOIN {$wWt2} r ON t.{$wPk} = r.{$wPid}
        ) SELECT a.*, a.depth + b.depth AS total_distance
        FROM {$wWt1} a JOIN {$wWt2} b ON a.{$wPk} = b.{$wPk}
        ORDER BY total_distance ASC LIMIT 1";

        // 复用原查询对象：保留调用方已设置的 select / where / order / limit 等全部条件，
        // 仅将数据源替换为递归 CTE 派生表。绑定顺序 select → from → where 与编译后 SQL 顺序一致，
        // 因此 CTE 内部的占位符绑定统一走 from 通道。
        BaseMacro::queryOf($query)->from(new Expression("({$recursiveQuery}) as {$wWt1}"));
        $query->addBinding([$id1, $id2], 'from');

        return $query;
    }

    /**
     * 查找同级节点（兄弟节点）
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query  查询构建器实例
     * @param  int  $id  目标节点ID
     * @param  string  $pidColumn  父ID字段名
     * @param  bool  $includeSelf  是否包含自身
     */
    public static function findSiblings(
        Builder|\Illuminate\Database\Query\Builder $query,
        int $id,
        string $pidColumn,
        bool $includeSelf = false
    ): Builder|\Illuminate\Database\Query\Builder {
        $table = BaseMacro::resolveTable($query);
        $primaryKey = BaseMacro::keyName($query);
        $pidColumn = BaseMacro::assertValidIdentifier($pidColumn, 'pidColumn');

        // 复用原查询对象：保留调用方已设置的 select / where / order / limit 条件，
        // 并在其基础上叠加兄弟节点筛选。注意：原查询的 where 绑定由查询构造器自行维护，
        // 绝不可手动 addBinding 追加，否则会出现占位符与绑定数量不匹配。
        $query->where(function ($q) use ($pidColumn, $id, $primaryKey, $table) {
            // 方式1：具有相同的父ID
            $q->where($pidColumn, function ($sub) use ($id, $primaryKey, $table, $pidColumn) {
                $sub->select($pidColumn)->from($table)->where($primaryKey, $id);
            })
            // 方式2：或者两者都是根节点（parent_id为null）
                ->orWhere(function ($q2) use ($pidColumn, $table, $id, $primaryKey) {
                    $q2->whereNull($pidColumn)
                        ->whereExists(function ($sub) use ($id, $primaryKey, $pidColumn, $table) {
                            $sub->select(DB::raw(1))
                                ->from($table.' as t2')
                                ->whereColumn('t2.'.$primaryKey, $table.'.'.$primaryKey)
                                ->where('t2.'.$primaryKey, $id)
                                ->whereNull('t2.'.$pidColumn);
                        });
                });
        });

        if (! $includeSelf) {
            $query->where($primaryKey, '!=', $id);
        }

        return $query;
    }

    /**
     * 构建树形结构查询
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query  查询构建器实例
     * @param  int|null  $pid  起始父ID，null表示从根节点开始
     * @param  string  $pidColumn  父ID字段名
     * @param  string  $nameColumn  名称字段名
     * @param  int  $maxDepth  最大递归深度
     * @param  string  $pathSeparator  路径分隔符
     */
    public static function buildTreeQuery(
        Builder|\Illuminate\Database\Query\Builder $query,
        ?int $pid,
        string $pidColumn,
        string $nameColumn,
        int $maxDepth,
        string $pathSeparator = ' > '
    ): Builder|\Illuminate\Database\Query\Builder {
        $table = BaseMacro::resolveTable($query);
        $primaryKey = BaseMacro::keyName($query);
        $pidColumn = BaseMacro::assertValidIdentifier($pidColumn, 'pidColumn');
        $nameColumn = BaseMacro::assertValidIdentifier($nameColumn, 'nameColumn');
        $wTable = BaseMacro::wrapIdentifier($query, $table);
        $wPk = BaseMacro::wrapIdentifier($query, $primaryKey);
        $wPid = BaseMacro::wrapIdentifier($query, $pidColumn);
        $wName = BaseMacro::wrapIdentifier($query, $nameColumn);
        $withTable = WithRecursiveMacro::generateWithTableName();
        $wWith = BaseMacro::wrapIdentifier($query, $withTable);
        // MySQL 使用 CHAR(n) 进行定长转换，PostgreSQL 使用 text（避免 bpchar 补空格污染路径串）
        $castPk = BaseMacro::isPostgres($query) ? 'text' : 'CHAR(200)';
        $castName = BaseMacro::isPostgres($query) ? 'text' : 'CHAR(1000)';

        $pathSeparator = addcslashes($pathSeparator, "'\\");

        $rootCondition = WithRecursiveMacro::buildRootCondition($query, $table, $pidColumn, $primaryKey);

        // 确定锚定查询条件
        if ($pid !== null) {
            $baseWhere = "{$wTable}.{$wPid} = ?";
            $bindings = [$pid, $maxDepth];
        } else {
            $baseWhere = $rootCondition;
            $bindings = [$maxDepth];
        }

        // 构建递归CTE，返回完整树路径
        $recursiveQuery = "WITH RECURSIVE {$wWith} AS (
            SELECT *,
                   CAST({$wTable}.{$wPk} AS {$castPk}) AS path_ids,
                   CAST({$wTable}.{$wName} AS {$castName}) AS tree_path,
                   0 AS depth
            FROM {$wTable}
            WHERE {$baseWhere}
            UNION ALL
            SELECT t.*,
                   CONCAT(r.path_ids, ',', t.{$wPk}) AS path_ids,
                   CONCAT(r.tree_path, '{$pathSeparator}', t.{$wName}) AS tree_path,
                   r.depth + 1
            FROM {$wTable} t JOIN {$wWith} r ON t.{$wPid} = r.{$wPk}
            WHERE r.depth < ?
        ) SELECT *, tree_path AS absolute_path FROM {$wWith} ORDER BY path_ids";

        // 复用原查询对象：保留调用方已设置的 select / where / order / limit 等全部条件，
        // 仅将数据源替换为递归 CTE 派生表。绑定顺序 select → from → where 与编译后 SQL 顺序一致，
        // 因此 CTE 内部的占位符绑定统一走 from 通道。
        BaseMacro::queryOf($query)->from(new Expression("({$recursiveQuery}) as {$wWith}"));
        $query->addBinding($bindings, 'from');

        return $query;
    }

    /**
     * 获取后代节点数量
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query  查询构建器实例
     * @param  int  $id  目标节点ID
     * @param  string  $pidColumn  父ID字段名
     */
    public static function getDescendantsCount(
        Builder|\Illuminate\Database\Query\Builder $query,
        int $id,
        string $pidColumn = 'pid'
    ): Builder|\Illuminate\Database\Query\Builder {
        $table = BaseMacro::resolveTable($query);
        $primaryKey = BaseMacro::keyName($query);
        $pidColumn = BaseMacro::assertValidIdentifier($pidColumn, 'pidColumn');
        $wTable = BaseMacro::wrapIdentifier($query, $table);
        $wPk = BaseMacro::wrapIdentifier($query, $primaryKey);
        $wPid = BaseMacro::wrapIdentifier($query, $pidColumn);
        $withTable = WithRecursiveMacro::generateWithTableName();
        $wWith = BaseMacro::wrapIdentifier($query, $withTable);

        // 递归获取所有后代（含自身），然后计数减1
        $recursiveQuery = "WITH RECURSIVE {$wWith} AS (
            SELECT {$wPk}, {$wPid} FROM {$wTable} WHERE {$wPk} = ?
            UNION ALL
            SELECT t.{$wPk}, t.{$wPid}
            FROM {$wTable} t JOIN {$wWith} r ON t.{$wPid} = r.{$wPk}
        ) SELECT COUNT(*) - 1 AS descendants_count FROM {$wWith}";

        BaseMacro::queryOf($query)->from(new Expression("({$recursiveQuery}) as {$wWith}"));
        $query->addBinding([$id], 'from');

        // 计数聚合：覆盖调用方可能存在的 select 列
        $query->selectRaw('COUNT(*) - 1 AS descendants_count');

        return $query;
    }

    /**
     * 构建通用递归查询（高级用法）
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query  查询构建器实例
     * @param  callable  $baseQuery  基础查询回调，返回SQL字符串
     * @param  callable  $recursiveQuery  递归查询回调，返回SQL字符串
     * @param  array  $columns  查询列
     * @param  int  $maxDepth  最大递归深度
     * @param  string  $depthColumn  深度字段名
     *
     * @throws InvalidArgumentException 当回调无效时抛出
     */
    public static function buildGenericRecursiveQuery(
        Builder|\Illuminate\Database\Query\Builder $query,
        callable $baseQuery,
        callable $recursiveQuery,
        array $columns = ['*'],
        int $maxDepth = 100,
        string $depthColumn = 'depth'
    ): Builder|\Illuminate\Database\Query\Builder {
        $table = BaseMacro::resolveTable($query);
        $depthColumn = BaseMacro::assertValidIdentifier($depthColumn, 'depthColumn');
        $withTable = WithRecursiveMacro::generateWithTableName();
        $wWith = BaseMacro::wrapIdentifier($query, $withTable);
        $wDepth = BaseMacro::wrapIdentifier($query, $depthColumn);

        // 验证回调
        if (! is_callable($baseQuery) || ! is_callable($recursiveQuery)) {
            throw new InvalidArgumentException('基础查询和递归查询必须是可调用的回调函数');
        }

        // 构建 CTE 输出列列表：默认 '*'，确保外层原查询的 where / select 可引用任意列；
        // 显式指定列时逐项走白名单校验，防止注入。
        $columnList = ($columns === [] || $columns === ['*'])
            ? '*'
            : implode(', ', array_map(
                static fn ($col): string => BaseMacro::wrapIdentifier($query, BaseMacro::assertValidIdentifier((string) $col, 'column')),
                $columns
            ));

        // 执行回调获取SQL
        $baseQuerySql = call_user_func($baseQuery, $query, $withTable);
        $recursiveQuerySql = call_user_func($recursiveQuery, $query, $withTable);

        if (! is_string($baseQuerySql) || ! is_string($recursiveQuerySql)) {
            throw new InvalidArgumentException('回调函数必须返回SQL字符串');
        }

        // 自动添加深度字段
        if (! Str::contains($baseQuerySql, "AS {$wDepth}") && ! Str::contains($baseQuerySql, "AS {$depthColumn}")) {
            $baseQuerySql = rtrim($baseQuerySql, ';');
            $baseQuerySql .= ", 0 AS {$wDepth}";
        }
        if (! Str::contains($recursiveQuerySql, "AS {$wDepth}") && ! Str::contains($recursiveQuerySql, "AS {$depthColumn}")) {
            $recursiveQuerySql = rtrim($recursiveQuerySql, ';');
            $recursiveQuerySql .= ", r.{$wDepth} + 1 AS {$wDepth}";
        }

        $recursiveCte = "WITH RECURSIVE {$wWith} AS (
            {$baseQuerySql}
            UNION ALL
            {$recursiveQuerySql}
            WHERE r.{$wDepth} < ?
        ) SELECT {$columnList} FROM {$wWith}";

        // 复用原查询对象：保留调用方已设置的 select / where / order / limit 等全部条件，
        // 仅将数据源替换为递归 CTE 派生表。绑定顺序 select → from → where 与编译后 SQL 顺序一致，
        // 因此 CTE 内部的占位符绑定统一走 from 通道。
        // 注意：本方法的 CTE 变量名为 $recursiveCte（$recursiveQuery 是回调参数，不可混用）。
        BaseMacro::queryOf($query)->from(new Expression("({$recursiveCte}) as {$wWith}"));
        $query->addBinding([$maxDepth], 'from');

        return $query;
    }

    /**
     * 重置递归查询状态
     * 清除所有递归相关的查询状态和绑定
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query  查询构建器实例
     */
    public static function resetRecursiveQuery(Builder|\Illuminate\Database\Query\Builder $query): Builder|\Illuminate\Database\Query\Builder
    {
        $model = BaseMacro::modelOf($query);
        $newQuery = BaseMacro::freshQuery($this);

        $baseQuery = BaseMacro::queryOf($query);
        $freshQuery = BaseMacro::queryOf($newQuery);

        $properties = ['wheres', 'bindings', 'joins', 'groups', 'havings', 'orders', 'distinct', 'limit', 'offset'];

        foreach ($properties as $prop) {
            if (property_exists($baseQuery, $prop) && property_exists($freshQuery, $prop)) {
                $freshQuery->{$prop} = is_array($baseQuery->{$prop})
                    ? []
                    : (is_bool($baseQuery->{$prop}) ? false : null);
            }
        }

        return $newQuery;
    }

    /**
     * 构建根节点条件
     * 支持NULL值根节点和特定值根节点两种情况
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query  查询构建器实例（用于 grammar wrap 标识符）
     * @param  string  $table  表名
     * @param  string  $pidColumn  父ID字段名
     * @param  string  $primaryKey  主键字段名
     * @return string SQL条件字符串
     */
    public static function buildRootCondition(Builder|\Illuminate\Database\Query\Builder $query, string $table, string $pidColumn, string $primaryKey): string
    {
        $pidColumn = BaseMacro::assertValidIdentifier($pidColumn, 'pidColumn');
        $primaryKey = BaseMacro::assertValidIdentifier($primaryKey, 'primaryKey');
        $wTable = BaseMacro::wrapIdentifier($query, $table);
        $wPid = BaseMacro::wrapIdentifier($query, $pidColumn);
        $wPk = BaseMacro::wrapIdentifier($query, $primaryKey);
        $rootValue = WithRecursiveMacro::$rootValue;

        if ($rootValue === null) {
            // rootValue为null时，根节点是parent_id为null的节点
            return "{$wPid} IS NULL OR NOT EXISTS (SELECT 1 FROM {$wTable} AS parent WHERE parent.{$wPk} = {$wTable}.{$wPid})";
        }

        // rootValue为特定值时，根节点是parent_id等于该值的节点
        return sprintf(
            "{$wPid} = %s OR NOT EXISTS (SELECT 1 FROM {$wTable} AS parent WHERE parent.{$wPk} = {$wTable}.{$wPid})",
            is_int($rootValue) ? $rootValue : "'".str_replace("'", "''", (string) $rootValue)."'"
        );
    }
}
