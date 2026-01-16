<?php

namespace zxf\Modules\Console\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Facades\Module;

class ModuleCacheCommand extends Command
{
    /**
     * 控制台命令的名称和签名。
     *
     * @var string
     */
    protected $signature = 'module:cache';

    /**
     * 控制台命令描述。
     *
     * @var string
     */
    protected $description = '缓存模块发现结果以提高性能';

    /**
     * 执行控制台命令。
     */
    public function handle(): int
    {
        $this->info('正在缓存模块...');

        Module::cache();
        
        $this->info('模块缓存成功。');

        return Command::SUCCESS;
    }
}