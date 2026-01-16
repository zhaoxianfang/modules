<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use zxf\Modules\Facades\Module;

class ModuleDeleteCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:delete
                            {name : 模块名称}
                            {--force : 强制删除，不提示确认}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '删除一个模块';

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $name = $this->argument('name');
        $force = $this->option('force');

        $module = Module::find($name);

        if (! $module) {
            $this->error("Module [{$name}] does not exist.");

            return Command::FAILURE;
        }

        if (! $force && ! $this->confirm("Are you sure you want to delete module [{$name}]?")) {
            $this->info('Operation cancelled.');

            return Command::SUCCESS;
        }

        File::deleteDirectory($module->getPath());

        $this->info("Module [{$name}] has been deleted.");

        return Command::SUCCESS;
    }
}
