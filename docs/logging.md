# 数据库日志驱动（database channel）使用文档

> 本功能随 `zxf/modules` 包一同提供，命名空间为 `zxf\Modules\Logging`。
> 它实现了一个**轻量且专注**的「数据库日志驱动」：开箱即可把 Laravel 全站日志
> （包含每次 HTTP 请求自动产生的「请求 → 响应」汇总日志）写入 `logs` 数据表，
> 字段齐全、信息丰富。**本模块只负责采集与落库日志，不提供任何后台管理界面**
> （查看 / 检索 / 删除等控制器功能已移除），过期日志的清理交由业务系统按需基于 `Log` 模型自行处理。

---

## 一、功能特性

- **零配置启用**：`.env` 中设置 `LOG_CHANNEL=database` 即可，无需改动应用代码。
- **完整的请求上下文**：方法、完整 URL、路径、路由名、控制器@方法、IP、User-Agent、
  来源 Referer、Session ID、认证用户（id / 类型）、请求入参（**敏感字段自动脱敏**）、请求头。
- **完整的响应结果（Laravel 13+ 重点）**：HTTP 状态码、状态短语、请求耗时（ms）、
  峰值内存（MB）、响应头（敏感头脱敏）、响应体（仅文本类，截断至 2 万字符）。
- **异常全链路**：异常类名、消息、文件:行、完整堆栈，并自动与触发它的请求/响应关联。
- **安全**：密码 / token / secret / Authorization / Cookie 等敏感信息强制 `***`。
- **去重**：单次请求内相同异常只落库一次，避免刷屏。
- **健壮**：写库异常被静默吞掉，**绝不影响主业务流程**。
- **友好检索**：落库表 `logs` 与模型 `zxf\Modules\Logging\Models\Log` 公开可用，可直接查询或接入外部日志平台。

---

## 二、安装与启用

### 1. 发布迁移并建表

```bash
# 发布迁移文件到 database/migrations
php artisan vendor:publish --tag=modules-logging

# 执行迁移，创建 logs 表
php artisan migrate
```

> 迁移文件位于 `src/Logging/Database/Migrations/`，兼容 MySQL / PostgreSQL / SQLite。

### 2. 设置 .env

```dotenv
LOG_CHANNEL=database

# 可选：控制最低记录等级（默认 debug）
LOG_LEVEL=debug

# 可选：是否允许 CLI 模式下的日志也写入数据库（默认 false）
LOG_DB_LOG_CLI=false

# 可选：响应体最大存储字符数（中间件采集响应体时按此截断，默认 20000）
LOG_DB_MAX_BODY_LENGTH=20000

# 可选：追加的敏感字段键名（会与内置默认名单合并，而非覆盖），例如：
# LOG_DB_SENSITIVE_KEYS=pass,credit_card,id_card
```

> CLI 日志默认不落库（无请求上下文）。若需记录，可设 `LOG_DB_LOG_CLI=true` 全局开启，
> 或在单条日志上携带 `Log::info('...', ['console_to_log' => true])` 精准开启。

完成。此后所有 `Log::info()` / `Log::error()` 以及**每次 HTTP 请求的访问汇总**
都会写入 `logs` 表。

---

## 三、数据表字段说明（logs）

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

- 使用 `$record['level_name']`（Monolog 3 数组访问，返回大写等级名如 ERROR，避免对 `level` 重新赋值）；
- 请求内精确去重（去重集合存放于 `LogContext`，随每次请求 `reset()` 清空，Octane / Swoole 常驻进程下也不会跨请求串味）；
- **敏感字段脱敏**：对 `password` / `token` / `secret` / `authorization` / `api_key` / `access_token` /
  `client_secret` / `private_key` / `csrf_token` 等键名做键名脱敏，并对 `message` / `response_body`
  等自由文本做正则脱敏（命中 `"password":"..."` 形态即替换为 `***`），防止密码等明文落库；
- 剔除 `Throwable` / 对象（无法 JSON 序列化）；
- **异常双写**：当某条日志承载异常（`context.exception` 为 `Throwable`）时，除落库外，额外写入 Laravel
  默认的 `stack` **文件**通道（`Log::channel('stack')`），实现「数据库 + 文件」双保险，便于事后排查；
- 任何异常都吞掉，不影响主流程。

---

## 五、设计定位：只记录，不提供管理界面

本模块**只负责采集与落库日志信息**，刻意不提供任何后台管理界面（列表 / 详情 / 删除 / 检索等
控制器功能均已移除）。理由：

- 日志的查看、检索、审计通常由业务系统各自的后台 / 日志平台承担，重复造一个查看器既冗余
  又易引入越权风险；
- 落库表 `logs` 与模型 `zxf\Modules\Logging\Models\Log` 仍公开可用，需要检索时直接用模型查询
  （见下节）或接入 ELK / Grafana 等外部系统即可。

过期日志的清理不在本模块范围内，业务系统可基于 `Log` 模型按需实现（如定时任务批量删除）。

---

## 六、与 Laravel `report()` 集成（异常日志也能落库）

本模块自动监听日志事件：当应用代码中调用 Laravel 原生的 `report($e)`（或任何携带
`exception` 的日志）时，只要 database 通道已配置，该异常也会被写入 `logs` 表，从而能被后台
datatable 检索到。

```php
use Throwable;

try {
    // ... 业务代码
} catch (Throwable $e) {
    report($e);   // 异常会被自动记录到 database 通道（logs 表），不影响主流程
}
```

行为要点：
- 仅当日志 `context` 中携带 `exception`、且来源通道不是 `database` 时，才会额外写入，避免重复。
- 若 `LOG_CHANNEL=database`（默认通道已是 database），`report($e)` 已直接落库，不会重复。
- 若默认 `stack` 通道已包含 `database`，同样已落库，不会重复。
- 以原始等级（error / critical 等）转发，保留等级信息。
- CLI 模式（队列 / 命令）下 database 通道默认不落库；如需记录，设置 `LOG_DB_LOG_CLI=true`
  或在该条日志携带 `context['console_to_log'] = true`。

---

## 七、编程方式读取日志

```php
use zxf\Modules\Logging\Models\Log;

// 最近 7 天的错误日志
$errors = Log::errors()->recent(7)->latest()->get();

// 某次请求产生的全部日志（按路径 / IP 检索）
$byPath = Log::where('request_path', '/api/orders')->get();

// 某异常类相关日志
$byEx = Log::where('exception_class', 'App\Exceptions\FooException')->get();
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

### 异步队列落库（高并发优化）

`.env`：`LOG_DB_QUEUE=true`

开启后，日志写入由队列 worker（`php artisan queue:work`）在后台完成，请求主链路不再阻塞于
数据库 I/O，显著提升高并发接口吞吐。需配置可用的队列连接（如 `QUEUE_CONNECTION=redis`）；
若仍使用默认 `sync` 驱动，则等价于同步写入，行为不变。切换对业务代码与落库核心逻辑完全透明。

---

## 九、与 Laravel 13 日志体系的关系

- Laravel 11+ 引入的 **`Context` 门面**（请求 / 任务 / 命令级上下文）被本功能用于共享
  请求 / 响应上下文；但本功能自维护 `LogContext` 以保证跨版本行为一致与字段精确控制。
- 底层依旧是 Monolog 3 + `AbstractProcessingHandler`，与 Laravel 的 `single` / `daily` /
  `stack` 等通道完全兼容，可随意组合（例如 `stack` 同时写文件与数据库）。
- 配置加载兼容 `config:cache`（即使配置被缓存，`LoggingServiceProvider` 也会强制确保
  `database` 通道存在）。

---

## 十、架构设计

本模块刻意保持「薄而专一」：只负责**采集 + 脱敏 + 落库**，不涉及任何后台管理界面。
核心组件与数据流如下：

```
HTTP 请求
   │
   ▼
LogContextMiddleware（web / api 组，仅 HTTP）
   ├─ 请求开始：采集请求上下文 → LogContext（请求级单例）+ Laravel Context 门面
   └─ 响应终止（terminating）：采集响应上下文 → LogContext
        └─ 写入一条「请求 → 响应」汇总日志到 database 通道
   │
   ▼
database 通道（Monolog 3）
   ├─ DatabaseLogTap（仅 Web 挂载）→ RequestContextProcessor
   │     把 LogContext 的请求 / 响应上下文注入每条日志的 extra
   ▼
DatabaseLogHandler::write(LogRecord)
   ├─ ① 去重：相同「level|message|exception」单次请求内只落库一次
   │        （去重集合存于 LogContext，随每次请求 reset，避免 Octane 跨请求串味）
   ├─ ② 归一化：buildAttributes() 把 LogRecord 映射为 logs 表字段数组
   ├─ ③ 脱敏：Redactor（全模块唯一事实来源）统一处理
   │        - 键名脱敏（password / token / secret … → ***）
   │        - 自由文本脱敏（"key":"value" 形态）
   │        - 请求 / 响应头脱敏（Authorization / Cookie / CSRF …）
   ├─ ④ 落库：委托 LogWriter 抽象（默认 DatabaseLogWriter；开启 logging.db.queue 后切换为
   │        QueuedDatabaseLogWriter，由队列 worker 异步写库，剥离请求主链路 I/O）
   │        - 落库失败 → 回退写入 stack 文件通道，避免日志丢失
   └─ ⑤ 事件：每次成功落库派发 LogRecorded 事件（默认无监听，可用于告警 / 同步 / 审计）
```

### 关键设计决策

- **Redactor 单一事实来源**：敏感键名原本散落在 handler（两套常量）与 middleware（两套方法）
  共三处，极易不一致。现收敛到 `Support/Redactor` 一处，handler 与 middleware 共用；
  内置 `DEFAULT_KEYS` 永远生效，应用配置只做【合并】不做【覆盖】，确保核心密钥不会被误放行。
  正则模式在构造时预编译一次，避免每条日志重复拼接。
- **LogWriter 抽象 + 队列可选**：落库动作与具体存储解耦。`DatabaseLogHandler` 依赖
  `Contracts/LogWriter` 接口，默认 `Writer/DatabaseLogWriter`（Eloquent，同步）。设置
  `logging.db.queue = true` 后自动切换为 `Writer/QueuedDatabaseLogWriter`，由 `Jobs/WriteLogJob`
  在队列 worker 中真正写库，把数据库 I/O 从请求主链路剥离（未配置真实队列时退化为同步，行为不变）。
  需要写入其它存储时，实现该接口并替换绑定即可，handler 本身无需改动；单元测试也可注入假实现。
- **RedactorContract 契约**：脱敏器现在是 `Contracts/RedactorContract` 接口的具体实现
  `Support/Redactor`，便于替换为接入密钥管理系统的自定义脱敏策略，而不动 handler / middleware。
- **LoggingConfig 配置对象**：把散落的 `config('logging.db.*')` 与 `handler_with.*` 读取收敛为
  单一强类型 DTO（`Config/LoggingConfig`），各组件统一读取，避免口径不一致、重复解析；
  且在缺少 Laravel 环境（如纯单元测试）时自动回退默认配置，可独立测试。
- **LogRecorded 事件**：每次成功落库后派发，默认无任何监听器；业务系统可借此实现「入库即告警」
  等解耦的下游能力，而不污染落库核心逻辑。同步 / 异步两条写入路径都会派发，事件语义一致。
- **关注点分离**：`DatabaseLogHandler` 只做「去重 / 归一化 / 落库 / 派发事件」，不再内联脱敏与持久化细节，
  单个类职责清晰、易测试。异常日志的「文件落盘」由 Laravel 默认通道（report($e) / stack）负责，
  本 handler 不再二次写 stack，避免与默认通道重复；仅落库失败时回退 stack 文件。
- **健壮性**：任何写库 / 序列化异常都被捕获；数据库不可用时仍有 stack 文件通道兜底，绝不反向影响主流程。
- **常驻进程安全**：所有请求级状态集中在 `LogContext`，由中间件在每次请求开始时 `reset()`，
  在 Laravel Octane / Swoole 下不会跨请求串味。
- **可检索性**：`logs` 表已为 `level_name`、`channel`、`created_at` 及其组合建立索引；
  检索时直接使用 `zxf\Modules\Logging\Models\Log` 模型或接入 ELK / Grafana 等外部系统。
