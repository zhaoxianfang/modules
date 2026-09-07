<?php

declare(strict_types=1);

namespace zxf\Modules\Facades;

use Illuminate\Support\Facades\Facade;
use zxf\Modules\Contracts\RepositoryInterface;

/**
 * 模块门面
 *
 * 提供对模块仓库的静态访问接口。
 *
 * @method static array                                 all() 获取所有已注册模块（包含启用与禁用）的集合
 * @method static array                                 allEnabled() 获取所有已启用模块的集合
 * @method static array                                 allDisabled() 获取所有已禁用模块的集合
 * @method static \zxf\Modules\Contracts\ModuleInterface|null find(string $name) 按名称查找模块，未找到返回 null
 * @method static \zxf\Modules\Contracts\ModuleInterface      findOrFail(string $name) 按名称查找模块，未找到抛出异常
 * @method static bool                                  has(string $name) 判断指定名称的模块是否已注册
 * @method static array                                 getNames() 获取所有已注册模块的名称列表
 * @method static array                                 getEnabledNames() 获取所有已启用模块的名称列表
 * @method static int                                   count() 统计已注册模块的总数量
 * @method static int                                   countEnabled() 统计已启用模块的数量
 * @method static void                                  scan() 扫描模块目录，注册新发现的模块
 * @method static void                                  rescan() 强制重新扫描模块目录并刷新模块缓存
 * @method static self                                  registerModule(\zxf\Modules\Contracts\ModuleInterface $module) 手动注册一个模块实例
 * @method static bool                                  unregisterModule(string $name) 注销指定名称的模块
 * @method static string                                getModulePath(string $name, ?string $path = null) 获取指定模块的路径（可拼接子路径）
 * @method static self                                  addPath(string $path) 添加一个模块扫描目录
 * @method static array                                 getPaths() 获取所有已配置的模块扫描目录
 * @method static void                                  clearCache() 清除模块发现缓存（配置、清单等缓存文件）
 * @method static void                                  clearRepositoryCache() 清除仓库级注册清单缓存并强制下次访问重新扫描磁盘
 * @method static void                                  ensureScanned() 确保已完成模块扫描（未扫描时立即扫描）
 * @method static void                                  invalidateStatusCache() 失效启用/禁用模块集合的内存缓存
 *
 * @see \zxf\Modules\Contracts\RepositoryInterface
 */
class Module extends Facade
{
    /**
     * 获取组件注册名称
     */
    protected static function getFacadeAccessor(): string
    {
        return RepositoryInterface::class;
    }
}
