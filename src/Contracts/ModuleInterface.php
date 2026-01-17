<?php

namespace zxf\Modules\Contracts;

interface ModuleInterface
{
    /**
     * 获取模块名称
     *
     * @return string
     */
    public function getName(): string;

    /**
     * 获取模块驼峰名称
     *
     * @return string
     */
    public function getCamelName(): string;

    /**
     * 获取模块小驼峰名称
     *
     * @return string
     */
    public function getLowerCamelName(): string;

    /**
     * 获取模块小写名称
     *
     * @return string
     */
    public function getLowerName(): string;

    /**
     * 获取模块路径
     *
     * @param string|null $path
     * @return string
     */
    public function getPath(?string $path = null): string;

    /**
     * 获取模块命名空间
     *
     * @return string
     */
    public function getNamespace(): string;

    /**
     * 检查模块是否已启用
     *
     * @return bool
     */
    public function isEnabled(): bool;

    /**
     * 获取模块配置文件路径
     *
     * @return string
     */
    public function getConfigPath(): string;

    /**
     * 获取模块路由路径
     *
     * @return string
     */
    public function getRoutesPath(): string;

    /**
     * 获取模块服务提供者路径
     *
     * @return string
     */
    public function getProvidersPath(): string;

    /**
     * 获取模块命令路径
     *
     * @return string
     */
    public function getCommandsPath(): string;

    /**
     * 获取模块视图路径
     *
     * @return string
     */
    public function getViewsPath(): string;

    /**
     * 获取模块迁移路径
     *
     * @return string
     */
    public function getMigrationsPath(): string;

    /**
     * 获取模块控制器路径
     *
     * @return string
     */
    public function getControllersPath(): string;

    /**
     * 获取模块配置值
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function config(string $key, $default = null);

    /**
     * 检查模块是否有某个路由文件
     *
     * @param string $route
     * @return bool
     */
    public function hasRoute(string $route): bool;

    /**
     * 获取模块服务提供者类名
     *
     * @return string|null
     */
    public function getServiceProviderClass(): ?string;

    /**
     * 获取所有路由文件
     *
     * @return array
     */
    public function getRouteFiles(): array;

    /**
     * 获取模块配置（直接从文件读取）
     *
     * @return array
     */
    public function getModuleConfig(): array;
}
