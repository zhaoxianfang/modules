<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Console\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Logging\Models\SystemLog;

/**
 * 清理过期数据库日志。
 *
 * 用法：
 *   php artisan logs:clean                 # 删除 30 天前的日志（默认保留天数）
 *   php artisan logs:clean --days=7       # 仅保留最近 7 天
 *   php artisan logs:clean --level=error  # 仅清理 error 等级
 *   php artisan logs:clean --dry-run      # 仅预览，不实际删除
 *
 * @package zxf\Modules\Logging
 */
class CleanLogsCommand extends Command
{
    protected $signature = 'logs:clean
        {--days=30 : 保留最近 N 天的日志，更早的将被删除}
        {--level= : 仅清理指定等级（如 error / warning）}
        {--dry-run : 仅预览将被删除的记录数，不实际删除}';

    protected $description = '清理 system_logs 表中的过期数据库日志';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $level = $this->option('level');
        $dryRun = (bool) $this->option('dry-run');

        $query = SystemLog::query()->where('created_at', '<', now()->subDays($days));
        if ($level !== null && $level !== '') {
            $query->where('level_name', strtoupper($level));
        }

        $count = $query->count();

        $this->info(sprintf(
            '将删除 %d 条早于 %d 天的日志%s%s',
            $count,
            $days,
            $level !== null && $level !== '' ? "（等级={$level}）" : '',
            $dryRun ? ' [dry-run]' : ''
        ));

        if ($dryRun || $count === 0) {
            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("清理完成，共删除 {$deleted} 条记录。");

        return self::SUCCESS;
    }
}
