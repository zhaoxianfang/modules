<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WhereHasMacros;

class WhereHasCrossJoin extends WhereHasJoin
{
    /**
     * @var string
     */
    protected $method = 'crossJoin';
}
