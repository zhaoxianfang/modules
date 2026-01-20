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
 * 在指定模块中创建迁移文件，支持智能命名解析
 * 按照 Laravel 标准只使用三个模板：create（创建表）、update（修改表）、migration（空白迁移）
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
                            {--create= : 要创建的表名（使用该选项时自动使用 create.stub）}
                            {--update= : 要修改的表名（使用该选项时自动使用 update.stub）}
                            {--path= : 迁移文件路径}
                            {--realpath : 指示提供的任何迁移文件路径都是预解析的绝对路径}
                            {--fullpath : 不在模块 Database/Migrations 目录中生成迁移}';

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
        $updateTable = $this->option('update');

        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在");

            return Command::FAILURE;
        }

        // 解析迁移名称和确定模板类型
        $migrationInfo = $this->parseMigrationName($migrationName, $createTable, $updateTable);

        // 确保迁移目录存在
        $migrationDir = $this->getMigrationPath($module);
        if (! is_dir($migrationDir)) {
            File::makeDirectory($migrationDir, 0755, true);
        }

        $timestamp = date('Y_m_d_His');
        $filename = $timestamp . '_' . $migrationName . '.php';
        $migrationPath = $migrationDir . DIRECTORY_SEPARATOR . $filename;

        // 检查迁移文件是否已存在
        if (File::exists($migrationPath)) {
            $this->error("模块 [{$moduleName}] 中已存在迁移文件 [{$filename}]");
            $this->line('提示：请使用不同的迁移名称，或手动删除现有的迁移文件');

            return Command::FAILURE;
        }

        // 生成迁移内容
        $stubGenerator = new StubGenerator($moduleName);
        $migrationStub = $this->generateMigrationStub($stubGenerator, $migrationInfo);

        $result = File::put($migrationPath, $migrationStub);

        if ($result) {
            $this->info("成功在模块 [{$moduleName}] 中创建迁移文件 [{$filename}]");

            // 显示迁移类型信息
            $this->line('');
            $this->line("<fg=yellow>迁移模板:</> {$migrationInfo['stub_type']}");
            if ($migrationInfo['table']) {
                $this->line("<fg=yellow>操作对象:</> {$migrationInfo['table']}");
            }
            if ($migrationInfo['description']) {
                $this->line("<fg=yellow>迁移说明:</> {$migrationInfo['description']}");
            }

            return Command::SUCCESS;
        }

        $this->error("创建迁移文件 [{$filename}] 失败");

        return Command::FAILURE;
    }

    /**
     * 解析迁移名称并确定使用的模板类型
     *
     * 按照 Laravel 标准提供三种模板类型：
     * 1. create - 创建新的数据表（使用 create.stub）
     * 2. update - 修改现有数据表（使用 update.stub）
     * 3. migration - 空白迁移，自定义逻辑（使用 migration.stub）
     *
     * @param string $name 迁移名称
     * @param string|null $createTable --create 选项指定的表名
     * @param string|null $updateTable --update 选项指定的表名
     * @return array 迁移信息数组
     */
    protected function parseMigrationName(string $name, ?string $createTable = null, ?string $updateTable = null): array
    {
        $name = strtolower($name);
        $stub_type = 'migration';
        $description = '自定义迁移逻辑';

        // 解析迁移文件名和表名
        $parseTypeAndTable = $this->parseTableAndTypeByMigrationName($name);

        $table = !empty($parseTypeAndTable['table'])?$parseTypeAndTable['table']:null;
        if(empty($createTable) && empty($updateTable)){
            if($parseTypeAndTable['type'] == 'create'){
                $stub_type = 'create';
                $description = "创建数据表：{$table}";
            }
            if($parseTypeAndTable['type'] == 'update'){
                $stub_type = 'update';
                $description = "修改数据表：{$table}";
            }
        }else{
            $table = !empty($createTable)?$createTable:$updateTable;
            $stub_type = !empty($createTable)?'create':'update';
        }

        // 迁移类型
        return [
            'stub_type' => $stub_type,
            'table' => $table,
            'description' => $description,
            'migration_name' => $name,
        ];
    }

    // 通过迁移文件名解析迁移类型和表名
    private function parseTableAndTypeByMigrationName(string $migrationName): array
    {
        // 移除时间戳前缀
        $name = preg_replace('/^\d+_\d+_\d+_\d+_/', '', $migrationName);

        // 匹配 create 类型
        if (preg_match('/^create_([^_]+?)(?:_table|_tables)?$/i', $name, $m)) {
            return ['type' => 'create', 'table' => $m[1]];
        }

        // 匹配 add 类型
        if (preg_match('/^add_.+_to_([^_]+?)(?:_table|_tables)?$/i', $name, $m)) {
            return ['type' => 'update', 'table' => $m[1]];
        }

        // 匹配其他类型
        $patterns = [
            'update' => '/^update_([^_]+?)(?:_table|_tables)?$/i',
            'drop' => '/^drop_([^_]+?)(?:_table|_tables)?$/i',
            'rename' => '/^rename_([^_]+?)_to_/i',
            'change' => '/^change_.+_in_([^_]+?)(?:_table|_tables)?$/i',
        ];

        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $name, $m)) {
                return ['type' => $type, 'table' => $m[1]];
            }
        }

        return ['type' => 'update', 'table' => preg_replace('/_(?:table|tables)$/i', '', $name)];
    }

    /**
     * 生成迁移 stub 内容
     *
     * 根据迁移信息选择合适的 stub 模板，替换占位符生成最终内容
     *
     * @param StubGenerator $stubGenerator stub 生成器实例
     * @param array $migrationInfo 迁移信息
     * @return string 生成的迁移文件内容
     */
    protected function generateMigrationStub(StubGenerator $stubGenerator, array $migrationInfo): string
    {
        // 生成类名
        $className = Str::studly(preg_replace('/[-_]/', ' ', $migrationInfo['migration_name']));
        $stubGenerator->addReplacement('{{CLASS}}', $className);

        // 添加命名空间和名称变量
        $namespace = config('modules.namespace', 'Modules');
        $stubGenerator->addReplacement('{{NAMESPACE}}', $namespace);
        $stubGenerator->addReplacement('{{NAME}}', $moduleName);
        $stubGenerator->addReplacement('{{LOWER_NAME}}', strtolower($moduleName));

        // 设置表名占位符，如果没有指定表名则使用默认值
        $tableName = $migrationInfo['table'] ?? strtolower($moduleName);
        $stubGenerator->addReplacement('{{TABLE}}', $tableName);

        // 获取对应的 stub 模板文件
        $stubPath = $this->getStubPath($migrationInfo['stub_type']);
        $stub = File::get($stubPath);

        // 执行变量替换
        foreach ($stubGenerator->getReplacements() as $search => $replace) {
            $stub = str_replace($search, $replace, $stub);
        }

        // 调试：检查是否有未替换的变量
        if (config('app.debug', false)) {
            $unreplaced = [];
            if (preg_match_all('/\{\{[\w]+\}\}/', $stub, $matches)) {
                $unreplaced = array_unique($matches[0]);
            }
            if (! empty($unreplaced)) {
                logger()->warning('迁移文件中存在未替换的变量', [
                    'migration' => $className,
                    'variables' => $unreplaced,
                    'stub_type' => $migrationInfo['stub_type'],
                ]);
            }
        }

        return $stub;
    }

    /**
     * 获取 stub 模板文件的完整路径
     *
     * 按照 Laravel 标准，只支持三个模板文件：
     * - create.stub: 用于创建新表
     * - update.stub: 用于修改现有表
     * - migration.stub: 空白模板，用于自定义迁移逻辑
     *
     * @param string $stubType stub 类型
     * @return string stub 模板文件的完整路径
     */
    protected function getStubPath(string $stubType): string
    {
        $stubsPath = __DIR__ . '/stubs/migration';

        $stubFiles = [
            'create' => 'create.stub',
            'update' => 'update.stub',
            'migration' => 'migration.stub',
        ];

        $stubFile = $stubFiles[$stubType] ?? 'migration.stub';

        return $stubsPath . DIRECTORY_SEPARATOR . $stubFile;
    }

    /**
     * 获取迁移文件的存储路径
     *
     * 根据命令选项确定迁移文件应该保存的路径
     *
     * @param mixed $module 模块实例
     * @return string 迁移文件保存的路径
     */
    protected function getMigrationPath($module): string
    {
        $path = $this->option('path');
        $realPath = $this->option('realpath');
        $fullPath = $this->option('fullpath');

        if ($fullPath) {
            return $path ?: $module->getPath('Database/Migrations');
        }

        if ($realPath) {
            return $path;
        }

        return $module->getPath('Database/Migrations');
    }
}
