<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;

/**
 * 检查模块本地化文件差异命令
 *
 * 用于对比模块内不同语言的翻译文件,找出缺失的配置项
 */
class ModuleCheckLangCommand extends Command
{
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
     * 执行命令
     *
     * @return int
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
     *
     * @param string $moduleName
     * @return int
     */
    protected function checkModuleLang(string $moduleName): int
    {
        $module = Module::find(Str::studly($moduleName));

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在");

            return Command::FAILURE;
        }

        $langPath = $this->getLangPath($module);

        if (! is_dir($langPath)) {
            $this->warn("模块 [{$moduleName}] 不存在本地化目录: {$langPath}");

            return Command::SUCCESS;
        }

        $this->info("正在检查模块 [{$moduleName}] 的本地化文件...");

        $result = $this->analyzeLangFiles($langPath, $moduleName);

        if ($result['total_languages'] < 2) {
            $this->warn("模块 [{$moduleName}] 只有 {$result['total_languages']} 种语言,无法进行对比");
            $this->line('语言列表: ' . implode(', ', $result['languages']));

            return Command::SUCCESS;
        }

        $this->newLine();
        $this->info("发现 {$result['total_languages']} 种语言: " . implode(', ', $result['languages']));

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
                $this->line("  文件 [{$file}] 缺失 " . count($keys) . " 个配置项:");
                foreach ($keys as $key) {
                    $this->line("    - {$key}");
                }
            }
        }

        return Command::FAILURE;
    }

    /**
     * 检查所有模块的本地化文件
     *
     * @return int
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
                    'message' => '只有 ' . $result['total_languages'] . ' 种语言',
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

            $totalMissing = array_sum(array_map(fn ($item) => array_sum(array_map('count', $item)), $result['missing_keys']));

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
                $this->warn("模块 [{$name}] 的详细差异：");
                $this->displayModuleIssues($name, $result['details']);
            }
        }

        return $hasIssues ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * 分析本地化文件
     *
     * @param string $langPath
     * @param string $moduleName
     * @return array
     */
    protected function analyzeLangFiles(string $langPath, string $moduleName): array
    {
        // 获取所有语言目录
        $languageDirs = glob($langPath . '/*', GLOB_ONLYDIR);

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

            $files = glob($langDir . '/*.php');

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
     * @param string $filePath
     * @return array
     */
    protected function loadLangFile(string $filePath): array
    {
        $content = File::getRequire($filePath);

        return is_array($content) ? $content : [];
    }

    /**
     * 获取数组中的所有键（支持嵌套）
     *
     * @param array $array
     * @param string $prefix
     * @return array
     */
    protected function getAllKeys(array $array, string $prefix = ''): array
    {
        $keys = [];

        foreach ($array as $key => $value) {
            $fullKey = $prefix ? $prefix . '.' . $key : $key;

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
     * @param mixed $module
     * @return string
     */
    protected function getLangPath($module): string
    {
        $customPath = $this->option('path');

        if ($customPath) {
            return $module->getPath() . '/' . trim($customPath, '/');
        }

        return $module->getPath() . '/' . config('modules.translations.path', 'Resources/lang');
    }

    /**
     * 显示模块的问题详情
     *
     * @param string $moduleName
     * @param array $details
     * @return void
     */
    protected function displayModuleIssues(string $moduleName, array $details): void
    {
        $this->line('语言列表: ' . implode(', ', $details['languages']));
        $this->newLine();

        foreach ($details['missing_keys'] as $language => $missing) {
            $this->line("<fg=red>语言 [{$language}] 缺失的配置项：</>");

            foreach ($missing as $file => $keys) {
                $this->line("  文件 [{$file}] 缺失 " . count($keys) . " 个配置项:");
                foreach ($keys as $key) {
                    $this->line("    - {$key}");
                }
            }

            $this->newLine();
        }
    }
}
