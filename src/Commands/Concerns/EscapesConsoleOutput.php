<?php

declare(strict_types=1);

namespace zxf\Modules\Commands\Concerns;

use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * 控制台输出转义助手。
 *
 * Symfony Console 的 OutputFormatter 会把 `<tag>` 解析为样式标记：
 *  - 输出含 `<info>` 之类合法标记时内容被吞掉；
 *  - 输出 `<foo>` 这类未知标记时抛 InvalidArgumentException: Unknown style，
 *    直接把命令打成非零退出（`php artisan module:info '<x>'` 即触发）。
 *
 * 因此凡是把**用户可控输入**（参数、选项、路径、模块名等）拼进
 * error() / warn() / info() / line() / components->*() 的地方，
 * 都必须先用 {@see self::esc()} 转义，否则构成「输出层格式化标记注入」。
 *
 * 用法：
 *
 *     $this->error("模块 [".$this->esc($moduleName)."] 不存在。");
 *
 * 注意：本 trait 只负责转义，不负责校验。若某处还需要拒绝非法输入，
 * 应在校验分支里拒绝，并仍对本行的非法值做 esc()（错误提示本身也会被解析）。
 */
trait EscapesConsoleOutput
{
    /**
     * 转义字符串中的 Console 格式标记（`\` → `\\`、`<` → `\<`）。
     *
     * @param  mixed  $value  任意可转字符串的值（null / 对象等一律安全降级）
     */
    protected function esc(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            return $value === true ? '1' : '';
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            return OutputFormatter::escape((string) $value);
        }

        return OutputFormatter::escape(self::stringify($value));
    }

    /** 数组批量转义（保持键不变） */
    protected function escAll(array $values): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            $out[$key] = is_scalar($value) || $value === null
                ? $this->esc($value)
                : $this->esc(self::stringify($value));
        }

        return $out;
    }

    /** 安全地把任意值转成字符串（不触发 notice / 异常） */
    private static function stringify(mixed $value): string
    {
        if (is_object($value) && ! $value instanceof \Stringable) {
            return get_class($value);
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        return (string) $value;
    }
}
