<?php

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * MySQL 8.4+ CTE (Common Table Expressions) 增强宏
 *
 * MySQL 8.4 支持递归和非递归 CTE，这些宏让复杂查询更简单
 *
 * @date 2026-04-07
 */
class CteMacro
{
    /**
     * 存储 CTE 定义
     */
    protected static array $cteDefinitions = [];

    /**
     * 存储递归 CTE 定义
     */
    protected static array $recursiveCteDefinitions = [];

    /**
     * 注册宏
     */
    public static function register(): void
    {
        // withCTE - 添加非递归 CTE
        Builder::macro('withCTE', function (string $name, Closure $callback) {
            /** @var Builder $this */
            $query = $callback(app('db')->query());
            $sql = $query->toSql();
            $bindings = $query->getBindings();

            CteMacro::addCte($name, $sql, $bindings, false);

            return $this;
        });

        // withRecursiveCTE - 添加递归 CTE
        Builder::macro('withRecursiveCTE', function (string $name, Closure $callback) {
            /** @var Builder $this */
            $query = $callback(app('db')->query());
            $sql = $query->toSql();
            $bindings = $query->getBindings();

            CteMacro::addCte($name, $sql, $bindings, true);

            return $this;
        });

        // fromCTE - 从 CTE 查询
        Builder::macro('fromCTE', function (string $cteName, ?string $alias = null) {
            /** @var Builder $this */
            $alias = $alias ?: $cteName;

            return $this->from(\DB::raw("{$cteName} as {$alias}"));
        });

        // withMultipleCTEs - 添加多个 CTE
        Builder::macro('withMultipleCTEs', function (array $ctes) {
            /** @var Builder $this */
            foreach ($ctes as $name => $callback) {
                $this->withCTE($name, $callback);
            }

            return $this;
        });

        // withHierarchicalCTE - 层次结构递归 CTE（树形数据查询）
        Builder::macro('withHierarchicalCTE', function (
            string $name,
            string $table,
            string $idColumn,
            string $parentColumn,
            $startCondition,
            ?string $additionalColumns = null
        ) {
            /** @var Builder $this */
            $columns = $additionalColumns
                ? "{$idColumn}, {$parentColumn}, {$additionalColumns}"
                : "{$idColumn}, {$parentColumn}";

            $startSql = is_callable($startCondition)
                ? $startCondition(app('db')->query())->toSql()
                : "SELECT * FROM {$table} WHERE {$startCondition}";

            $recursiveSql = "SELECT t.{$columns} FROM {$table} t " .
                "INNER JOIN {$name} h ON t.{$parentColumn} = h.{$idColumn}";

            $cteSql = "{$startSql} UNION ALL {$recursiveSql}";

            CteMacro::addRawCte($name, $cteSql, [], true);

            return $this;
        });

        // withPathCTE - 带路径的递归 CTE
        Builder::macro('withPathCTE', function (
            string $name,
            string $table,
            string $idColumn,
            string $parentColumn,
            string $nameColumn,
            $startCondition,
            string $separator = ' > '
        ) {
            /** @var Builder $this */
            $pathColumn = "CONCAT(h.path, '{$separator}', t.{$nameColumn})";

            $startSql = is_callable($startCondition)
                ? "SELECT *, CAST({$nameColumn} AS CHAR(1000)) as path FROM (" .
                  $startCondition(app('db')->query())->toSql() . ") AS base"
                : "SELECT *, CAST({$nameColumn} AS CHAR(1000)) as path " .
                  "FROM {$table} WHERE {$startCondition}";

            $recursiveSql = "SELECT t.*, {$pathColumn} as path FROM {$table} t " .
                "INNER JOIN {$name} h ON t.{$parentColumn} = h.{$idColumn}";

            $cteSql = "{$startSql} UNION ALL {$recursiveSql}";

            CteMacro::addRawCte($name, $cteSql, [], true);

            return $this;
        });

        // withBreadcrumbCTE - 面包屑路径 CTE
        Builder::macro('withBreadcrumbCTE', function (
            string $name,
            string $table,
            string $idColumn,
            string $parentColumn,
            string $nameColumn,
            $leafCondition
        ) {
            /** @var Builder $this */
            $startSql = is_callable($leafCondition)
                ? "SELECT *, CAST({$nameColumn} AS CHAR(1000)) as breadcrumb, 0 as level FROM (" .
                  $leafCondition(app('db')->query())->toSql() . ") AS leaf"
                : "SELECT *, CAST({$nameColumn} AS CHAR(1000)) as breadcrumb, 0 as level " .
                  "FROM {$table} WHERE {$leafCondition}";

            $recursiveSql = "SELECT p.*, CONCAT(p.{$nameColumn}, ' / ', h.breadcrumb) as breadcrumb, h.level + 1 as level " .
                "FROM {$table} p INNER JOIN {$name} h ON p.{$idColumn} = h.{$parentColumn}";

            $cteSql = "{$startSql} UNION ALL {$recursiveSql}";

            CteMacro::addRawCte($name, $cteSql, [], true);

            return $this;
        });

        // withCycleCTE - 循环检测 CTE（MySQL 8.4 CYCLE 检测）
        Builder::macro('withCycleCTE', function (
            string $name,
            string $table,
            string $idColumn,
            string $parentColumn,
            $startCondition
        ) {
            /** @var Builder $this */
            $startSql = is_callable($startCondition)
                ? "SELECT *, CAST({$idColumn} AS CHAR(1000)) as path, 0 as is_cycle FROM (" .
                  $startCondition(app('db')->query())->toSql() . ") AS base"
                : "SELECT *, CAST({$idColumn} AS CHAR(1000)) as path, 0 as is_cycle " .
                  "FROM {$table} WHERE {$startCondition}";

            $recursiveSql = "SELECT t.*, CONCAT(h.path, ',', t.{$idColumn}) as path, " .
                "LOCATE(t.{$idColumn}, h.path) > 0 as is_cycle " .
                "FROM {$table} t INNER JOIN {$name} h ON t.{$parentColumn} = h.{$idColumn} " .
                "WHERE h.is_cycle = 0";

            $cteSql = "{$startSql} UNION ALL {$recursiveSql}";

            CteMacro::addRawCte($name, $cteSql, [], true);

            return $this;
        });

        // applyCTEs - 应用所有 CTE 到查询（内部使用）
        Builder::macro('applyCTEs', function () {
            /** @var Builder $this */
            $ctes = CteMacro::getCteDefinitions();
            $recursiveCtes = CteMacro::getRecursiveCteDefinitions();

            if (empty($ctes) && empty($recursiveCtes)) {
                return $this;
            }

            $allCtes = array_merge($ctes, $recursiveCtes);
            $cteSql = [];

            foreach ($allCtes as $name => $definition) {
                $cteSql[] = "{$name} AS ({$definition['sql']})";
            }

            $withClause = empty($recursiveCtes)
                ? 'WITH ' . implode(', ', $cteSql)
                : 'WITH RECURSIVE ' . implode(', ', $cteSql);

            // 将 CTE 添加到查询开头
            $this->beforeQuery(fn ($q) => $q->addBinding($definition['bindings'] ?? [], 'select'));

            CteMacro::clearCtes();

            return $this->selectRaw($withClause . ' SELECT * FROM (' . $this->toSql() . ') as cte_query');
        });
    }

    /**
     * 添加 CTE 定义
     */
    public static function addCte(string $name, string $sql, array $bindings, bool $recursive = false): void
    {
        $definition = [
            'sql' => $sql,
            'bindings' => $bindings,
        ];

        if ($recursive) {
            self::$recursiveCteDefinitions[$name] = $definition;
        } else {
            self::$cteDefinitions[$name] = $definition;
        }
    }

    /**
     * 添加原始 CTE 定义
     */
    public static function addRawCte(string $name, string $sql, array $bindings, bool $recursive = false): void
    {
        self::addCte($name, $sql, $bindings, $recursive);
    }

    /**
     * 获取 CTE 定义
     */
    public static function getCteDefinitions(): array
    {
        return self::$cteDefinitions;
    }

    /**
     * 获取递归 CTE 定义
     */
    public static function getRecursiveCteDefinitions(): array
    {
        return self::$recursiveCteDefinitions;
    }

    /**
     * 清除所有 CTE 定义
     */
    public static function clearCtes(): void
    {
        self::$cteDefinitions = [];
        self::$recursiveCteDefinitions = [];
    }
}
