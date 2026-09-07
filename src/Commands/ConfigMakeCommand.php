<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建配置文件命令
 *
 * 在指定模块中创建配置文件
 */
class ConfigMakeCommand extends AbstractMakeCommand
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:make-config
                            {module : 模块名称}
                            {name : 配置文件名称}
                            {--force : 覆盖已存在的配置文件}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '在指定模块中创建一个配置文件';

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $module = $this->resolveModule();
        if (! $module) {
            return Command::FAILURE;
        }

        $configName = strtolower($this->argument('name'));
        $force = $this->option('force');

        $generator = $this->makeStubGenerator();
        // 注意：不可覆盖 {{NAME}}——config.stub 中 {{NAME}} 全部代表模块名
        // （如 module_enabled('{{NAME}}')、'name' => '{{NAME}}'），覆盖成配置文件名
        // 会生成内容错误的模块配置。配置文件仅体现在目标路径 Config/{name}.php。
        $generator->addReplacement('{{MODULE_NAME}}', $module->getName());

        return $this->writeStub($generator, 'config.stub', 'Config/' . $configName . '.php', '配置文件', $force);
    }
}
