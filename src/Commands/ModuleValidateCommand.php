<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\ModuleValidator;

class ModuleValidateCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:validate {name? : 模块名称（可选，不指定则验证所有模块）}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '验证模块的完整性和正确性';

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $moduleName = $this->argument('name');

        if ($moduleName) {
            return $this->validateModule($moduleName);
        }

        return $this->validateAllModules();
    }

    /**
     * 验证指定模块
     *
     * @param string $moduleName
     * @return int
     */
    protected function validateModule(string $moduleName): int
    {
        $module = Module::find(Str::studly($moduleName));

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在。");

            return Command::FAILURE;
        }

        $this->info("正在验证模块 [{$moduleName}]...");

        $validation = ModuleValidator::validate($module);

        if ($validation['valid']) {
            $this->info('<fg=green>✓ 模块验证通过！</>');

            if (! empty($validation['warnings'])) {
                $this->newLine();
                $this->warn('警告：');
                foreach ($validation['warnings'] as $warning) {
                    $this->line("  - {$warning}");
                }
            }

            return Command::SUCCESS;
        }

        $this->error('<fg=red>✗ 模块验证失败！</>');

        if (! empty($validation['errors'])) {
            $this->newLine();
            $this->error('错误：');
            foreach ($validation['errors'] as $error) {
                $this->line("  - {$error}");
            }
        }

        if (! empty($validation['warnings'])) {
            $this->newLine();
            $this->warn('警告：');
            foreach ($validation['warnings'] as $warning) {
                $this->line("  - {$warning}");
            }
        }

        return Command::FAILURE;
    }

    /**
     * 验证所有模块
     *
     * @return int
     */
    protected function validateAllModules(): int
    {
        $modules = Module::all();

        if (empty($modules)) {
            $this->warn('没有找到任何模块。');

            return Command::SUCCESS;
        }

        $this->info('正在验证所有模块...');

        $results = [];

        foreach ($modules as $module) {
            $validation = ModuleValidator::validate($module);

            $results[$module->getName()] = [
                'valid' => $validation['valid'],
                'errors' => count($validation['errors']),
                'warnings' => count($validation['warnings']),
            ];
        }

        $this->newLine();

        // 显示结果
        $rows = [];
        foreach ($results as $name => $result) {
            $status = $result['valid']
                ? '<fg=green>通过</>'
                : '<fg=red>失败</>';

            $rows[] = [
                $name,
                $status,
                $result['errors'] . ' 个错误',
                $result['warnings'] . ' 个警告',
            ];
        }

        $this->table(['模块名称', '验证状态', '错误', '警告'], $rows);

        $hasErrors = collect($results)->contains('valid', false);

        return $hasErrors ? Command::FAILURE : Command::SUCCESS;
    }
}
