<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use zxf\Modules\Commands\Concerns\EscapesConsoleOutput;
use zxf\Modules\Facades\Module;

/**
 * 检查模块本地化文件差异命令
 * 用于对比模块内不同语言的翻译文件,找出缺失的配置项
 */
class ModuleCheckLangCommand extends Command
{
    use EscapesConsoleOutput;

    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:check-lang
                            {name? : 模块名称（可选，不指定则检查所有模块）}
                            {--path= : 自定义本地化路径（默认：Resources/lang）}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '检查模块内不同语言本地化文件的配置项差异';

    /**
     * 允许加载语言文件的根边界（= 模块根目录）
     *
     * 由 getLangPath() 写入，loadLangFile() 据此拒绝越界路径。
     * 默认空串表示「尚未设定」→ loadLangFile() 会一律拒绝（fail-safe）。
     */
    protected string $allowedLangRoot = '';

    /**
     * 执行命令
     */
    public function handle(): int
    {
        $moduleName = $this->argument('name');

        if ($moduleName) {
            return $this->checkModuleLang($moduleName);
        }

        return $this->checkAllModules();
    }

    /**
     * 检查指定模块的本地化文件
     */
    protected function checkModuleLang(string $moduleName): int
    {
        $module = Module::find(Str::studly($moduleName));

        if (! $module) {
            $this->error("模块 [{$this->esc($moduleName)}] 不存在");

            return Command::FAILURE;
        }

        $langPath = $this->getLangPath($module);

        // getLangPath() 返回空串 = --path 非法（已输出错误），必须中止而非继续
        if ($langPath === '') {
            return Command::FAILURE;
        }

        if (! is_dir($langPath)) {
            $this->warn("模块 [{$this->esc($moduleName)}] 不存在本地化目录: {$langPath}");

            return Command::SUCCESS;
        }

        $this->info("正在检查模块 [{$this->esc($moduleName)}] 的本地化文件...");

        $result = $this->analyzeLangFiles($langPath, $moduleName);

        if ($result['total_languages'] < 2) {
            $this->warn("模块 [{$this->esc($moduleName)}] 只有 {$result['total_languages']} 种语言,无法进行对比");
            $this->line('语言列表: '.implode(', ', $result['languages']));

            return Command::SUCCESS;
        }

        $this->newLine();
        $this->info("发现 {$result['total_languages']} 种语言: ".implode(', ', $result['languages']));

        if (empty($result['missing_keys'])) {
            $this->newLine();
            $this->info('<fg=green>✓ 所有语言的配置项完整一致！</>');

            return Command::SUCCESS;
        }

        $this->newLine();
        $this->warn('<fg=yellow>发现配置项差异：</>');

        foreach ($result['missing_keys'] as $language => $missing) {
            $this->newLine();
            $this->line("<fg=red>语言 [{$language}] 缺失的配置项：</>");

            foreach ($missing as $file => $keys) {
                $this->line("  文件 [{$this->esc($file)}] 缺失 ".count($keys).' 个配置项:');
                foreach ($keys as $key) {
                    $this->line("    - {$key}");
                }
            }
        }

        return Command::FAILURE;
    }

    /**
     * 检查所有模块的本地化文件
     */
    protected function checkAllModules(): int
    {
        $modules = Module::all();

        if (empty($modules)) {
            $this->warn('没有找到任何模块');

            return Command::SUCCESS;
        }

        $this->info('正在检查所有模块的本地化文件...');

        $results = [];
        $hasIssues = false;

        foreach ($modules as $module) {
            $moduleName = $module->getName();
            $langPath = $this->getLangPath($module);

            // --path 非法时 getLangPath() 返回空串，记录并跳过该模块
            if ($langPath === '') {
                $results[$moduleName] = [
                    'status' => 'invalid_path',
                    'message' => '--path 非法',
                ];
                $hasIssues = true;

                continue;
            }

            if (! is_dir($langPath)) {
                $results[$moduleName] = [
                    'status' => 'no_lang',
                    'message' => '无本地化目录',
                ];

                continue;
            }

            $result = $this->analyzeLangFiles($langPath, $moduleName);

            if ($result['total_languages'] < 2) {
                $results[$moduleName] = [
                    'status' => 'single_lang',
                    'message' => '只有 '.$result['total_languages'].' 种语言',
                    'issues' => 0,
                ];

                continue;
            }

            if (empty($result['missing_keys'])) {
                $results[$moduleName] = [
                    'status' => 'ok',
                    'message' => '完整',
                    'issues' => 0,
                ];

                continue;
            }

            // $result['missing_keys'] 结构为 [语言 => [文件 => [键...]]]
            // 需对每个语言的「各文件缺失键数组」求和，而非对文件数组 count（后者统计的是文件数）。
            $totalMissing = 0;
            foreach ($result['missing_keys'] as $files) {
                $totalMissing += array_sum(array_map('count', $files));
            }

            $results[$moduleName] = [
                'status' => 'issues',
                'message' => '存在差异',
                'issues' => $totalMissing,
                'details' => $result,
            ];

            $hasIssues = true;
        }

        $this->newLine();

        // 显示汇总表格
        $rows = [];
        foreach ($results as $name => $result) {
            $statusColor = match ($result['status']) {
                'ok' => 'green',
                'issues' => 'red',
                default => 'yellow',
            };

            $status = "<fg={$statusColor}>{$result['message']}</>";

            $rows[] = [
                $name,
                $status,
                $result['issues'] ?? '-',
            ];
        }

        $this->table(['模块名称', '状态', '缺失配置项数'], $rows);

        // 显示详细信息
        foreach ($results as $name => $result) {
            if ($result['status'] === 'issues') {
                $this->newLine();
                $this->warn("模块 [{$this->esc($name)}] 的详细差异：");
                $this->displayModuleIssues($name, $result['details']);
            }
        }

        return $hasIssues ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * 分析本地化文件
     */
    protected function analyzeLangFiles(string $langPath, string $moduleName): array
    {
        // 获取所有语言目录
        $languageDirs = glob($langPath.'/*', GLOB_ONLYDIR);

        if (empty($languageDirs)) {
            return [
                'total_languages' => 0,
                'languages' => [],
                'missing_keys' => [],
            ];
        }

        $languages = [];
        $allFiles = [];
        $languageFiles = [];

        // 收集所有语言和文件
        foreach ($languageDirs as $langDir) {
            $lang = basename($langDir);
            $languages[] = $lang;

            $files = glob($langDir.'/*.php');

            foreach ($files as $file) {
                $fileName = basename($file, '.php');
                $allFiles[$fileName] = true;

                $languageFiles[$lang][$fileName] = $this->loadLangFile($file);
            }
        }

        // 收集所有可能的配置键（从所有语言的所有文件中）
        $allKeysByFile = [];

        foreach ($languageFiles as $lang => $files) {
            foreach ($files as $fileName => $content) {
                if (! isset($allKeysByFile[$fileName])) {
                    $allKeysByFile[$fileName] = [];
                }
                $allKeysByFile[$fileName] = array_merge($allKeysByFile[$fileName], $this->getAllKeys($content));
            }
        }

        // 去重
        foreach ($allKeysByFile as $fileName => $keys) {
            $allKeysByFile[$fileName] = array_unique($keys);
        }

        // 检查每种语言缺失的配置项
        $missingKeys = [];

        foreach ($languages as $lang) {
            foreach (array_keys($allKeysByFile) as $fileName) {
                $currentKeys = isset($languageFiles[$lang][$fileName])
                    ? $this->getAllKeys($languageFiles[$lang][$fileName])
                    : [];

                $allKeys = $allKeysByFile[$fileName];
                $missing = array_diff($allKeys, $currentKeys);

                if (! empty($missing)) {
                    $missingKeys[$lang][$fileName] = array_values($missing);
                }
            }
        }

        return [
            'total_languages' => count($languages),
            'languages' => $languages,
            'files' => array_keys($allFiles),
            'all_keys' => $allKeysByFile,
            'missing_keys' => $missingKeys,
        ];
    }

    /**
     * 加载本地化文件
     *
     * 两条安全约束：
     *  1) 语言文件本应只是「返回数组」，用 require 会真正执行该 PHP 文件，
     *     意味着任何被扫到的 .php 都会被执行（若路径可被 --path 引导到模块外，
     *     风险更大），故改用 include（require 对返回数组没有额外价值）；
     *  2) 必须做路径边界校验 —— 路径须位于该模块目录内且必须是 .php 文件。
     *
     * 另做异常隔离，单个语言文件出错不影响其它文件。
     */
    protected function loadLangFile(string $filePath): array
    {
        if (! $this->isPathInside($this->allowedLangRoot, $filePath)) {
            $this->warn("跳过模块目录之外的路径: {$this->esc($filePath)}");

            return [];
        }

        if (! is_file($filePath) || ! str_ends_with(strtolower($filePath), '.php')) {
            return [];
        }

        try {
            /** @psalm-suppress UnresolvableInclude */
            $content = include $filePath;
        } catch (\Throwable $e) {
            // 单个语言文件出错不影响其它文件
            $this->warn("读取语言文件失败 [{$this->esc($filePath)}]: ".$e->getMessage());

            return [];
        }

        return is_array($content) ? $content : [];
    }

    /**
     * 判断 $path 是否位于 $root 之内（含 root 自身）
     * 采用纯字符串归一化，不依赖 realpath（目标可能尚未存在）
     */
    protected function isPathInside(string $root, string $path): bool
    {
        $normalizedRoot = $this->normalizeForCompare($root);
        $normalizedPath = $this->normalizeForCompare($path);

        if ($normalizedRoot === '') {
            return false;
        }

        return $normalizedPath === $normalizedRoot
            || str_starts_with($normalizedPath, $normalizedRoot.'/');
    }

    /**
     * 路径归一化（消解 . 与 ..、统一分隔符、去掉尾斜杠）
     */
    protected function normalizeForCompare(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/');
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return ($isAbsolute ? '/' : '').implode('/', $segments);
    }

    /**
     * 获取数组中的所有键（支持嵌套）
     */
    protected function getAllKeys(array $array, string $prefix = ''): array
    {
        $keys = [];

        foreach ($array as $key => $value) {
            $fullKey = $prefix ? $prefix.'.'.$key : $key;

            if (is_array($value)) {
                $keys = array_merge($keys, $this->getAllKeys($value, $fullKey));
            } else {
                $keys[] = $fullKey;
            }
        }

        return $keys;
    }

    /**
     * 获取模块的本地化路径
     *
     * @param  mixed  $module
     */
    protected function getLangPath($module): string
    {
        $customPath = $this->option('path');

        // 语言文件加载允许的根边界 = 模块根目录。
        // 必须在 getLangPath() 里就固定下来，供 loadLangFile() 做越界校验。
        $this->allowedLangRoot = $module->getPath();

        if ($customPath) {
            // `--path=../../../etc` 会解析到模块目录之外，
            // 配合无边界校验的 getRequire() 即可 require 任意 .php 文件，故拒绝 ".."。
            $normalized = str_replace('\\', '/', (string) $customPath);

            foreach (explode('/', $normalized) as $segment) {
                if ($segment === '..') {
                    $this->error("--path [{$this->esc($customPath)}] 非法：不允许包含 ..");
                    $this->line('提示：--path 只能是模块目录内的相对子路径（例如 Resources/lang）');

                    return '';
                }
            }

            return $module->getPath().'/'.trim($normalized, '/');
        }

        return $module->getPath().'/'.config('modules.translations.path', 'Resources/lang');
    }

    /**
     * 显示模块的问题详情
     */
    protected function displayModuleIssues(string $moduleName, array $details): void
    {
        $this->line('语言列表: '.implode(', ', $details['languages']));
        $this->newLine();

        foreach ($details['missing_keys'] as $language => $missing) {
            $this->line("<fg=red>语言 [{$language}] 缺失的配置项：</>");

            foreach ($missing as $file => $keys) {
                $this->line("  文件 [{$this->esc($file)}] 缺失 ".count($keys).' 个配置项:');
                foreach ($keys as $key) {
                    $this->line("    - {$key}");
                }
            }

            $this->newLine();
        }
    }
}
