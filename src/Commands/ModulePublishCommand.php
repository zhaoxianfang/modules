<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Commands\Concerns\EscapesConsoleOutput;
use zxf\Modules\Support\SafeFilesystem;

/**
 * 发布多模块资源命令
 * 发布模块系统的相关资源，包括：
 * - 多模块用户指南
 * - 配置文件
 * - 其他资源文件
 */
class ModulePublishCommand extends Command
{
    use EscapesConsoleOutput;

    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:publish
                            {--guide : 发布多模块用户指南到 Modules 目录}
                            {--config : 发布配置文件}
                            {--force : 强制覆盖已存在的文件}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '发布多模块系统资源（用户指南、配置文件等）';

    /**
     * 执行命令
     */
    public function handle(): int
    {
        $publishGuide = $this->option('guide');
        $publishConfig = $this->option('config');
        $force = $this->option('force');

        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('  多模块系统资源发布工具');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        // 如果没有指定任何选项，发布所有内容
        if (! $publishGuide && ! $publishConfig) {
            $this->line('未指定发布选项，将发布所有资源...');
            $publishGuide = true;
            $publishConfig = true;
            $this->newLine();
        }

        $success = true;

        // 发布用户指南
        if ($publishGuide) {
            // publishUserGuide() 为三态返回：
            // true=成功 / null=已存在跳过（幂等，非错误）/ false=真失败。
            $guideStatus = $this->publishUserGuide($force);

            match ($guideStatus) {
                true => $this->info('✓ 用户指南发布成功'),
                null => $this->line('· 用户指南已存在，已跳过（--force 可覆盖）'),
                default => $this->error('✗ 用户指南发布失败'),
            };

            if ($guideStatus === false) {
                $success = false;
            }
            $this->newLine();
        }

        // 发布配置文件
        if ($publishConfig) {
            $configStatus = $this->publishConfigFile($force);

            match ($configStatus) {
                true => $this->info('✓ 配置文件发布成功'),
                null => $this->line('· 配置文件已存在，已跳过（--force 可覆盖）'),
                default => $this->error('✗ 配置文件发布失败'),
            };

            if ($configStatus === false) {
                $success = false;
            }
            $this->newLine();
        }

        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        return $success ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * 发布多模块用户指南
     *
     * @param  bool  $force  是否强制覆盖
     * @return bool|null true=已发布；null=已存在而跳过（非失败）；false=真失败
     */
    protected function publishUserGuide(bool $force): ?bool
    {
        $modulesPath = config('modules.path', base_path('Modules'));
        $guidePath = $modulesPath.'/UserGuide.md';

        $this->line('<comment>📖 发布用户指南...</comment>');

        // 检查是否已存在
        //
        // 返回 null 表示「已存在而跳过」：这是幂等操作而非错误，
        // 由调用方输出中性提示，不计入失败退出码。
        if (file_exists($guidePath) && ! $force) {
            $this->line("用户指南已存在: {$guidePath}");

            return null;
        }

        // 确保目录存在
        if (! is_dir($modulesPath)) {
            SafeFilesystem::makeDirectory($modulesPath, 0755, true);
            $this->line("创建目录: {$modulesPath}");
        }

        // 读取 stub 文件
        $stubPath = __DIR__.'/stubs/modules-user-guide.stub';
        if (! file_exists($stubPath)) {
            $this->error("用户指南模板文件不存在: {$stubPath}");

            return false;
        }

        // 写入文件
        $content = file_get_contents($stubPath);
        $result = SafeFilesystem::put($guidePath, $content);

        if ($result) {
            $this->line("文件位置: {$guidePath}");

            return true;
        }

        $this->error('写入文件失败');

        return false;
    }

    /**
     * 发布配置文件
     *
     * @param  bool  $force  是否强制覆盖
     */
    protected function publishConfigFile(bool $force): ?bool
    {
        $configPath = config_path('modules.php');

        $this->line('<comment>⚙️  发布配置文件...</comment>');

        // 同 publishUserGuide()：已存在而跳过属于正常情况，
        // 返回 null 交由调用方输出中性提示，而不是判定为失败。
        if (file_exists($configPath) && ! $force) {
            $this->line("配置文件已存在: {$configPath}");

            return null;
        }

        // 读取包中的配置文件
        $packageConfigPath = dirname(dirname(__DIR__)).'/config/modules.php';

        if (! file_exists($packageConfigPath)) {
            $this->error("包配置文件不存在: {$packageConfigPath}");

            return false;
        }

        // 复制配置文件
        $result = SafeFilesystem::copy($packageConfigPath, $configPath);

        if ($result) {
            $this->line("文件位置: {$configPath}");

            return true;
        }

        $this->error('复制配置文件失败');

        return false;
    }
}
