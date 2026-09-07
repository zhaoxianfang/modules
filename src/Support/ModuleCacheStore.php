<?php

declare(strict_types=1);

namespace zxf\Modules\Support;

/**
 * 模块缓存存储
 *
 * 统一以 JSON 格式持久化模块元数据与自动发现清单，替代早期的
 * 「var_export + require」PHP 文件缓存方案。
 *
 * 为什么要改用 JSON：
 * 1. **OPcache 陷阱**：`require` 的 PHP 缓存文件会被 OPcache 编译缓存。生产环境
 *    若设置 `opcache.validate_timestamps=0`，执行 module:cache 重新生成文件后，
 *    require 仍会返回旧的编译结果且永不刷新，比「缓存没生效」更危险。
 * 2. **代码执行风险**：`require` 一个被篡改的缓存文件等价于执行任意 PHP 代码；
 *    JSON 文件只会被当作数据解析，天然免疫。
 * 3. **原子性**：`file_put_contents` 是「先截断为 0 再写」，并发请求可能读到
 *    半截文件。本类采用「临时文件 + rename」的原子写入，读取方只会看到完整内容。
 *
 * @package zxf\Modules
 */
final class ModuleCacheStore
{
    /**
     * 读取 JSON 缓存
     *
     * 任何异常（文件不存在、内容损坏、JSON 解析失败）都返回 null，
     * 调用方据此回退到实时扫描，绝不阻断启动。
     *
     * @param string $file 缓存文件路径
     * @return array|null 解析成功返回数组，否则 null
     */
    public static function read(string $file): ?array
    {
        if (! is_file($file)) {
            return null;
        }

        try {
            $raw = @file_get_contents($file);

            if ($raw === false || $raw === '') {
                return null;
            }

            $data = json_decode($raw, true);

            return is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 原子写入 JSON 缓存
     *
     * @param string $file 缓存文件路径
     * @param array  $data 待写入数据（必须可 JSON 序列化）
     * @return bool 写入成功返回 true
     */
    public static function write(string $file, array $data): bool
    {
        $tmp = null;

        try {
            $dir = dirname($file);
            if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                return false;
            }

            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($json === false) {
                return false;
            }

            // 写入同目录下的临时文件后 rename：rename 在同一文件系统上是原子操作，
            // 避免并发读取到「截断为 0」或半截内容的缓存文件。
            $tmp = $file.'.'.getmypid().'.tmp';

            if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
                return false;
            }

            if (! @rename($tmp, $file)) {
                return false;
            }

            @chmod($file, 0644);

            // 清理历史版本遗留的同名 PHP 缓存文件
            self::deleteLegacyPhpCache($file);

            // 若曾经被 OPcache 编译过（历史 .php 缓存或异常场景），主动失效
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }

            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            if ($tmp !== null && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * 删除缓存文件（含写入残留的临时文件）
     *
     * @param string $file 缓存文件路径
     * @return void
     */
    public static function delete(string $file): void
    {
        if (is_file($file)) {
            @unlink($file);
        }

        self::deleteLegacyPhpCache($file);

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }

        // 清理并发写入过程中可能残留的临时文件
        foreach ((array) glob($file.'.*.tmp') as $stale) {
            if (is_string($stale) && is_file($stale)) {
                @unlink($stale);
            }
        }
    }

    /**
     * 删除旧版 PHP 缓存文件（modules.php / discovery.php）
     *
     * 缓存格式由 PHP 迁移为 JSON 后，旧文件已成为垃圾；若残留，
     * 部分运维脚本（如手动清理缓存的部署脚本）可能误删或误读。
     *
     * @param string $file 当前 JSON 缓存文件路径
     * @return void
     */
    protected static function deleteLegacyPhpCache(string $file): void
    {
        if (! str_ends_with($file, '.json')) {
            return;
        }

        $legacy = substr($file, 0, -5).'.php';

        if (is_file($legacy)) {
            @unlink($legacy);

            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($legacy, true);
            }
        }
    }
}
