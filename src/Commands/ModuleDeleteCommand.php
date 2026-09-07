<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;

class ModuleDeleteCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:delete
                            {name : 模块名称（例如：Blog）}
                            {--force : 强制删除，不提示确认，谨慎使用！}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '删除一个模块（包括所有文件和数据）';

    /**
     * 执行命令
     *
     * 删除模块前会进行确认（除非使用 --force）。
     * 注意：删除操作不可恢复，请谨慎操作。
     *
     * 健壮性说明：
     * - 先以磁盘真实状态为准重新扫描（绕过可能过期的模块缓存），
     *   避免“模块明明存在却提示不存在”的问题；
     * - 若模块未被注册表识别（如配置损坏、手动放置的目录、部署新增），
     *   只要磁盘上确实存在该模块目录，仍会直接删除。
     *
     * @return int
     */
    public function handle(): int
    {
        $name = Str::studly($this->argument('name'));
        $force = $this->option('force');

        // 以磁盘真实状态为准：绕过可能过期的模块缓存重新扫描，
        // 确保与 module:list 等命令看到一致的结果。
        try {
            Module::rescan();
        } catch (\Throwable) {
            // 扫描失败不应阻断删除流程，后续会回退到直接检查磁盘
        }

        $module = Module::find($name);
        $fromRegistry = $module !== null;
        $modulePath = $module?->getPath();

        // 兜底：即使模块未被注册表识别，只要磁盘上确实存在该模块目录也允许删除。
        if ($modulePath === null) {
            $modulePath = $this->resolveModulePathOnDisk($name);
        }

        if ($modulePath === null) {
            $this->error("模块 [{$name}] 不存在");
            $this->line("提示：使用 php artisan module:list 查看所有可用模块");

            return Command::FAILURE;
        }

        // 安全性校验：删除是递归且不可恢复的操作，必须确保目标路径确实位于
        // 受信任的模块目录（modules.path 及其 scan_paths）之下，防止路径穿越
        // （如传入含 ../ 的模块名）误删系统文件。
        if (! $this->isWithinTrustedPaths($modulePath)) {
            $this->error("模块路径 [{$modulePath}] 不在受信任的模块目录内，已拒绝删除以防止误删。");
            $this->line("受信任目录: " . implode(', ', $this->trustedPaths()));

            return Command::FAILURE;
        }

        $this->warn("⚠️  警告：此操作将永久删除模块 [{$name}]");
        $this->line("模块路径: {$modulePath}");

        if (! $fromRegistry) {
            $this->warn("该模块未被模块注册表识别，将直接按磁盘目录删除。");
        }

        $this->line("");

        if (! $force) {
            if (! $this->confirm("确定要删除模块 [{$name}] 及其所有文件吗？此操作不可恢复！", false)) {
                $this->info('操作已取消');

                return Command::SUCCESS;
            }
        }

        // 删除模块目录
        if (! File::deleteDirectory($modulePath)) {
            $this->error("删除模块 [{$name}] 失败");
            $this->line("提示：检查文件权限");

            return Command::FAILURE;
        }

        // 删除后清理模块缓存，确保后续 module:list 等命令重新扫描磁盘
        try {
            Module::clearRepositoryCache();
        } catch (\Throwable) {
            // 忽略缓存清理失败
        }

        $this->info("✓ 模块 [{$name}] 已成功删除");
        $this->line("删除的路径: {$modulePath}");
        $this->line("");
        $this->line("提示：如果该模块有数据库迁移，请手动执行数据库回滚（php artisan module:migrate:rollback {$name}）");

        return Command::SUCCESS;
    }

    /**
     * 在磁盘上查找模块目录（兜底方案）
     *
     * 在 modules.path 及其 scan_paths 下，按模块名的多种写法
     * （StudlyCase / Snake_Case / 小写）查找真实存在的目录。
     */
    protected function resolveModulePathOnDisk(string $name): ?string
    {
        $candidates = array_values(array_unique([
            $name,
            Str::snake($name),
            strtolower($name),
        ]));

        $paths = array_merge(
            [config('modules.path', base_path('Modules'))],
            (array) config('modules.scan_paths', [])
        );

        foreach ($paths as $base) {
            if (! is_string($base) || $base === '') {
                continue;
            }

            $base = rtrim($base, '/\\');

            foreach ($candidates as $candidate) {
                if ($candidate === '') {
                    continue;
                }

                $dir = $base . DIRECTORY_SEPARATOR . $candidate;

                if (is_dir($dir)) {
                    return $dir;
                }
            }
        }

        return null;
    }

    /**
     * 获取所有受信任的模块根目录（已规范化为绝对路径）
     *
     * @return array<int, string>
     */
    protected function trustedPaths(): array
    {
        $paths = array_merge(
            [config('modules.path', base_path('Modules'))],
            (array) config('modules.scan_paths', [])
        );

        $result = [];
        foreach ($paths as $base) {
            if (! is_string($base) || $base === '') {
                continue;
            }

            $real = realpath($base);
            if ($real !== false) {
                $result[] = $real;
            } elseif (is_dir($base)) {
                $result[] = rtrim($base, '/\\');
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * 判断给定路径是否位于受信任的模块目录之内（防止路径穿越）
     */
    protected function isWithinTrustedPaths(string $path): bool
    {
        $real = realpath($path);
        if ($real === false) {
            return false;
        }

        foreach ($this->trustedPaths() as $trusted) {
            if ($real === $trusted || str_starts_with($real . DIRECTORY_SEPARATOR, $trusted . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }
}
