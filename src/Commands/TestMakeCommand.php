<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
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
class TestMakeCommand extends AbstractMakeCommand
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
        $module = $this->resolveModule();
        if (! $module) {
            return Command::FAILURE;
        }

        $testName = Str::studly($this->argument('name'));
        $force = $this->option('force');
        $isFeature = $this->option('feature');

        // 确保测试名以 Test 结尾
        if (! str_ends_with($testName, 'Test')) {
            $testName .= 'Test';
        }

        $generator = $this->makeStubGenerator();
        $generator->addReplacements([
            '{{CLASS}}' => $testName,
            '{{NAMESPACE}}' => $this->module->getNamespace(),
            '{{NAME}}' => $module->getName(),
        ]);

        $result = $this->writeStub($generator, 'test.stub', 'Tests/' . $testName . '.php', '测试', $force);

        if ($result === Command::SUCCESS) {
            $testType = $isFeature ? '功能测试' : '单元测试';
            $this->info("✓ 成功在模块 [{$module->getName()}] 中创建{$testType} [{$testName}]");
        }

        return $result;
    }
}
