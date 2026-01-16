<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use zxf\Modules\Facades\Module;

class MigrateStatusCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:migrate-status
                            {module? : 模块名称（可选）}
                            {--path= : 指定迁移路径}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '显示所有模块的迁移状态';

    /**
     * 表头
     *
     * @var array
     */
    protected array $headers = ['#', '模块', '迁移文件', '批量', '状态'];

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $moduleName = $this->argument('module');
        $customPath = $this->option('path');

        if ($moduleName) {
            $this->showModuleStatus($moduleName, $customPath);
        } else {
            $this->showAllModulesStatus($customPath);
        }

        return Command::SUCCESS;
    }

    /**
     * 显示指定模块的迁移状态
     *
     * @param string $moduleName
     * @param string|null $customPath
     * @return void
     */
    protected function showModuleStatus(string $moduleName, ?string $customPath = null): void
    {
        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在。");

            return;
        }

        if (! $module->isEnabled()) {
            $this->warn("模块 [{$moduleName}] 未启用。");

            return;
        }

        $migrationPath = $customPath ?: $module->getMigrationsPath();

        if (! is_dir($migrationPath)) {
            $this->warn("模块 [{$moduleName}] 没有迁移文件。");

            return;
        }

        $rows = $this->getMigrationStatus($module, $migrationPath);

        if (empty($rows)) {
            $this->warn("模块 [{$moduleName}] 没有迁移文件。");

            return;
        }

        $this->table($this->headers, $rows);
    }

    /**
     * 显示所有模块的迁移状态
     *
     * @param string|null $customPath
     * @return void
     */
    protected function showAllModulesStatus(?string $customPath = null): void
    {
        $modules = Module::allEnabled();

        if (empty($modules)) {
            $this->warn('没有已启用的模块。');

            return;
        }

        $allRows = [];
        $index = 1;

        foreach ($modules as $module) {
            $migrationPath = $customPath ?: $module->getMigrationsPath();

            if (! is_dir($migrationPath)) {
                continue;
            }

            $rows = $this->getMigrationStatus($module, $migrationPath);

            foreach ($rows as $row) {
                array_unshift($row, $module->getName());
                $row[0] = $index++;
                $allRows[] = $row;
            }
        }

        if (empty($allRows)) {
            $this->warn('没有迁移文件。');

            return;
        }

        $this->table($this->headers, $allRows);
    }

    /**
     * 获取迁移状态
     *
     * @param mixed $module
     * @param string $migrationPath
     * @return array
     */
    protected function getMigrationStatus($module, string $migrationPath): array
    {
        $files = glob($migrationPath . DIRECTORY_SEPARATOR . '*.php');

        if (empty($files)) {
            return [];
        }

        $ran = $this->getRanMigrations();
        $rows = [];
        $index = 1;

        foreach ($files as $file) {
            $migrationName = basename($file, '.php');
            $status = in_array($migrationName, $ran)
                ? '<fg=green>已运行</>'
                : '<fg=red>待运行</>';

            $batch = in_array($migrationName, $ran)
                ? $this->getMigrationBatch($migrationName)
                : '-';

            $rows[] = [
                $index++,
                $migrationName,
                $batch,
                $status,
            ];
        }

        return $rows;
    }

    /**
     * 获取已运行的迁移
     *
     * @return array
     */
    protected function getRanMigrations(): array
    {
        return DB::table('migrations')
            ->orderBy('batch', 'asc')
            ->orderBy('migration', 'asc')
            ->pluck('migration')
            ->toArray();
    }

    /**
     * 获取迁移批次
     *
     * @param string $migration
     * @return string
     */
    protected function getMigrationBatch(string $migration): string
    {
        $record = DB::table('migrations')
            ->where('migration', $migration)
            ->first();

        return $record ? (string) $record->batch : '-';
    }
}
