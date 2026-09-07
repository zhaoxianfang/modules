<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use zxf\Modules\BuilderQuery\Concerns\SqlSecurity;

/**
 * 递归查询宏类 - 树形结构数据处理的完整解决方案
 *
 * 提供 MySQL 8.0+ WITH RECURSIVE 递归 CTE 的封装，支持：
 * - 上下级递归查询（withAllChildren/withAllParents）
 * - 路径追踪（withBreadcrumbs/withFullPath）
 * - 层级关系判断（isParentOf/isChildOf）
 * - 树形构建（withTree/withRoot/withLeafNodes）
 *
 * @package zxf\Modules\BuilderQuery\WindowMacros
 * @version 2.1.0
 * @requires PHP 8.3+, Laravel 11+ / 12+ / 13+, MySQL 8.0+
 */
class WithRecursiveMacro
{
    use SqlSecurity;

    /** @var mixed 根节点值，默认0，可设为null表示根节点parent_id为NULL */
    protected static mixed $rootValue = 0;

    /** @var int 默认最大递归深度，防止无限递归 */
    protected static int $defaultMaxDepth = 100;

    /**
     * 设置根节点值
     * @param mixed $value 根节点标识值，null表示使用IS NULL判断
     */
    public static function setRootValue(mixed $value): void
    {
        self::$rootValue = $value;
    }

    /** 获取当前根节点值 */
    public static function getRootValue(): mixed
    {
        return self::$rootValue;
    }

    /** 设置默认最大递归深度 */
    public static function setDefaultMaxDepth(int $depth): void
    {
        self::$defaultMaxDepth = max(1, $depth);
    }

    /** 获取默认最大递归深度 */
    public static function getDefaultMaxDepth(): int
    {
        return self::$defaultMaxDepth;
    }

    /** 生成唯一的WITH临时表名 */
    protected static function generateWithTableName(): string
    {
        return 'recursive_' . Str::random(8);
    }

    /**
     * 注册所有递归查询宏
     * 分类：1.上下级递归 2.路径导航 3.关系判断 4.树形操作 5.高级查询
     */
    public static function register(): void
    {
        // ========== 1. 上下级递归查询 ==========

        /** 获取所有后代子节点（向下递归） */
        Builder::macro('withAllChildren', function (int $id, string $pidColumn = 'pid', int $maxDepth = 100, bool $includeSelf = false): Builder {
            return WithRecursiveMacro::buildRecursiveQuery($this, $id, $pidColumn, 'children', $maxDepth, $includeSelf);
        });

        /** 获取所有祖先父节点（向上递归） */
        Builder::macro('withAllParents', function (int $id, string $pidColumn = 'pid', int $maxDepth = 100, bool $includeSelf = false): Builder {
            return WithRecursiveMacro::buildRecursiveQuery($this, $id, $pidColumn, 'parents', $maxDepth, $includeSelf);
        });

        /** 获取第N级父节点（0=自身, 1=父节点, 2=祖父） */
        Builder::macro('withNthParent', function (int $id, int $n, string $pidColumn = 'pid'): Builder {
            return WithRecursiveMacro::findNthLevelRelation($this, $id, $pidColumn, 'parents', $n);
        });

        /** 获取第N级子节点（1=子节点, 2=孙节点） */
        Builder::macro('withNthChildren', function (int $id, int $n, string $pidColumn = 'pid'): Builder {
            return WithRecursiveMacro::findNthLevelRelation($this, $id, $pidColumn, 'children', $n);
        });

        // ========== 2. 路径与导航 ==========

        /** 获取完整路径信息（返回absolute_path, path_ids, depth字段） */
        Builder::macro('withFullPath', function (array $ids = [], array $conditions = [], string $pidColumn = 'pid', string $nameColumn = 'name', string $pathSeparator = ' > '): Builder {
            return WithRecursiveMacro::findNodePaths($this, $ids, $conditions, $pidColumn, $nameColumn, $pathSeparator);
        });

        /** 获取面包屑路径（从根到当前节点，按depth升序） */
        Builder::macro('withBreadcrumbs', function (int $id, string $pidColumn = 'pid', string $nameColumn = 'name'): Builder {
            return WithRecursiveMacro::findBreadcrumbs($this, $id, $pidColumn, $nameColumn);
        });

        /** 获取节点到根节点的路径长度 */
        Builder::macro('withPathLength', function (int $id, string $pidColumn = 'pid'): Builder {
            return WithRecursiveMacro::findPathLength($this, $id, $pidColumn);
        });

        // ========== 3. 关系判断 ==========

        /** 判断parentId是否为childId的祖先节点（strict=true只检查直接父节点） */
        Builder::macro('isParentOf', function (int $parentId, int $childId, string $pidColumn = 'pid', bool $strict = false): bool {
            return WithRecursiveMacro::checkIsParent($this, $parentId, $childId, $pidColumn, $strict);
        });

        /** 判断childId是否为parentId的后代节点 */
        Builder::macro('isChildOf', function (int $childId, int $parentId, string $pidColumn = 'pid', bool $strict = false): bool {
            return WithRecursiveMacro::checkIsParent($this, $parentId, $childId, $pidColumn, $strict);
        });

        /** 查找两个节点的最近公共祖先（LCA） */
        Builder::macro('withNearestAncestor', function (int $id1, int $id2, string $pidColumn = 'pid'): Builder {
            return WithRecursiveMacro::findNearestAncestor($this, $id1, $id2, $pidColumn);
        });

        // ========== 4. 树形结构操作 ==========

        /** 获取同级节点（兄弟节点），includeSelf控制是否包含自身 */
        Builder::macro('withSiblings', function (int $id, string $pidColumn = 'pid', bool $includeSelf = false): Builder {
            return WithRecursiveMacro::findSiblings($this, $id, $pidColumn, $includeSelf);
        });

        /** 构建完整树形结构，返回包含tree_path, path_ids, depth字段 */
        Builder::macro('withTree', function (?int $pid = null, string $pidColumn = 'pid', string $nameColumn = 'name', int $maxDepth = 100, string $pathSeparator = ' > '): Builder {
            return WithRecursiveMacro::buildTreeQuery($this, $pid, $pidColumn, $nameColumn, $maxDepth, $pathSeparator);
        });

        /** 获取所有根节点（parent_id等于rootValue或为null） */
        Builder::macro('withRoot', function (string $pidColumn = 'pid'): Builder {
            $rootValue = WithRecursiveMacro::getRootValue();
            return $rootValue === null
                ? $this->whereNull($pidColumn)
                : $this->where($pidColumn, $rootValue);
        });

        /** 获取所有叶子节点（没有子节点的节点） */
        Builder::macro('withLeafNodes', function (string $pidColumn = 'pid'): Builder {
            $model = $this->getModel();
            $table = $model->getTable();
            $primaryKey = $model->getKeyName();

            return $this->whereNotExists(function ($query) use ($table, $primaryKey, $pidColumn) {
                $query->select(DB::raw(1))
                    ->from("{$table} as children")
                    ->whereColumn("children.{$pidColumn}", "{$table}.{$primaryKey}");
            });
        });

        /** 获取指定节点的后代节点总数（不含自身） */
        Builder::macro('withDescendantsCount', function (int $id, string $pidColumn = 'pid'): Builder {
            return WithRecursiveMacro::getDescendantsCount($this, $id, $pidColumn);
        });

        // ========== 5. 高级递归查询 ==========

        /** 通用递归CTE查询，支持自定义基础查询和递归查询回调 */
        Builder::macro('recursiveQuery', function (callable $baseQuery, callable $recursiveQuery, array $columns = ['*'], int $maxDepth = 100, string $depthColumn = 'depth'): Builder {
            return WithRecursiveMacro::buildGenericRecursiveQuery($this, $baseQuery, $recursiveQuery, $columns, $maxDepth, $depthColumn);
        });

        /** 重置递归查询状态 */
        Builder::macro('resetRecursive', function (): Builder {
            return WithRecursiveMacro::resetRecursiveQuery($this);
        });
    }

    /**
     * 构建通用递归查询
     *
     * 支持向上（parents）和向下（children）两个方向的递归查询
     *
     * @param Builder $query         查询构建器实例
     * @param int     $id            起始节点ID
     * @param string  $pidColumn     父ID字段名
     * @param string  $direction     递归方向：'children'=向下，'parents'=向上
     * @param int     $maxDepth      最大递归深度
     * @param bool    $includeSelf   是否包含起始节点
     * @return Builder
     */
    public static function buildRecursiveQuery(
        Builder $query,
        int $id,
        string $pidColumn,
        string $direction,
        int $maxDepth,
        bool $includeSelf = false
    ): Builder {
        $table = $query->getModel()->getTable();
        $primaryKey = $query->getModel()->getKeyName();
        WithRecursiveMacro::assertMysql($query, 'withRecursive');
        $pidColumn = WithRecursiveMacro::assertValidIdentifier($pidColumn, 'pidColumn');
        $withTable = self::generateWithTableName();

        // 保存当前查询状态以便恢复

        // 构建递归连接条件：向下=子节点.parent_id = 父节点.id，向上=父节点.id = 子节点.parent_id
        $joinCondition = $direction === 'children'
            ? "t.`{$pidColumn}` = r.`{$primaryKey}`"
            : "t.`{$primaryKey}` = r.`{$pidColumn}`";

        // 构建递归CTE查询
        $recursiveQuery = "WITH RECURSIVE `{$withTable}` AS (
            -- 锚定成员：起始节点
            SELECT *, 0 AS depth
            FROM `{$table}`
            WHERE `{$primaryKey}` = ?

            UNION ALL

            -- 递归成员：根据方向向上或向下递归
            SELECT t.*, r.depth + 1
            FROM `{$table}` t
            JOIN `{$withTable}` r ON {$joinCondition}
            WHERE r.depth < ?
        )
        SELECT * FROM `{$withTable}`" . ($includeSelf ? '' : " WHERE `{$primaryKey}` != ?");

        // 创建新查询
        // 绑定参数
        $bindings = $includeSelf ? [$id, $maxDepth] : [$id, $maxDepth, $id];

        // 复用原查询对象：保留调用方已设置的 select / where / order / limit 等全部条件，
        // 仅将数据源替换为递归 CTE 派生表。绑定顺序 select → from → where 与编译后 SQL 顺序一致，
        // 因此 CTE 内部的占位符绑定统一走 from 通道。
        $query->getQuery()->from(new Expression("({$recursiveQuery}) as `{$withTable}`"));
        $query->addBinding($bindings, 'from');

        return $query;
    }

    /**
     * 查找第N层级的关系节点
     *
     * @param Builder $query      查询构建器实例
     * @param int     $id         起始节点ID
     * @param string  $pidColumn  父ID字段名
     * @param string  $direction  递归方向：'children' 或 'parents'
     * @param int     $n          目标层级（0=自身，1=直接父/子，2=祖父/孙）
     * @return Builder
     */
    public static function findNthLevelRelation(
        Builder $query,
        int $id,
        string $pidColumn,
        string $direction,
        int $n
    ): Builder {
        $table = $query->getModel()->getTable();
        $primaryKey = $query->getModel()->getKeyName();
        WithRecursiveMacro::assertMysql($query, 'withRecursive');
        $pidColumn = WithRecursiveMacro::assertValidIdentifier($pidColumn, 'pidColumn');
        $withTable = self::generateWithTableName();


        $joinCondition = $direction === 'children'
            ? "t.`{$pidColumn}` = r.`{$primaryKey}`"
            : "t.`{$primaryKey}` = r.`{$pidColumn}`";

        $recursiveQuery = "WITH RECURSIVE `{$withTable}` AS (
            SELECT *, 0 AS relative_level FROM `{$table}` WHERE `{$primaryKey}` = ?
            UNION ALL
            SELECT t.*, r.relative_level + 1
            FROM `{$table}` t JOIN `{$withTable}` r ON {$joinCondition}
            WHERE r.relative_level < ?
        ) SELECT * FROM `{$withTable}` WHERE relative_level = ?";

        // 复用原查询对象：保留调用方已设置的 select / where / order / limit 等全部条件，
        // 仅将数据源替换为递归 CTE 派生表。绑定顺序 select → from → where 与编译后 SQL 顺序一致，
        // 因此 CTE 内部的占位符绑定统一走 from 通道。
        $query->getQuery()->from(new Expression("({$recursiveQuery}) as `{$withTable}`"));
        $query->addBinding([$id, $n, $n], 'from');

        return $query;
    }

    /**
     * 查找节点路径信息
     *
     * 返回包含absolute_path（名称路径）、path_ids（ID链）、depth（深度）字段的结果
     *
     * @param Builder $query           查询构建器实例
     * @param array   $ids             目标节点ID数组
     * @param array   $conditions      额外筛选条件（如 ['status' => 1]）
     * @param string  $pidColumn       父ID字段名
     * @param string  $nameColumn      名称字段名
     * @param string  $pathSeparator   路径分隔符
     * @return Builder
     */
    public static function findNodePaths(
        Builder $query,
        array $ids = [],
        array $conditions = [],
        string $pidColumn = 'pid',
        string $nameColumn = 'name',
        string $pathSeparator = ' > '
    ): Builder {
        $table = $query->getModel()->getTable();
        $primaryKey = $query->getModel()->getKeyName();
        WithRecursiveMacro::assertMysql($query, 'withRecursive');
        $pidColumn = WithRecursiveMacro::assertValidIdentifier($pidColumn, 'pidColumn');
        $nameColumn = WithRecursiveMacro::assertValidIdentifier($nameColumn, 'nameColumn');
        $pathSeparator = addcslashes($pathSeparator, "'\\");
        $withTable = self::generateWithTableName();


        // 构建额外筛选条件
        $whereConditions = '';
        $bindings = [];

        if (!empty($conditions)) {
            $whereParts = [];
            $tempBindings = [];
            foreach ($conditions as $key => $value) {
                $key = WithRecursiveMacro::assertValidIdentifier((string) $key, 'condition');
                $whereParts[] = "`{$table}`.`{$key}` = ?";
                $tempBindings[] = $value;
            }
            // 条件需要绑定两次：锚定查询和递归查询各一次
            $bindings = array_merge($tempBindings, $tempBindings);
            $whereConditions = implode(' AND ', $whereParts);
        }

        // 构建ID筛选条件
        $idCondition = '';
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $idCondition = "WHERE `{$withTable}`.`{$primaryKey}` IN ({$placeholders})";
            $bindings = array_merge($bindings, $ids);
        }

        // 构建根节点条件
        $rootCondition = self::buildRootCondition($table, $pidColumn, $primaryKey);

        // 构建递归CTE查询
        $recursiveQuery = "WITH RECURSIVE `{$withTable}` AS (
            -- 锚定成员：从根节点开始
            SELECT *,
                   `{$table}`.`{$nameColumn}` AS absolute_path,
                   CAST(`{$table}`.`{$primaryKey}` AS CHAR(200)) AS path_ids,
                   0 AS depth
            FROM `{$table}`
            WHERE ({$rootCondition})" .
            (!empty($whereConditions) ? " AND {$whereConditions}" : '') . "

            UNION ALL

            -- 递归成员：向下查找子节点
            SELECT t.*,
                   CONCAT(r.absolute_path, '{$pathSeparator}', t.`{$nameColumn}`) AS absolute_path,
                   CONCAT(r.path_ids, ',', t.`{$primaryKey}`) AS path_ids,
                   r.depth + 1
            FROM `{$table}` t
            JOIN `{$withTable}` r ON t.`{$pidColumn}` = r.`{$primaryKey}`" .
            (!empty($whereConditions) ? ' WHERE ' . str_replace("`{$table}`.", 't.', $whereConditions) : '') . "
        )
        SELECT `{$withTable}`.* FROM `{$withTable}` {$idCondition}
        ORDER BY path_ids";

        // 复用原查询对象（保留调用方条件），仅替换数据源为递归 CTE 派生表
        $query->getQuery()->from(new Expression("({$recursiveQuery}) as `{$withTable}`"));

        if (!empty($bindings)) {
            $query->addBinding($bindings, 'from');
        }

        return $query;
    }

    /**
     * 查找面包屑路径（从根节点到当前节点的祖先链）
     *
     * @param Builder $query       查询构建器实例
     * @param int     $id          目标节点ID
     * @param string  $pidColumn   父ID字段名
     * @param string  $nameColumn  名称字段名
     * @return Builder
     */
    public static function findBreadcrumbs(
        Builder $query,
        int $id,
        string $pidColumn = 'pid',
        string $nameColumn = 'name'
    ): Builder {
        $table = $query->getModel()->getTable();
        $primaryKey = $query->getModel()->getKeyName();
        WithRecursiveMacro::assertMysql($query, 'withRecursive');
        $pidColumn = WithRecursiveMacro::assertValidIdentifier($pidColumn, 'pidColumn');
        $withTable = self::generateWithTableName();

        // 向上递归直到根节点，然后按depth降序排列（从根到当前）
        $recursiveQuery = "WITH RECURSIVE `{$withTable}` AS (
            SELECT *, 0 AS depth FROM `{$table}` WHERE `{$primaryKey}` = ?
            UNION ALL
            SELECT t.*, r.depth + 1
            FROM `{$table}` t JOIN `{$withTable}` r ON t.`{$primaryKey}` = r.`{$pidColumn}`
        ) SELECT * FROM `{$withTable}` ORDER BY depth DESC";

        // 复用原查询对象：保留调用方已设置的 select / where / order / limit 等全部条件，
        // 仅将数据源替换为递归 CTE 派生表。绑定顺序 select → from → where 与编译后 SQL 顺序一致，
        // 因此 CTE 内部的占位符绑定统一走 from 通道。
        $query->getQuery()->from(new Expression("({$recursiveQuery}) as `{$withTable}`"));
        $query->addBinding([$id], 'from');

        return $query;
    }

    /**
     * 查找路径长度（节点到根节点的深度）
     *
     * @param Builder $query      查询构建器实例
     * @param int     $id         目标节点ID
     * @param string  $pidColumn  父ID字段名
     * @return Builder
     */
    public static function findPathLength(
        Builder $query,
        int $id,
        string $pidColumn = 'pid'
    ): Builder {
        $table = $query->getModel()->getTable();
        $primaryKey = $query->getModel()->getKeyName();
        WithRecursiveMacro::assertMysql($query, 'withRecursive');
        $pidColumn = WithRecursiveMacro::assertValidIdentifier($pidColumn, 'pidColumn');
        $withTable = self::generateWithTableName();

        $recursiveQuery = "WITH RECURSIVE `{$withTable}` AS (
            SELECT *, 0 AS path_length FROM `{$table}` WHERE `{$primaryKey}` = ?
            UNION ALL
            SELECT t.*, r.path_length + 1
            FROM `{$table}` t JOIN `{$withTable}` r ON t.`{$primaryKey}` = r.`{$pidColumn}`
        ) SELECT path_length FROM `{$withTable}` ORDER BY path_length DESC LIMIT 1";

        // 复用原查询对象：保留调用方已设置的 select / where / order / limit 等全部条件，
        // 仅将数据源替换为递归 CTE 派生表。绑定顺序 select → from → where 与编译后 SQL 顺序一致，
        // 因此 CTE 内部的占位符绑定统一走 from 通道。
        $query->getQuery()->from(new Expression("({$recursiveQuery}) as `{$withTable}`"));
        $query->addBinding([$id], 'from');

        return $query;
    }

    /**
     * 检查是否为祖先节点
     *
     * @param Builder $query      查询构建器实例
     * @param int     $parentId   可能的祖先节点ID
     * @param int     $childId    目标节点ID
     * @param string  $pidColumn  父ID字段名
     * @param bool    $strict     是否严格模式（只检查直接父节点）
     * @return bool
     */
    public static function checkIsParent(
        Builder $query,
        int $parentId,
        int $childId,
        string $pidColumn,
        bool $strict = false
    ): bool {
        $table = $query->getModel()->getTable();
        $primaryKey = $query->getModel()->getKeyName();
        WithRecursiveMacro::assertMysql($query, 'withRecursive');
        $pidColumn = WithRecursiveMacro::assertValidIdentifier($pidColumn, 'pidColumn');

        // 严格模式：只检查直接父节点
        if ($strict) {
            $result = $query->getConnection()->selectOne(
                "SELECT `{$pidColumn}` FROM `{$table}` WHERE `{$primaryKey}` = ?",
                [$childId]
            );
            return $result && ($result->{$pidColumn} == $parentId);
        }

        // 非严格模式：递归检查祖先链
        $withTable = self::generateWithTableName();
        $recursiveQuery = "WITH RECURSIVE `{$withTable}` AS (
            SELECT * FROM `{$table}` WHERE `{$primaryKey}` = ?
            UNION ALL
            SELECT t.* FROM `{$table}` t
            JOIN `{$withTable}` r ON t.`{$primaryKey}` = r.`{$pidColumn}`
            WHERE t.`{$primaryKey}` != r.`{$primaryKey}`
        ) SELECT COUNT(*) AS count FROM `{$withTable}` WHERE `{$primaryKey}` = ?";

        $result = $query->getConnection()->selectOne($recursiveQuery, [$childId, $parentId]);

        return ($result->count ?? 0) > 0;
    }

    /**
     * 查找最近公共祖先（LCA）
     *
     * 使用两个CTE分别向上查找两个节点的祖先链，然后取交集按距离排序
     *
     * @param Builder $query      查询构建器实例
     * @param int     $id1        第一个节点ID
     * @param int     $id2        第二个节点ID
     * @param string  $pidColumn  父ID字段名
     * @return Builder
     */
    public static function findNearestAncestor(
        Builder $query,
        int $id1,
        int $id2,
        string $pidColumn = 'pid'
    ): Builder {
        $table = $query->getModel()->getTable();
        $primaryKey = $query->getModel()->getKeyName();
        WithRecursiveMacro::assertMysql($query, 'withRecursive');
        $pidColumn = WithRecursiveMacro::assertValidIdentifier($pidColumn, 'pidColumn');
        $wt1 = self::generateWithTableName();
        $wt2 = self::generateWithTableName();

        // 两个独立的CTE分别查找两个节点的祖先链
        $recursiveQuery = "WITH RECURSIVE `{$wt1}` AS (
            SELECT `{$primaryKey}`, `{$pidColumn}`, 0 AS depth FROM `{$table}` WHERE `{$primaryKey}` = ?
            UNION ALL
            SELECT t.`{$primaryKey}`, t.`{$pidColumn}`, r.depth + 1
            FROM `{$table}` t JOIN `{$wt1}` r ON t.`{$primaryKey}` = r.`{$pidColumn}`
        ), `{$wt2}` AS (
            SELECT `{$primaryKey}`, `{$pidColumn}`, 0 AS depth FROM `{$table}` WHERE `{$primaryKey}` = ?
            UNION ALL
            SELECT t.`{$primaryKey}`, t.`{$pidColumn}`, r.depth + 1
            FROM `{$table}` t JOIN `{$wt2}` r ON t.`{$primaryKey}` = r.`{$pidColumn}`
        ) SELECT a.*, a.depth + b.depth AS total_distance
        FROM `{$wt1}` a JOIN `{$wt2}` b ON a.`{$primaryKey}` = b.`{$primaryKey}`
        ORDER BY total_distance ASC LIMIT 1";

        // 复用原查询对象：保留调用方已设置的 select / where / order / limit 等全部条件，
        // 仅将数据源替换为递归 CTE 派生表。绑定顺序 select → from → where 与编译后 SQL 顺序一致，
        // 因此 CTE 内部的占位符绑定统一走 from 通道。
        $query->getQuery()->from(new Expression("({$recursiveQuery}) as `ancestor`"));
        $query->addBinding([$id1, $id2], 'from');

        return $query;
    }

    /**
     * 查找同级节点（兄弟节点）
     *
     * @param Builder $query        查询构建器实例
     * @param int     $id           目标节点ID
     * @param string  $pidColumn    父ID字段名
     * @param bool    $includeSelf  是否包含自身
     * @return Builder
     */
    public static function findSiblings(
        Builder $query,
        int $id,
        string $pidColumn,
        bool $includeSelf = false
    ): Builder {
        $table = $query->getModel()->getTable();
        $primaryKey = $query->getModel()->getKeyName();
        $pidColumn = WithRecursiveMacro::assertValidIdentifier($pidColumn, 'pidColumn');

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
                            ->from($table . ' as t2')
                            ->whereColumn('t2.' . $primaryKey, $table . '.' . $primaryKey)
                            ->where('t2.' . $primaryKey, $id)
                            ->whereNull('t2.' . $pidColumn);
                    });
            });
        });

        if (!$includeSelf) {
            $query->where($primaryKey, '!=', $id);
        }

        return $query;
    }

    /**
     * 构建树形结构查询
     *
     * @param Builder  $query           查询构建器实例
     * @param int|null $pid             起始父ID，null表示从根节点开始
     * @param string   $pidColumn       父ID字段名
     * @param string   $nameColumn      名称字段名
     * @param int      $maxDepth        最大递归深度
     * @param string   $pathSeparator   路径分隔符
     * @return Builder
     */
    public static function buildTreeQuery(
        Builder $query,
        ?int $pid,
        string $pidColumn,
        string $nameColumn,
        int $maxDepth,
        string $pathSeparator = ' > '
    ): Builder {
        $table = $query->getModel()->getTable();
        $primaryKey = $query->getModel()->getKeyName();
        WithRecursiveMacro::assertMysql($query, 'withRecursive');
        $pidColumn = WithRecursiveMacro::assertValidIdentifier($pidColumn, 'pidColumn');
        $nameColumn = WithRecursiveMacro::assertValidIdentifier($nameColumn, 'nameColumn');
        $pathSeparator = addcslashes($pathSeparator, "'\\");
        $withTable = self::generateWithTableName();

        $rootCondition = self::buildRootCondition($table, $pidColumn, $primaryKey);

        // 确定锚定查询条件
        if ($pid !== null) {
            $baseWhere = "`{$table}`.`{$pidColumn}` = ?";
            $bindings = [$pid, $maxDepth];
        } else {
            $baseWhere = $rootCondition;
            $bindings = [$maxDepth];
        }

        // 构建递归CTE，返回完整树路径
        $recursiveQuery = "WITH RECURSIVE `{$withTable}` AS (
            SELECT *,
                   CAST(`{$table}`.`{$primaryKey}` AS CHAR(200)) AS path_ids,
                   CAST(`{$table}`.`{$nameColumn}` AS CHAR(1000)) AS tree_path,
                   0 AS depth
            FROM `{$table}`
            WHERE {$baseWhere}
            UNION ALL
            SELECT t.*,
                   CONCAT(r.path_ids, ',', t.`{$primaryKey}`) AS path_ids,
                   CONCAT(r.tree_path, '{$pathSeparator}', t.`{$nameColumn}`) AS tree_path,
                   r.depth + 1
            FROM `{$table}` t JOIN `{$withTable}` r ON t.`{$pidColumn}` = r.`{$primaryKey}`
            WHERE r.depth < ?
        ) SELECT *, tree_path AS absolute_path FROM `{$withTable}` ORDER BY path_ids";

        // 复用原查询对象：保留调用方已设置的 select / where / order / limit 等全部条件，
        // 仅将数据源替换为递归 CTE 派生表。绑定顺序 select → from → where 与编译后 SQL 顺序一致，
        // 因此 CTE 内部的占位符绑定统一走 from 通道。
        $query->getQuery()->from(new Expression("({$recursiveQuery}) as `{$withTable}`"));
        $query->addBinding($bindings, 'from');

        return $query;
    }

    /**
     * 获取后代节点数量
     *
     * @param Builder $query      查询构建器实例
     * @param int     $id         目标节点ID
     * @param string  $pidColumn  父ID字段名
     * @return Builder
     */
    public static function getDescendantsCount(
        Builder $query,
        int $id,
        string $pidColumn = 'pid'
    ): Builder {
        $table = $query->getModel()->getTable();
        $primaryKey = $query->getModel()->getKeyName();
        WithRecursiveMacro::assertMysql($query, 'withRecursive');
        $pidColumn = WithRecursiveMacro::assertValidIdentifier($pidColumn, 'pidColumn');
        $withTable = self::generateWithTableName();

        // 递归获取所有后代（含自身），然后计数减1
        $recursiveQuery = "WITH RECURSIVE `{$withTable}` AS (
            SELECT `{$primaryKey}`, `{$pidColumn}` FROM `{$table}` WHERE `{$primaryKey}` = ?
            UNION ALL
            SELECT t.`{$primaryKey}`, t.`{$pidColumn}`
            FROM `{$table}` t JOIN `{$withTable}` r ON t.`{$pidColumn}` = r.`{$primaryKey}`
        ) SELECT COUNT(*) - 1 AS descendants_count FROM `{$withTable}`";

        $query->getQuery()->from(new Expression("({$recursiveQuery}) as `{$withTable}`"));
        $query->addBinding([$id], 'from');

        // 计数聚合：覆盖调用方可能存在的 select 列
        $query->selectRaw('COUNT(*) - 1 AS descendants_count');

        return $query;
    }

    /**
     * 构建通用递归查询（高级用法）
     *
     * @param Builder  $query           查询构建器实例
     * @param callable $baseQuery       基础查询回调，返回SQL字符串
     * @param callable $recursiveQuery  递归查询回调，返回SQL字符串
     * @param array    $columns         查询列
     * @param int      $maxDepth        最大递归深度
     * @param string   $depthColumn     深度字段名
     * @return Builder
     * @throws InvalidArgumentException 当回调无效时抛出
     */
    public static function buildGenericRecursiveQuery(
        Builder $query,
        callable $baseQuery,
        callable $recursiveQuery,
        array $columns = ['*'],
        int $maxDepth = 100,
        string $depthColumn = 'depth'
    ): Builder {
        $table = $query->getModel()->getTable();
        WithRecursiveMacro::assertMysql($query, 'withRecursive');
        $depthColumn = WithRecursiveMacro::assertValidIdentifier($depthColumn, 'depthColumn');
        $withTable = self::generateWithTableName();

        // 验证回调
        if (!is_callable($baseQuery) || !is_callable($recursiveQuery)) {
            throw new InvalidArgumentException('基础查询和递归查询必须是可调用的回调函数');
        }

        // 构建 CTE 输出列列表：默认 '*'，确保外层原查询的 where / select 可引用任意列；
        // 显式指定列时逐项走白名单校验，防止注入。
        $columnList = ($columns === [] || $columns === ['*'])
            ? '*'
            : implode(', ', array_map(
                static fn ($col): string => '`'.WithRecursiveMacro::assertValidIdentifier((string) $col, 'column').'`',
                $columns
            ));

        // 执行回调获取SQL
        $baseQuerySql = call_user_func($baseQuery, $query, $withTable);
        $recursiveQuerySql = call_user_func($recursiveQuery, $query, $withTable);

        if (!is_string($baseQuerySql) || !is_string($recursiveQuerySql)) {
            throw new InvalidArgumentException('回调函数必须返回SQL字符串');
        }

        // 自动添加深度字段
        if (!Str::contains($baseQuerySql, "AS `{$depthColumn}`") &&
            !Str::contains($baseQuerySql, "AS {$depthColumn}")) {
            $baseQuerySql = rtrim($baseQuerySql, ';');
            $baseQuerySql .= ", 0 AS `{$depthColumn}`";
        }
        if (!Str::contains($recursiveQuerySql, "AS `{$depthColumn}`") &&
            !Str::contains($recursiveQuerySql, "AS {$depthColumn}")) {
            $recursiveQuerySql = rtrim($recursiveQuerySql, ';');
            $recursiveQuerySql .= ", r.`{$depthColumn}` + 1 AS `{$depthColumn}`";
        }

        $recursiveCte = "WITH RECURSIVE `{$withTable}` AS (
            {$baseQuerySql}
            UNION ALL
            {$recursiveQuerySql}
            WHERE r.`{$depthColumn}` < ?
        ) SELECT {$columnList} FROM `{$withTable}`";

        // 复用原查询对象：保留调用方已设置的 select / where / order / limit 等全部条件，
        // 仅将数据源替换为递归 CTE 派生表。绑定顺序 select → from → where 与编译后 SQL 顺序一致，
        // 因此 CTE 内部的占位符绑定统一走 from 通道。
        // 注意：本方法的 CTE 变量名为 $recursiveCte（$recursiveQuery 是回调参数，不可混用）。
        $query->getQuery()->from(new Expression("({$recursiveCte}) as `{$withTable}`"));
        $query->addBinding([$maxDepth], 'from');

        return $query;
    }

    /**
     * 重置递归查询状态
     *
     * 清除所有递归相关的查询状态和绑定
     *
     * @param Builder $query  查询构建器实例
     * @return Builder
     */
    public static function resetRecursiveQuery(Builder $query): Builder
    {
        $model = $query->getModel();
        $newQuery = $model->newQuery();

        $baseQuery = $query->getQuery();
        $freshQuery = $newQuery->getQuery();

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
     *
     * 支持NULL值根节点和特定值根节点两种情况
     *
     * @param string $table       表名
     * @param string $pidColumn   父ID字段名
     * @param string $primaryKey  主键字段名
     * @return string SQL条件字符串
     */
    protected static function buildRootCondition(string $table, string $pidColumn, string $primaryKey): string
    {
        $pidColumn = WithRecursiveMacro::assertValidIdentifier($pidColumn, 'pidColumn');
        $primaryKey = WithRecursiveMacro::assertValidIdentifier($primaryKey, 'primaryKey');
        $rootValue = self::$rootValue;

        if ($rootValue === null) {
            // rootValue为null时，根节点是parent_id为null的节点
            return "`{$table}`.`{$pidColumn}` IS NULL OR NOT EXISTS (SELECT 1 FROM `{$table}` AS parent WHERE parent.`{$primaryKey}` = `{$table}`.`{$pidColumn}`)";
        }

        // rootValue为特定值时，根节点是parent_id等于该值的节点
        return sprintf(
            "`{$table}`.`{$pidColumn}` = %s OR NOT EXISTS (SELECT 1 FROM `{$table}` AS parent WHERE parent.`{$primaryKey}` = `{$table}`.`{$pidColumn}`)",
            is_int($rootValue) ? $rootValue : "'" . str_replace("'", "''", (string) $rootValue) . "'"
        );
    }

}
