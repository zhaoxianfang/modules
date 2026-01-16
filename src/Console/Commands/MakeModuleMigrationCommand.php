<?php

namespace zxf\Modules\Console\Commands;

use Illuminate\Support\Str;

class MakeModuleMigrationCommand extends ModuleGeneratorCommand
{
    /**
     * 控制台命令的名称和签名。
     *
     * @var string
     */
    protected $signature = 'module:make:migration {module : 模块名称} {name : 迁移名称} {--table= : 表名} {--create : 创建新表} {--force : 覆盖现有文件}';

    /**
     * 控制台命令的描述。
     *
     * @var string
     */
    protected $description = '为模块创建新的迁移文件';

    /**
     * 获取目标类路径。
     */
    protected function getDestinationPath(string $moduleName, string $name): string
    {
        $migrationName = $this->getMigrationName($name);
        $migrationPath = $this->getModulePath($moduleName) . '/database/migrations/' . $migrationName . '.php';

        return $migrationPath;
    }

    /**
     * 获取带时间戳的迁移名称。
     */
    protected function getMigrationName(string $name): string
    {
        $timestamp = date('Y_m_d_His');
        $snakeName = Str::snake($name);

        return "{$timestamp}_{$snakeName}";
    }

    /**
     * 获取存根文件路径。
     */
    protected function getStubFile(): string
    {
        return $this->getStubPath('migration.stub');
    }

    /**
     * 获取存根文件的替换变量。
     */
    protected function getReplacements(string $moduleName, string $name): array
    {
        $replacements = parent::getReplacements($moduleName, $name);
        
        // 确定表名
        $table = $this->option('table') ?: Str::snake(Str::pluralStudly($name));
        $replacements['{{table}}'] = $table;

        return $replacements;
    }
}