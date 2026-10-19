<?php

declare(strict_types=1);

namespace zxf\Modules\Support;

/**
 * 破坏性操作（删除/覆盖）路径安全闸门
 *
 * ============================ 为什么必须集中做 ============================
 *
 * 本包的 make / delete 命令都要往磁盘写或删目录，而「路径在受信任根目录内」
 * 这一判断，**只比较字符串前缀是不可靠的**：
 *
 *   1) **符号链接逃逸（真实可利用的高危）**
 *      `<Modules>/Blog` 若是软链，`is_dir()` 返回 true、字面路径也以 `Modules/` 开头，
 *      但 `Illuminate\Filesystem\Filesystem::deleteDirectory()` 内部是
 *      `new FilesystemIterator($directory)` —— 对软链目录会**遍历并删除链接目标的内容**。
 *      于是 `module:make Blog --force` 可能删掉 Modules 之外的整棵目录树。
 *
 *   2) **路径分隔符不一致**
 *      Windows 下 `DIRECTORY_SEPARATOR` 是 `\`，与配置里写死的 `/` 混用时，
 *      `str_starts_with($p, $root.'/')` 会误判为越界（或漏判）。
 *
 *   3) **`..` 与重复斜杠**：`Modules/./Blog/../Blog` 之类会绕过字面比较。
 *
 * 因此本 trait 提供**唯一**的判定实现：先归一化，再 `realpath()`（会解开沿途所有
 * 符号链接）做包含判断，并额外**硬性拒绝顶层软链**。
 *
 * 用法：
 *     $reason = $this->guardAgainstEscape($modulePath, $this->trustedRoots(), '模块目录');
 *     if ($reason !== null) { $this->error($reason); return Command::FAILURE; }
 *
 * @version 1.13.9
 */
trait PathGuard
{
    /**
     * 破坏性操作前的安全闸门
     *
     * @param  string                   $path  待操作路径（可以是尚不存在的新目录）
     * @param  array<int,string>|string $roots 受信任的根目录列表
     * @param  string                   $label 报错时使用的名称
     *
     * @return string|null 放行返回 null；拒绝返回可直接展示给用户的理由
     */
    protected function guardAgainstEscape(string $path, array|string $roots, string $label = '目标路径'): ?string
    {
        $rootList = [];

        foreach ((array) $roots as $root) {
            if (is_string($root) && $root !== '') {
                $rootList[] = $this->normalizeForCompare($root);
            }
        }

        // 没有配置任何可信根目录时不做限制（保持极简容器的可用性）
        if ($rootList === []) {
            return null;
        }

        // ① 顶层软链一律拒绝：deleteDirectory 会跟随软链删到目标内容
        if (is_link($path)) {
            return sprintf('%s [%s] 是符号链接，为防止误删模块根目录之外的内容，已拒绝操作。', $label, $path);
        }

        // ② realpath 判定（含父目录中的软链）
        $real = $this->resolveRealPath($path);

        if ($real === null) {
            // 整条路径链都无法定位（例如父目录都不存在）→ 无法判定，交由字面比较兜底
            return null;
        }

        foreach ($rootList as $root) {
            $rootReal = realpath($root);
            $base = $rootReal === false ? $root : $this->normalizeForCompare($rootReal);

            if ($real === $base || str_starts_with($real, $base.'/')) {
                return null;
            }
        }

        return sprintf(
            '%s [%s] 经符号链接解析后位于 [%s]，已超出受信任的模块根目录，拒绝操作。',
            $label,
            $path,
            $real
        );
    }

    /**
     * 取得「解开全部符号链接后的绝对路径」
     *
     * 目标本身可能尚不存在（首次创建模块），此时逐级向上找到最近的已存在祖先，
     * 对它做 realpath，再把缺失的尾部拼回去 —— 这样「父目录是软链」的情况也能被识破。
     */
    protected function resolveRealPath(string $path): ?string
    {
        // 先统一分隔符再解析：
        //  - Windows 上 `\` 才是分隔符，归一化后 realpath 仍能正确处理（C:/a/b）
        //  - POSIX 上 `\` 是普通文件名字符，若不归一化会被当成相对路径，
        //    导致下面「逐级上溯」一路退到 CWD，拼出毫无意义的绝对路径。
        $normalized = $this->normalizeForCompare($path);

        $real = realpath($normalized);

        if ($real !== false) {
            return $this->normalizeForCompare($real);
        }

        $probe = $normalized;
        $trailing = [];

        while ($probe !== '' && $probe !== '/' && $probe !== '.') {
            $trailing[] = basename($probe);
            $parent = dirname($probe);

            // 退到文件系统根、或 dirname 已无法前进 → 放弃解析。
            // 特别地：dirname('.') === '.'，若不在这里拦下就会一路取到 CWD，
            // 拼出「项目目录/tmp/xxx」这种凭空捏造的路径。
            if ($parent === $probe || $parent === '.' || $parent === '') {
                return null;
            }

            $probe = $parent;
            $candidate = realpath($probe);

            if ($candidate === false) {
                continue;
            }

            $candidate = $this->normalizeForCompare($candidate);

            // 解析结果必须是绝对路径，否则拼接出的结论没有意义
            if (! $this->looksAbsolute($candidate)) {
                return null;
            }

            foreach (array_reverse($trailing) as $segment) {
                $candidate .= '/'.$segment;
            }

            return $this->normalizeForCompare($candidate);
        }

        return null;
    }

    /**
     * 判断路径是否为绝对路径（兼容 POSIX 与 Windows 盘符）
     */
    protected function looksAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#', $path) === 1;
    }

    /**
     * 路径归一化：统一分隔符、压缩重复斜杠、去掉末尾斜杠
     *
     * 供本包各处做「字符串比较」之前统一调用，避免 Windows/POSIX 混用误判。
     */
    protected function normalizeForCompare(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    /**
     * 取所有受信任的模块根目录（modules.path + modules.scan_paths）
     *
     * 破坏性操作一律以此为白名单基准。
     */
    protected function trustedModuleRoots(): array
    {
        $roots = [];

        $paths = array_merge(
            [config('modules.path')],
            (array) config('modules.scan_paths', [])
        );

        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                $roots[] = $path;
            }
        }

        if ($roots === [] && function_exists('base_path')) {
            $roots[] = base_path('Modules');
        }

        return $roots;
    }

    /**
     * 判断 $path 是否位于 $root 之内（含相等），不做符号链接解析
     *
     * 仅用于「提示/展示」这类不需要强安全的场合；
     * 破坏性操作请一律走 guardAgainstEscape()。
     */
    protected function isPathInside(string $path, string $root): bool
    {
        $p = $this->normalizeForCompare($path);
        $r = $this->normalizeForCompare($root);

        return $p === $r || str_starts_with($p, $r.'/');
    }
}