<?php

namespace zxf\Modules\Contracts;

interface RepositoryInterface
{
    /**
     * 获取所有模块
     *
     * @return array
     */
    public function all(): array;

    /**
     * 获取所有已启用的模块
     *
     * @return array
     */
    public function allEnabled(): array;

    /**
     * 获取所有已禁用的模块
     *
     * @return array
     */
    public function allDisabled(): array;

    /**
     * 获取指定模块
     *
     * @param string $name
     * @return ModuleInterface|null
     */
    public function find(string $name): ?ModuleInterface;

    /**
     * 检查模块是否存在
     *
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool;

    /**
     * 获取所有模块名称
     *
     * @return array
     */
    public function getNames(): array;

    /**
     * 获取所有已启用模块名称
     *
     * @return array
     */
    public function getEnabledNames(): array;

    /**
     * 扫描并注册所有模块
     *
     * @return void
     */
    public function scan(): void;

    /**
     * 获取模块路径
     *
     * @param string $name
     * @param string|null $path
     * @return string
     */
    public function getModulePath(string $name, ?string $path = null): string;
}
