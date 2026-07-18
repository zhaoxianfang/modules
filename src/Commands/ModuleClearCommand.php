<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Facades\Module;

class ModuleClearCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:clear';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '清除模块缓存（下次访问将重新扫描磁盘）';

    /**
     * 执行命令
     *
     * 删除模块缓存文件并重置内存缓存，使后续命令以磁盘真实状态重新扫描。
     * 当模块被手动新增/删除（如部署、git 拉取）后缓存过期时，
     * 执行本命令可立即恢复 module:list / module:delete 等命令的正确性。
     *
     * @return int
     */
    public function handle(): int
    {
        try {
            Module::clearCache();

            $this->info('✓ 模块缓存已清除，下次访问将重新扫描磁盘。');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("清除模块缓存失败: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
