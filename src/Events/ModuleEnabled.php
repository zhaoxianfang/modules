<?php

namespace zxf\Modules\Events;

use Illuminate\Foundation\Events\Dispatchable;
use zxf\Modules\Contracts\ModuleInterface;

class ModuleEnabled
{
    use Dispatchable;

    /**
     * The module instance.
     */
    public ModuleInterface $module;

    /**
     * Create a new event instance.
     */
    public function __construct(ModuleInterface $module)
    {
        $this->module = $module;
    }
}