<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WhereHasMacros;

class WhereHasLeftJoin extends WhereHasJoin
{
    /**
     * @var string
     */
    protected $method = 'leftJoin';
}
