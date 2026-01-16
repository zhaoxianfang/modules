<?php

namespace zxf\Modules\Events;

use Illuminate\Foundation\Events\Dispatchable;
use zxf\Modules\Contracts\ModuleInterface;

class ModuleInstalled
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