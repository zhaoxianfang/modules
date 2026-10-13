<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WhereHasMacros;

class WhereHasLeftJoin extends WhereHasJoin
{
    /**
     * @var string
     *
     * @scenario 本类/成员说明。
     */
    protected $method = 'leftJoin';
}
