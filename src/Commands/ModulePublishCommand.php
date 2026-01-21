<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * 发布多模块资源命令
 *
 * 发布模块系统的相关资源，包括：
 * - 多模块用户指南
 * - 配置文件
 * - 其他资源文件
 */
class ModulePublishCommand extends Command
{
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
     *
     * @return int
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
            if ($this->publishUserGuide($force)) {
                $this->info('✓ 用户指南发布成功');
            } else {
                $this->error('✗ 用户指南发布失败');
                $success = false;
            }
            $this->newLine();
        }

        // 发布配置文件
        if ($publishConfig) {
            if ($this->publishConfigFile($force)) {
                $this->info('✓ 配置文件发布成功');
            } else {
                $this->error('✗ 配置文件发布失败');
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
     * @param bool $force 是否强制覆盖
     * @return bool
     */
    protected function publishUserGuide(bool $force): bool
    {
        $modulesPath = config('modules.path', base_path('Modules'));
        $guidePath = $modulesPath . '/ModulesUserGuide.md';

        $this->line('<comment>📖 发布用户指南...</comment>');

        // 检查是否已存在
        if (file_exists($guidePath) && ! $force) {
            $this->warn("用户指南已存在: {$guidePath}");
            $this->line("提示：使用 --force 选项覆盖已存在的文件");
            return false;
        }

        // 确保目录存在
        if (! is_dir($modulesPath)) {
            File::makeDirectory($modulesPath, 0755, true);
            $this->line("创建目录: {$modulesPath}");
        }

        // 读取 stub 文件
        $stubPath = __DIR__ . '/stubs/modules-user-guide.stub';
        if (! file_exists($stubPath)) {
            $this->error("用户指南模板文件不存在: {$stubPath}");
            return false;
        }

        // 写入文件
        $content = file_get_contents($stubPath);
        $result = File::put($guidePath, $content);

        if ($result) {
            $this->line("文件位置: {$guidePath}");
            return true;
        }

        $this->error("写入文件失败");
        return false;
    }

    /**
     * 发布配置文件
     *
     * @param bool $force 是否强制覆盖
     * @return bool
     */
    protected function publishConfigFile(bool $force): bool
    {
        $configPath = config_path('modules.php');

        $this->line('<comment>⚙️  发布配置文件...</comment>');

        // 检查是否已存在
        if (file_exists($configPath) && ! $force) {
            $this->warn("配置文件已存在: {$configPath}");
            $this->line("提示：使用 --force 选项覆盖已存在的文件");
            return false;
        }

        // 读取包中的配置文件
        $packageConfigPath = dirname(dirname(__DIR__)) . '/config/modules.php';

        if (! file_exists($packageConfigPath)) {
            $this->error("包配置文件不存在: {$packageConfigPath}");
            return false;
        }

        // 复制配置文件
        $result = File::copy($packageConfigPath, $configPath);

        if ($result) {
            $this->line("文件位置: {$configPath}");
            return true;
        }

        $this->error("复制配置文件失败");
        return false;
    }
}
