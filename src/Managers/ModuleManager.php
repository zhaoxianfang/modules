<?php

namespace zxf\Modules\Managers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Events\ModuleEnabled;
use zxf\Modules\Events\ModuleDisabled;
use zxf\Modules\Exceptions\ModuleNotFoundException;

class ModuleManager
{
    /**
     * The application instance.
     */
    protected Application $app;

    /**
     * The filesystem instance.
     */
    protected Filesystem $files;

    /**
     * The modules path.
     */
    protected string $modulesPath;

    /**
     * The enabled modules cache.
     *
     * @var array<string, ModuleInterface>|null
     */
    protected ?array $enabledModules = null;

    /**
     * All discovered modules.
     *
     * @var array<string, ModuleInterface>|null
     */
    protected ?array $modules = null;

    /**
     * Module aliases mapping.
     *
     * @var array<string, string>
     */
    protected array $aliases = [];

    /**
     * Create a new module manager instance.
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->files = $app['files'];
        $this->modulesPath = $app->basePath('modules');
        $this->aliases = $app['config']->get('modules.aliases', []);
    }

    /**
     * Get all modules.
     *
     * @return array<string, ModuleInterface>
     */
    public function all(): array
    {
        if ($this->modules !== null) {
            return $this->modules;
        }

        $this->modules = $this->getCachedModules();

        return $this->modules;
    }

    /**
     * Get enabled modules.
     *
     * @return array<string, ModuleInterface>
     */
    public function enabled(): array
    {
        if ($this->enabledModules !== null) {
            return $this->enabledModules;
        }

        $this->enabledModules = array_filter($this->all(), fn (ModuleInterface $module) => $module->isEnabled());

        return $this->enabledModules;
    }

    /**
     * Get disabled modules.
     *
     * @return array<string, ModuleInterface>
     */
    public function disabled(): array
    {
        return array_filter($this->all(), fn (ModuleInterface $module) => ! $module->isEnabled());
    }

    /**
     * Check if a module exists.
     */
    public function exists(string $name): bool
    {
        $name = $this->resolveAlias($name);
        return isset($this->all()[$name]);
    }

    /**
     * Get a module instance.
     *
     * @throws ModuleNotFoundException
     */
    public function find(string $name): ModuleInterface
    {
        $name = $this->resolveAlias($name);
        if (! $this->exists($name)) {
            throw new ModuleNotFoundException("Module [{$name}] not found.");
        }

        return $this->all()[$name];
    }

    /**
     * Enable a module.
     *
     * @throws ModuleNotFoundException
     * @throws \RuntimeException If dependency check fails
     */
    public function enable(string $name): void
    {
        $module = $this->find($name);
        
        if (!$module->isEnabled()) {
            // Check dependencies if enabled in config
            $dependencyCheck = $this->app['config']->get('modules.dependency_check', true);
            if ($dependencyCheck) {
                $missing = $this->getMissingDependencies($name);
                if (!empty($missing)) {
                    throw new \RuntimeException(
                        sprintf('Cannot enable module [%s]. Missing dependencies: %s', $name, implode(', ', $missing))
                    );
                }
            }
            
            $module->enable();
            
            // Trigger event if enabled in config
            $dispatchEvents = $this->app['config']->get('modules.dispatch_events', true);
            if ($dispatchEvents) {
                Event::dispatch(new ModuleEnabled($module));
            }
            
            // Clear cache
            $this->clearCache();
            $this->enabledModules = null;
            
            // 保存清单
            $this->saveManifest();
        }
    }

    /**
     * Disable a module.
     *
     * @throws ModuleNotFoundException
     */
    public function disable(string $name): void
    {
        $module = $this->find($name);
        
        if ($module->isEnabled()) {
            $module->disable();
            
            // Trigger event if enabled in config
            $dispatchEvents = $this->app['config']->get('modules.dispatch_events', true);
            if ($dispatchEvents) {
                Event::dispatch(new ModuleDisabled($module));
            }
            
            // Clear cache
            $this->clearCache();
            $this->enabledModules = null;
            
            // 保存清单
            $this->saveManifest();
        }
    }

    /**
     * Register all enabled modules.
     */
    public function registerModules(): void
    {
        // 检查是否自动注册服务提供者
        $autoRegisterProviders = $this->app['config']->get('modules.auto_register_providers', true);
        
        foreach ($this->enabled() as $module) {
            $module->register();
            
            if ($autoRegisterProviders) {
                // 注册模块服务提供者
                foreach ($module->getProviders() as $provider) {
                    if (class_exists($provider)) {
                        $this->app->register($provider);
                    }
                }
            }
        }
    }

    /**
     * Boot all enabled modules.
     */
    public function bootModules(): void
    {
        foreach ($this->enabled() as $module) {
            $module->boot();
        }
    }

    /**
     * Clear modules cache.
     */
    public function clearCache(): void
    {
        $cache = $this->app['cache'];
        $cacheKey = $this->app['config']->get('modules.cache_key', 'zxf.modules');
        
        $cache->forget($cacheKey);
        $this->modules = null;
        $this->enabledModules = null;
    }

    /**
     * Cache modules discovery results.
     */
    public function cache(): void
    {
        $cache = $this->app['cache'];
        $cacheKey = $this->app['config']->get('modules.cache_key', 'zxf.modules');
        $cacheDuration = $this->app['config']->get('modules.cache_duration', 3600);
        
        $modules = $this->discoverModules();
        $cache->put($cacheKey, $modules, $cacheDuration);
        
        $this->modules = $modules;
        $this->enabledModules = null;
    }

    /**
     * Get modules with caching support.
     *
     * @return array<string, ModuleInterface>
     */
    protected function getCachedModules(): array
    {
        $cacheEnabled = $this->app['config']->get('modules.cache', false);
        
        if (!$cacheEnabled) {
            return $this->discoverModules();
        }

        $cache = $this->app['cache'];
        $cacheKey = $this->app['config']->get('modules.cache_key', 'zxf.modules');
        $cacheDuration = $this->app['config']->get('modules.cache_duration', 3600);
        
        return $cache->remember($cacheKey, $cacheDuration, function () {
            return $this->discoverModules();
        });
    }

    /**
     * Discover modules from filesystem.
     *
     * @return array<string, ModuleInterface>
     */
    protected function discoverModules(): array
    {
        $modules = [];

        if (! is_dir($this->modulesPath)) {
            return $modules;
        }

        $directories = array_filter(glob($this->modulesPath . '/*'), 'is_dir');

        foreach ($directories as $directory) {
            $moduleName = basename($directory);
            $moduleClass = $this->getModuleClass($moduleName);

            if (class_exists($moduleClass)) {
                try {
                    $module = $this->app->make($moduleClass);
                    $modules[$moduleName] = $module;
                } catch (\Exception $e) {
                    // Log error and continue
                    if ($this->app->bound('log')) {
                        $this->app['log']->error("Failed to instantiate module {$moduleClass}: " . $e->getMessage());
                    }
                }
            }
        }

        // Discover modules from composer packages
        $composerModules = $this->discoverComposerPackages();
        
        // Merge composer modules (avoid duplicates, local modules take precedence)
        foreach ($composerModules as $name => $module) {
            if (!isset($modules[$name])) {
                $modules[$name] = $module;
            }
        }

        // 加载清单并更新模块启用状态
        $manifest = $this->loadManifest();
        foreach ($manifest as $name => $data) {
            if (isset($modules[$name]) && isset($data['enabled'])) {
                if ($data['enabled']) {
                    $modules[$name]->enable();
                } else {
                    $modules[$name]->disable();
                }
            }
        }

        // Sort by priority
        uasort($modules, fn (ModuleInterface $a, ModuleInterface $b) => $b->getPriority() <=> $a->getPriority());

        return $modules;
    }

    /**
     * Discover modules from composer packages.
     *
     * @return array<string, ModuleInterface>
     */
    protected function discoverComposerPackages(): array
    {
        $modules = [];
        
        $scanEnabled = $this->app['config']->get('modules.scan_composer_packages', false);
        if (!$scanEnabled) {
            return $modules;
        }

        // Get composer installed packages
        $composerPath = $this->app->basePath('vendor/composer/installed.json');
        if (!file_exists($composerPath)) {
            return $modules;
        }

        $installed = json_decode(file_get_contents($composerPath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $modules;
        }

        // Handle both Composer 1.x and 2.x format
        $packages = $installed['packages'] ?? $installed;

        foreach ($packages as $package) {
            if (isset($package['extra']['laravel-module'])) {
                $moduleConfig = $package['extra']['laravel-module'];
                $moduleName = $moduleConfig['name'] ?? $package['name'];
                
                // Determine module class
                $moduleClass = $moduleConfig['class'] ?? $this->getComposerModuleClass($package);
                
                if (class_exists($moduleClass)) {
                    try {
                        $module = $this->app->make($moduleClass);
                        $modules[$moduleName] = $module;
                    } catch (\Exception $e) {
                        if ($this->app->bound('log')) {
                            $this->app['log']->error("Failed to instantiate composer module {$moduleClass}: " . $e->getMessage());
                        }
                    }
                }
            }
        }

        return $modules;
    }

    /**
     * Get module class name from composer package.
     */
    protected function getComposerModuleClass(array $package): string
    {
        $namespace = $this->app['config']->get('modules.namespace', 'Modules');
        $packageName = $package['name'];
        $parts = explode('/', $packageName);
        $vendor = $parts[0];
        $project = $parts[1] ?? $vendor;
        $studiedName = Str::studly($project);

        return "{$namespace}\\{$studiedName}\\{$studiedName}Module";
    }

    /**
     * Get the module class name.
     */
    protected function getModuleClass(string $moduleName): string
    {
        $namespace = $this->app['config']->get('modules.namespace', 'Modules');
        $studlyName = Str::studly($moduleName);

        return "{$namespace}\\{$studlyName}\\{$studlyName}Module";
    }

    /**
     * Get the modules path.
     */
    public function getModulesPath(): string
    {
        return $this->modulesPath;
    }

    /**
     * Set the modules path.
     */
    public function setModulesPath(string $path): void
    {
        $this->modulesPath = $path;
        $this->modules = null;
        $this->enabledModules = null;
    }

    /**
     * 解析模块别名。
     * 如果给定的名称是别名，则返回真实模块名称；否则返回原名称。
     */
    public function resolveAlias(string $name): string
    {
        return $this->aliases[$name] ?? $name;
    }

    /**
     * 获取清单文件路径。
     *
     * @return string
     */
    public function getManifestPath(): string
    {
        $configPath = $this->app['config']->get('modules.manifest_path');
        if ($configPath) {
            return $configPath;
        }
        
        return storage_path('framework/modules.json');
    }

    /**
     * 保存模块清单。
     */
    public function saveManifest(): void
    {
        $manifest = [];
        foreach ($this->all() as $name => $module) {
            $manifest[$name] = [
                'enabled' => $module->isEnabled(),
                'version' => $module->getVersion(),
                'priority' => $module->getPriority(),
            ];
        }
        
        $path = $this->getManifestPath();
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, json_encode($manifest, JSON_PRETTY_PRINT));
    }

    /**
     * 加载模块清单。
     *
     * @return array<string, array{enabled: bool, version: string, priority: int}>
     */
    public function loadManifest(): array
    {
        $path = $this->getManifestPath();
        if (!$this->files->exists($path)) {
            return [];
        }
        
        $content = $this->files->get($path);
        $manifest = json_decode($content, true);
        return is_array($manifest) ? $manifest : [];
    }

    /**
     * Get missing dependencies for a module.
     *
     * @param string $name
     * @return array<string> List of missing dependencies
     */
    public function getMissingDependencies(string $name): array
    {
        if (! $this->exists($name)) {
            return [];
        }

        $module = $this->find($name);
        $dependencies = $module->getDependencies();
        $missing = [];

        foreach ($dependencies as $dependency) {
            if (! $this->exists($dependency)) {
                $missing[] = $dependency;
                continue;
            }

            $dependencyModule = $this->find($dependency);
            if (! $dependencyModule->isEnabled()) {
                $missing[] = $dependency;
            }
        }

        return $missing;
    }

    /**
     * Validate a module instance.
     *
     * @param ModuleInterface $module
     * @return bool
     */
    public function validateModule(ModuleInterface $module): bool
    {
        // Basic validation: check required methods exist
        $requiredMethods = [
            'getName',
            'getPath',
            'getNamespace',
            'getPriority',
            'isEnabled',
            'enable',
            'disable',
            'register',
            'boot',
        ];

        foreach ($requiredMethods as $method) {
            if (! method_exists($module, $method)) {
                return false;
            }
        }

        // Validate module class implements ModuleInterface
        if (! $module instanceof ModuleInterface) {
            return false;
        }

        return true;
    }

    /**
     * Get dependency graph for all modules.
     *
     * @return array<string, array<string>> Map of module name to its dependencies
     */
    public function getDependencyGraph(): array
    {
        $graph = [];

        foreach ($this->all() as $name => $module) {
            $graph[$name] = $module->getDependencies();
        }

        return $graph;
    }

    /**
     * Check if a module can be enabled (dependencies satisfied).
     *
     * @param string $name
     * @return bool
     */
    public function canEnable(string $name): bool
    {
        return empty($this->getMissingDependencies($name));
    }

    /**
     * Enable a module with dependency check.
     *
     * @param string $name
     * @throws \RuntimeException If dependencies are not satisfied
     */
    public function enableWithDependencies(string $name): void
    {
        $missing = $this->getMissingDependencies($name);
        if (! empty($missing)) {
            throw new \RuntimeException(
                sprintf('Cannot enable module [%s]. Missing dependencies: %s', $name, implode(', ', $missing))
            );
        }

        $this->enable($name);
    }
}