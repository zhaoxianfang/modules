<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WhereHasMacros;

class WhereHasRightJoin extends WhereHasJoin
{
    /**
     * @var string
     */
    protected $method = 'rightJoin';
}
