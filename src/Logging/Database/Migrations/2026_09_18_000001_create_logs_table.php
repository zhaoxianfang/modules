<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 创建 logs 表（数据库日志通道落库表）。
 *
 * 设计目标：字段齐全，覆盖
 *   - 日志基础信息（level / channel / message / context / extra）
 *   - 丰富的请求上下文（方法 / URL / 路由 / 控制器 / IP / UA / 来源 / 会话 / 用户 / 入参 / 请求头）
 *   - 完整的响应结果（状态码 / 状态短语 / 耗时 / 峰值内存 / 响应头 / 响应体）
 *   - 异常信息（类名 / 消息 / 文件:行 / 堆栈）
 *
 * 兼容 MySQL / PostgreSQL / SQLite（json 列在各驱动下均可用）。
 *
 * @package zxf\Modules\Logging
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logs', function (Blueprint $table) {
            $table->id();

            // ---- 日志基础信息 ----
            $table->string('level', 20)->index()->comment('等级键，如 error');
            $table->string('level_name', 20)->index()->comment('等级名（大写），如 ERROR');
            $table->string('channel', 50)->nullable()->index()->comment('日志通道名');
            $table->longText('message')->comment('日志正文');

            $table->longText('context')->nullable()->comment('Monolog context（已脱敏、剔除不可序列化对象、超长字段已截断）');
            $table->longText('extra')->nullable()->comment('Monolog extra（上下文附加数据）');

            // ---- 请求上下文 ----
            $table->string('request_method', 10)->nullable()->index();
            $table->text('request_url')->nullable();
            $table->string('request_path', 512)->nullable()->index();
            $table->string('request_route', 255)->nullable()->comment('路由名称');
            $table->string('request_controller', 255)->nullable()->comment('控制器@方法');
            $table->string('request_ip', 45)->nullable()->index();
            $table->text('request_user_agent')->nullable();
            $table->text('request_referer')->nullable();
            $table->string('request_session_id', 255)->nullable();
            $table->longText('request_input')->nullable()->comment('请求入参（已脱敏、超长字段已截断）');
            $table->longText('request_headers')->nullable()->comment('请求头（敏感头已脱敏）');

            // ---- 认证用户 ----
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_type', 255)->nullable();

            // ---- 响应结果 ----
            $table->unsignedSmallInteger('response_code')->nullable()->index()->comment('HTTP 状态码');
            $table->string('response_status', 50)->nullable()->comment('状态短语，如 OK');
            $table->unsignedInteger('response_duration_ms')->nullable()->comment('请求耗时（毫秒）');
            $table->decimal('response_memory_mb', 8, 2)->nullable()->comment('峰值内存（MB）');
            $table->longText('response_headers')->nullable()->comment('响应头（敏感头已脱敏）');
            $table->longText('response_body')->nullable()->comment('响应体（文本类，截断至 20000 字符，已脱敏）');

            // ---- 异常信息 ----
            $table->string('exception_class', 255)->nullable();
            $table->longText('exception_message')->nullable();
            $table->string('file', 512)->nullable()->comment('异常文件:行');
            $table->longText('trace')->nullable()->comment('异常堆栈');

            $table->timestamps();
            $table->index('created_at');
            // 复合索引：常见的「按等级 / 通道 + 时间」检索组合（按 created_at 检索仅需单列索引）
            $table->index(['level_name', 'created_at']);
            $table->index(['channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logs');
    }
};
