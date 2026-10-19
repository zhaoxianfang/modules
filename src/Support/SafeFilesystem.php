<?php

declare(strict_types=1);

namespace zxf\Modules\Support;

use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * 安全文件系统解析器（全包统一入口）
 *
 * ===================== Laravel 13 的破坏性变更 =====================
 *
 * Laravel 13 反转了容器别名的方向：
 *   Laravel 11/12： aliases['files'] = Illuminate\Filesystem\Filesystem::class
 *   Laravel 13   ： aliases[Illuminate\Filesystem\Filesystem::class] = 'files'
 *
 * 也就是说，'files' **不再是别名**，而只是一个「由
 * Illuminate\Filesystem\FilesystemServiceProvider::register() 注册的 singleton」。
 * 于是 Laravel 13 下：
 *
 *   bound('files')          → 未注册该 provider 时为 false（11/12 恒为 true）
 *   bound(Filesystem::class)→ 恒为 true（别名反向指向 'files'）
 *   make(Filesystem::class) → 被别名解析成 make('files')，未注册时**直接抛**：
 *                               Target class [files] does not exist.
 *                               Class "files" does not exist
 *
 * 而以下写法在两种版本下都会走「按字符串 files 解析」这条路径，
 * 一旦 FilesystemServiceProvider 尚未注册（精简内核 / 裁剪过默认 provider 的
 * 应用 / 某个 provider 在 register 早期就调用）就会**致命中断**，
 * 典型表现就是 `php artisan optimize` 直接报错退出：
 *
 *   - `Illuminate\Support\Facades\File::xxx()`（门面 accessor = 'files'）
 *   - `app('files')` / `$app['files']` / `$app->make('files')`
 *   - `$app->make(Filesystem::class)` ← **最隐蔽**：bound() 为 true，make() 却会炸
 *   - `ServiceProvider::loadRoutesFrom()` → `routesAreCached()` → `$this['files']`
 *   - `ServiceProvider::mergeConfigFrom()` → 老版本 `configurationIsCached()` → `$this['files']`
 *
 * ===================== 本类的解决思路 =====================
 *
 * 1. instance()：**绝不**把 `make(Filesystem::class)` 当作安全操作。
 *    逐级尝试并整体 try/catch：容器 'files' → 容器 Filesystem::class → 直接 new。
 * 2. ensureBound()：供服务提供者 register() 阶段**提前兜底绑定** 'files'。
 *    只要本包被注册，本次启动中后续所有 provider 的 register/boot
 *    （含 Passport 等会走 loadRoutesFrom() 的第三方包）都不会再因 files 缺失而崩。
 * 3. 静态代理：__callStatic 把 File::xxx() 原样转发到真实 Filesystem 实例，
 *    使调用点只需把 `File::` 换成 `SafeFilesystem::`，方法集完全跟随框架版本。
 *
 * @version 1.13.9
 *
 * @method static bool exists(string $path)
 * @method static bool missing(string $path)
 * @method static string get(string $path, bool $lock = false)
 * @method static int|bool put(string $path, $contents, bool $lock = false)
 * @method static int|bool append(string $path, $data)
 * @method static bool replace(string $path, $content)
 * @method static bool delete($paths)
 * @method static bool copy(string $from, string $to)
 * @method static bool move(string $from, string $to)
 * @method static bool makeDirectory($path, int $mode = 0755, bool $recursive = false, bool $force = false)
 * @method static bool deleteDirectory($directory, bool $preserve = false)
 * @method static bool cleanDirectory($directory)
 * @method static \Symfony\Component\Finder\SplFileInfo[] files(string $directory, bool $hidden = false)
 * @method static \Symfony\Component\Finder\SplFileInfo[] allFiles(string $directory, bool $hidden = false)
 * @method static \Symfony\Component\Finder\SplFileInfo[] directories(string $directory, bool $hidden = false)
 * @method static bool isDirectory($directory)
 * @method static bool isFile($file)
 * @method static bool isReadable($path)
 * @method static bool isWritable($path)
 * @method static int lastModified($path)
 * @method static int size($path)
 * @method static string|null mimeType($path)
 * @method static string hash(string $path, string $algorithm = 'md5')
 * @method static array glob(string $pattern, int $flags = 0)
 */
final class SafeFilesystem
{
    /**
     * 进程内缓存的文件系统实例
     */
    protected static ?Filesystem $instance = null;

    /**
     * 本类为纯静态工具，禁止实例化
     */
    private function __construct()
    {
    }

    /**
     * 获取可用的 Filesystem 实例（永不抛异常）
     *
     * 三级回退：
     *   1. 容器已绑定 'files'            → 直接复用（Laravel 11/12 别名 / 标准 13 应用）
     *   2. 容器已绑定 Filesystem::class  → 尝试解析（Laravel 11/12 行为；Laravel 13 会抛，被捕获）
     *   3. 都没有                        → 自建，保证功能可用
     *
     * 刻意**每次都重新向容器要**（singleton 的 make 只是一次数组查找，开销可忽略），
     * 而不是命中一次就永久缓存：
     * 万一本包在 FilesystemServiceProvider 注册之前就调用过一次（此时只能自建），
     * 永久缓存会让后续所有调用都用那个「野生实例」，
     * 与框架后续注册的实例割裂。每次重取可保证任何时候都用当前容器里的那个。
     */
    public static function instance(): Filesystem
    {
        $resolved = self::tryResolve('files') ?? self::tryResolve(Filesystem::class);

        if ($resolved instanceof Filesystem) {
            return self::$instance = $resolved;
        }

        return self::$instance ??= new Filesystem;
    }

    /**
     * 尝试从容器解析指定抽象，失败一律返回 null（绝不抛异常）
     */
    protected static function tryResolve(string $abstract): ?Filesystem
    {
        try {
            $app = self::container();

            if (! method_exists($app, 'bound') || ! $app->bound($abstract)) {
                return null;
            }

            $files = $app->make($abstract);

            return $files instanceof Filesystem ? $files : null;
        } catch (Throwable) {
            // Laravel 13 下 make(Filesystem::class) 会被别名解析成 make('files') 而抛异常，
            // 这里必须吞掉并继续走下一级回退。
            return null;
        }
    }

    /**
     * 在服务提供者 register() 阶段兜底绑定 'files'
     *
     * 为什么需要：本包自身的 ModuleAutoDiscovery 大量使用 File 门面，
     * 而宿主应用/第三方包（Passport 等）也会走 `loadRoutesFrom()` → `routesAreCached()` → `$app['files']`。
     * 只要本包被注册且 register() 早于这些 provider 的 boot，
     * 这里的一次兜底绑定就能保护整次启动，避免 `Target class [files] does not exist` 致命中断。
     *
     * 无副作用：
     *  - Laravel 11/12：bound('files') 恒为 true（别名），本方法直接返回；
     *  - 标准 Laravel 13 应用：FilesystemServiceProvider 先于本包 register，
     *    bound('files') 已为 true，直接返回；
     *  - 框架后续注册 FilesystemServiceProvider 时会覆盖该绑定，
     *    且我们**从未 make 过**，实例缓存里不会残留自建对象。
     */
    public static function ensureBound(?ContainerContract $app = null): void
    {
        try {
            $app ??= self::container();

            if ($app === null || ! method_exists($app, 'bound') || $app->bound('files')) {
                return;
            }

            $factory = static fn (): Filesystem => new Filesystem;

            if (method_exists($app, 'singleton')) {
                $app->singleton('files', $factory);
            } elseif (method_exists($app, 'bind')) {
                $app->bind('files', $factory);
            }
        } catch (Throwable) {
            // 容器能力不足时忽略：后续 instance() 仍有自建兜底
        }
    }

    /**
     * 重置进程内缓存（测试用 / 容器被替换时）
     */
    public static function flush(): void
    {
        self::$instance = null;
    }

    /**
     * 取当前容器（不可用时返回 null）
     */
    protected static function container(): ?ContainerContract
    {
        try {
            if (! function_exists('app')) {
                return null;
            }

            $app = app();

            return is_object($app) ? $app : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * 把 File::xxx() 原样转发给真实 Filesystem 实例
     *
     * 方法集完全跟随 Illuminate\Filesystem\Filesystem，
     * 因此新增的框架方法无需改动本包调用点。
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return self::instance()->{$method}(...$arguments);
    }
}