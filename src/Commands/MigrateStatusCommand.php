<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Facades\Module;

class MigrateStatusCommand extends Command
{
    /**
     * 命令签名
     *
     * 定义命令的名称、参数和选项
     *
     * 参数：
     * - module: 可选参数，指定要查看的模块名称
     *
     * 选项：
     * - --path: 指定自定义的迁移文件路径
     * - --pending: 只显示尚未运行的迁移文件
     * - --ran: 只显示已运行的迁移文件
     * - --no-stats: 不显示迁移统计信息
     *
     * @var string
     */
    protected $signature = 'module:migrate-status
                            {module? : 模块名称（可选，不指定则显示所有模块）}
                            {--path= : 指定自定义迁移文件路径}
                            {--pending : 仅显示待运行的迁移（与 --ran 互斥）}
                            {--ran : 仅显示已运行的迁移（与 --pending 互斥）}
                            {--no-stats : 不显示统计信息}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '显示所有模块或指定模块的数据库迁移状态，包括迁移文件、批次号和运行状态';

    /**
     * 表头定义
     *
     * 定义输出表格的列名
     * - #: 序号
     * - 模块: 模块名称
     * - 迁移文件: 迁移文件名
     * - 批量: 迁移批次号
     * - 状态: 迁移状态（已运行/待运行）
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
     * 检查是否应该显示待运行的迁移
     *
     * @return bool
     */
    protected function shouldShowPending(): bool
    {
        return ! $this->option('ran');
    }

    /**
     * 检查是否应该显示已运行的迁移
     *
     * @return bool
     */
    protected function shouldShowRan(): bool
    {
        return ! $this->option('pending');
    }

    /**
     * 检查是否应该显示统计信息
     *
     * @return bool
     */
    protected function shouldShowStats(): bool
    {
        return ! $this->option('no-stats');
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
            $this->line("");
            $this->line("提示：使用 'php artisan module:list' 查看所有可用模块");

            return;
        }

        if (! $module->isEnabled()) {
            $this->warn("模块 [{$moduleName}] 未启用。");
            $this->line("");
            $this->line("提示：使用 'php artisan module:enable {$moduleName}' 启用该模块");

            return;
        }

        $migrationPath = $customPath ?: $module->getMigrationsPath();

        if (! is_dir($migrationPath)) {
            $this->warn("模块 [{$moduleName}] 没有迁移文件。");
            $this->line("");
            $this->line("迁移路径: {$migrationPath}");

            return;
        }

        // 预加载已运行的迁移，避免重复查询
        $ranMigrations = $this->getRanMigrationsWithBatches();
        $rows = $this->getMigrationStatus($module, $migrationPath, $ranMigrations);

        if (empty($rows)) {
            $this->warn("模块 [{$moduleName}] 没有迁移文件。");
            $this->line("");
            $this->line("迁移路径: {$migrationPath}");

            return;
        }

        // 为单个模块添加模块名称列，并根据过滤选项筛选
        $displayRows = [];
        $index = 1;
        $ranCount = 0;
        $pendingCount = 0;

        foreach ($rows as $row) {
            // 根据选项过滤
            $status = $row[2] ?? '';
            $isRan = $status === '<fg=green>已运行</>';
            if ($isRan && ! $this->shouldShowRan()) {
                continue;
            }
            if (! $isRan && ! $this->shouldShowPending()) {
                continue;
            }

            if ($isRan) {
                $ranCount++;
            } else {
                $pendingCount++;
            }

            $displayRows[] = [
                $index++,
                $module->getName(),
                $row[0] ?? '',  // 迁移文件名
                $row[1] ?? '-',  // 批次
                $status,         // 状态
            ];
        }

        if (empty($displayRows)) {
            $this->warn("没有符合条件的迁移文件。");
            return;
        }

        $this->table($this->headers, $displayRows);

        // 显示统计信息
        if ($this->shouldShowStats()) {
            $this->newLine();
            $this->info("模块 [{$moduleName}] 迁移统计:");
            $this->line("  总计: " . count($displayRows));
            $this->line("  <fg=green>已运行: {$ranCount}</>");
            $this->line("  <fg=red>待运行: {$pendingCount}</>");
        }
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

        // 预加载所有已运行的迁移，避免重复查询数据库
        $ranMigrations = $this->getRanMigrationsWithBatches();
        $allRows = [];
        $index = 1;
        $totalRan = 0;
        $totalPending = 0;
        $modulesWithMigrations = 0;

        foreach ($modules as $module) {
            $migrationPath = $customPath ?: $module->getMigrationsPath();

            if (! is_dir($migrationPath)) {
                continue;
            }

            $rows = $this->getMigrationStatus($module, $migrationPath, $ranMigrations);

            if (empty($rows)) {
                continue;
            }

            $modulesWithMigrations++;

            foreach ($rows as $row) {
                // 根据选项过滤
                $status = $row[2] ?? '';
                $isRan = $status === '<fg=green>已运行</>';
                if ($isRan && ! $this->shouldShowRan()) {
                    continue;
                }
                if (! $isRan && ! $this->shouldShowPending()) {
                    continue;
                }

                if ($isRan) {
                    $totalRan++;
                } else {
                    $totalPending++;
                }

                $allRows[] = [
                    $index++,
                    $module->getName(),
                    $row[0] ?? '',  // 迁移文件名
                    $row[1] ?? '-',  // 批次
                    $status,         // 状态
                ];
            }
        }

        if (empty($allRows)) {
            $this->warn('没有符合条件的迁移文件。');
            return;
        }

        $this->table($this->headers, $allRows);

        // 显示统计信息
        if ($this->shouldShowStats()) {
            $this->newLine();
            $this->info("迁移统计:");
            $this->line("  模块总数: {$modulesWithMigrations} / " . count($modules));
            $this->line("  迁移文件总数: " . count($allRows));
            $this->line("  <fg=green>已运行: {$totalRan}</>");
            $this->line("  <fg=red>待运行: {$totalPending}</>");
        }
    }

    /**
     * 获取迁移状态
     *
     * @param ModuleInterface $module 模块实例（保留用于扩展性和方法签名一致性）
     * @param string $migrationPath 迁移文件路径
     * @param array $ranMigrations 预加载的已运行迁移数据 ['migration_name' => ['batch' => 1]]
     * @return array
     */
    protected function getMigrationStatus(ModuleInterface $module, string $migrationPath, array $ranMigrations = []): array
    {
        // $module 参数保留用于方法签名一致性，当前实现中暂未使用
        unset($module);

        $files = glob($migrationPath . DIRECTORY_SEPARATOR . '*.php');

        if (empty($files)) {
            return [];
        }

        // 如果没有提供预加载的迁移数据，则加载
        if (empty($ranMigrations)) {
            $ranMigrations = $this->getRanMigrationsWithBatches();
        }

        $rows = [];

        foreach ($files as $file) {
            $migrationName = basename($file, '.php');
            $isRan = isset($ranMigrations[$migrationName]);

            $status = $isRan
                ? '<fg=green>已运行</>'
                : '<fg=red>待运行</>';

            $batch = $isRan
                ? (string) $ranMigrations[$migrationName]['batch']
                : '-';

            $rows[] = [
                $migrationName,
                $batch,
                $status,
            ];
        }

        return $rows;
    }

    /**
     * 获取已运行的迁移（仅名称列表）
     *
     * @return array
     */
    protected function getRanMigrations(): array
    {
        return array_keys($this->getRanMigrationsWithBatches());
    }

    /**
     * 获取已运行的迁移及批次信息
     *
     * 优化：一次性查询所有迁移的名称和批次，减少数据库查询次数
     * 返回格式: ['migration_name' => ['batch' => 1], ...]
     *
     * @return array
     */
    protected function getRanMigrationsWithBatches(): array
    {
        try {
            return DB::table('migrations')
                ->orderBy('batch', 'asc')
                ->orderBy('migration', 'asc')
                ->pluck('batch', 'migration')
                ->map(fn ($batch) => ['batch' => $batch])
                ->toArray();
        } catch (\Throwable $e) {
            // 如果数据库表不存在或其他错误，返回空数组
            logger()->error('查询迁移状态失败: ' . $e->getMessage());
            return [];
        }
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
