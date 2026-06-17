<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WhereHasMacros;

class WhereHasNotIn extends WhereHasIn
{
    /**
     * @var string
     */
    protected $method = 'whereNotIn';
}
