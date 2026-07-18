<?php

declare(strict_types=1);

namespace zxf\Modules\Facades;

use Illuminate\Support\Facades\Facade;
use zxf\Modules\Contracts\RepositoryInterface;

/**
 * 模块门面
 *
 * 提供对模块仓库的静态访问接口。
 *
 * @method static array                                 all()
 * @method static array                                 allEnabled()
 * @method static array                                 allDisabled()
 * @method static \zxf\Modules\Contracts\ModuleInterface|null find(string $name)
 * @method static \zxf\Modules\Contracts\ModuleInterface      findOrFail(string $name)
 * @method static bool                                  has(string $name)
 * @method static array                                 getNames()
 * @method static array                                 getEnabledNames()
 * @method static int                                   count()
 * @method static int                                   countEnabled()
 * @method static void                                  scan()
 * @method static void                                  rescan()
 * @method static void                                  ensureScanned()
 * @method static string                                getModulePath(string $name, ?string $path = null)
 * @method static void                                  clearCache()
 *
 * @see \zxf\Modules\Contracts\RepositoryInterface
 */
class Module extends Facade
{
    /**
     * 获取组件注册名称
     */
    protected static function getFacadeAccessor(): string
    {
        return RepositoryInterface::class;
    }
}
