# 性能优化指南

本文档介绍 `zxf/modules` 扩展包提供的性能优化功能和最佳实践。

## 概述

本扩展包针对 Laravel 11+ 进行了全面的性能优化，包括：

- 多级缓存系统（内存缓存、持久化缓存、编译缓存）
- 查询优化（索引提示、批量操作、窗口函数）
- 内存管理（垃圾回收、对象池、流式处理）
- 性能监控（查询分析、慢查询检测、性能报告）

## 缓存系统

### 模块缓存

扩展包提供三层缓存机制：

#### 1. 运行时内存缓存

同一请求内共享数据，自动清理：

```php
// 自动启用，无需配置
$modules = app('modules');  // 从内存缓存读取
```

#### 2. Laravel 持久化缓存

跨请求缓存，支持 Redis、Memcached 等：

```php
// 配置缓存驱动（config/modules.php）
'cache' => [
    'enabled' => true,
    'driver' => 'redis',  // 或 'file', 'database'
    'ttl' => 3600,  // 缓存时间（秒）
],
```

#### 3. 编译缓存（生产环境推荐）

将模块配置编译为单一 PHP 文件，利用 OPcache：

```php
// 启用编译缓存
'cache' => [
    'compiled' => true,
    'compiled_path' => storage_path('framework/modules'),
],

// 生成编译缓存
php artisan modules:compile
```

### 缓存管理命令

```bash
# 清除模块缓存
php artisan modules:clear-cache

# 预热缓存
php artisan modules:warm

# 编译模块（生产环境）
php artisan modules:compile
```

## 查询优化

### 索引优化

```php
use Illuminate\Support\Facades\DB;

// 强制使用索引
$users = DB::table('users')
    ->forceIndex('idx_email_status')
    ->where('email', 'like', '%@example.com')
    ->where('status', 'active')
    ->get();

// 建议索引
$orders = DB::table('orders')
    ->useIndex('idx_created_at')
    ->where('created_at', '>', now()->subDays(7))
    ->get();

// 忽略废弃索引
$products = DB::table('products')
    ->ignoreIndex('idx_old_column')
    ->get();
```

### 批量操作

```php
// 批量插入（比单条插入快 10-100 倍）
$users = [];
for ($i = 0; $i < 10000; $i++) {
    $users[] = [
        'name' => "User {$i}",
        'email' => "user{$i}@example.com",
    ];
}

DB::table('users')->insertIgnore($users);

// 插入或更新
DB::table('stats')->upsert(
    $dailyStats,           // 数据数组
    ['date', 'category'],  // 唯一键
    ['views', 'clicks']    // 更新字段
);

// 批量更新
$updates = [
    1 => ['status' => 'active', 'updated_at' => now()],
    2 => ['status' => 'inactive', 'updated_at' => now()],
    // ...
];

DB::table('users')->batchUpdate('users', 'id', $updates, 500);
```

### 窗口函数优化

使用窗口函数替代自连接和子查询：

```php
// ❌ 低效：自连接
$topUsers = DB::table('users as u1')
    ->whereNotExists(function ($q) {
        $q->from('users as u2')
            ->whereColumn('u2.score', '>', 'u1.score')
            ->whereRaw('u2.score - u1.score <= 10');
    })
    ->get();

// ✅ 高效：窗口函数
$topUsers = DB::table('users')
    ->withRowNumber('rank', null, 'score DESC')
    ->where('rank', '<=', 10)
    ->get();
```

### 分页优化

```php
// ❌ 低效：大偏移量分页
$page1000 = DB::table('logs')
    ->orderBy('id')
    ->offset(100000)
    ->limit(20)
    ->get();

// ✅ 高效：游标分页
$page1000 = DB::table('logs')
    ->where('id', '>', $lastId)
    ->orderBy('id')
    ->limit(20)
    ->get();

// ✅ 或使用窗口函数
$page1000 = DB::table('logs')
    ->withRowNumber('row_num', null, 'id')
    ->whereBetween('row_num', [100001, 100020])
    ->get();
```

## 内存管理

### 大数据集处理

```php
use zxf\Modules\Support\Performance\MemoryManager;

// 方式1：分块处理（控制内存使用）
MemoryManager::batchProcess(
    $largeDataset,
    function ($item) {
        process($item);
    },
    1000,  // 每批处理 1000 条
    100    // 每 100 条检查一次内存
);

// 方式2：流式处理（最小内存占用）
MemoryManager::streamProcess($largeDataset, function ($item) {
    process($item);
});

// 方式3：生成器包装
foreach (MemoryManager::lazy($largeDataset) as $item) {
    process($item);
}
```

### 对象池

重用对象减少内存分配：

```php
// 从对象池获取
$processor = MemoryManager::acquire(DataProcessor::class, ['config' => $config]);

try {
    $processor->process($data);
} finally {
    // 归还到对象池
    MemoryManager::release($processor);
}
```

### 内存监控

```php
// 标记内存检查点
MemoryManager::mark('start');
processLargeData();
MemoryManager::mark('after_processing');

// 获取内存报告
$report = MemoryManager::getReport();
// [
//     'start' => ['usage_mb' => 15.2, 'peak_mb' => 15.5],
//     'after_processing' => ['usage_mb' => 128.5, 'delta_mb' => 113.3],
// ]

// 检查内存是否充足
if (! MemoryManager::hasEnoughMemory(256)) {
    // 执行垃圾回收
    MemoryManager::gc(true);
}
```

## 性能监控

### 基础监控

```php
use zxf\Modules\Support\Performance\PerformanceMonitor;

// 启用监控
PerformanceMonitor::enable();

// 监控代码块
$result = PerformanceMonitor::measure('heavy_operation', function () {
    return performHeavyOperation();
});

// 停止计时
PerformanceMonitor::startTimer('database_query');
// ... 执行查询
$metric = PerformanceMonitor::stopTimer('database_query');
```

### 自定义指标

```php
// 记录自定义指标
PerformanceMonitor::record('cache_hit_rate', 95.5, 'percent', ['region' => 'us-east']);
PerformanceMonitor::record('queue_size', 150, 'count');
PerformanceMonitor::record('api_latency', 45, 'ms', ['endpoint' => '/api/users']);
```

### 性能报告

```php
// 获取完整报告
$report = PerformanceMonitor::generateReport();
// [
//     'enabled' => true,
//     'summary' => [
//         'total_operations' => 150,
//         'slow_operations' => 3,
//         'avg_duration_ms' => 25.5,
//     ],
//     'slow_operations' => [...],
//     'memory' => [
//         'current_mb' => 64.5,
//         'peak_mb' => 128.2,
//     ],
// ]

// 获取慢操作
$slowOps = PerformanceMonitor::getSlowOperations();

// 按名称统计
$summary = PerformanceMonitor::getSummary();
// [
//     'by_name' => [
//         'database_query' => ['count' => 50, 'avg_ms' => 15.2],
//         'api_call' => ['count' => 30, 'avg_ms' => 45.8],
//     ],
// ]
```

## 查询分析

### 自动分析

```php
use zxf\Modules\Support\Performance\QueryOptimizer;

// 启用查询分析
QueryOptimizer::enableAnalysis();

// 分析查询
$analysis = QueryOptimizer::analyzeQuery(
    'SELECT * FROM users WHERE email = ?',
    ['user@example.com']
);

// 获取优化建议
$suggestions = $analysis['suggestions'];
// [
//     '考虑添加索引以避免全表扫描',
//     '考虑添加排序索引以优化 filesort',
// ]
```

### 慢查询检测

```php
// 设置慢查询阈值
QueryOptimizer::setSlowQueryThreshold(500);  // 500ms

// 自动记录慢查询
DB::listen(function ($query) {
    QueryOptimizer::logQuery($query->sql, $query->time);
});

// 获取慢查询
$slowQueries = QueryOptimizer::getSlowQueries();
```

## 配置优化

### 推荐配置

```php
// config/modules.php
return [
    // 性能配置
    'performance' => [
        // 延迟加载
        'lazy_loading' => true,

        // 批量注册
        'batch_register' => true,

        // 缓存类检查
        'cache_class_checks' => true,

        // 预加载服务
        'preload_services' => [
            // 高频使用的服务
            App\Services\CacheService::class,
            App\Services\AuthService::class,
        ],
    ],

    // 缓存配置
    'cache' => [
        // 启用缓存
        'enabled' => env('MODULES_CACHE_ENABLED', true),

        // 运行时内存缓存
        'runtime' => true,

        // 静态缓存
        'static' => true,

        // 编译缓存（生产环境）
        'compiled' => env('MODULES_CACHE_COMPILED', false),
        'compiled_path' => storage_path('framework/modules'),

        // 缓存驱动
        'driver' => env('MODULES_CACHE_DRIVER', config('cache.default')),

        // 缓存时间
        'ttl' => 3600,

        // 发现缓存
        'discovery' => true,
    ],

    // 自动发现配置
    'auto_discovery' => [
        // 启用自动发现
        'enabled' => true,

        // 延迟发现
        'lazy' => true,

        // 发现项
        'items' => [
            'providers' => true,
            'commands' => true,
            'routes' => true,
            'configs' => true,
            'views' => true,
            'migrations' => true,
            'translations' => true,
            'factories' => true,
            'middleware' => false,  // 延迟加载
            'events' => false,      // 延迟加载
            'observers' => false,   // 延迟加载
            'policies' => false,    // 延迟加载
        ],
    ],
];
```

### 环境配置

```env
# 开发环境
MODULES_CACHE_ENABLED=false
MODULES_CACHE_COMPILED=false

# 生产环境
MODULES_CACHE_ENABLED=true
MODULES_CACHE_COMPILED=true
MODULES_CACHE_DRIVER=redis
```

## 最佳实践

### 1. 生产环境部署

```bash
# 1. 优化 Composer 自动加载
composer install --optimize-autoloader --no-dev

# 2. 缓存配置
php artisan config:cache

# 3. 缓存路由
php artisan route:cache

# 4. 编译模块
php artisan modules:compile

# 5. 预热缓存
php artisan modules:warm
```

### 2. 数据库优化

```php
// 使用连接池（如果使用 Swoole/RoadRunner）
'database' => [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            // ...
            'pool' => [
                'min_connections' => 5,
                'max_connections' => 100,
            ],
        ],
    ],
],

// 启用查询缓存（Redis）
'database' => [
    'redis' => [
        'options' => [
            'prefix' => 'db:',
        ],
    ],
],
```

### 3. 模块设计

```php
// 延迟加载服务提供者
class MyModuleServiceProvider extends ServiceProvider
{
    // 只在需要时注册
    public function register(): void
    {
        $this->app->singleton(HeavyService::class, function () {
            return new HeavyService();
        });
    }

    // 延迟引导
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // 只在控制台模式下加载命令
            $this->commands([
                MyCommand::class,
            ]);
        }
    }
}
```

### 4. 查询优化

```php
// 使用选择子集
$users = User::select('id', 'name', 'email')  // 避免 SELECT *
    ->with('profile:id,user_id,bio')  // 选择关联字段
    ->get();

// 使用游标（大数据集）
User::query()->cursor()->each(function ($user) {
    process($user);
});

// 使用惰性集合
User::query()->lazy()->each(function ($user) {
    process($user);
});
```

## 性能基准

以下是典型场景的性能对比：

| 操作 | 优化前 | 优化后 | 提升 |
|------|--------|--------|------|
| 模块扫描 | 50-100ms | 1-5ms | 90%+ |
| 批量插入 10K | 30s | 2s | 15x |
| 大偏移分页 | 5s | 50ms | 100x |
| 窗口函数 Top N | 2s | 100ms | 20x |
| 内存占用（大数据集） | 512MB | 64MB | 8x |

## 故障排除

### 内存不足

```php
// 增加内存限制
MemoryManager::setMemoryLimit(1024);  // 1GB

// 启用自动垃圾回收
MemoryManager::setAutoGc(true);
MemoryManager::setGcThreshold(256);  // 256MB 触发 GC
```

### 缓存未命中

```php
// 检查缓存配置
php artisan tinker
>>> config('modules.cache')

// 清除并重新生成
php artisan modules:clear-cache
php artisan modules:warm
```

### 慢查询

```php
// 启用查询日志
DB::enableQueryLog();

// 执行查询后
$queries = DB::getQueryLog();
foreach ($queries as $query) {
    if ($query['time'] > 100) {
        logger()->warning('Slow query', $query);
    }
}
```

## 参考

- [Laravel Performance Optimization](https://laravel.com/docs/11.x/telescope)
- [MySQL 8.4 Performance Schema](https://dev.mysql.com/doc/refman/8.4/en/performance-schema.html)
- [Redis Best Practices](https://redis.io/docs/management/optimization/)
