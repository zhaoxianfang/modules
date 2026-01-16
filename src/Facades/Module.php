<?php

namespace zxf\Modules\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array all()
 * @method static array enabled()
 * @method static array disabled()
 * @method static bool exists(string $name)
 * @method static \zxf\Modules\Contracts\ModuleInterface find(string $name)
 * @method static void enable(string $name)
 * @method static void disable(string $name)
 * @method static void bootModules()
 * @method static string getModulesPath()
 * @method static void setModulesPath(string $path)
 *
 * @see \zxf\Modules\Managers\ModuleManager
 */
class Module extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return \zxf\Modules\Managers\ModuleManager::class;
    }
}