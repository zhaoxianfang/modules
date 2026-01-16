<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建迁移文件命令
 *
 * 在指定模块中创建迁移文件
 */
class MigrationMakeCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:make-migration
                            {module : 模块名称}
                            {name : 迁移名称}
                            {--create= : 要创建的表名}
                            {--table= : 要修改的表名}
                            {--force : 覆盖已存在的迁移文件}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '在指定模块中创建一个迁移文件';

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $moduleName = Str::studly($this->argument('module'));
        $migrationName = $this->argument('name');
        $createTable = $this->option('create');
        $table = $this->option('table');

        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("Module [{$moduleName}] does not exist.");

            return Command::FAILURE;
        }

        $timestamp = date('Y_m_d_His');
        $filename = $timestamp . '_' . $migrationName . '.php';
        $migrationPath = $module->getMigrationsPath() . DIRECTORY_SEPARATOR . $filename;

        // 确保迁移目录存在
        $migrationDir = $module->getMigrationsPath();
        if (! is_dir($migrationDir)) {
            File::makeDirectory($migrationDir, 0755, true);
        }

        $tableName = $createTable ?: $table;

        $stubGenerator = new StubGenerator($moduleName);
        $stubGenerator->addReplacement('{{CLASS}}', Str::studly($migrationName));
        $stubGenerator->addReplacement('{{TABLE}}', $tableName ?: 'table_name');

        $result = $stubGenerator->generate('migration.stub', 'Database/Migrations/' . $filename);

        if ($result) {
            $this->info("Migration [{$filename}] created successfully in module [{$moduleName}].");

            return Command::SUCCESS;
        }

        $this->error("Failed to create migration [{$filename}].");

        return Command::FAILURE;
    }
}
