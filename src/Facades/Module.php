<?php

namespace zxf\Modules\Facades;

use Illuminate\Support\Facades\Facade;
use zxf\Modules\Contracts\RepositoryInterface;

/**
 * @see \zxf\Modules\Contracts\RepositoryInterface
 */
class Module extends Facade
{
    /**
     * 获取组件注册名称
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return RepositoryInterface::class;
    }
}
