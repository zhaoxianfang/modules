<?php

namespace zxf\Modules\Tests;

use zxf\Modules\Managers\ModuleManager;

class ModuleManagerTest extends TestCase
{
    private ModuleManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new ModuleManager($this->app);
    }

    public function test_manager_can_be_instantiated(): void
    {
        $this->assertInstanceOf(ModuleManager::class, $this->manager);
    }

    public function test_get_modules_path(): void
    {
        $path = $this->manager->getModulesPath();
        $this->assertIsString($path);
        $this->assertStringEndsWith('modules', $path);
    }

    public function test_set_modules_path(): void
    {
        $newPath = '/tmp/test-modules';
        $this->manager->setModulesPath($newPath);
        $this->assertEquals($newPath, $this->manager->getModulesPath());
    }

    public function test_all_modules_returns_array(): void
    {
        $modules = $this->manager->all();
        $this->assertIsArray($modules);
    }

    public function test_enabled_modules_returns_array(): void
    {
        $modules = $this->manager->enabled();
        $this->assertIsArray($modules);
    }

    public function test_disabled_modules_returns_array(): void
    {
        $modules = $this->manager->disabled();
        $this->assertIsArray($modules);
    }

    public function test_exists_returns_boolean(): void
    {
        $exists = $this->manager->exists('NonExistentModule');
        $this->assertIsBool($exists);
        $this->assertFalse($exists);
    }

    public function test_find_throws_exception_for_nonexistent_module(): void
    {
        $this->expectException(\zxf\Modules\Exceptions\ModuleNotFoundException::class);
        $this->manager->find('NonExistentModule');
    }

    public function test_get_missing_dependencies_returns_array(): void
    {
        $missing = $this->manager->getMissingDependencies('TestModule');
        $this->assertIsArray($missing);
    }

    public function test_can_enable_returns_boolean(): void
    {
        $canEnable = $this->manager->canEnable('TestModule');
        $this->assertIsBool($canEnable);
    }

    public function test_clear_cache_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        $this->manager->clearCache();
    }

    public function test_cache_method_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        $this->manager->cache();
    }

    public function test_get_dependency_graph_returns_array(): void
    {
        $graph = $this->manager->getDependencyGraph();
        $this->assertIsArray($graph);
    }

    public function test_validate_module_returns_boolean(): void
    {
        // Create a mock module
        $mockModule = new class extends \zxf\Modules\AbstractModule {
            public function getName(): string { return 'TestModule'; }
            public function getPath(): string { return '/tmp'; }
            public function getNamespace(): string { return 'Modules\\TestModule'; }
            public function getPriority(): int { return 100; }
            public function isEnabled(): bool { return true; }
            public function enable(): void {}
            public function disable(): void {}
            public function register(): void {}
            public function boot(): void {}
        };

        $isValid = $this->manager->validateModule($mockModule);
        $this->assertIsBool($isValid);
    }
}