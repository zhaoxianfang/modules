<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Facades\Module;

/**
 * 模块命令调试命令
 *
 * 用于检查模块命令的注册和发现情况
 */
class ModuleDebugCommandsCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:debug-commands
                            {--module= : 指定模块名称（不指定则检查所有模块）}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '调试模块命令的注册和发现情况';

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $moduleName = $this->option('module');

        if ($moduleName) {
            $this->debugModule($moduleName);
        } else {
            $this->debugAllModules();
        }

        return Command::SUCCESS;
    }

    /**
     * 调试所有模块
     *
     * @return void
     */
    protected function debugAllModules(): void
    {
        $modules = Module::all();

        $this->info("=== 模块命令调试 ===");
        $this->newLine();

        foreach ($modules as $module) {
            $this->debugModule($module->getName());
            $this->newLine();
        }
    }

    /**
     * 调试单个模块
     *
     * @param string $moduleName
     * @return void
     */
    protected function debugModule(string $moduleName): void
    {
        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在");
            return;
        }

        $this->info("模块: {$moduleName}");
        $this->line("状态: " . ($module->isEnabled() ? '<info>已启用</info>' : '<comment>已禁用</comment>'));
        $this->line("路径: {$module->getPath()}");
        $this->newLine();

        // 检查 Console/Commands 目录
        $commandsPath = $module->getPath('Console/Commands');
        $this->info("Console/Commands 目录:");
        if (is_dir($commandsPath)) {
            $this->line("  ✓ 存在 ({$commandsPath})");
            $commandFiles = glob($commandsPath . '/*.php');
            $this->line("  文件数: " . count($commandFiles));
            if (! empty($commandFiles)) {
                foreach ($commandFiles as $file) {
                    $className = basename($file, '.php');
                    $this->line("    - {$className}");
                }
            }
        } else {
            $this->line("  ✗ 不存在");
        }

        // 检查 Commands 目录
        $altCommandsPath = $module->getPath('Commands');
        $this->info("Commands 目录:");
        if (is_dir($altCommandsPath)) {
            $this->line("  ✓ 存在 ({$altCommandsPath})");
            $commandFiles = glob($altCommandsPath . '/*.php');
            $this->line("  文件数: " . count($commandFiles));
            if (! empty($commandFiles)) {
                foreach ($commandFiles as $file) {
                    $className = basename($file, '.php');
                    $this->line("    - {$className}");
                }
            }
        } else {
            $this->line("  ✗ 不存在");
        }

        $this->newLine();

        // 尝试发现并注册命令
        if ($module->isEnabled()) {
            $discovery = new \zxf\Modules\Support\ModuleAutoDiscovery($module);
            $discovery->discoverCommands();
            $cache = $discovery->getCache();
            $logs = $discovery->getLogs();

            $this->info("发现缓存:");
            if (isset($cache['commands'])) {
                $this->line("  命令数: " . count($cache['commands']));
                foreach ($cache['commands'] as $commandClass) {
                    $this->line("    - {$commandClass}");

                    // 检查命令签名
                    try {
                        if (class_exists($commandClass)) {
                            // 过滤掉 Laravel 内核类
                            if (in_array($commandClass, ['artisan', 'Illuminate\\Foundation\\Console\\Kernel'])) {
                                $this->line("      <comment>Laravel 内核类，跳过签名检查</comment>");
                                continue;
                            }

                            $reflection = new \ReflectionClass($commandClass);
                            if ($reflection->hasProperty('signature')) {
                                $signatureProperty = $reflection->getProperty('signature');
                                $signatureProperty->setAccessible(true);
                                $instance = app($commandClass);
                                $signature = $signatureProperty->getValue($instance);
                                $this->line("      签名: {$signature}");
                            }
                        }
                    } catch (\Throwable $e) {
                        $this->line("      <comment>无法获取签名: {$e->getMessage()}</comment>");
                    }
                }
            } else {
                $this->line("  未发现任何命令");
            }

            $this->newLine();
            $this->info("发现日志:");
            if (! empty($logs)) {
                foreach ($logs as $time => $message) {
                    $this->line("  [{$time}] {$message}");
                }
            } else {
                $this->line("  无日志");
            }
        }

        // 检查 Laravel Artisan 应用中的命令
        $this->newLine();
        $this->info("已注册到 Artisan 的命令:");
        try {
            $artisan = app('Illuminate\Contracts\Console\Kernel');
            $allCommands = $artisan->all();
            $moduleCommands = array_filter($allCommands, function ($command) use ($moduleName) {
                return str_contains($command->getName(), strtolower($moduleName));
            });
        } catch (\Throwable $e) {
            $this->line("  <comment>无法获取已注册命令: {$e->getMessage()}</comment>");
            $moduleCommands = [];
        }

        if (! empty($moduleCommands)) {
            foreach ($moduleCommands as $name => $command) {
                $description = $command->getDescription() ?? '无描述';
                $this->line("  ✓ {$name} - {$description}");
            }
        } else {
            $this->line("  ✗ 未找到已注册的 {$moduleName} 命令");
        }
    }
}
