<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use zxf\Modules\Commands\Concerns\EscapesConsoleOutput;
use zxf\Modules\Contracts\ModuleInterface;
use zxf\Modules\Facades\Module;
use zxf\Modules\Support\PathGuard;
use zxf\Modules\Support\StubGenerator;

/**
 * 生成器命令抽象基类
 * 统一所有 module:make-* 生成器命令的通用流程：
 *  - 解析并校验模块（module 参数 → studly → Module::find）
 *  - 基于已解析模块构造 StubGenerator
 *  - 计算目标文件相对路径并执行存在性校验（支持 --force 覆盖）
 *  - 渲染 stub 并写入模块目录
 *  - 统一的成功 / 失败 / 已存在信息输出
 * 子类只需声明 $signature / $description，并实现 {@see handle()}，
 * 在 handle() 中调用 {@see resolveModule()}、{@see makeStubGenerator()}、
 * {@see writeStub()} 即可，无需重复样板代码。
 */
abstract class AbstractMakeCommand extends Command
{
    use EscapesConsoleOutput;
    use PathGuard;

    /**
     * 命令入口：先统一校验实体名，再交给子类的 handle()
     *
     * 【为什么要放在基类而不是各子类】
     * 19 个 make-* 命令都把用户输入的 name 拼进「目标相对路径」，
     * 只要出现 '/' 或 '..' 就能写到模块目录之外，甚至生成非法 PHP
     * （如 class Foo/Bar）导致 PSR-4 永久无法加载。
     * 此前只有 Route / View 两个命令自己做了校验，其余 17 个都是裸拼。
     * 这里覆写 execute() 做**前置拦截**，子类零改动即可全部获得保护，
     * 也避免将来新增 make 命令时又漏掉。
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (($reason = $this->rejectInvalidEntityArgument()) !== null) {
            $this->error($reason);
            $this->line('提示：实体名只能包含字母、数字、下划线、点与连字符，且必须以字母/数字/下划线开头。');

            return self::FAILURE;
        }

        return parent::execute($input, $output);
    }

    /**
     * 校验 name / module 两个位置参数，非法时返回可展示的拒绝理由（合法返回 null）
     */
    protected function rejectInvalidEntityArgument(): ?string
    {
        foreach (['name', 'module'] as $key) {
            $value = $this->optionalArgument($key);

            if ($value === null || $value === '') {
                continue;
            }

            if (! $this->isValidEntityName($value)) {
                return sprintf('参数 [%s] 的值 [%s] 不合法。', $key, $value);
            }
        }

        return null;
    }

    /**
     * 安全读取位置参数（未声明该参数时返回 null 而不是抛异常）
     */
    protected function optionalArgument(string $key): ?string
    {
        try {
            $value = $this->argument($key);
        } catch (\Throwable) {
            return null;
        }

        if ($value === null || is_array($value)) {
            return null;
        }

        return (string) $value;
    }

    /**
     * 当前已解析的模块实例（resolveModule 后可用）
     */
    protected ?ModuleInterface $module = null;

    /**
     * 解析并校验模块
     * 将 module 参数转为 StudlyCase 并查找模块实例；
     * 若模块不存在，输出错误并返回 null（子类应直接 return）。
     *
     * @param  string  $argument  模块参数名，默认 'module'
     */
    protected function resolveModule(string $argument = 'module'): ?ModuleInterface
    {
        $moduleName = Str::studly($this->argument($argument));

        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$this->esc($moduleName)}] 不存在");

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
     * 实体名里混入 '..'（例如把 name 传成 '../../config/app'）时，
     * 生成的文件就会写到模块目录之外。StubGenerator::getFullPath() 虽有兜底，
     * 但并非所有 make 命令都经由 StubGenerator（例如 ViewMakeCommand 会先
     * 用 targetPath() 做过存在性判断），因此在基类这里统一拦截最稳妥。
     *
     * @param  string  $relativePath  相对于模块根目录的路径（如 Http/Controllers/Foo.php）
     *
     * @throws \InvalidArgumentException 路径非法（含 ".." 或 NUL 字节）时抛出
     */
    protected function targetPath(string $relativePath): string
    {
        if (! $this->module) {
            throw new \RuntimeException('必须先调用 resolveModule() 解析模块');
        }

        if (! $this->isSafeRelativePath($relativePath)) {
            throw new \InvalidArgumentException(sprintf(
                '非法的目标路径 [%s]：只允许字母、数字、下划线、中划线、斜杠与点，禁止包含 ".." 或空字节。',
                $relativePath
            ));
        }

        return $this->module->getPath($relativePath);
    }

    /**
     * 校验「相对模块根目录的路径」是否安全
     *
     * 判定规则：
     * - 禁止 NUL 字节（可截断路径，绕过后续字符串判断）
     * - 禁止任何一层为 ".."
     * - 允许绝对路径（部分命令如 MigrationMakeCommand 的 --realpath 需要），
     *   但同样禁止其中的 ".."
     */
    protected function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        $normalized = str_replace('\\', '/', $path);

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * 校验「实体名」是否可安全用于拼接文件路径
     *
     * 多数 make 命令把用户输入的实体名（视图名 / 路由名 / 模型名 …）直接拼进
     * 相对路径。只要其中出现 '/' 或 '..' 就能写到模块目录之外，因此这里
     * 在拼接之前就拦下，并给出可读的错误提示。
     * targetPath() 里的抛出属于最后一道兜底，正常情况下不应触发。
     */
    protected function isValidEntityName(?string $name): bool
    {
        return $name !== null
            && $name !== ''
            && preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.\-]*$/', $name) === 1;
    }

    /**
     * 渲染 stub 并写入模块目录
     * 统一处理：
     *  - 文件已存在且未指定 --force → 输出提示并返回 FAILURE
     *  - 文件已存在且指定 --force → 输出覆盖警告
     *  - 调用 StubGenerator::generate() 写入
     *  - 成功 / 失败信息输出
     *
     * @param  StubGenerator  $generator  已配置替换变量的 StubGenerator 实例
     * @param  string  $stub  stub 文件名（相对于 stubs 目录）
     * @param  string  $relativePath  目标相对路径（相对于模块根目录）
     * @param  string  $entityLabel  实体中文标签（用于输出信息，如 “控制器”）
     * @param  bool  $force  是否覆盖已存在文件
     * @return int Command::SUCCESS / Command::FAILURE
     */
    protected function writeStub(
        StubGenerator $generator,
        string $stub,
        string $relativePath,
        string $entityLabel,
        bool $force
    ): int {
        // 非法路径不应以异常栈回溯的形式甩给使用者，转成常规失败返回
        if (! $this->isSafeRelativePath($relativePath)) {
            $this->error("非法的目标路径 [{$relativePath}]");
            $this->line('提示：路径中不允许包含 ".." 或空字节');

            return Command::FAILURE;
        }

        $absolutePath = $this->targetPath($relativePath);
        $moduleName = $this->module->getName();
        $entityName = basename($relativePath, '.php');

        if (file_exists($absolutePath) && ! $force) {
            $this->error("模块 [{$this->esc($moduleName)}] 中已存在{$entityLabel} [{$entityName}]");
            $this->line("提示：使用 --force 选项覆盖已存在的{$entityLabel}");

            return Command::FAILURE;
        }

        if (file_exists($absolutePath) && $force) {
            $this->warn("正在覆盖模块 [{$this->esc($moduleName)}] 中已存在的{$entityLabel} [{$entityName}]");
        }

        $result = $generator->generate($stub, $relativePath, $force);

        if ($result) {
            $this->info("成功在模块 [{$this->esc($moduleName)}] 中创建{$entityLabel} [{$entityName}]");

            return Command::SUCCESS;
        }

        $this->error("创建{$entityLabel} [{$entityName}] 失败");

        return Command::FAILURE;
    }

    /**
     * 便捷方法：从已配置 generator 生成文件（目录自动创建）
     * 与 {@see writeStub()} 相同，但允许在生成前由调用方决定路径，
     * 适用于需要额外动态计算目录的命令。
     */
    protected function generateFile(StubGenerator $generator, string $stub, string $relativePath, bool $force): bool
    {
        return $generator->generate($stub, $relativePath, $force) !== false;
    }
}
