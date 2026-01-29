<?php

namespace zxf\Modules\BuilderQuery;

class WhereHasNotIn extends WhereHasIn
{
    /**
     * @var string
     */
    protected $method = 'whereNotIn';
}
