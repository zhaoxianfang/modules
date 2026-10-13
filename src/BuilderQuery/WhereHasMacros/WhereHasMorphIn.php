<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WhereHasMacros;

use Illuminate\Database\Eloquent\Relations\Relation;

class WhereHasMorphIn
{
    public static function make()
    {
        return function ($relation, $types, $callback = null, $boolean = 'and') {
            $relation = $this->getRelationWithoutConstraints($relation);

            $types = (array) $types;

            if ($types === ['*']) {
                $types = $this->model->newModelQuery()->distinct()->pluck($relation->getMorphType())->all();

                foreach ($types as &$type) {
                    $type = Relation::getMorphedModel($type) ?? $type;
                }
            }

            return $this->where(function ($query) use ($relation, $callback, $types) {
                foreach ($types as $type) {
                    // 跳过无法解析为具体模型类的多态类型（如未注册别名的裸字符串），
                    // 避免 (new $type) 实例化失败导致整条查询抛致命错误。
                    if (! class_exists($type)) {
                        continue;
                    }

                    $query->orWhere(function ($query) use ($relation, $callback, $type) {
                        $belongsTo = $this->getBelongsToRelation($relation, $type);

                        // 必须用局部变量 $cb 包裹，禁止就地重赋值被 use 捕获的 $callback，
                        // 否则第 2 个起的类型会把首个类型传入回调，造成多类型检索结果错乱。
                        $cb = $callback ? function ($q) use ($callback, $type) {
                            return $callback($q, $type);
                        } : null;

                        $query->where($relation->getRelated()->getTable().'.'.$relation->getMorphType(), '=', (new $type)->getMorphClass())
                            ->whereHasIn($belongsTo, $cb);
                    });
                }
            }, null, null, $boolean);
        };
    }
}
