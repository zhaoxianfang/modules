#!/usr/bin/env bash
#
# zxf/modules 统一回归入口
#
# 用法：
#   bash tests/run.sh
#
# 说明：
#   1) 语法检查：遍历 src/ 与 tests/ 下所有 PHP 文件
#   2) 运行时验证：依赖一个已安装 Laravel 的宿主项目（默认 /Users/aha/www/wsf）
#      提供 vendor/autoload.php；可通过第一个参数或环境变量 WSF_PATH 覆盖。
#
# 退出码：0 全部通过，1 存在失败。

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOST="${1:-${WSF_PATH:-/Users/aha/www/wsf}}"

echo "=============================================="
echo " zxf/modules 回归测试"
echo " 包路径: ${ROOT}"
echo " 宿主:   ${HOST}"
echo "=============================================="

fail=0

# ---------- 1. 语法检查 ----------
echo ""
echo "[1/4] PHP 语法检查"
while IFS= read -r file; do
    if ! out=$(php -l "$file" 2>&1); then
        echo "  FAIL  ${file}"
        echo "        ${out}"
        fail=1
    fi
done < <(find "${ROOT}/src" "${ROOT}/tests" -name '*.php' -type f)
[ $fail -eq 0 ] && echo "  PASS  全部文件语法正确"

# ---------- 2. 宿主环境检查 ----------
echo ""
echo "[2/4] 宿主 Laravel 环境检查"
if [ ! -f "${HOST}/vendor/autoload.php" ]; then
    echo "  SKIP  未找到 ${HOST}/vendor/autoload.php，跳过运行时验证"
    echo "        用法：bash tests/run.sh /path/to/laravel-project"
    exit $fail
fi
echo "  PASS  宿主可用"

# ---------- 3. 运行时验证 ----------
run_php_test() {
    local name="$1"
    local script="$2"
    local out summary pass fail_count

    echo ""
    echo "[3/4] 运行时验证 - ${name}"

    out=$(php "${ROOT}/tests/${script}" 2>&1)
    summary=$(echo "$out" | grep -oE '结果: [0-9]+ PASS / [0-9]+ FAIL' | tail -1)

    if [ -z "$summary" ]; then
        echo "  FAIL  ${script}（未输出结果行，脚本可能异常终止）"
        echo "$out" | head -20
        fail=1

        return
    fi

    pass=$(echo "$summary" | grep -oE '[0-9]+ PASS' | grep -oE '[0-9]+')
    fail_count=$(echo "$summary" | grep -oE '[0-9]+ FAIL' | grep -oE '[0-9]+')

    # 注意：以结果行判定而非退出码 —— 宿主项目可能注册第三方错误/异常处理器
    # （如 trace 调试包），会在脚本正常结束后改写进程退出码，造成误判。
    if [ "${fail_count:-1}" -eq 0 ]; then
        echo "  PASS  ${summary}"
    else
        echo "  FAIL  ${summary}"
        echo "$out" | grep -E '^(FAIL|FATAL)' | head -20
        fail=1
    fi
}

run_php_test "字符串函数宏" "verify_string_macros.php"
run_php_test "递归 CTE / JSON 宏" "verify_recursive_macros.php"
run_php_test "模块缓存存储" "verify_cache_store.php"

# ---------- 4. 汇总 ----------
echo ""
echo "=============================================="
if [ $fail -eq 0 ]; then
    echo " 全部通过 ✅"
else
    echo " 存在失败 ❌"
fi
echo "=============================================="

exit $fail
