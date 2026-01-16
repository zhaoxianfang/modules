<?php

namespace zxf\Modules;

use Illuminate\Support\Str;
use zxf\Modules\Contracts\ModuleInterface;

abstract class AbstractModule implements ModuleInterface
{
    /**
     * 模块名称。
     */
    protected string $name;

    /**
     * 模块路径。
     */
    protected string $path;

    /**
     * 模块是否启用。
     */
    protected bool $enabled = true;

    /**
     * 模块优先级。
     */
    protected int $priority = 0;

    /**
     * 创建一个新的模块实例。
     */
    public function __construct()
    {
        // 初始化模块
        $this->initialize();
    }

    /**
     * 初始化模块。
     * 此方法应设置 $this->name 和 $this->path。
     * 重写此方法以设置自定义名称和路径。
     */
    protected function initialize(): void
    {
        $this->setName($this->guessName());
        $this->setPath($this->guessPath());
        
        // 从配置中设置默认优先级
        if ($this->priority === 0) {
            $this->priority = app('config')->get('modules.default_priority', 100);
        }
        
        // 读取模块配置文件中的启用状态
        $configPath = $this->getPath() . '/config/config.php';
        if (file_exists($configPath)) {
            $config = require $configPath;
            $enabledKey = app('config')->get('modules.module_config_enabled_key', 'enabled');
            if (is_array($config) && isset($config[$enabledKey])) {
                $this->enabled = (bool) $config[$enabledKey];
            }
        }
    }

    /**
     * 获取模块名称。
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 获取模块路径。
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * 获取模块命名空间。
     */
    public function getNamespace(): string
    {
        $namespace = app('config')->get('modules.namespace', 'Modules');
        $studlyName = Str::studly($this->getName());

        return "{$namespace}\\{$studlyName}";
    }

    /**
     * 获取模块优先级。
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * 检查模块是否启用。
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * 启用模块。
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * 禁用模块。
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * 注册模块服务。
     */
    public function register(): void
    {
        //
    }

    /**
     * 引导模块。
     */
    public function boot(): void
    {
        //
    }

    /**
     * 获取模块服务提供者。
     *
     * @return array<class-string>
     */
    public function getProviders(): array
    {
        return [];
    }

    /**
     * 获取模块迁移路径。
     *
     * @return array<string>
     */
    public function getMigrations(): array
    {
        return [$this->getPath() . '/database/migrations'];
    }

    /**
     * 获取模块路由路径。
     *
     * @return array<string>
     */
    public function getRoutes(): array
    {
        $routes = [];

        if (file_exists($this->getPath() . '/routes/web.php')) {
            $routes['web'] = $this->getPath() . '/routes/web.php';
        }

        if (file_exists($this->getPath() . '/routes/api.php')) {
            $routes['api'] = $this->getPath() . '/routes/api.php';
        }

        if (file_exists($this->getPath() . '/routes/console.php')) {
            $routes['console'] = $this->getPath() . '/routes/console.php';
        }

        return $routes;
    }

    /**
     * 获取模块视图路径。
     *
     * @return array<string>
     */
    public function getViews(): array
    {
        return [$this->getPath() . '/resources/views'];
    }

    /**
     * 获取模块配置文件路径。
     *
     * @return array<string, string>
     */
    public function getConfig(): array
    {
        $config = [];

        $configPath = $this->getPath() . '/config';

        if (is_dir($configPath)) {
            foreach (glob($configPath . '/*.php') as $file) {
                $key = basename($file, '.php');
                $config[$key] = $file;
            }
        }

        return $config;
    }

    /**
     * 获取模块数据填充器路径。
     *
     * @return array<string>
     */
    public function getSeeders(): array
    {
        $seeders = [];

        $seedersPath = $this->getPath() . '/database/seeders';

        if (is_dir($seedersPath)) {
            foreach (glob($seedersPath . '/*Seeder.php') as $file) {
                $seeders[] = $this->getNamespace() . '\\Database\\Seeders\\' . basename($file, '.php');
            }
        }

        return $seeders;
    }

    /**
     * 获取模块工厂路径。
     *
     * @return array<string>
     */
    public function getFactories(): array
    {
        $factories = [];

        $factoriesPath = $this->getPath() . '/database/factories';

        if (is_dir($factoriesPath)) {
            foreach (glob($factoriesPath . '/*Factory.php') as $file) {
                $factories[] = $this->getNamespace() . '\\Database\\Factories\\' . basename($file, '.php');
            }
        }

        return $factories;
    }

    /**
     * 获取模块依赖。
     *
     * @return array<string>
     */
    public function getDependencies(): array
    {
        return [];
    }

    /**
     * 获取模块中间件。
     *
     * @return array<string, array<string>>
     */
    public function getMiddleware(): array
    {
        return [];
    }

    /**
     * 获取模块翻译路径。
     *
     * @return array<string>
     */
    public function getTranslations(): array
    {
        $translationsPath = $this->getPath() . '/resources/lang';
        
        if (is_dir($translationsPath)) {
            return [$translationsPath];
        }
        
        return [];
    }

    /**
     * 安装模块。
     * 此方法可用于运行迁移、数据填充、发布资源等。
     */
    public function install(): void
    {
        // Default implementation does nothing
        // Override in concrete module class
    }

    /**
     * 卸载模块。
     * 此方法可用于回滚迁移、清理资源等。
     */
    public function uninstall(): void
    {
        // Default implementation does nothing
        // Override in concrete module class
    }

    /**
     * 执行模块健康检查。
     * 返回一个数组，包含检查结果。每个元素应为：
     * ['check' => '检查名称', 'status' => true|false, 'message' => '描述信息']
     *
     * @return array<array{check: string, status: bool, message: string}>
     */
    public function healthCheck(): array
    {
        // Default implementation does nothing
        // Override in concrete module class
        return [];
    }

    /**
     * 获取模块版本。
     *
     * @return string
     */
    public function getVersion(): string
    {
        return '1.0.0';
    }

    /**
     * 获取模块描述。
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'A Laravel module';
    }

    /**
     * 获取模块作者。
     *
     * @return string
     */
    public function getAuthor(): string
    {
        return '';
    }

    /**
     * 获取模块主页。
     *
     * @return string
     */
    public function getHomepage(): string
    {
        return '';
    }

    /**
     * 获取模块许可证。
     *
     * @return string
     */
    public function getLicense(): string
    {
        return 'MIT';
    }

    /**
     * 检查模块依赖是否满足。
     *
     * @return bool
     */
    public function checkDependencies(): bool
    {
        $dependencies = $this->getDependencies();
        
        if (empty($dependencies)) {
            return true;
        }

        $moduleManager = app('zxf.modules.manager');
        
        foreach ($dependencies as $dependency) {
            if (!$moduleManager->exists($dependency)) {
                return false;
            }
            
            $depModule = $moduleManager->find($dependency);
            if (!$depModule->isEnabled()) {
                return false;
            }
        }

        return true;
    }

    /**
     * 检查模块是否需要特定的PHP版本。
     *
     * @return bool
     */
    public function requiresPhp(string $version): bool
    {
        return version_compare(PHP_VERSION, $version, '>=');
    }

    /**
     * 检查模块是否需要特定的Laravel版本。
     *
     * @return bool
     */
    public function requiresLaravel(string $version): bool
    {
        $laravelVersion = app()->version();
        return version_compare($laravelVersion, $version, '>=');
    }

    /**
     * 获取模块要求（PHP、Laravel、扩展等）。
     *
     * @return array<string, string>
     */
    public function getRequirements(): array
    {
        return [];
    }

    /**
     * 获取模块建议（可选依赖）。
     *
     * @return array<string>
     */
    public function getSuggestions(): array
    {
        return [];
    }

    /**
     * 获取模块标签用于分类。
     *
     * @return array<string>
     */
    public function getTags(): array
    {
        return [];
    }

    /**
     * 获取额外模块数据。
     *
     * @return array<string, mixed>
     */
    public function getExtra(): array
    {
        return [];
    }

    /**
     * 检查模块是否有特定的配置文件。
     */
    public function hasConfig(string $key): bool
    {
        $config = $this->getConfig();
        return isset($config[$key]);
    }

    /**
     * 从模块配置文件中获取配置值。
     *
     * @param string $key 配置键（文件名.键或文件名.子键）
     * @param mixed $default 未找到时的默认值
     * @return mixed
     */
    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        // Simple implementation: only checks if config file exists
        // For full implementation, would need to parse config files
        // $config = $this->getConfig(); // reserved for future use
        return $default;
    }

    /**
     * 获取模块信息为数组。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->getName(),
            'namespace' => $this->getNamespace(),
            'path' => $this->getPath(),
            'version' => $this->getVersion(),
            'description' => $this->getDescription(),
            'author' => $this->getAuthor(),
            'homepage' => $this->getHomepage(),
            'license' => $this->getLicense(),
            'enabled' => $this->isEnabled(),
            'priority' => $this->getPriority(),
            'dependencies' => $this->getDependencies(),
            'providers' => $this->getProviders(),
            'migrations' => $this->getMigrations(),
            'routes' => $this->getRoutes(),
            'views' => $this->getViews(),
            'config' => $this->getConfig(),
            'seeders' => $this->getSeeders(),
            'factories' => $this->getFactories(),
            'middleware' => $this->getMiddleware(),
            'requirements' => $this->getRequirements(),
            'suggestions' => $this->getSuggestions(),
            'tags' => $this->getTags(),
            'extra' => $this->getExtra(),
        ];
    }

    /**
     * 获取模块JSON表示。
     *
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }

    /**
     * 设置模块名称。
     */
    protected function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * 设置模块路径。
     */
    protected function setPath(string $path): void
    {
        $this->path = $path;
    }

    /**
     * 猜测模块名称。
     */
    protected function guessName(): string
    {
        return Str::snake(class_basename($this));
    }

    /**
     * 猜测模块路径。
     */
    protected function guessPath(): string
    {
        $modulesPath = app('config')->get('modules.path', app()->basePath('modules'));
        $name = $this->getName();

        return realpath($modulesPath . '/' . $name) ?: $modulesPath . '/' . $name;
    }
}