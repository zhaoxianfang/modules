<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Commands\Concerns\EscapesConsoleOutput;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\ModuleAutoDiscovery;

/**
 * 模块缓存命令
 * 将模块清单（启用的模块列表、配置快照等）写入缓存文件，
 * 提升运行时模块发现性能。
 */
class ModuleCacheCommand extends Command
{
    use EscapesConsoleOutput;

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
     * 绕过可能过期的缓存，以磁盘真实状态重新扫描并写入模块缓存。
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
                $failed = [];

                foreach (Module::allEnabled() as $module) {
                    try {
                        ModuleAutoDiscovery::warm($module);
                        $warmed++;
                    } catch (\Throwable $e) {
                        // 单个模块预热失败不影响其余模块，但必须**留痕**。
                        // 原实现空 catch 吞掉异常，最终仍打印 "✓ 已预热 N 个模块" 并返回 SUCCESS，
                        // 而缺失的模块清单会在每次请求时退回全量目录扫描 ——
                        // 命令"看起来成功了"，性能问题却原封不动，属于典型的假成功。
                        $failed[] = $module->getName().' ('.$e->getMessage().')';
                    }
                }

                $this->info("✓ 自动发现清单已预热，覆盖 {$warmed} 个已启用模块。");

                if ($failed !== []) {
                    $this->warn(sprintf('⚠ %d 个模块预热失败（这些模块将退回每次请求全量扫描）：', count($failed)));
                    foreach (array_slice($failed, 0, 10) as $item) {
                        $this->warn('  - '.$item);
                    }
                    if (count($failed) > 10) {
                        $this->warn('  ... 其余 '.(count($failed) - 10).' 项省略');
                    }

                    // 预热完全失败时返回 FAILURE：此时预热没带来任何收益，
                    // 继续报 SUCCESS 会掩盖真实的失败。
                    return $warmed === 0 ? Command::FAILURE : Command::SUCCESS;
                }
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
