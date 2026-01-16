<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建数据填充器命令
 *
 * 在指定模块中创建数据填充器
 */
class SeederMakeCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:make-seeder
                            {module : 模块名称}
                            {name : 填充器名称}
                            {--force : 覆盖已存在的填充器}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '在指定模块中创建一个数据填充器';

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $moduleName = Str::studly($this->argument('module'));
        $seederName = Str::studly($this->argument('name'));
        $force = $this->option('force');

        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("Module [{$moduleName}] does not exist.");

            return Command::FAILURE;
        }

        $seederPath = $module->getPath('Database/Seeders/' . $seederName . '.php');

        if (File::exists($seederPath) && ! $force) {
            $this->error("Seeder [{$seederName}] already exists in module [{$moduleName}].");

            return Command::FAILURE;
        }

        $namespace = config('modules.namespace', 'Modules');

        $stubGenerator = new StubGenerator($moduleName);
        $stubGenerator->addReplacement('{{CLASS}}', $seederName);
        $stubGenerator->addReplacement('{{NAMESPACE}}', $namespace . '\\' . $moduleName);

        // 确保填充器目录存在
        $seederDir = $module->getPath('Database/Seeders');
        if (! is_dir($seederDir)) {
            File::makeDirectory($seederDir, 0755, true);
        }

        $result = $stubGenerator->generate('seeder.stub', 'Database/Seeders/' . $seederName . '.php', $force);

        if ($result) {
            $this->info("Seeder [{$seederName}] created successfully in module [{$moduleName}].");

            return Command::SUCCESS;
        }

        $this->error("Failed to create seeder [{$seederName}].");

        return Command::FAILURE;
    }
}
