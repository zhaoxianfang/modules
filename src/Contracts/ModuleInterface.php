<?php

declare(strict_types=1);

namespace zxf\Modules\Contracts;

/**
 * 模块接口
 *
 * 定义模块实体的核心契约，所有模块实现类必须遵循此接口。
 *
 * @package zxf\Modules
 * @version 4.0.0
 */
interface ModuleInterface
{
    // ========================================================================
    //  名称相关
    // ========================================================================

    /**
     * 获取模块名称 (StudlyCase)
     */
    public function getName(): string;

    /**
     * 获取模块小写名称
     */
    public function getLowerName(): string;

    /**
     * 获取蛇形命名
     */
    public function getSnakeName(): string;

    /**
     * 获取驼峰命名（首字母小写）
     */
    public function getCamelName(): string;

    /**
     * 获取小驼峰命名
     */
    public function getLowerCamelName(): string;

    // ========================================================================
    //  路径与命名空间
    // ========================================================================

    /**
     * 获取模块路径
     *
     * @param string|null $path 子路径（可选）
     */
    public function getPath(?string $path = null): string;

    /**
     * 获取模块根命名空间
     */
    public function getNamespace(): string;

    /**
     * 获取模块类命名空间（根命名空间\模块名）
     */
    public function getClassNamespace(): string;

    // ========================================================================
    //  状态
    // ========================================================================

    /**
     * 检查模块是否启用
     */
    public function isEnabled(): bool;

    /**
     * 获取模块优先级（数字越小优先级越高）
     */
    public function getPriority(): int;

    // ========================================================================
    //  元数据
    // ========================================================================

    /**
     * 获取模块描述
     */
    public function getDescription(): string;

    /**
     * 获取模块别名
     *
     * @return array<string>
     */
    public function getAliases(): array;

    /**
     * 获取模块作者
     */
    public function getAuthor(): string;

    /**
     * 获取模块版本
     */
    public function getVersion(): string;

    // ========================================================================
    //  目录路径
    // ========================================================================

    /**
     * 获取配置目录路径
     */
    public function getConfigPath(): string;

    /**
     * 获取路由目录路径
     */
    public function getRoutesPath(): string;

    /**
     * 获取服务提供者目录路径
     */
    public function getProvidersPath(): string;

    /**
     * 获取命令目录路径
     */
    public function getCommandsPath(): string;

    /**
     * 获取视图目录路径
     */
    public function getViewsPath(): string;

    /**
     * 获取迁移目录路径
     */
    public function getMigrationsPath(): string;

    /**
     * 获取控制器目录路径
     */
    public function getControllersPath(): string;

    /**
     * 获取种子数据目录路径
     */
    public function getSeedersPath(): string;

    /**
     * 获取语言文件目录路径
     */
    public function getLangPath(): string;

    // ========================================================================
    //  文件/目录检查
    // ========================================================================

    /**
     * 检查路由文件是否存在
     */
    public function hasRoute(string $route): bool;

    /**
     * 获取所有路由文件（不含扩展名）
     *
     * @return array<string>
     */
    public function getRouteFiles(): array;

    // ========================================================================
    //  配置
    // ========================================================================

    /**
     * 获取模块配置值
     *
     * @param string $key     配置键
     * @param mixed  $default 默认值
     */
    public function config(string $key, mixed $default = null): mixed;

    /**
     * 获取模块完整配置数组
     *
     * @return array<string, mixed>
     */
    public function getModuleConfig(): array;

    /**
     * 获取服务提供者类名
     */
    public function getServiceProviderClass(): ?string;

    // ========================================================================
    //  缓存
    // ========================================================================

    /**
     * 清除模块内部缓存
     */
    public function clearCache(): void;
}
