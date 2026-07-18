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

            if (! config('modules.cache.enabled', false)) {
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
