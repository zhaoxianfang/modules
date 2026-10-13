<?php

declare(strict_types=1);

namespace zxf\Modules\BuilderQuery\WhereHasMacros;

class WhereHasNotIn extends WhereHasIn
{
    /**
     * @var string
     *
     * @scenario 本类/成员说明。
     */
    protected $method = 'whereNotIn';
}
