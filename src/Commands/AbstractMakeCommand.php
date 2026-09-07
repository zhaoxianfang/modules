<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\StubGenerator;

/**
 * 生成器命令抽象基类
 *
 * 统一所有 module:make-* 生成器命令的通用流程：
 *  - 解析并校验模块（module 参数 → studly → Module::find）
 *  - 基于已解析模块构造 StubGenerator
 *  - 计算目标文件相对路径并执行存在性校验（支持 --force 覆盖）
 *  - 渲染 stub 并写入模块目录
 *  - 统一的成功 / 失败 / 已存在信息输出
 *
 * 子类只需声明 $signature / $description，并实现 {@see handle()}，
 * 在 handle() 中调用 {@see resolveModule()}、{@see makeStubGenerator()}、
 * {@see writeStub()} 即可，无需重复样板代码。
 *
 * @package zxf\Modules\Commands
 */
abstract class AbstractMakeCommand extends Command
{
    /**
     * 当前已解析的模块实例（resolveModule 后可用）
     */
    protected ?ModuleInterface $module = null;

    /**
     * 解析并校验模块
     *
     * 将 module 参数转为 StudlyCase 并查找模块实例；
     * 若模块不存在，输出错误并返回 null（子类应直接 return）。
     *
     * @param string $argument 模块参数名，默认 'module'
     */
    protected function resolveModule(string $argument = 'module'): ?ModuleInterface
    {
        $moduleName = \Illuminate\Support\Str::studly($this->argument($argument));

        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在");

            return null;
        }

        $this->module = $module;

        return $module;
    }

    /**
     * 基于当前已解析模块构造 StubGenerator
     *
     * @throws \RuntimeException 若尚未调用 resolveModule 或模块解析失败
     */
    protected function makeStubGenerator(): StubGenerator
    {
        if (! $this->module) {
            throw new \RuntimeException('必须先调用 resolveModule() 解析模块');
        }

        return new StubGenerator($this->module->getName());
    }

    /**
     * 计算目标文件的绝对路径
     *
     * @param string $relativePath 相对于模块根目录的路径（如 Http/Controllers/Foo.php）
     */
    protected function targetPath(string $relativePath): string
    {
        if (! $this->module) {
            throw new \RuntimeException('必须先调用 resolveModule() 解析模块');
        }

        return $this->module->getPath($relativePath);
    }

    /**
     * 渲染 stub 并写入模块目录
     *
     * 统一处理：
     *  - 文件已存在且未指定 --force → 输出提示并返回 FAILURE
     *  - 文件已存在且指定 --force → 输出覆盖警告
     *  - 调用 StubGenerator::generate() 写入
     *  - 成功 / 失败信息输出
     *
     * @param StubGenerator $generator  已配置替换变量的 StubGenerator 实例
     * @param string        $stub        stub 文件名（相对于 stubs 目录）
     * @param string        $relativePath 目标相对路径（相对于模块根目录）
     * @param string        $entityLabel 实体中文标签（用于输出信息，如 “控制器”）
     * @param bool          $force       是否覆盖已存在文件
     * @return int Command::SUCCESS / Command::FAILURE
     */
    protected function writeStub(
        StubGenerator $generator,
        string $stub,
        string $relativePath,
        string $entityLabel,
        bool $force
    ): int {
        $absolutePath = $this->targetPath($relativePath);
        $moduleName = $this->module->getName();
        $entityName = basename($relativePath, '.php');

        if (file_exists($absolutePath) && ! $force) {
            $this->error("模块 [{$moduleName}] 中已存在{$entityLabel} [{$entityName}]");
            $this->line("提示：使用 --force 选项覆盖已存在的{$entityLabel}");

            return Command::FAILURE;
        }

        if (file_exists($absolutePath) && $force) {
            $this->warn("正在覆盖模块 [{$moduleName}] 中已存在的{$entityLabel} [{$entityName}]");
        }

        $result = $generator->generate($stub, $relativePath, $force);

        if ($result) {
            $this->info("成功在模块 [{$moduleName}] 中创建{$entityLabel} [{$entityName}]");

            return Command::SUCCESS;
        }

        $this->error("创建{$entityLabel} [{$entityName}] 失败");

        return Command::FAILURE;
    }

    /**
     * 便捷方法：从已配置 generator 生成文件（目录自动创建）
     *
     * 与 {@see writeStub()} 相同，但允许在生成前由调用方决定路径，
     * 适用于需要额外动态计算目录的命令。
     */
    protected function generateFile(StubGenerator $generator, string $stub, string $relativePath, bool $force): bool
    {
        return $generator->generate($stub, $relativePath, $force) !== false;
    }
}
