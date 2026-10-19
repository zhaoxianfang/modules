<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Writer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;
use zxf\Modules\Logging\Models\Log;

/**
 * 日志模型实例工厂（同步写入 / 队列写入 / Job 三条路径共用唯一实现）。
 *
 * 解决的问题：
 *  1. **属性与表结构不匹配导致整条日志丢失**：新增列（如 bot_*）后，旧安装的 logs 表
 *     尚未升级时，`fill()` 会因未知属性静默丢弃，或 `save()` 直接抛 SQL 错误。
 *     这里在写入前按「真实表列」过滤属性，未升级的列自动跳过，且不影响其余字段落库。
 *  2. **自定义模型的 $fillable 不含日志字段**：改用 forceFill()（日志数据由内部处理器生成，
 *     属于可信来源），避免静默全量丢弃。
 *  3. **每条日志都查一次 information_schema**：按表名静态缓存列清单，每进程每表只查一次。
 *
 * 列清单获取失败（无数据库连接 / 权限不足 / SQLite 旧版本等）时返回 null，
 * 此时不做列过滤，交给模型/数据库自行处理（不会因探测失败而丢日志）。
 */
final class LogRecordFactory
{
    /**
     * 表列清单缓存
     *
     * 结构：表名 → ['columns' => array<int,string>|null, 'expires_at' => int]
     * columns 为 null 表示探测失败（不过滤）
     *
     * @var array<string, array{columns: array<int, string>|null, expires_at: int}>
     */
    private static array $columns = [];

    /**
     * 表存在性缓存（与列清单同 TTL），供 DatabaseLogHandler 复用。
     *
     * 原先 handler 与本类各自调 Schema::hasTable —— 同一次请求里对同一张表
     * 重复探测两遍。收敛到这里的唯一入口后，一次 TTL 周期内只发一次查询。
     *
     * 注意：**探测失败（抛异常）一律不写缓存**，只把失败频率限制交给
     * DatabaseLogHandler 的短 TTL 负缓存处理，避免"缓存住 false 导致长期静默"。
     *
     * @var array<string, array{exists: bool, expires_at: int}>
     */
    private static array $exists = [];

    /** TTL 本身也缓存（只解析一次 config()） */
    private static ?int $cacheTtl = null;

    /**
     * 依据属性数组构造一个「已填充」的日志模型实例。
     *
     * @param  array<string, mixed>  $attributes  与 logs 表字段对应的属性
     * @param  string|null  $model  自定义模型类（null 使用内置 Log）
     * @param  string|null  $table  自定义表名（null 使用模型默认表）
     */
    public static function make(array $attributes, ?string $model = null, ?string $table = null): ?Model
    {
        $modelClass = ($model !== null && class_exists($model)) ? $model : Log::class;

        /** @var Model $instance */
        $instance = new $modelClass;
        if ($table !== null) {
            $instance->setTable($table);
        }

        $columns = self::columns($instance->getTable());

        if ($columns !== null) {
            $attributes = array_intersect_key($attributes, array_flip($columns));
        }

        // 日志数据由内部处理器生成（可信来源），使用 forceFill 规避自定义模型 $fillable 限制
        $instance->forceFill($attributes);

        return $instance;
    }

    /**
     * 获取表列清单（按表名缓存）。
     *
     * @return array<int, string>|null null 表示探测失败，调用方应跳过列过滤
     */
    public static function columns(string $table): ?array
    {
        if ($table === '') {
            return null;
        }

        // 在 php-fpm 下每请求新进程，缓存自然失效，没问题；
        // 但在 Octane / queue worker / supervisor 这类**长驻进程**里，
        // 进程启动时缓存过一次列清单后就再也不变了 ——
        // 此时执行迁移新增一列，这些新列会被列过滤静默丢掉
        // （不报错、不落库，极难发现），日志内容凭空缺字段。
        //
        // 现在给缓存加 TTL：超过配置时长（默认 300 秒）自动重新探测。
        // 每条日志一次时间比较，开销可忽略；最坏情况下多一次
        // information_schema 查询。
        $cached = self::$columns[$table] ?? null;

        if ($cached !== null) {
            $expiresAt = $cached['expires_at'] ?? 0;

            if ($expiresAt > time()) {
                return $cached['columns'];
            }
        }

        $columns = null;
        try {
            if (class_exists(Schema::class) && self::tableExists($table)) {
                $listing = Schema::getColumnListing($table);
                $columns = is_array($listing) ? $listing : null;
            }
        } catch (Throwable) {
            $columns = null;
        }

        self::$columns[$table] = [
            'columns' => $columns,
            'expires_at' => time() + self::cacheTtl(),
        ];

        return $columns;
    }

    /**
     * 表是否存在（按表名缓存，成功结果才缓存）。
     *
     * 与 {@see self::columns()} 共用同一份列/表缓存语义，取代原先在 handler 与本类
     * 里各自发起的 Schema::hasTable() 重复探测。
     *
     * 注意：本方法**不吞异常** —— 探测异常直接向上抛，由调用方决定失败后的行为：
     *  - {@see self::columns()} 自己有 try/catch，异常时按"不做列过滤"处理；
     *  - DatabaseLogHandler 据此建立短 TTL 负缓存，避免每条日志都重试一次连接。
     * 两种失败路径都刻意【不写缓存】，避免数据库恢复后仍长期静默跳过日志。
     */
    public static function tableExists(string $table): bool
    {
        if ($table === '') {
            return false;
        }

        $cached = self::$exists[$table] ?? null;
        if ($cached !== null && $cached['expires_at'] > time()) {
            return $cached['exists'];
        }

        if (! class_exists(Schema::class)) {
            return false;
        }

        $exists = Schema::hasTable($table);

        self::$exists[$table] = ['exists' => $exists, 'expires_at' => time() + self::cacheTtl()];

        return $exists;
    }

    /**
     * 列清单缓存存活时长（秒）
     *
     * 可通过 config('logging.db.column_cache_ttl', 300) 调整；
     * 设为 0 表示缓存永不过期，只有调用 flushCache() 才失效。
     */
    private static function cacheTtl(): int
    {
        // TTL 本身只解析一次：它是进程级常量性的配置，
        // 每条日志都走一次 config() 在高 QPS 下是不必要的容器解析开销。
        if (self::$cacheTtl !== null) {
            return self::$cacheTtl;
        }

        $ttl = 300;
        try {
            if (function_exists('config') && function_exists('app') && app()->bound('config')) {
                $ttl = max(0, (int) config('logging.db.column_cache_ttl', 300));
            }
        } catch (Throwable) {
            // 容器不可用：用默认值
        }

        return self::$cacheTtl = $ttl;
    }

    /**
     * 清空列清单缓存（迁移 / 测试改表结构后调用，使新列立即生效）。
     */
    public static function flushCache(): void
    {
        self::$columns = [];
        self::$exists = [];
        self::$cacheTtl = null;
    }
}
