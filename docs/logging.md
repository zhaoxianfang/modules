# 数据库日志驱动（database channel）使用文档

> 本功能随 `zxf/modules` 包一同提供，命名空间为 `zxf\Modules\Logging`。
> 它实现了一个**强大且友好**的「数据库日志驱动」：开箱即可把 Laravel 全站日志
> （包含每次 HTTP 请求自动产生的「请求 → 响应」汇总日志）写入 `system_logs` 数据表，
> 字段齐全、信息丰富，并自带后台检索界面与清理命令。

---

## 一、功能特性

- **零配置启用**：`.env` 中设置 `LOG_CHANNEL=database` 即可，无需改动应用代码。
- **完整的请求上下文**：方法、完整 URL、路径、路由名、控制器@方法、IP、User-Agent、
  来源 Referer、Session ID、认证用户（id / 类型）、请求入参（**敏感字段自动脱敏**）、请求头。
- **完整的响应结果（Laravel 13+ 重点）**：HTTP 状态码、状态短语、请求耗时（ms）、
  峰值内存（MB）、响应头（敏感头脱敏）、响应体（仅文本类，截断至 2 万字符）。
- **异常全链路**：异常类名、消息、文件:行、完整堆栈，并自动与触发它的请求/响应关联。
- **安全**：密码 / token / secret / Authorization / Cookie 等敏感信息强制 `***REDACTED***`。
- **去重**：单次请求内相同异常只落库一次，避免刷屏。
- **健壮**：写库异常被静默吞掉，**绝不影响主业务流程**。
- **友好检索**：自带 `/logs` 后台界面（列表、详情、删除、清理），并支持 `logs:clean` 命令。

---

## 二、安装与启用

### 1. 发布迁移并建表

```bash
# 发布迁移文件到 database/migrations
php artisan vendor:publish --tag=modules-logging

# 执行迁移，创建 system_logs 表
php artisan migrate
```

> 迁移文件位于 `src/Logging/Database/Migrations/`，兼容 MySQL / PostgreSQL / SQLite。

### 2. 设置 .env

```dotenv
LOG_CHANNEL=database

# 可选：控制最低记录等级（默认 debug）
LOG_LEVEL=debug

# 可选：查看器开关 / URL 前缀 / 保留天数（均有默认值）
LOG_DB_VIEWER_ENABLED=true
LOG_DB_VIEWER_PREFIX=logs
LOG_DB_RETENTION_DAYS=30
```

完成。此后所有 `Log::info()` / `Log::error()` 以及**每次 HTTP 请求的访问汇总**
都会写入 `system_logs` 表。

---

## 三、数据表字段说明（system_logs）

| 字段 | 说明 |
| --- | --- |
| `id` | 主键 |
| `level` / `level_name` | 等级键（error）/ 等级名（ERROR） |
| `channel` | 日志通道名 |
| `message` | 日志正文 |
| `context` | Monolog context（已脱敏、剔除不可序列化对象，JSON） |
| `extra` | Monolog extra 附加数据（JSON） |
| `request_method` | 请求方法 |
| `request_url` | 完整请求 URL |
| `request_path` | 请求路径 |
| `request_route` | 路由名称 |
| `request_controller` | 控制器@方法 |
| `request_ip` | 客户端 IP |
| `request_user_agent` | User-Agent |
| `request_referer` | 来源 Referer |
| `request_session_id` | Session ID |
| `request_input` | 请求入参（已脱敏，JSON） |
| `request_headers` | 请求头（敏感头已脱敏，JSON） |
| `user_id` / `user_type` | 认证用户 id / 类型 |
| `response_code` | HTTP 状态码 |
| `response_status` | 状态短语（如 OK） |
| `response_duration_ms` | 请求耗时（毫秒） |
| `response_memory_mb` | 峰值内存（MB） |
| `response_headers` | 响应头（敏感头已脱敏，JSON） |
| `response_body` | 响应体（文本类，截断至 20000 字符） |
| `exception_class` | 异常类名 |
| `exception_message` | 异常消息 |
| `file` | 异常文件:行 |
| `trace` | 异常堆栈 |
| `created_at` / `updated_at` | 时间戳 |

---

## 四、架构与实现要点

### 1. 基于 Laravel 13 的 Monolog 3 通道

`config/logging.php`（包内）通过 `LoggingServiceProvider::mergeConfigFrom` 合并进应用的
`config('logging')`，注入名为 `database` 的 Monolog 通道：

```php
'database' => [
    'driver' => 'monolog',
    'level' => env('LOG_LEVEL', 'debug'),
    'handler' => \zxf\Modules\Logging\Handler\DatabaseLogHandler::class,
    'handler_with' => [
        'model' => null,
        'table' => null,
        'captureRequestBody' => true,
        'captureResponseBody' => true,
        'maxBodyLength' => 20000,
        'retentionDays' => 30,
    ],
    'tap' => [ \zxf\Modules\Logging\Handler\DatabaseLogTap::class ],
    'processors' => [],
],
```

> `handler_with` 的键名必须与 `DatabaseLogHandler` 构造参数逐一对应。

### 2. 请求 / 响应上下文捕获（核心）

- **`LogContextMiddleware`**：在请求开始采集请求信息，并在 `app()->terminating()`
  阶段采集**最终响应结果**，随后向 `database` 通道写入一条「请求 → 响应」汇总日志。
  同时会把数据同步进 Laravel 13 的 `Context` 门面（`Context::add`），供其它通道共享。
- **`RequestContextProcessor`**：由 `DatabaseLogTap` 挂到通道上，在每条日志记录写入前
  把 `LogContext` 里的请求 / 响应信息注入 `extra`，最终映射到数据表字段。
- 轻量请求信息**每条日志都附带**（便于溯源）；完整响应体**仅汇总日志写入**（避免重复存储）。

### 3. 落库处理器 `DatabaseLogHandler`

继承 `Monolog\Handler\AbstractProcessingHandler`，负责：

- 使用 `LogRecord->level->name` / `level_name`（Monolog 3 枚举，避免对 `level` 重新赋值）；
- 请求内去重；
- 敏感字段脱敏；
- 剔除 `Throwable` / 对象（无法 JSON 序列化）；
- 任何异常都吞掉，不影响主流程。

---

## 五、后台检索界面

默认在 `{prefix}`（默认 `/logs`）提供：

- `GET  /logs` —— 列表（按等级 / 通道 / 关键字筛选、分页）
- `GET  /logs/{id}` —— 详情（请求、响应、异常、原始 context/extra 完整展开）
- `DELETE /logs/{id}` —— 删除单条
- `POST /logs/clean` —— 按保留天数清理过期日志

> 生产环境建议为该前缀追加认证中间件。可在 `config('logging.db.viewer_middleware')`
> 中配置（默认 `['web']`），或设置 `LOG_DB_VIEWER_ENABLED=false` 彻底关闭。

---

## 六、清理命令

```bash
php artisan logs:clean                 # 删除 30 天前的日志（默认保留天数）
php artisan logs:clean --days=7        # 仅保留最近 7 天
php artisan logs:clean --level=error   # 仅清理 error 等级
php artisan logs:clean --dry-run       # 仅预览，不实际删除
```

---

## 七、编程方式读取日志

```php
use zxf\Modules\Logging\Models\SystemLog;

// 最近 7 天的错误日志
$errors = SystemLog::errors()->recent(7)->latest()->get();

// 某次请求产生的全部日志（按路径 / IP 检索）
$byPath = SystemLog::where('request_path', '/api/orders')->get();

// 某异常类相关日志
$byEx = SystemLog::where('exception_class', 'App\Exceptions\FooException')->get();
```

模型已内置作用域：`scopeLevel`、`scopeOfChannel`、`scopeRecent`、`scopeErrors`。

---

## 八、进阶配置

### 自定义表名 / 模型

通过 `handler_with` 覆盖（在应用 `config/logging.php` 的 `channels.database` 中）：

```php
'handler_with' => [
    'model' => \App\Models\MyLog::class,
    'table' => 'my_logs',
],
```

### 关闭请求体 / 响应体采集（节省空间）

```php
'handler_with' => [
    'captureRequestBody' => false,
    'captureResponseBody' => false,
],
```

### 仅记录错误级别以上

`.env`：`LOG_LEVEL=error`

### 关闭查看器

`.env`：`LOG_DB_VIEWER_ENABLED=false`

---

## 九、与 Laravel 13 日志体系的关系

- Laravel 11+ 引入的 **`Context` 门面**（请求 / 任务 / 命令级上下文）被本功能用于共享
  请求 / 响应上下文；但本功能自维护 `LogContext` 以保证跨版本行为一致与字段精确控制。
- 底层依旧是 Monolog 3 + `AbstractProcessingHandler`，与 Laravel 的 `single` / `daily` /
  `stack` 等通道完全兼容，可随意组合（例如 `stack` 同时写文件与数据库）。
- 配置加载兼容 `config:cache`（即使配置被缓存，`LoggingServiceProvider` 也会强制确保
  `database` 通道存在）。
