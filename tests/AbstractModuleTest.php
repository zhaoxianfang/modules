<?php

namespace zxf\Modules\Tests;

use zxf\Modules\AbstractModule;

class AbstractModuleTest extends TestCase
{
    private ConcreteTestModule $module;

    protected function setUp(): void
    {
        parent::setUp();

        $this->module = new ConcreteTestModule();
    }

    public function test_module_has_name(): void
    {
        $this->assertEquals('concrete_test_module', $this->module->getName());
    }

    public function test_module_has_path(): void
    {
        $path = $this->module->getPath();
        $this->assertIsString($path);
        $this->assertStringContainsString('modules', $path);
    }

    public function test_module_has_namespace(): void
    {
        $namespace = $this->module->getNamespace();
        $this->assertEquals('Modules\ConcreteTestModule', $namespace);
    }

    public function test_module_has_priority(): void
    {
        $priority = $this->module->getPriority();
        $this->assertIsInt($priority);
        $this->assertEquals(100, $priority); // default from config
    }

    public function test_module_can_be_enabled_and_disabled(): void
    {
        $this->assertTrue($this->module->isEnabled());
        
        $this->module->disable();
        $this->assertFalse($this->module->isEnabled());
        
        $this->module->enable();
        $this->assertTrue($this->module->isEnabled());
    }

    public function test_module_providers_returns_array(): void
    {
        $providers = $this->module->getProviders();
        $this->assertIsArray($providers);
    }

    public function test_module_migrations_returns_array(): void
    {
        $migrations = $this->module->getMigrations();
        $this->assertIsArray($migrations);
    }

    public function test_module_routes_returns_array(): void
    {
        $routes = $this->module->getRoutes();
        $this->assertIsArray($routes);
    }

    public function test_module_views_returns_array(): void
    {
        $views = $this->module->getViews();
        $this->assertIsArray($views);
    }

    public function test_module_config_returns_array(): void
    {
        $config = $this->module->getConfig();
        $this->assertIsArray($config);
    }

    public function test_module_seeders_returns_array(): void
    {
        $seeders = $this->module->getSeeders();
        $this->assertIsArray($seeders);
    }

    public function test_module_factories_returns_array(): void
    {
        $factories = $this->module->getFactories();
        $this->assertIsArray($factories);
    }

    public function test_module_dependencies_returns_array(): void
    {
        $dependencies = $this->module->getDependencies();
        $this->assertIsArray($dependencies);
    }

    public function test_module_middleware_returns_array(): void
    {
        $middleware = $this->module->getMiddleware();
        $this->assertIsArray($middleware);
    }

    public function test_module_translations_returns_array(): void
    {
        $translations = $this->module->getTranslations();
        $this->assertIsArray($translations);
    }

    public function test_module_requirements_returns_array(): void
    {
        $requirements = $this->module->getRequirements();
        $this->assertIsArray($requirements);
    }

    public function test_module_suggestions_returns_array(): void
    {
        $suggestions = $this->module->getSuggestions();
        $this->assertIsArray($suggestions);
    }

    public function test_module_tags_returns_array(): void
    {
        $tags = $this->module->getTags();
        $this->assertIsArray($tags);
    }

    public function test_module_extra_returns_array(): void
    {
        $extra = $this->module->getExtra();
        $this->assertIsArray($extra);
    }

    public function test_module_to_array_returns_array(): void
    {
        $array = $this->module->toArray();
        $this->assertIsArray($array);
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('namespace', $array);
        $this->assertArrayHasKey('path', $array);
        $this->assertArrayHasKey('enabled', $array);
    }

    public function test_module_to_json_returns_string(): void
    {
        $json = $this->module->toJson();
        $this->assertIsString($json);
        $this->assertJson($json);
    }

    public function test_module_has_config_check(): void
    {
        $this->assertFalse($this->module->hasConfig('nonexistent'));
    }

    public function test_module_check_dependencies(): void
    {
        $this->assertTrue($this->module->checkDependencies());
    }

    public function test_module_requires_php(): void
    {
        $this->assertTrue($this->module->requiresPhp('7.4'));
    }

    public function test_module_requires_laravel(): void
    {
        $this->assertTrue($this->module->requiresLaravel('5.8'));
    }
}

/**
 * Concrete implementation of AbstractModule for testing.
 */
class ConcreteTestModule extends AbstractModule
{
    protected function initialize(): void
    {
        parent::initialize();
        // Override name for testing
        $this->name = 'concrete_test_module';
    }
}