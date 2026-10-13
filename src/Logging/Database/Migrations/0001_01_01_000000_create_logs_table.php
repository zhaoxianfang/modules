<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 创建 logs 表（数据库日志通道落库表）。
 * 字段说明（与本期需求一一对应）：
 *   - module_name      模块名称（由宿主应用的 module_name() 助手获取，小写下划线形式），位于 id 之后；
 *   - level            日志等级（PSR 小写键：error / info / warning / critical …，与 Monolog 一致），
 *                      原为冗余的 level_name（大写名）字段，已彻底移除；
 *   - is_crawler       是否为爬虫 / 机器人请求（bool），位于 extra 之后；
 *   - response_content 响应内容：视图页面响应记录「视图页面地址（视图名）」，JSON 响应记录「JSON 数据」，
 *                      位于 response_status 之后。
 */
return new class extends Migration
{
    public function up(): void
    {

        Schema::create('logs', function (Blueprint $table) {
            $table->id();

            // ---- 日志基础信息 ----
            // module_name：记录「当前日志由哪个模块产生」，由宿主应用的 module_name() 助手获取。
            // 该助手返回小写下划线蛇形模块名（如 users / goods / blog），便于按模块检索与统计。
            $table->string('module_name', 50)->nullable()->index()
                ->comment('模块名称（由 module_name() 函数获取，记录日志发生的模块，小写下划线蛇形）');
            // level：统一承载 PSR 小写等级键（error / info / warning / critical …），由 handler 的
            // normalizeLevel() 归一化。原冗余的 level_name（大写名）字段已废弃并移除。
            $table->string('level', 20)->index()
                ->comment('日志等级（PSR 小写键，如 error / info / warning）');
            $table->string('channel', 50)->nullable()->index()->comment('日志通道名（如 database / stack）');
            $table->longText('message')->comment('日志正文');

            // context / extra：Monolog 原生字段，已脱敏、剔除不可序列化对象、超长字段已截断。
            $table->longText('context')->nullable()
                ->comment('Monolog context（已脱敏、剔除不可序列化对象、超长字段已截断，JSON）');
            $table->longText('extra')->nullable()->comment('Monolog extra（上下文附加数据，JSON）');
            // is_crawler：基于 User-Agent 等特征识别搜索引擎 / 机器人，单独成列便于统计与过滤。
            $table->boolean('is_crawler')->default(false)->index()
                ->comment('是否为爬虫请求：1=爬虫/机器人，0=普通浏览器或接口调用');

            // ---- 请求上下文 ----
            $table->string('request_method', 10)->nullable()->index()->comment('HTTP 方法（GET/POST…）');
            $table->text('request_url')->nullable()->comment('完整请求 URL');
            $table->string('request_path', 512)->nullable()->index()->comment('请求路径（不含域名与查询串）');
            $table->string('request_route', 255)->nullable()->comment('路由名称（命名路由）');
            $table->string('request_controller', 255)->nullable()->comment('控制器@方法');
            $table->string('request_ip', 45)->nullable()->index()->comment('客户端 IP');
            $table->text('request_user_agent')->nullable()->comment('User-Agent');
            $table->text('request_referer')->nullable()->comment('来源 Referer');
            $table->string('request_session_id', 255)->nullable()->comment('会话 ID（仅当会话已启动时采集）');
            $table->longText('request_input')->nullable()->comment('请求入参（已脱敏、超长字段已截断，JSON）');
            $table->longText('request_headers')->nullable()->comment('请求头（敏感头已脱敏，JSON）');

            // ---- 认证用户 ----
            $table->unsignedBigInteger('user_id')->nullable()->index()->comment('认证用户 ID（未认证为 null）');
            $table->string('user_type', 255)->nullable()->comment('认证用户类型（类名）');

            // ---- 响应结果 ----
            $table->unsignedSmallInteger('response_code')->nullable()->index()->comment('HTTP 状态码（如 200/404/500）');
            $table->string('response_status', 50)->nullable()->comment('状态短语（如 OK / Not Found）');
            // response_content：视图页面响应记录「视图名」，JSON 响应记录「JSON 数据」，便于事后复盘。
            $table->longText('response_content')->nullable()
                ->comment('响应内容：视图页面响应记录视图页面地址（视图名），JSON 响应记录 JSON 数据');
            $table->unsignedInteger('response_duration_ms')->nullable()->comment('请求耗时（毫秒）');
            $table->decimal('response_memory_mb', 8, 2)->nullable()->comment('峰值内存（MB）');
            $table->longText('response_headers')->nullable()->comment('响应头（敏感头已脱敏，JSON）');
            $table->longText('response_body')->nullable()->comment('响应体（文本类，截断至 20000 字符，已脱敏）');

            // ---- 异常信息 ----
            $table->string('exception_class', 255)->nullable()->comment('异常类名');
            $table->longText('exception_message')->nullable()->comment('异常消息');
            $table->string('file', 512)->nullable()->comment('异常文件:行');
            $table->longText('trace')->nullable()->comment('异常堆栈');

            $table->timestamps();
            $table->index('created_at');
            // 常见检索组合索引：按「等级 / 通道 + 时间」过滤（单列 created_at 已单独建索引）。
            $table->index(['level', 'created_at']);
            $table->index(['channel', 'created_at']);

            if (DB::getDriverName() !== 'sqlite') {
                $table->comment('管理员角色组');
            }
        });
    }

    public function down(): void
    {
        // 还原为「建表前」状态：删除 logs 表（含所有演进字段）。
        // 说明：本迁移是「合并后的最终形态」单一文件，回滚即视为放弃本模块日志表，
        // 符合「单一迁移文件」语义；不重建已被移除的 level_name 旧结构。
        Schema::dropIfExists('logs');
    }
};
