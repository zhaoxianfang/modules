<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Facades\Module;

class ModuleCacheCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:cache';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '重新扫描并缓存所有模块（用于生产环境加速模块加载）';

    /**
     * 执行命令
     *
     * 绕过可能过期的缓存，以磁盘真实状态重新扫描并写入模块缓存。
     *
     * @return int
     */
    public function handle(): int
    {
        try {
            Module::rescan();

            $count = Module::count();

            $this->info("✓ 模块缓存已重新生成，共缓存 {$count} 个模块。");

            // 预热自动发现清单：扫描并持久化各模块的组件清单，
            // 使后续请求跳过目录扫描与自动加载探测（仅当缓存启用时生效）。
            if (config('modules.cache.enabled', false)) {
                $warmed = 0;
                foreach (Module::allEnabled() as $module) {
                    try {
                        \zxf\Modules\Support\ModuleAutoDiscovery::warm($module);
                        $warmed++;
                    } catch (\Throwable) {
                        // 单个模块预热失败不影响整体
                    }
                }
                $this->info("✓ 自动发现清单已预热，覆盖 {$warmed} 个已启用模块。");
            } else {
                $this->warn('提示：当前 modules.cache.enabled 为 false，缓存不会被持久化（仅本次进程生效）。');
                $this->warn('      如需持久化，请在 .env 中设置 MODULES_CACHE_ENABLED=true。');
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("生成模块缓存失败: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
