<?php

namespace zxf\Modules\BuilderQuery\WindowMacros;

use Illuminate\Database\Query\Builder;

/**
 * MySQL 8.4+ JSON 函数宏
 *
 * MySQL 8.4 提供了丰富的 JSON 函数，这些宏让 JSON 操作更简单
 *
 * @date 2026-04-07
 */
class JsonMacro
{
    /**
     * 注册宏
     */
    public static function register(): void
    {
        // whereJsonContains - 检查 JSON 字段是否包含值（MySQL 8.4 优化版）
        Builder::macro('whereJsonContains', function (string $column, $value, string $boolean = 'and') {
            /** @var Builder $this */
            $jsonValue = is_string($value) ? json_encode($value) : json_encode($value);

            return $this->whereRaw(
                "JSON_CONTAINS({$this->grammar->wrap($column)}, ?, '$')",
                [$jsonValue],
                $boolean
            );
        });

        // orWhereJsonContains
        Builder::macro('orWhereJsonContains', function (string $column, $value) {
            /** @var Builder $this */
            return $this->whereJsonContains($column, $value, 'or');
        });

        // whereJsonContainsPath - 检查 JSON 路径是否存在（MySQL 8.4 JSON_CONTAINS_PATH）
        Builder::macro('whereJsonContainsPath', function (string $column, string $path, string $boolean = 'and') {
            /** @var Builder $this */
            return $this->whereRaw(
                "JSON_CONTAINS_PATH({$this->grammar->wrap($column)}, 'one', ?)",
                ['$.' . ltrim($path, '.')],
                $boolean
            );
        });

        // whereJsonLength - JSON 数组/对象长度条件（MySQL 8.4 JSON_LENGTH）
        Builder::macro('whereJsonLength', function (string $column, $operator, $value = null, string $boolean = 'and') {
            /** @var Builder $this */
            if ($value === null) {
                $value = $operator;
                $operator = '=';
            }

            return $this->whereRaw(
                "JSON_LENGTH({$this->grammar->wrap($column)}) {$operator} ?",
                [$value],
                $boolean
            );
        });

        // whereJsonExtract - JSON 提取条件（MySQL 8.4 JSON_EXTRACT）
        Builder::macro('whereJsonExtract', function (string $column, string $path, $operator, $value = null, string $boolean = 'and') {
            /** @var Builder $this */
            if ($value === null) {
                $value = $operator;
                $operator = '=';
            }

            $wrappedColumn = $this->grammar->wrap($column);
            $jsonPath = '$.' . ltrim($path, '.');

            return $this->whereRaw(
                "JSON_UNQUOTE(JSON_EXTRACT({$wrappedColumn}, ?)) {$operator} ?",
                [$jsonPath, $value],
                $boolean
            );
        });

        // orWhereJsonExtract
        Builder::macro('orWhereJsonExtract', function (string $column, string $path, $operator, $value = null) {
            /** @var Builder $this */
            return $this->whereJsonExtract($column, $path, $operator, $value, 'or');
        });

        // orderByJson - 按 JSON 字段排序（MySQL 8.4 优化）
        Builder::macro('orderByJson', function (string $column, string $path, string $direction = 'asc') {
            /** @var Builder $this */
            $wrappedColumn = $this->grammar->wrap($column);
            $jsonPath = '$.' . ltrim($path, '.');
            $direction = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';

            return $this->orderByRaw(
                "JSON_UNQUOTE(JSON_EXTRACT({$wrappedColumn}, ?)) {$direction}",
                [$jsonPath]
            );
        });

        // selectJson - 选择 JSON 字段（MySQL 8.4 JSON_EXTRACT）
        Builder::macro('selectJson', function (string $column, string $path, ?string $alias = null) {
            /** @var Builder $this */
            $wrappedColumn = $this->grammar->wrap($column);
            $jsonPath = '$.' . ltrim($path, '.');
            $alias = $alias ?: str_replace('.', '_', $path);

            return $this->selectRaw(
                "JSON_UNQUOTE(JSON_EXTRACT({$wrappedColumn}, ?)) as {$this->grammar->wrap($alias)}",
                [$jsonPath]
            );
        });

        // selectJsonAgg - JSON 聚合（MySQL 8.4 JSON_ARRAYAGG / JSON_OBJECTAGG）
        Builder::macro('selectJsonAgg', function (string $column, ?string $alias = null) {
            /** @var Builder $this */
            $alias = $alias ?: $column . '_json';

            return $this->selectRaw(
                "JSON_ARRAYAGG({$this->grammar->wrap($column)}) as {$this->grammar->wrap($alias)}"
            );
        });

        // jsonSearch - JSON 搜索（MySQL 8.4 JSON_SEARCH）
        Builder::macro('whereJsonSearch', function (string $column, string $search, string $boolean = 'and') {
            /** @var Builder $this */
            return $this->whereRaw(
                "JSON_SEARCH({$this->grammar->wrap($column)}, 'one', ?) IS NOT NULL",
                ['%' . $search . '%'],
                $boolean
            );
        });

        // jsonMergePatch - JSON 合并（MySQL 8.4 JSON_MERGE_PATCH）
        Builder::macro('updateJsonMerge', function (string $column, array $data) {
            /** @var Builder $this */
            $jsonData = json_encode($data);
            $wrappedColumn = $this->grammar->wrap($column);

            return $this->update([
                $column => \DB::raw("JSON_MERGE_PATCH({$wrappedColumn}, '{$jsonData}')")
            ]);
        });

        // jsonRemove - 删除 JSON 路径（MySQL 8.4 JSON_REMOVE）
        Builder::macro('updateJsonRemove', function (string $column, string ...$paths) {
            /** @var Builder $this */
            $wrappedColumn = $this->grammar->wrap($column);
            $pathArgs = implode(', ', array_map(fn ($p) => "'$." . ltrim($p, '.') . "'", $paths));

            return $this->update([
                $column => \DB::raw("JSON_REMOVE({$wrappedColumn}, {$pathArgs})")
            ]);
        });

        // jsonAppend - 向 JSON 数组追加（MySQL 8.4 JSON_ARRAY_APPEND）
        Builder::macro('updateJsonAppend', function (string $column, string $path, $value) {
            /** @var Builder $this */
            $wrappedColumn = $this->grammar->wrap($column);
            $jsonPath = '$.' . ltrim($path, '.');
            $jsonValue = json_encode($value);

            return $this->update([
                $column => \DB::raw("JSON_ARRAY_APPEND({$wrappedColumn}, '{$jsonPath}', CAST('{$jsonValue}' AS JSON))")
            ]);
        });

        // whereJsonOverlaps - JSON 数组重叠（MySQL 8.4 JSON_OVERLAPS - 8.0.17+）
        Builder::macro('whereJsonOverlaps', function (string $column, array $values, string $boolean = 'and') {
            /** @var Builder $this */
            $jsonArray = json_encode($values);

            return $this->whereRaw(
                "JSON_OVERLAPS({$this->grammar->wrap($column)}, ?)",
                [$jsonArray],
                $boolean
            );
        });

        // whereJsonKeys - 检查 JSON 键（MySQL 8.4 JSON_KEYS）
        Builder::macro('whereJsonHasKey', function (string $column, string $key, string $boolean = 'and') {
            /** @var Builder $this */
            return $this->whereRaw(
                "JSON_CONTAINS(JSON_KEYS({$this->grammar->wrap($column)}), JSON_QUOTE(?))",
                [$key],
                $boolean
            );
        });

        // selectJsonObject - 构建 JSON 对象（MySQL 8.4 JSON_OBJECT）
        Builder::macro('selectJsonObject', function (array $pairs, string $alias) {
            /** @var Builder $this */
            $objectParts = [];
            $bindings = [];

            foreach ($pairs as $key => $value) {
                if (is_string($key)) {
                    $objectParts[] = "?";
                    $objectParts[] = $value;
                    $bindings[] = $key;
                } else {
                    $objectParts[] = "'{$value}'";
                    $objectParts[] = $this->grammar->wrap($value);
                }
            }

            $objectStr = implode(', ', $objectParts);

            return $this->selectRaw(
                "JSON_OBJECT({$objectStr}) as {$this->grammar->wrap($alias)}",
                $bindings
            );
        });
    }
}
