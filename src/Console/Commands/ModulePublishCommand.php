<?php

namespace zxf\Modules\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use zxf\Modules\Facades\Module;

class ModulePublishCommand extends Command
{
    /**
     * 控制台命令的名称和签名。
     *
     * @var string
     */
    protected $signature = 'module:publish 
                            {name : 模块名称}
                            {--tag= : 要发布的标签}
                            {--all : 发布所有资源}';

    /**
     * 控制台命令的描述。
     *
     * @var string
     */
    protected $description = '发布模块资源（配置、视图、翻译、资源文件）';

    /**
     * 执行控制台命令。
     */
    public function handle(): int
    {
        $name = $this->argument('name');

        if (!Module::exists($name)) {
            $this->error("模块 [{$name}] 未找到。");
            return Command::FAILURE;
        }

        $module = Module::find($name);
        $providers = $module->getProviders();

        if (empty($providers)) {
            $this->warn("模块 [{$name}] 没有服务提供者可发布。");
            return Command::SUCCESS;
        }

        $tags = $this->option('tag') ? explode(',', $this->option('tag')) : [];
        $publishAll = $this->option('all');
        
        // 获取发布组配置
        $publishGroups = config('modules.publish_groups', [
            'config',
            'migrations',
            'views',
            'translations',
            'assets',
            'commands',
        ]);
        
        // 获取存储驱动配置
        $storageDriver = config('modules.storage_driver', 'local');

        foreach ($providers as $provider) {
            if (!class_exists($provider)) {
                $this->warn("提供者 [{$provider}] 不存在，跳过。");
                continue;
            }

            $params = ['--provider' => $provider];
            
            if (!empty($tags)) {
                $params['--tag'] = $tags;
            } elseif ($publishAll) {
                $params['--all'] = true;
            } else {
                // 如果没有指定标签或--all，使用配置的发布组
                $params['--tag'] = $publishGroups;
            }
            
            // 添加存储驱动参数（如果支持）
            if ($storageDriver !== 'local' && method_exists($this->laravel['filesystem'], 'disk')) {
                $params['--disk'] = $storageDriver;
            }

            $this->info("正在为提供者发布资源：{$provider}");
            Artisan::call('vendor:publish', $params);
            
            $output = Artisan::output();
            if (!empty(trim($output))) {
                $this->line($output);
            }
        }

        $this->info("模块 [{$name}] 资源已成功发布。");

        return Command::SUCCESS;
    }
}