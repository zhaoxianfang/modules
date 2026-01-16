<?php

namespace zxf\Modules\Console\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Facades\Module;

class ModuleCheckCommand extends Command
{
    /**
     * 控制台命令的名称和签名。
     *
     * @var string
     */
    protected $signature = 'module:check {name? : 模块名称（可选）}';

    /**
     * 控制台命令的描述。
     *
     * @var string
     */
    protected $description = '检查模块健康状态和依赖关系';

    /**
     * 执行控制台命令。
     */
    public function handle(): int
    {
        $name = $this->argument('name');

        if ($name) {
            return $this->checkSingleModule($name);
        }

        return $this->checkAllModules();
    }

    /**
     * 检查单个模块。
     */
    protected function checkSingleModule(string $name): int
    {
        if (!Module::exists($name)) {
            $this->error("模块 [{$name}] 未找到。");
            return Command::FAILURE;
        }

        $module = Module::find($name);
        $this->info("Checking module: {$name}");

        $results = $this->runChecks($module);

        return $this->displayResults($results) ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * 检查所有模块。
     */
    protected function checkAllModules(): int
    {
        $modules = Module::all();
        
        if (empty($modules)) {
            $this->info('No modules found.');
            return Command::SUCCESS;
        }

        $overallSuccess = true;

        foreach ($modules as $name => $module) {
            $this->info("\nChecking module: {$name}");
            $results = $this->runChecks($module);
            
            if (!$this->displayResults($results)) {
                $overallSuccess = false;
            }
        }

        return $overallSuccess ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * 对模块运行健康检查。
     *
     * @return array<string, array{status: string, message: string}>
     */
    protected function runChecks($module): array
    {
        $checks = [];

        // 检查 1：模块类是否存在
        $checks['class_exists'] = [
            'status' => 'success',
            'message' => '模块类存在。',
        ];

        // 检查 2：依赖关系
        $dependencies = $module->getDependencies();
        if (empty($dependencies)) {
            $checks['dependencies'] = [
                'status' => 'success',
                'message' => '没有依赖项。',
            ];
        } else {
            $missing = [];
            $disabled = [];
            
            foreach ($dependencies as $dependency) {
                if (!Module::exists($dependency)) {
                    $missing[] = $dependency;
                } elseif (!Module::find($dependency)->isEnabled()) {
                    $disabled[] = $dependency;
                }
            }

            if (empty($missing) && empty($disabled)) {
                $checks['dependencies'] = [
                    'status' => 'success',
                    'message' => sprintf('所有依赖项已满足 (%s)。', implode(', ', $dependencies)),
                ];
            } else {
                $messages = [];
                if (!empty($missing)) {
                    $messages[] = 'Missing: ' . implode(', ', $missing);
                }
                if (!empty($disabled)) {
                    $messages[] = 'Disabled: ' . implode(', ', $disabled);
                }
                
                $checks['dependencies'] = [
                    'status' => 'error',
                    'message' => '依赖项问题：' . implode('; ', $messages),
                ];
            }
        }

        // 检查 3：PHP 版本
        $phpVersion = $module->requiresPhp('8.2') ? '8.2+' : 'unknown';
        $checks['php_version'] = [
            'status' => 'success',
            'message' => sprintf('PHP 版本兼容 (%s)。', $phpVersion),
        ];

        // 检查 4：Laravel 版本
        $laravelVersion = $module->requiresLaravel('11.0') ? '11.0+' : 'unknown';
        $checks['laravel_version'] = [
            'status' => 'success',
            'message' => sprintf('Laravel 版本兼容 (%s)。', $laravelVersion),
        ];

        // 检查 5：模块启用状态
        if ($module->isEnabled()) {
            $checks['enabled'] = [
                'status' => 'success',
                'message' => '模块已启用。',
            ];
        } else {
            $checks['enabled'] = [
                'status' => 'warning',
                'message' => '模块已禁用。',
            ];
        }

        // 检查 6：服务提供者是否存在
        $providers = $module->getProviders();
        $invalidProviders = [];
        
        foreach ($providers as $provider) {
            if (!class_exists($provider)) {
                $invalidProviders[] = $provider;
            }
        }

        if (empty($invalidProviders)) {
            $checks['providers'] = [
                'status' => 'success',
                'message' => sprintf('所有提供者有效 (%s)。', count($providers)),
            ];
        } else {
            $checks['providers'] = [
                'status' => 'error',
                'message' => sprintf('无效的提供者：%s。', implode(', ', $invalidProviders)),
            ];
        }

        return $checks;
    }

    /**
     * 显示检查结果。
     *
     * @param array<string, array{status: string, message: string}> $results
     */
    protected function displayResults(array $results): bool
    {
        $allSuccess = true;

        foreach ($results as $result) {
            $status = $result['status'];
            $message = $result['message'];

            $icon = match ($status) {
                'success' => '✓',
                'warning' => '⚠',
                'error' => '✗',
                default => '?',
            };

            $color = match ($status) {
                'success' => 'green',
                'warning' => 'yellow',
                'error' => 'red',
                default => 'white',
            };

            $this->line(sprintf(
                '  %s <fg=%s>%s</>',
                $icon,
                $color,
                $message
            ));

            if ($status === 'error') {
                $allSuccess = false;
            }
        }

        return $allSuccess;
    }
}