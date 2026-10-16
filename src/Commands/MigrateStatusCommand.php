<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use zxf\Modules\Commands\Concerns\EscapesConsoleOutput;
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Facades\Module;

class MigrateStatusCommand extends Command
{
    use EscapesConsoleOutput;

    /**
     * 命令签名
     * 定义命令的名称、参数和选项
     * 参数：
     * - module: 可选参数，指定要查看的模块名称
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
     * 定义输出表格的列名
     * - #: 序号
     * - 模块: 模块名称
     * - 迁移文件: 迁移文件名
     * - 批量: 迁移批次号
     * - 状态: 迁移状态（已运行/待运行）
     */
    protected array $headers = ['#', '模块', '迁移文件', '批量', '状态'];

    /**
     * 执行命令
     */
    public function handle(): int
    {
        $moduleName = $this->argument('module');
        $customPath = $this->option('path');

        if ($moduleName) {
            return $this->showModuleStatus($moduleName, $customPath)
                ? Command::SUCCESS
                : Command::FAILURE;
        }

        return $this->showAllModulesStatus($customPath)
            ? Command::SUCCESS
            : Command::FAILURE;
    }

    /**
     * 检查是否应该显示待运行的迁移
     */
    protected function shouldShowPending(): bool
    {
        return ! $this->option('ran');
    }

    /**
     * 检查是否应该显示已运行的迁移
     */
    protected function shouldShowRan(): bool
    {
        return ! $this->option('pending');
    }

    /**
     * 检查是否应该显示统计信息
     */
    protected function shouldShowStats(): bool
    {
        return ! $this->option('no-stats');
    }

    /**
     * 显示指定模块的迁移状态
     *
     * 返回 false 表示存在失败场景（模块不存在、无迁移文件等），
     * 由 handle() 转为非零退出码，供 CI / 脚本判断。
     */
    protected function showModuleStatus(string $moduleName, ?string $customPath = null): bool
    {
        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$this->esc($moduleName)}] 不存在。");
            $this->line('');
            $this->line("提示：使用 'php artisan module:list' 查看所有可用模块");

            return false;
        }

        if (! $module->isEnabled()) {
            $this->warn("模块 [{$this->esc($moduleName)}] 未启用。");
            $this->line('');
            $this->line("提示：使用 'php artisan module:enable {$this->esc($moduleName)}' 启用该模块");

            return false;
        }

        $migrationPath = $customPath ?: $module->getMigrationsPath();

        if (! is_dir($migrationPath)) {
            $this->warn("模块 [{$this->esc($moduleName)}] 没有迁移文件。");
            $this->line('');
            $this->line("迁移路径: {$migrationPath}");

            return false;
        }

        // 预加载已运行的迁移，避免重复查询
        $ranMigrations = $this->getRanMigrationsWithBatches();
        $rows = $this->getMigrationStatus($module, $migrationPath, $ranMigrations);

        if (empty($rows)) {
            $this->warn("模块 [{$this->esc($moduleName)}] 没有迁移文件。");
            $this->line('');
            $this->line("迁移路径: {$migrationPath}");

            return false;
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
            $this->warn('没有符合条件的迁移文件。');

            return true;
        }

        $this->table($this->headers, $displayRows);

        // 显示统计信息
        if ($this->shouldShowStats()) {
            $this->newLine();
            $this->info("模块 [{$this->esc($moduleName)}] 迁移统计:");
            $this->line('  总计: '.count($displayRows));
            $this->line("  <fg=green>已运行: {$ranCount}</>");
            $this->line("  <fg=red>待运行: {$pendingCount}</>");
        }

        return true;
    }

    /**
     * 显示所有模块的迁移状态
     */
    protected function showAllModulesStatus(?string $customPath = null): bool
    {
        $modules = Module::allEnabled();

        if (empty($modules)) {
            $this->warn('没有已启用的模块。');

            return false;
        }

        // 预加载所有已运行的迁移，避免重复查询数据库
        $ranMigrations = $this->getRanMigrationsWithBatches();
        $allRows = [];
        $index = 1;
        $totalRan = 0;
        $totalPending = 0;
        $modulesWithMigrations = 0;

        // 多模块模式下 --path 表示「统一的自定义迁移目录」，对所有模块而言
        // 指向同一路径，只应查询一次（否则会重复输出相同内容）。
        //
        // 原判断写作 `if ($customPath !== null && $index > 1) break;`，
        // 用「行序号」充当「是否已处理过」的哨兵。但 $index 只在真正产出
        // 表格行时才自增 —— 若首个模块恰好没有迁移文件（rows 为空会 continue），
        // $index 仍是 1，哨兵失效，循环会把同一个 --path 目录
        // 按模块数量重复输出一遍。
        $customPathConsumed = false;

        foreach ($modules as $module) {
            if ($customPath !== null && $customPathConsumed) {
                break;
            }

            $migrationPath = $customPath ?: $module->getMigrationsPath();

            if (! is_dir($migrationPath)) {
                // 同理：--path 指向的目录不存在时也视为已消费，
                // 否则后续模块会再次尝试扫描同一个不存在的目录。
                $customPathConsumed = $customPathConsumed || $customPath !== null;

                continue;
            }

            $rows = $this->getMigrationStatus($module, $migrationPath, $ranMigrations);

            if (empty($rows)) {
                // 即使本模块没有可显示的行，也要标记 --path 已被消费，
                // 否则后续模块会重复扫描并输出同一目录（见上方哨兵说明）。
                $customPathConsumed = $customPathConsumed || $customPath !== null;

                continue;
            }

            $customPathConsumed = $customPathConsumed || $customPath !== null;

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

            return true;
        }

        $this->table($this->headers, $allRows);

        // 显示统计信息
        if ($this->shouldShowStats()) {
            $this->newLine();
            $this->info('迁移统计:');
            $this->line("  模块总数: {$modulesWithMigrations} / ".count($modules));
            $this->line('  迁移文件总数: '.count($allRows));
            $this->line("  <fg=green>已运行: {$totalRan}</>");
            $this->line("  <fg=red>待运行: {$totalPending}</>");
        }
    }

    /**
     * 获取迁移状态
     *
     * @param  ModuleInterface  $module  模块实例（保留用于扩展性和方法签名一致性）
     * @param  string  $migrationPath  迁移文件路径
     * @param  array  $ranMigrations  预加载的已运行迁移数据 ['migration_name' => ['batch' => 1]]
     */
    protected function getMigrationStatus(ModuleInterface $module, string $migrationPath, array $ranMigrations = []): array
    {
        // 注：$module 参数保留以维持方法签名一致性，当前实现按迁移路径查询，
        // 模块归属由调用方在 showAllModulesStatus 中拼装，此处不依赖 $module。

        $files = glob($migrationPath.DIRECTORY_SEPARATOR.'*.php');

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
     * 获取已运行的迁移及批次信息
     * 优化：一次性查询所有迁移的名称和批次，减少数据库查询次数
     * 返回格式: ['migration_name' => ['batch' => 1], ...]
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
            logger()->error('查询迁移状态失败: '.$e->getMessage());

            return [];
        }
    }
}
