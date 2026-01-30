<?php

namespace zxf\Modules\BuilderQuery\WhereHasMacros;

class WhereHasNotIn extends WhereHasIn
{
    /**
     * @var string
     */
    protected $method = 'whereNotIn';
}
