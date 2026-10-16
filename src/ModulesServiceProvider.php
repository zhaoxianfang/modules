<?php

declare(strict_types=1);

namespace zxf\Modules;

use Composer\InstalledVersions;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use zxf\Modules\BuilderQuery\MacrosBuilder;
use zxf\Modules\Contracts\RepositoryInterface;
use zxf\Modules\Support\ModuleAutoDiscovery;
use zxf\Modules\Support\ModuleContext;
use zxf\Modules\Support\ModuleLoader;

/**
 * 模块系统服务提供者
 * 为 Laravel 11+ / 12+ / 13+ 优化的模块化系统服务提供者。
 * 设计原则：
 * - 最小化 Laravel 框架依赖，使用 illuminate/contracts 而非完整框架
 * - 延迟加载非必要服务，不影响 HTTP 请求性能
 * - 自动发现和注册模块组件（路由、视图、中间件、事件、命令等）
 * - 支持模块缓存以提升生产环境加载速度
 * - **环境隔离**：CLI 命令相关功能/文件仅在命令行环境加载，浏览器环境完全跳过
 * Laravel 13 兼容特性：
 * - 兼容 Eloquent Builder 的新查询宏（如向量相似度 whereVectorSimilarTo）
 * - 兼容 withRouting() 回调与属性路由（#[Get]/#[Post]/#[Middleware]）
 * - CSRF 中间件已使用新增的 PreventRequestForgery 命名约定
 * - 兼容 withScheduling() 的延迟注册时机与队列路由等 13.x 新特性
 *
 * @version 1.13.9
 */
class ModulesServiceProvider extends ServiceProvider
{
    /**
     * 注册服务
     */
    public function register(): void
    {
        $this->registerRepository();
        $this->registerModuleLoader();
        $this->mergeModuleConfig();

        // 注册 Eloquent Builder 查询宏（whereHasIn 等）
        if ($this->app->bound('db')) {
            MacrosBuilder::register($this);
        }

        // ------------------------------------------------------------------
        // CLI 环境专用注册（命令行 Artisan 命令、about 信息）
        //
        // 包自带的 module:* 命令与 about 信息不依赖任何模块加载结果，
        // 因此放在 register() 阶段、且仅在命令行（runningInConsole）下注册：
        //   - 比 boot() 更早完成命令注册，避免依赖 boot 生命周期；
        //   - 浏览器（HTTP）请求阶段完全跳过，命令类文件不会被加载；
        //   - 即使 boot() 中模块加载出现非致命异常，module:* 命令依然可用，
        //     从而规避「There are no commands defined in the 'module' namespace」。
        // ------------------------------------------------------------------
        if (ModuleContext::runningInConsole()) {
            $this->registerPackageCommands();
            $this->registerAboutInfo();
        }
    }

    /**
     * 引导服务
     * 加载策略严格区分「浏览器（HTTP）环境」与「命令行（CLI）环境」：
     *  - HTTP 环境：仅加载处理请求所必需的模块组件
     *    （路由、视图、翻译、配置、迁移、服务提供者等），
     *    完全不触碰 Artisan 命令相关逻辑与文件。
     *  - CLI 环境：在 HTTP 加载的基础上，额外注册扩展包自身的
     *    Artisan 命令、模块内自动发现的命令，以及 about 信息。
     * 这样可确保 src/Commands 下的命令类在 HTTP 请求期间不会被
     * 自动加载/实例化，减少内存占用与自动加载开销。
     * 注意：由于模块的加载必须在服务提供者注册后立刻执行
     * （路由、视图、翻译等都需要在请求处理前完成），
     * 因此本提供者不支持延迟加载，boot() 会立即执行。
     */
    public function boot(): void
    {
        // 发布配置（HTTP / CLI 均需要）
        $this->publishConfig();

        // 加载所有模块（HTTP / CLI 均需要，且必须在 boot 阶段完成，不可延迟）
        $this->loadModules();

        // 注册模块视图命名空间（HTTP 必需，CLI 下无害）
        $this->registerViewNamespace();

        // 仅命令行（CLI）环境才需要注册「模块内自动发现的 Artisan 命令」。
        // 注意：模块命令必须在 loadModules()（模块自动发现）之后注册，
        // 因此放在 boot() 而非 register()；包自带命令已在 register() 注册。
        // 浏览器（HTTP）环境下整体跳过，避免加载 src/Commands 下的命令类。
        if (ModuleContext::runningInConsole()) {
            $this->registerModuleCommands();
        }

        $this->registerTerminatingCleanup();
        $this->registerQueueJobCleanup();
    }

    /**
     * 注册「请求结束」清理钩子
     *
     * ModuleContext 用静态属性缓存「当前请求属于哪个模块」与「模块配置值」。
     * 在 php-fpm 下每请求一个进程，静态天然重置；
     * 但在 Octane / queue worker / supervisor 这类**长驻进程**里，
     * 静态会跨请求存活，导致：
     *  - 第二个请求直接复用上一个请求缓存的模块名；
     *  - 模块配置值读到上一个请求留下的旧值。
     * 表现是「偶发、只在长驻环境下出现」的模块识别/配置串台。
     *
     * 这里挂到 $app->terminating()，让每个 HTTP 请求结束后自动清掉
     * 请求作用域的缓存。只清请求作用域的两项，
     * 保留 repository（重新解析会丢掉已注册的模块，得不偿失）。
     *
     * 注意 terminating() **不覆盖队列**：queue worker 不调用
     * Application::terminate()，因此队列场景由 registerQueueJobCleanup() 兜底。
     */
    protected function registerTerminatingCleanup(): void
    {
        if (! method_exists($this->app, 'terminating')) {
            return;
        }

        try {
            $this->app->terminating(static function (): void {
                ModuleContext::flushRequestScopedCache();
            });
        } catch (\Throwable) {
            // 最小容器可能没有 terminating 能力，忽略即可
        }
    }

    /**
     * 注册「每个队列任务前后」的请求作用域清理钩子
     *
     * queue worker 是长驻进程且**不会**触发 Application::terminate()，
     * 只靠 terminating() 会让「当前模块」与「模块配置」跨 job 存活，
     * 表现为「A 任务的日志/配置被打上 B 模块标签」。
     * 这里挂到 JobProcessing / JobProcessed 两个事件做双向兜底：
     * 处理前清一次（挡住上一个任务残留），处理后再清一次（挡住本次残留）。
     *
     * 队列组件不可用（未安装 illuminate/queue 或最小容器）时静默跳过。
     */
    protected function registerQueueJobCleanup(): void
    {
        try {
            if (! class_exists(JobProcessing::class) || ! class_exists(JobProcessed::class)) {
                return;
            }

            $events = $this->app['events'] ?? null;

            if (! is_object($events) || ! method_exists($events, 'listen')) {
                return;
            }

            $flush = static function (): void {
                ModuleContext::flushRequestScopedCache();
            };

            $events->listen(JobProcessing::class, $flush);
            $events->listen(JobProcessed::class, $flush);
        } catch (\Throwable) {
            // 队列子系统不可用时不影响包的正常装载
        }
    }

    /**
     * 注册模块仓库
     */
    protected function registerRepository(): void
    {
        // 形参类型由 `Application`（= Contracts\Foundation\Application）放宽为无类型。
        // 原因同 registerModuleLoader()：容器把实际容器实例传进来，
        // 裸 Container 不实现 Foundation\Application 契约 → TypeError，
        // register() 失败 = 整个包无法装载。
        $this->app->singleton(RepositoryInterface::class, function ($app) {
            // 收集所有扫描路径
            //
            // 统一在这里做「收敛 + 兜底」，保证至少有一条合法路径：
            //  - config('modules.path') 允许为 null（项目未发布配置时），
            //    直接 array_filter 会把它整条剔除 → $paths 可能变成空数组，
            //    后续 Repository 零模块且没有任何提示，极难排查；
            //  - 若残留非字符串值，Repository::scanPath(string $path)
            //    会抛未捕获 TypeError，直接把 boot() 打断；
            //  - 还需保留「默认目录」这一兜底。
            $paths = [];

            $primary = config('modules.path');
            if (is_string($primary) && $primary !== '') {
                $paths[] = $primary;
            }

            $extraPaths = config('modules.scan_paths', []);
            if (is_array($extraPaths)) {
                foreach ($extraPaths as $extra) {
                    if (is_string($extra) && $extra !== '') {
                        $paths[] = $extra;
                    }
                }
            }

            // 去重并保留原始顺序（优先级由后续 sortByPriority 决定）
            $paths = array_values(array_unique($paths));

            if (empty($paths)) {
                // 兜底：项目根 Modules 目录（能力感知，裸容器下退化为 cwd）
                $paths[] = ModuleContext::basePath('Modules');
            }

            return new Repository(
                $this->resolveFilesystem($app),
                $paths,
                // 显式收敛为 string，避免 config 返回 null/mixed 时
                // Repository 的 `string $namespace` 形参在 strict_types 下抛 TypeError
                (string) (config('modules.namespace') ?: 'Modules')
            );
        });

        $this->app->alias(RepositoryInterface::class, 'modules');
    }

    /**
     * 解析文件系统实例
     *
     * 只要 'files' 这个字符串绑定不在容器里就会抛：
     *     Target class [files] does not exist.
     *     Class "files" does not exist
     * 而本包的设计原则是「最小化 Laravel 框架依赖」，并不保证运行容器一定
     * 是完整的 Illuminate\Foundation\Application（测试用裸 Container、
     * 精简容器、自建微内核等都可能出现该报错，且每次 boot 必现）。
     *
     * 现在按三级回退：已绑定 'files' → 已绑定 Filesystem 类名 → 直接 new。
     * 第三级让本包在没有文件系统服务的容器里也能正常工作。
     */
    /**
     * @param  Container  $app
     *
     * 形参类型由 Foundation\Application 放宽为 Container 基类。
     * 本方法只用到 bound() / make()，两者都在 Container 基类上；
     * 沿用 Foundation 契约会让裸容器直接抛 TypeError。
     */
    protected function resolveFilesystem($app): Filesystem
    {
        if ($app->bound('files')) {
            $files = $app->make('files');

            if ($files instanceof Filesystem) {
                return $files;
            }
        }

        if ($app->bound(Filesystem::class)) {
            $files = $app->make(Filesystem::class);

            if ($files instanceof Filesystem) {
                return $files;
            }
        }

        return new Filesystem;
    }

    /**
     * 注册模块加载器
     */
    protected function registerModuleLoader(): void
    {
        // 容器解析 singleton 时会把**实际容器实例**传进来，
        // 而在裸 Container 场景下那是 Illuminate\Container\Container，
        // 它**并不实现** Foundation\Application 契约 →
        //     TypeError: Argument #1 ($app) must be of type
        //     Illuminate\Contracts\Foundation\Application, Container given
        // boot() 直接崩，且报错信息完全指不到真正原因（模块加载器注册）。
        //
        // 这里去掉形参类型约束（模块加载器构造只要求容器契约 Container），
        // 让它在任何容器实现下都能正常构建。
        $this->app->singleton(ModuleLoader::class, static function ($app) {
            return new ModuleLoader(
                $app->make(RepositoryInterface::class),
                $app
            );
        });
    }

    /**
     * 合并模块配置
     */
    protected function mergeModuleConfig(): void
    {
        $configPath = __DIR__.'/../config/modules.php';

        if (file_exists($configPath)) {
            $this->mergeConfigFrom($configPath, 'modules');
        }
    }

    /**
     * 发布配置文件
     */
    protected function publishConfig(): void
    {
        // 这两个全局助手内部会调用 $app->configPath() / $app->resourcePath()，
        // 而这些方法**只存在于 Illuminate\Foundation\Application**。
        // 于是在裸 Container / 精简容器下 register() 会直接致命：
        //     Call to undefined method Illuminate\Container\Container::configPath()
        // 而 register() 失败 = 整个包无法装载（每次请求都崩）。
        //
        // publishes() 本身只是登记「vendor:publish 映射」，
        // 在没有完整 Foundation 容器时本就无从执行，直接跳过即可。
        if (! $this->hasFoundationPathHelpers()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/modules.php' => config_path('modules.php'),
        ], 'modules-config');

        // 发布 stub 文件
        $this->publishes([
            __DIR__.'/Commands/stubs' => resource_path('stubs/modules'),
        ], 'modules-stubs');
    }

    /**
     * 当前容器是否支持 config_path() / resource_path() 这类 Foundation 路径助手
     *
     * 判据：Illuminate\Foundation\Application 提供了
     * configPath() / resourcePath() / basePath() 等实例方法，
     * 裸 Container 没有 → 助手函数调用必然抛 Error。
     */
    protected function hasFoundationPathHelpers(): bool
    {
        return method_exists($this->app, 'configPath')
            && method_exists($this->app, 'resourcePath');
    }

    /**
     * 注册扩展包自身命令
     */
    protected function registerPackageCommands(): void
    {
        if (! ModuleContext::runningInConsole()) {
            return;
        }

        $commands = [
            Commands\ModuleMakeCommand::class,
            Commands\ModuleListCommand::class,
            Commands\ModuleDeleteCommand::class,
            Commands\ModuleCacheCommand::class,
            Commands\ModuleClearCommand::class,
            Commands\ModuleInfoCommand::class,
            Commands\ModuleValidateCommand::class,
            Commands\ModuleDebugCommandsCommand::class,
            Commands\ModuleCheckLangCommand::class,
            Commands\ModulePublishCommand::class,
            Commands\ControllerMakeCommand::class,
            Commands\ModelMakeCommand::class,
            Commands\MigrationMakeCommand::class,
            Commands\RequestMakeCommand::class,
            Commands\SeederMakeCommand::class,
            Commands\ProviderMakeCommand::class,
            Commands\CommandMakeCommand::class,
            Commands\EventMakeCommand::class,
            Commands\ListenerMakeCommand::class,
            Commands\JobMakeCommand::class,
            Commands\MiddlewareMakeCommand::class,
            Commands\RouteMakeCommand::class,
            Commands\ConfigMakeCommand::class,
            Commands\ObserverMakeCommand::class,
            Commands\PolicyMakeCommand::class,
            Commands\RepositoryMakeCommand::class,
            Commands\ResourceMakeCommand::class,
            Commands\TestMakeCommand::class,
            Commands\ViewMakeCommand::class,
            Commands\SeedCommand::class,
            Commands\MigrateCommand::class,
            Commands\MigrateResetCommand::class,
            Commands\MigrateRefreshCommand::class,
            Commands\MigrateFreshCommand::class,
            Commands\MigrateRollbackCommand::class,
            Commands\MigrateStatusCommand::class,
        ];

        $this->commands($commands);
    }

    /**
     * 加载所有模块
     */
    protected function loadModules(): void
    {
        /** @var ModuleLoader $loader */
        $loader = $this->app->make(ModuleLoader::class);
        $loader->loadAll();
    }

    /**
     * 注册模块中的 Artisan 命令
     */
    protected function registerModuleCommands(): void
    {
        if (! ModuleContext::runningInConsole()) {
            return;
        }

        $moduleCommands = ModuleAutoDiscovery::getGlobalCommands();

        if (! empty($moduleCommands)) {
            $this->commands($moduleCommands);
        }
    }

    /**
     * 注册视图命名空间
     */
    protected function registerViewNamespace(): void
    {
        $viewPath = __DIR__.'/../resources/views';

        if (! is_dir($viewPath)) {
            return;
        }

        if (! $this->app->bound('view')) {
            return;
        }

        $this->loadViewsFrom($viewPath, 'modules');

        // 允许用户自定义覆盖视图
        $customViewPath = resource_path('views/vendor/modules');
        if (is_dir($customViewPath)) {
            $this->app['view']->prependNamespace('modules', $customViewPath);
        }
    }

    /**
     * 注册 about 命令信息
     */
    protected function registerAboutInfo(): void
    {
        if (! class_exists('Illuminate\Foundation\Console\AboutCommand')) {
            return;
        }

        try {
            AboutCommand::add('Extend', [
                'zxf/modules' => fn () => InstalledVersions::getPrettyVersion('zxf/modules') ?? 'unknown',
            ]);
        } catch (\Throwable) {
            // 静默失败
        }
    }

    /**
     * 获取服务提供者提供的服务
     * 注意：本提供者因 boot() 阶段需要加载模块路由/视图/翻译等，
     * 不可使用 Laravel 延迟加载机制。provides() 仅作文档用途。
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            RepositoryInterface::class,
            ModuleLoader::class,
            'modules',
        ];
    }
}
