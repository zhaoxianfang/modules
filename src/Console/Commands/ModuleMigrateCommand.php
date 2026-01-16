<?php

namespace zxf\Modules\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use zxf\Modules\Facades\Module;

class ModuleMigrateCommand extends Command
{
    /**
     * 控制台命令的名称和签名。
     *
     * @var string
     */
    protected $signature = 'module:migrate 
                            {name : 模块名称}
                            {--fresh : 删除所有表并重新运行迁移}
                            {--seed : 运行迁移后运行数据填充}
                            {--force : 强制在生产环境中运行}
                            {--pretend : 显示要运行的 SQL 查询而不执行}
                            {--step : 强制迁移按步骤运行，以便可以单独回滚}';

    /**
     * 控制台命令的描述。
     *
     * @var string
     */
    protected $description = '运行模块的数据库迁移';

    /**
     * 执行控制台命令。
     */
    public function handle(): int
    {
        $name = $this->argument('name');

        if (!Module::exists($name)) {
            $this->error("模块 [{$name}] 不存在。");
            return Command::FAILURE;
        }

        $module = Module::find($name);
        $migrationPath = $module->getPath() . '/database/migrations';

        if (!is_dir($migrationPath)) {
            $this->warn("模块 [{$name}] 没有迁移目录。");
            return Command::SUCCESS;
        }

        $params = ['--path' => $migrationPath];
        
        if ($this->option('fresh')) {
            $params['--fresh'] = true;
        }
        
        if ($this->option('seed')) {
            $params['--seed'] = true;
        }
        
        if ($this->option('force')) {
            $params['--force'] = true;
        }
        
        if ($this->option('pretend')) {
            $params['--pretend'] = true;
        }
        
        if ($this->option('step')) {
            $params['--step'] = true;
        }

        $this->info("正在运行模块 [{$name}] 的迁移...");
        
        try {
            Artisan::call('migrate', $params);
            
            $output = Artisan::output();
            if (!empty(trim($output))) {
                $this->line($output);
            }
            
            $this->info("模块 [{$name}] 的迁移已成功运行。");
            
        } catch (\Exception $e) {
            $this->error("运行模块迁移时出错: " . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}