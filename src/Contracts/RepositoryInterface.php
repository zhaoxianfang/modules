<?php

declare(strict_types=1);

namespace zxf\Modules\Contracts;

use zxf\Modules\Exceptions\ModuleNotFoundException;

/**
 * 模块仓库接口
 *
 * 定义模块仓库的核心契约。
 *
 * @package zxf\Modules
 * @version 4.0.0
 */
interface RepositoryInterface
{
    /**
     * 获取所有模块
     *
     * @return array<string, ModuleInterface>
     */
    public function all(): array;

    /**
     * 获取所有已启用模块
     *
     * @return array<string, ModuleInterface>
     */
    public function allEnabled(): array;

    /**
     * 获取所有已禁用模块
     *
     * @return array<string, ModuleInterface>
     */
    public function allDisabled(): array;

    /**
     * 查找模块
     *
     * @param string $name 模块名称或别名
     */
    public function find(string $name): ?ModuleInterface;

    /**
     * 查找模块，若不存在则抛出异常
     *
     * @throws ModuleNotFoundException
     */
    public function findOrFail(string $name): ModuleInterface;

    /**
     * 检查模块是否存在
     */
    public function has(string $name): bool;

    /**
     * 获取所有模块名称
     *
     * @return array<string>
     */
    public function getNames(): array;

    /**
     * 获取已启用模块名称
     *
     * @return array<string>
     */
    public function getEnabledNames(): array;

    /**
     * 模块总数
     */
    public function count(): int;

    /**
     * 已启用模块总数
     */
    public function countEnabled(): int;

    /**
     * 扫描并注册所有模块
     */
    public function scan(): void;

    /**
     * 强制重新扫描
     */
    public function rescan(): void;

    /**
     * 手动注册模块
     */
    public function registerModule(ModuleInterface $module): self;

    /**
     * 注销模块
     */
    public function unregisterModule(string $name): bool;

    /**
     * 获取模块路径
     *
     * @param string      $name 模块名称
     * @param string|null $path 子路径
     */
    public function getModulePath(string $name, ?string $path = null): string;

    /**
     * 清除所有缓存
     */
    public function clearCache(): void;

    /**
     * 清除仓库级模块注册清单缓存并强制下次访问重新扫描磁盘
     *
     * 与 clearCache() 的区别：额外重置「已扫描」标记与内存中的模块注册表，
     * 适用于模块被创建/删除后需要立即与磁盘状态保持一致的场景。
     */
    public function clearRepositoryCache(): void;

    /**
     * 添加扫描路径
     */
    public function addPath(string $path): self;

    /**
     * 获取所有扫描路径
     *
     * @return array<string>
     */
    public function getPaths(): array;
}
