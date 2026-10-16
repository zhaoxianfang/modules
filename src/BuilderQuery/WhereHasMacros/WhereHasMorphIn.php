<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WhereHasMacros;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * whereHasMorphIn 的实现：多态关联（morphTo）的 IN 子查询优化。
 *
 * 生成形态：
 *   SELECT ... FROM 主表
 *         OR 主表.多态类型列 = '类型B' AND 主表.主键 IN (SELECT …  类型B 对应表) )
 *
 *  1. **多态类型列被重复限定（致命）**
 *     但自 Laravel 6 起 `MorphTo::getMorphType()` 返回的**已经是限定列名**
 *     （形如 `images.imageable_type`），再拼一次表名会生成
 *     `images.images.imageable_type` —— 任何调用都必然抛数据库「未知列」错误。
 *     必须直接使用 `getMorphType()`（它返回的已是限定列名）。
 *  2. **'*' 通配从错误的模型上取类型列表**
 *     而多态类型列位于【被关联的父模型表】上，结果集永远是空 → 静默返回 0 条。
 *     现改用 `$relation->getRelated()`。
 *  3. **只接受类名，注册过的多态别名被静默跳过**
 *     而业务代码通常通过 `Relation::morphMap(['user' => User::class])` 注册别名后
 *     传 `'user'`——class_exists('user') 为 false，该类型被静默跳过，查询结果缺一半且不报错。
 *     现先尝试别名解析（getMorphedModel），失败再按类名处理；两者都不成立才跳过。
 *  4. **未校验关系类型**
 *     底层 `getBelongsToRelation()` 只接受 MorphTo，传错关系类型会抛难以理解的 TypeError。
 *     现前置校验并抛出明确的中文异常。
 *
 * 与原生 whereHasMorph 一致，不自动追加 deleted_at 过滤。
 */
class WhereHasMorphIn
{
    /**
     * 生成宏闭包。
     */
    public static function make(): Closure
    {
        /**
         * @param  string  $relation  morphTo 关联名（不支持点号嵌套，请分多次调用）
         * @param  string|array  $types  多态类型：模型类名、已注册的 morphMap 别名，或 ['*'] 表示全部
         * @param  Closure|null  $callback  关联查询约束闭包，签名 fn ($query, string $type)
         * @param  string  $boolean  连接条件 and|or
         */
        return function ($relation, $types, ?Closure $callback = null, string $boolean = 'and'): Builder {
            $name = $relation;
            $relation = $this->getRelationWithoutConstraints($relation);

            if (! $relation instanceof MorphTo) {
                throw new \InvalidArgumentException(sprintf(
                    'whereHasMorphIn 只支持 morphTo 关系，传入的 [%s] 解析后是 [%s]。',
                    is_string($name) ? $name : get_debug_type($name),
                    $relation instanceof Relation ? $relation::class : get_debug_type($relation)
                ));
            }

            // 必须使用【完全限定类名】调用：闭包在运行时会被绑定到 Eloquent Builder 实例，
            // 一旦用 self::/static:: 解析，self 会被重绑定为 Builder，导致
            // "Call to undefined method Illuminate\Database\Eloquent\Builder::xxx()" 这类
            // 极难定位的错误（历史版本正是栽在 __FUNCTION__/__CLASS__ 上）。
            $types = WhereHasMorphIn::normalizeTypes($relation, $types);

            return $this->where(function ($query) use ($relation, $callback, $types): void {
                foreach ($types as $type) {
                    // 用局部变量 $class 包裹：禁止就地重赋值被 use 捕获的 $type，
                    // 否则第 2 个起的类型会把首个类型传入回调，造成多类型检索结果错乱。
                    $class = $type;

                    $query->orWhere(function ($query) use ($relation, $callback, $class): void {
                        $belongsTo = $this->getBelongsToRelation($relation, $class);

                        $cb = $callback === null
                            ? null
                            : static fn ($sub) => $callback($sub, $class);

                        // 多态类型列必须【限定到父表】：MorphTo::getMorphType() 返回的是
                        // 未经限定的列名（如 subject_type），直接使用会在 JOIN / 多表查询里歧义。
                        // 用 qualifyColumn() 而非手工拼表名，可同时兼容 morphType 已带表名前缀的情况。
                        $query->where(
                            $relation->getRelated()->qualifyColumn($relation->getMorphType()),
                            '=',
                            (new $class)->getMorphClass()
                        )
                            ->whereHasIn($belongsTo, $cb);
                    });
                }
            }, null, null, $boolean);
        };
    }

    /**
     * 归一化并解析多态类型列表。
     *
     * @return string[] 模型类名列表（已剔除无法解析的类型）
     */
    public static function normalizeTypes(MorphTo $relation, string|array $types): array
    {
        $types = array_values(array_filter((array) $types, static fn ($t): bool => $t !== null && $t !== ''));

        // '*'：从【被关联的父模型表】上取全部已使用过的多态类型（修复 2）
        if ($types === ['*']) {
            $types = $relation->getRelated()
                ->newQuery()
                ->distinct()
                ->pluck($relation->getMorphType())
                ->all();
        }

        $resolved = [];
        foreach ($types as $type) {
            $type = is_string($type) ? $type : (is_object($type) ? $type::class : '');

            if ($type === '') {
                continue;
            }

            // 修复 3：先按已注册的 morphMap 别名解析，再按类名处理
            if (! class_exists($type)) {
                $mapped = Relation::getMorphedModel($type) ?? $type;
            } else {
                $mapped = $type;
            }

            if (class_exists($mapped)) {
                $resolved[$mapped] = $mapped;
            }
        }

        return array_values($resolved);
    }
}
