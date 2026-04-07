<?php

namespace zxf\Modules\Support;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;

/**
 * 模块缓存管理器
 *
 * 提供高性能的模块信息缓存机制，减少文件系统操作
 * 支持多层级缓存：内存缓存 > 应用缓存 > 文件缓存
 *
 * 优化策略：
 * 1. 运行时内存缓存 - 避免同一请求内重复读取
 * 2. Laravel 缓存 - 跨请求持久化
 * 3. 编译缓存 - 生产环境使用 PHP 编译缓存
 * 4. 智能失效 - 基于文件修改时间的缓存失效
 */
class ModuleCacheManager
{
    /**
     * 内存缓存 - 当前请求内有效
     *
     * @var array<string, mixed>
     */
    protected static array $runtimeCache = [];

    /**
     * 缓存键前缀
     */
    protected const CACHE_KEY_PREFIX = 'zxf_modules_';

    /**
     * 缓存版本号 - 用于缓存失效
     */
    protected const CACHE_VERSION = 'v2';

    /**
     * 应用实例
     */
    protected Application $app;

    /**
     * 缓存仓库
     */
    protected ?CacheRepository $cache = null;

    /**
     * 是否启用缓存
     */
    protected bool $enabled = false;

    /**
     * 缓存 TTL（秒）
     */
    protected int $ttl = 3600;

    /**
     * 是否使用内存缓存
     */
    protected bool $useRuntimeCache = true;

    /**
     * 创建新实例
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->enabled = config('modules.cache.enabled', true);
        $this->ttl = config('modules.cache.ttl', 3600);
        $this->useRuntimeCache = config('modules.cache.runtime', true);
    }

    /**
     * 获取缓存仓库
     */
    protected function getCache(): CacheRepository
    {
        if ($this->cache === null) {
            $this->cache = $this->app['cache'];
        }

        return $this->cache;
    }

    /**
     * 构建缓存键
     */
    protected function buildKey(string $key): string
    {
        return self::CACHE_KEY_PREFIX . self::CACHE_VERSION . '_' . $key;
    }

    /**
     * 从运行时缓存获取
     *
     * @template T
     * @param string $key 缓存键
     * @param T|null $default 默认值
     * @return T|null
     */
    public function getFromRuntime(string $key, mixed $default = null): mixed
    {
        if (! $this->useRuntimeCache) {
            return $default;
        }

        return self::$runtimeCache[$key] ?? $default;
    }

    /**
     * 设置运行时缓存
     */
    public function setRuntime(string $key, mixed $value): void
    {
        if (! $this->useRuntimeCache) {
            return;
        }

        self::$runtimeCache[$key] = $value;
    }

    /**
     * 检查运行时缓存是否存在
     */
    public function hasRuntime(string $key): bool
    {
        if (! $this->useRuntimeCache) {
            return false;
        }

        return array_key_exists($key, self::$runtimeCache);
    }

    /**
     * 从缓存获取（运行时 + 持久化）
     *
     * 优先级：运行时缓存 > Laravel 缓存
     *
     * @template T
     * @param string $key 缓存键
     * @param T|null $default 默认值
     * @return T|null
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // 先检查运行时缓存
        if ($this->hasRuntime($key)) {
            return $this->getFromRuntime($key);
        }

        if (! $this->enabled) {
            return $default;
        }

        // 检查持久化缓存
        $cacheKey = $this->buildKey($key);
        $value = $this->getCache()->get($cacheKey);

        if ($value !== null) {
            // 回填运行时缓存
            $this->setRuntime($key, $value);
            return $value;
        }

        return $default;
    }

    /**
     * 设置缓存
     */
    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        // 设置运行时缓存
        $this->setRuntime($key, $value);

        if (! $this->enabled) {
            return;
        }

        // 设置持久化缓存
        $cacheKey = $this->buildKey($key);
        $this->getCache()->put($cacheKey, $value, $ttl ?? $this->ttl);
    }

    /**
     * 检查缓存是否存在
     */
    public function has(string $key): bool
    {
        if ($this->hasRuntime($key)) {
            return true;
        }

        if (! $this->enabled) {
            return false;
        }

        return $this->getCache()->has($this->buildKey($key));
    }

    /**
     * 删除缓存
     */
    public function forget(string $key): void
    {
        unset(self::$runtimeCache[$key]);

        if ($this->enabled) {
            $this->getCache()->forget($this->buildKey($key));
        }
    }

    /**
     * 批量获取缓存
     *
     * @param array<string> $keys 缓存键数组
     * @return array<string, mixed>
     */
    public function getMultiple(array $keys): array
    {
        $result = [];
        $missingKeys = [];

        // 先检查运行时缓存
        foreach ($keys as $key) {
            if ($this->hasRuntime($key)) {
                $result[$key] = $this->getFromRuntime($key);
            } else {
                $missingKeys[] = $key;
            }
        }

        // 从持久化缓存获取缺失的键
        if ($this->enabled && ! empty($missingKeys)) {
            $cacheKeys = array_map([$this, 'buildKey'], $missingKeys);
            $cachedValues = $this->getCache()->getMultiple($cacheKeys);

            foreach ($missingKeys as $index => $key) {
                $cacheKey = $cacheKeys[$index];
                if (isset($cachedValues[$cacheKey])) {
                    $result[$key] = $cachedValues[$cacheKey];
                    $this->setRuntime($key, $cachedValues[$cacheKey]);
                }
            }
        }

        return $result;
    }

    /**
     * 批量设置缓存
     *
     * @param array<string, mixed> $values 键值对
     */
    public function setMultiple(array $values, ?int $ttl = null): void
    {
        foreach ($values as $key => $value) {
            $this->setRuntime($key, $value);
        }

        if (! $this->enabled) {
            return;
        }

        $cacheValues = [];
        foreach ($values as $key => $value) {
            $cacheValues[$this->buildKey($key)] = $value;
        }

        $this->getCache()->putMultiple($cacheValues, $ttl ?? $this->ttl);
    }

    /**
     * 获取模块发现缓存
     *
     * @return array<string, mixed>|null
     */
    public function getModuleDiscovery(string $moduleName): ?array
    {
        return $this->get("discovery:{$moduleName}");
    }

    /**
     * 设置模块发现缓存
     *
     * @param array<string, mixed> $discovery
     */
    public function setModuleDiscovery(string $moduleName, array $discovery): void
    {
        $this->set("discovery:{$moduleName}", $discovery);
    }

    /**
     * 获取所有模块列表缓存
     *
     * @return array<string, mixed>|null
     */
    public function getAllModules(): ?array
    {
        return $this->get('modules:all');
    }

    /**
     * 设置所有模块列表缓存
     *
     * @param array<string, mixed> $modules
     */
    public function setAllModules(array $modules): void
    {
        $this->set('modules:all', $modules);
    }

    /**
     * 获取模块文件映射缓存
     *
     * 缓存模块文件路径映射，避免重复扫描
     *
     * @return array<string, array<string>>|null
     */
    public function getFileMap(string $moduleName): ?array
    {
        return $this->get("filemap:{$moduleName}");
    }

    /**
     * 设置模块文件映射缓存
     *
     * @param array<string, array<string>> $fileMap
     */
    public function setFileMap(string $moduleName, array $fileMap): void
    {
        $this->set("filemap:{$moduleName}", $fileMap);
    }

    /**
     * 获取类存在性检查结果缓存
     */
    public function getClassCheck(string $className): ?bool
    {
        return $this->get("classcheck:{$className}");
    }

    /**
     * 设置类存在性检查结果缓存
     */
    public function setClassCheck(string $className, bool $exists): void
    {
        $this->set("classcheck:{$className}", $exists, 7200); // 类检查缓存更久
    }

    /**
     * 清空所有模块缓存
     */
    public function clear(): void
    {
        // 清空运行时缓存
        self::$runtimeCache = [];

        if (! $this->enabled) {
            return;
        }

        // 清空持久化缓存（使用标签如果支持）
        $cache = $this->getCache();

        if (method_exists($cache->getStore(), 'flush')) {
            $cache->flush();
        } else {
            // 逐个删除已知的缓存键
            $keys = [
                'modules:all',
            ];
            foreach ($keys as $key) {
                $cache->forget($this->buildKey($key));
            }
        }
    }

    /**
     * 清空运行时缓存
     */
    public static function clearRuntimeCache(): void
    {
        self::$runtimeCache = [];
    }

    /**
     * 获取缓存统计信息
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'enabled' => $this->enabled,
            'runtime_cache_size' => count(self::$runtimeCache),
            'use_runtime_cache' => $this->useRuntimeCache,
            'ttl' => $this->ttl,
            'version' => self::CACHE_VERSION,
        ];
    }

    /**
     * 检查缓存是否启用
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * 检查是否使用运行时缓存
     */
    public function isRuntimeCacheEnabled(): bool
    {
        return $this->useRuntimeCache;
    }
}
