<?php

namespace zxf\Modules\Tests;

use zxf\Modules\Facades\Module;
use zxf\Modules\Managers\ModuleManager;
use Illuminate\Support\Facades\Artisan;

class ModulesServiceProviderTest extends TestCase
{
    public function test_module_manager_is_registered(): void
    {
        $manager = $this->app->make(ModuleManager::class);
        $this->assertInstanceOf(ModuleManager::class, $manager);
    }

    public function test_module_facade_resolves_to_manager(): void
    {
        $manager = Module::getFacadeRoot();
        $this->assertInstanceOf(ModuleManager::class, $manager);
    }

    public function test_config_is_mergeable(): void
    {
        $config = $this->app['config']->get('modules');
        $this->assertIsArray($config);
        $this->assertArrayHasKey('path', $config);
        $this->assertArrayHasKey('namespace', $config);
    }

    public function test_commands_are_registered(): void
    {
        $commands = Artisan::all();
        $this->assertArrayHasKey('module:make', $commands);
        $this->assertArrayHasKey('module:list', $commands);
        $this->assertArrayHasKey('module:enable', $commands);
        $this->assertArrayHasKey('module:disable', $commands);
    }
}