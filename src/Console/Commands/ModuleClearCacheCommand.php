<?php

namespace zxf\Modules\Console\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Facades\Module;

class ModuleClearCacheCommand extends Command
{
    /**
     * 控制台命令的名称和签名。
     *
     * @var string
     */
    protected $signature = 'module:clear-cache';

    /**
     * 控制台命令描述。
     *
     * @var string
     */
    protected $description = '清除模块发现缓存';

    /**
     * 执行控制台命令。
     */
    public function handle(): int
    {
        $this->info('正在清除模块缓存...');

        Module::clearCache();
        
        $this->info('模块缓存已清除成功。');

        return Command::SUCCESS;
    }
}