<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建测试命令
 *
 * 在指定模块中创建 PHPUnit 测试类。
 * 支持单元测试和功能测试两种类型。
 *
 * 使用示例：
 *   php artisan module:make-test Blog PostTest
 *   php artisan module:make-test Blog PostFeatureTest --feature
 *
 * @package zxf\Modules\Commands
 */
class TestMakeCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'module:make-test
                            {module : 模块名称（必需，例如：Blog）}
                            {name : 测试名称（必需，例如：PostTest）}
                            {--feature : 创建功能测试（默认单元测试）}
                            {--force : 覆盖已存在的文件}';

    /**
     * @var string
     */
    protected $description = '在指定模块中创建测试类';

    /**
     * 执行命令
     */
    public function handle(): int
    {
        $moduleName = Str::studly($this->argument('module'));
        $testName = Str::studly($this->argument('name'));
        $force = $this->option('force');
        $isFeature = $this->option('feature');

        // 确保测试名以 Test 结尾
        if (! str_ends_with($testName, 'Test')) {
            $testName .= 'Test';
        }

        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在");
            return Command::FAILURE;
        }

        $testPath = $module->getPath('Tests/' . $testName . '.php');

        if (File::exists($testPath) && ! $force) {
            $this->error("模块 [{$moduleName}] 中已存在测试 [{$testName}]");
            $this->line('提示：使用 --force 选项覆盖已存在的测试');
            return Command::FAILURE;
        }

        if (File::exists($testPath) && $force) {
            $this->warn("正在覆盖模块 [{$moduleName}] 中已存在的测试 [{$testName}]");
        }

        $namespace = config('modules.namespace', 'Modules');

        $stubGenerator = new StubGenerator($moduleName);
        $stubGenerator->addReplacements([
            '{{CLASS}}' => $testName,
            '{{NAMESPACE}}' => $namespace,
            '{{NAME}}' => $moduleName,
        ]);

        // 确保目录存在
        $testDir = $module->getPath('Tests');
        if (! is_dir($testDir)) {
            File::makeDirectory($testDir, 0755, true);
        }

        $result = $stubGenerator->generate(
            'test.stub',
            'Tests/' . $testName . '.php',
            $force
        );

        if ($result) {
            $testType = $isFeature ? '功能测试' : '单元测试';
            $this->info("✓ 成功在模块 [{$moduleName}] 中创建{$testType} [{$testName}]");
            return Command::SUCCESS;
        }

        $this->error("创建测试 [{$testName}] 失败");
        return Command::FAILURE;
    }
}
