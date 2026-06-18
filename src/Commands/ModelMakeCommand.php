<?php

declare(strict_types=1);

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;

/**
 * 创建模型命令
 *
 * 在指定模块中创建 Eloquent 模型类，支持以下核心功能：
 * - 从现有数据库表自动解析字段信息（兼容 MySQL / PostgreSQL / SQLite）
 * - 自动生成 @property PHPDoc 属性注释（包括字段注释、类型、可空性）
 * - 自动生成 $fillable 属性（排除主键、时间戳、自动递增字段）
 * - 自动生成 casts() 方法（date/datetime 使用 Carbon 转换）
 * - 自动生成 $attributes 属性（数据库默认值映射）
 * - 自动检测软删除（deleted_at）、时间戳字段
 * - 可选创建对应的迁移文件（--migration）和数据工厂（--factory）
 * - 支持覆盖已存在文件（--force）
 *
 * 使用示例：
 *   1. 从表生成模型：php artisan module:make-model Logs User --table=users
 *   2. 创建新模型和迁移：php artisan module:make-model Blog Post --migration
 *   3. 覆盖已存在的模型：php artisan module:make-model Blog User --force
 *
 * @package zxf\Modules\Commands
 */
class ModelMakeCommand extends Command
{
    /**
     * 数据库字段类型到 PHP / Eloquent 类型映射
     *
     * 覆盖各大数据库引擎的通用字段类型。
     *
     * 分类：
     * - 整数类型: int, integer, tinyint, smallint, mediumint, bigint
     * - 浮点类型: float, double, decimal
     * - 字符串类型: char, varchar, text, tinytext, mediumtext, longtext
     * - 二进制类型: binary, varbinary, blob, tinyblob, mediumblob, longblob
     * - 日期时间类型: date, datetime, timestamp, time, year
     * - JSON 类型: json, jsonb
     * - 布尔类型: boolean, bool
     * - 枚举类型: enum, set
     * - UUID 类型: uuid
     *
     * @var array<string, string>
     */
    protected array $typeMapping = [
        // 整数类型
        'int' => 'integer', 'integer' => 'integer', 'tinyint' => 'integer',
        'smallint' => 'integer', 'mediumint' => 'integer', 'bigint' => 'integer',
        'bit' => 'boolean',

        // 浮点类型
        'float' => 'float', 'double' => 'float', 'decimal' => 'decimal',

        // 字符串类型
        'char' => 'string', 'varchar' => 'string', 'text' => 'string',
        'tinytext' => 'string', 'mediumtext' => 'string', 'longtext' => 'string',

        // 二进制类型
        'binary' => 'binary', 'varbinary' => 'binary',
        'blob' => 'binary', 'tinyblob' => 'binary',
        'mediumblob' => 'binary', 'longblob' => 'binary',

        // 日期时间类型
        'date' => 'date', 'datetime' => 'datetime',
        'timestamp' => 'datetime', 'time' => 'string', 'year' => 'integer',

        // JSON 类型
        'json' => 'array', 'jsonb' => 'array',

        // 布尔类型
        'boolean' => 'boolean', 'bool' => 'boolean',

        // 枚举类型
        'enum' => 'string', 'set' => 'array',

        // UUID
        'uuid' => 'string',
    ];

    /**
     * 时间戳相关的字段名称
     *
     * 这些字段会被检测以决定 $timestamps 属性的值，
     * 默认情况下自动排除出 $fillable 列表。
     *
     * @var array<int, string>
     */
    protected array $timestampColumns = ['created_at', 'updated_at', 'deleted_at'];

    /**
     * 命令签名
     *
     * 参数：
     * - module: 必需，目标模块名称（首字母大写，如 Blog）
     * - name: 必需，模型类名（首字母大写，如 Post）
     *
     * 选项：
     * - --table=: 从现有数据库表解析字段信息，自动生成完整模型属性
     * - --migration: 同时创建对应的数据库迁移文件
     * - --factory: 同时创建对应的数据工厂类
     * - --force: 覆盖已存在的模型文件，不提示确认
     *
     * @var string
     */
    protected $signature = 'module:make-model
                            {module : 模块名称（必需，首字母大写，例如：Blog）}
                            {name : 模型名称（必需，首字母大写，例如：Post）}
                            {--table= : 从现有数据库表生成模型，自动解析所有字段信息}
                            {--migration : 同时创建对应的数据库迁移文件}
                            {--factory : 同时创建对应的数据工厂类}
                            {--force : 覆盖已存在的模型文件（不提示确认）}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '在指定模块中创建 Eloquent 模型类，支持从数据库表自动解析字段信息';

    // ────────────────────────────────────
    //  命令入口
    // ────────────────────────────────────

    /**
     * 执行命令
     *
     * 流程：
     * 1. 解析并验证参数
     * 2. 检查模块是否存在
     * 3. 检查模型是否已存在（除非 --force）
     * 4. 从数据库表解析字段（如果指定了 --table）
     * 5. 生成模型文件内容
     * 6. 写入模型文件
     * 7. 可选：创建迁移文件、数据工厂
     *
     * @return int Command::SUCCESS 或 Command::FAILURE
     */
    public function handle(): int
    {
        $moduleName = $this->parseModuleName();
        $modelName = $this->parseModelName();

        if ($this->hasInvalidName($moduleName) || $this->hasInvalidName($modelName)) {
            $this->error('模块名称和模型名称只能包含字母、数字');
            return Command::FAILURE;
        }

        $tableName = $this->option('table') ?: Str::snake(Str::plural($modelName));
        $createMigration = $this->option('migration');
        $createFactory = $this->option('factory');
        $force = $this->option('force');

        // 校验表名合法性
        if (! $this->isValidTableName($tableName)) {
            $this->error("表名 [{$tableName}] 格式不合法");
            return Command::FAILURE;
        }

        // 验证模块是否存在
        $module = Module::find($moduleName);
        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在");
            $this->line("提示：请先创建模块，使用 php artisan module:make {$moduleName}");
            return Command::FAILURE;
        }

        $modelDir = config('modules.paths.generator.model.path', 'Models');
        $modelPath = $module->getPath($modelDir . '/' . $modelName . '.php');

        // 存在性检查
        if (File::exists($modelPath)) {
            if (! $force) {
                $this->error("模块 [{$moduleName}] 中已存在模型类 [{$modelName}]");
                $this->line("文件位置: {$modelPath}");
                $this->line('提示：使用 --force 选项覆盖已存在的模型');
                return Command::FAILURE;
            }
            $this->warn("正在覆盖模块 [{$moduleName}] 中已存在的模型类 [{$modelName}]");
        }

        $namespace = config('modules.namespace', 'Modules');

        // 从数据库表解析字段信息（跨数据库兼容）
        $columns = $this->resolveTableColumns($tableName);

        if ($columns === null) {
            // 表不存在无法获取字段
            $this->warn("未找到表 [{$tableName}]，将创建基础模型");
            $this->line('');
            $columns = [];
        } elseif (empty($columns)) {
            $this->warn("表 [{$tableName}] 无可用字段信息，将创建基础模型");
            $this->line('');
        } else {
            $this->info("已从表 [{$tableName}] 解析到 " . count($columns) . " 个字段");
            $this->line('');
        }

        // 生成模型内容
        $modelContent = $this->generateModelContent(
            $moduleName,
            $modelName,
            $namespace,
            $modelDir,
            $tableName,
            $columns
        );

        // 确保模型目录存在
        $modelBaseDir = $module->getPath($modelDir);
        if (! is_dir($modelBaseDir)) {
            File::makeDirectory($modelBaseDir, 0755, true);
        }

        // 写入模型文件
        if (! File::put($modelPath, $modelContent)) {
            $this->error("创建模型类 [{$modelName}] 失败");
            $this->line('提示：检查文件权限和磁盘空间');
            return Command::FAILURE;
        }

        $this->info("✓ 成功在模块 [{$moduleName}] 中创建模型类 [{$modelName}]");
        $this->line("模型位置: {$modelPath}");
        $this->line("数据表: {$tableName}");

        // 可选：创建迁移文件
        if ($createMigration) {
            $this->line('');
            $this->line('正在创建迁移文件...');
            $this->call('module:make-migration', [
                'module' => $moduleName,
                'name' => 'create_' . $tableName . '_table',
                '--create' => $tableName,
            ]);
        }

        // 可选：创建数据工厂
        if ($createFactory) {
            $this->line('');
            $this->line('正在创建数据工厂...');
            $this->call('module:make-seeder', [
                'module' => $moduleName,
                'name' => $modelName . 'Seeder',
            ]);
        }

        return Command::SUCCESS;
    }

    // ────────────────────────────────────
    //  输入解析与校验
    // ────────────────────────────────────

    /**
     * 解析模块名称（标准化为 StudlyCase）
     */
    protected function parseModuleName(): string
    {
        return Str::studly($this->argument('module'));
    }

    /**
     * 解析模型名称（标准化为 StudlyCase）
     */
    protected function parseModelName(): string
    {
        return Str::studly($this->argument('name'));
    }

    /**
     * 检查名称是否包含非法字符
     *
     * 允许字母、数字，排除纯符号或空字符串。
     */
    protected function hasInvalidName(string $name): bool
    {
        return $name === '' || ! preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $name);
    }

    /**
     * 校验表名格式（防止 SQL 注入和特殊字符）
     *
     * 仅允许传统数据库标识符字符：字母、数字、下划线。
     */
    protected function isValidTableName(string $tableName): bool
    {
        return preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $tableName) === 1;
    }

    // ────────────────────────────────────
    //  数据库字段解析（跨数据库兼容）
    // ────────────────────────────────────

    /**
     * 解析表的列信息（跨数据库兼容）
     *
     * 优先级：
     * 1. Laravel 10+ Schema::getColumns() — 支持 MySQL / PostgreSQL / SQLite
     * 2. MySQL 专有语法 SHOW FULL COLUMNS（回退方案）
     *
     * 返回 null 表示表不存在，返回空数组表示无字段。
     *
     * @param string $tableName 表名
     * @return array<int, array<string, mixed>>|null
     */
    protected function resolveTableColumns(string $tableName): ?array
    {
        if (! $this->isValidTableName($tableName)) {
            return null;
        }

        // 使用 Schema Builder 检查表是否存在
        try {
            if (! Schema::hasTable($tableName)) {
                return null;
            }
        } catch (\Throwable $e) {
            $this->error("无法连接数据库检查表 [{$tableName}]: " . $e->getMessage());
            return null;
        }

        // 优先使用 Laravel 10+ 原生 Schema::getColumns() — 跨数据库兼容
        try {
            if (method_exists(Schema::class, 'getColumns')) {
                return $this->resolveColumnsViaSchema($tableName);
            }
        } catch (\Throwable $e) {
            // 降级到 MySQL 专有语法
        }

        // 回退：MySQL 专有语法 SHOW FULL COLUMNS（兼容旧版 Laravel）
        return $this->resolveColumnsViaMysql($tableName);
    }

    /**
     * 通过 Schema::getColumns() 解析列信息（Laravel 10+）
     *
     * @param string $tableName
     * @return array<int, array<string, mixed>>
     */
    protected function resolveColumnsViaSchema(string $tableName): array
    {
        $schemaColumns = Schema::getColumns($tableName);
        $results = [];

        foreach ($schemaColumns as $col) {
            $results[] = [
                'name'       => $col['name'],
                'type'       => $col['type'] ?? $col['type_name'] ?? 'varchar',
                'null'       => $col['nullable'] ?? true,
                'key'        => '',
                'default'    => $col['default'] ?? null,
                'extra'      => $col['auto_increment'] ? 'auto_increment' : '',
                'comment'    => $col['comment'] ?? '',
            ];
        }

        return $results;
    }

    /**
     * 通过 MySQL SHOW FULL COLUMNS 解析列信息（回退方案）
     *
     * 注意：表名已经 isValidTableName() 校验，可直接拼接。
     *
     * @param string $tableName
     * @return array<int, array<string, mixed>>|null
     */
    protected function resolveColumnsViaMysql(string $tableName): ?array
    {
        try {
            $rawColumns = DB::select("SHOW FULL COLUMNS FROM `{$tableName}`");
        } catch (\Throwable $e) {
            $this->error("无法获取表 [{$tableName}] 的信息: " . $e->getMessage());
            return null;
        }

        $results = [];
        foreach ($rawColumns as $column) {
            $results[] = [
                'name'    => $column->Field,
                'type'    => $column->Type,
                'null'    => $column->Null === 'YES',
                'key'     => $column->Key,
                'default' => $column->Default,
                'extra'   => $column->Extra,
                'comment' => $column->Comment ?? '',
            ];
        }

        return $results;
    }

    // ────────────────────────────────────
    //  模型文件生成
    // ────────────────────────────────────

    /**
     * 生成完整的模型 PHP 文件内容
     *
     * @param string $moduleName 模块名称
     * @param string $modelName  模型类名
     * @param string $namespace  根命名空间
     * @param string $modelDir   模型子目录（如 Models）
     * @param string $tableName  数据表名称
     * @param array  $columns    列信息数组
     * @return string 模型 PHP 文件内容
     */
    protected function generateModelContent(
        string $moduleName,
        string $modelName,
        string $namespace,
        string $modelDir,
        string $tableName,
        array $columns
    ): string {
        $lines = [];

        // 文件头
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = "namespace {$namespace}\\{$moduleName}\\{$modelDir};";
        $lines[] = '';
        $lines[] = 'use Illuminate\Database\Eloquent\Model;';
        $lines[] = '';

        // PHPDoc 类注释
        $lines[] = '/**';
        $lines[] = " * {$modelName} 模型";
        $lines[] = ' *';
        $lines[] = " * {$moduleName} 模块的数据模型，对应 [{$tableName}] 表。";
        $lines[] = " * 继承 Eloquent Model，提供完整的数据库操作能力。";
        $lines[] = ' *';

        // 生成 @property 注释
        if (! empty($columns)) {
            foreach ($columns as $column) {
                $phpDocType = $this->resolvePhpDocType($column);
                $comment = $this->buildColumnComment($column);

                if (empty($comment)) {
                    $lines[] = " * @property {$phpDocType} \${$column['name']}";
                } else {
                    $lines[] = " * @property {$phpDocType} \${$column['name']} {$comment}";
                }
            }
        }

        $lines[] = ' */';
        $lines[] = "class {$modelName} extends Model";
        $lines[] = '{';
        $lines[] = '';

        // $table 属性
        $lines[] = '    /**';
        $lines[] = '     * 数据表名称';
        $lines[] = '     *';
        $lines[] = "     * 对应数据库中的 `{$tableName}` 表。";
        $lines[] = "     * 如不指定，Eloquent 会默认使用模型类名的蛇形复数形式。";
        $lines[] = '     *';
        $lines[] = '     * @var string';
        $lines[] = '     */';
        $lines[] = "    protected \$table = '{$tableName}';";
        $lines[] = '';

        // $timestamps 属性
        $hasTimestamps = $this->detectTimestamps($columns);
        $lines[] = '    /**';
        $lines[] = '     * 是否自动维护 created_at 与 updated_at 时间戳';
        $lines[] = '     *';
        $lines[] = '     * @var bool';
        $lines[] = '     */';
        $lines[] = '    public $timestamps = ' . ($hasTimestamps ? 'true' : 'false') . ';';
        $lines[] = '';

        // $fillable 属性
        $lines = array_merge($lines, $this->generateFillableSection($modelName, $columns));
        $lines[] = '';

        // casts() 方法
        $lines = array_merge($lines, $this->generateCastsSection($columns));
        $lines[] = '';

        // $attributes 属性（默认值）
        $lines = array_merge($lines, $this->generateAttributesSection($columns));

        $lines[] = '}';

        return implode("\n", $lines);
    }

    /**
     * 构建字段注释文本
     *
     * 优先级：数据库注释 > 默认值 + extra 信息
     */
    protected function buildColumnComment(array $column): string
    {
        $comment = $column['comment'] ?? '';

        if (! empty($comment)) {
            return $comment;
        }

        // 无注释时，拼接默认值和额外信息
        $parts = [];
        $default = $column['default'] ?? null;
        $extra = $column['extra'] ?? '';

        if ($default !== null && $default !== '') {
            $parts[] = "默认值: {$default}";
        }
        if (! empty($extra)) {
            $parts[] = $extra;
        }

        return implode(' ', $parts);
    }

    /**
     * 生成 $fillable 区块
     */
    protected function generateFillableSection(string $modelName, array $columns): array
    {
        $lines = [];
        $fillable = $this->collectFillableColumns($columns);

        $lines[] = '    /**';
        $lines[] = '     * 可批量赋值的属性';
        $lines[] = '     *';
        $lines[] = '     * 这些属性可通过 create()、update()、fill() 方法批量赋值。';
        $lines[] = '     * 主键（id）、时间戳字段（created_at / updated_at / deleted_at）';
        $lines[] = '     * 以及自动递增字段已自动排除，如需额外保护可使用 $guarded。';
        $lines[] = '     *';
        $lines[] = '     * @var array<int, string>';

        if (! empty($fillable)) {
            $lines[] = '     *';
            $lines[] = '     * @example 批量创建示例';
            $lines[] = '     * ```php';
            $lines[] = "     * {$modelName}::create([";
            $exampleFields = array_slice($fillable, 0, 3);
            foreach ($exampleFields as $field) {
                $lines[] = "     *     '{$field}' => '示例值',";
            }
            $lines[] = '     * ]);';
            $lines[] = '     * ```';
        }

        $lines[] = '     */';

        if (! empty($fillable)) {
            $fillableStr = "'" . implode("', '", $fillable) . "'";
            $lines[] = "    protected \$fillable = [{$fillableStr}];";
        } else {
            $lines[] = '    protected $fillable = [];';
        }

        return $lines;
    }

    /**
     * 生成 casts() 方法区块
     */
    protected function generateCastsSection(array $columns): array
    {
        $lines = [];
        $casts = $this->collectCasts($columns);

        $lines[] = '    /**';
        $lines[] = '     * 获取属性的类型转换规则';
        $lines[] = '     *';
        $lines[] = '     * 支持的转换类型：';
        $lines[] = '     * array, boolean, collection, date, datetime,';
        $lines[] = '     * decimal:&lt;precision&gt;, double, float, hashed,';
        $lines[] = '     * integer, object, real, string, timestamp';
        $lines[] = '     *';
        $lines[] = '     * @return array&lt;string, string&gt;';
        $lines[] = '     */';
        $lines[] = '    protected function casts(): array';
        $lines[] = '    {';
        $lines[] = '        return [';

        if (! empty($casts)) {
            foreach ($casts as $column => $cast) {
                $lines[] = "            '{$column}' => '{$cast}',";
            }
        }

        $lines[] = '        ];';
        $lines[] = '    }';

        return $lines;
    }

    /**
     * 生成 $attributes 属性区块（数据库默认值）
     */
    protected function generateAttributesSection(array $columns): array
    {
        $lines = [];
        $attributes = $this->collectDefaultAttributes($columns);

        if (empty($attributes)) {
            return $lines;
        }

        $lines[] = '    /**';
        $lines[] = '     * 模型属性的默认值';
        $lines[] = '     *';
        $lines[] = '     * 从数据表的 DEFAULT 子句自动提取。';
        $lines[] = '     * 注意：这些默认值仅影响 PHP 层面的 new 实例，';
        $lines[] = '     * 数据库的 DEFAULT 值由迁移文件控制。';
        $lines[] = '     *';
        $lines[] = '     * @var array&lt;string, mixed&gt;';
        $lines[] = '     */';
        $lines[] = '    protected $attributes = [';
        foreach ($attributes as $key => $value) {
            if (is_string($value)) {
                $lines[] = "        '{$key}' => '{$value}',";
            } elseif (is_bool($value)) {
                $lines[] = "        '{$key}' => " . ($value ? 'true' : 'false') . ',';
            } else {
                $lines[] = "        '{$key}' => {$value},";
            }
        }
        $lines[] = '    ];';

        return $lines;
    }

    // ────────────────────────────────────
    //  模型属性推导
    // ────────────────────────────────────

    /**
     * 获取 PHPDoc @property 类型
     *
     * 特殊处理：
     * - datetime / timestamp → Carbon
     * - date → Carbon
     * - json / jsonb → object（PHPDoc 语义）
     * - nullable → 追加 |null
     */
    protected function resolvePhpDocType(array $column): string
    {
        $type = strtolower($column['type']);
        $nullable = $column['null'] ?? false;

        // 解析基础类型名
        $baseType = $type;
        if (preg_match('/^(\w+)/', $type, $matches)) {
            $baseType = $matches[1];
        }

        // 日期时间类型统一使用 Carbon（PHPDoc 表达更精确）
        if (in_array($baseType, ['datetime', 'timestamp', 'date'], true)) {
            $phpType = 'Carbon';
        } elseif ($baseType === 'json' || $baseType === 'jsonb') {
            $phpType = 'object';
        } else {
            $phpType = $this->typeMapping[$baseType] ?? 'mixed';
        }

        // 可空标记
        if ($nullable) {
            return $phpType . '|null';
        }

        return $phpType;
    }

    /**
     * 收集可批量赋值的字段
     *
     * 排除规则：
     * - 主键 id
     * - 时间戳字段（created_at / updated_at / deleted_at）
     * - 自动递增字段
     * - 软删除字段
     */
    protected function collectFillableColumns(array $columns): array
    {
        $fillable = [];

        foreach ($columns as $column) {
            $name = $column['name'] ?? '';

            // 排除主键
            if ($name === 'id') {
                continue;
            }

            // 排除时间戳和软删除
            if (in_array($name, $this->timestampColumns, true)) {
                continue;
            }

            // 排除自动递增字段
            $extra = strtolower($column['extra'] ?? '');
            if (str_contains($extra, 'auto_increment')) {
                continue;
            }

            $fillable[] = $name;
        }

        return $fillable;
    }

    /**
     * 收集类型转换规则
     *
     * 特殊处理：
     * - created_at / updated_at → datetime:Y-m-d H:i:s
     * - decimal → decimal:小数位
     * - json / jsonb → array
     * - deleted_at → datetime（软删除字段）
     */
    protected function collectCasts(array $columns): array
    {
        $casts = [];

        foreach ($columns as $column) {
            $name = $column['name'] ?? '';
            $type = strtolower($column['type'] ?? '');

            // 解析基础类型名
            $baseType = $type;
            if (preg_match('/^(\w+)/', $type, $matches)) {
                $baseType = $matches[1];
            }

            // 时间戳字段：格式化 datetime 转换
            if (in_array($name, $this->timestampColumns, true)) {
                $casts[$name] = 'datetime:Y-m-d H:i:s';
                continue;
            }

            // 主键自增
            if ($name === 'id') {
                $casts[$name] = 'integer';
                continue;
            }

            // decimal 精度处理
            if ($baseType === 'decimal' && preg_match('/decimal\((\d+),\s*(\d+)\)/', $type, $matches)) {
                $casts[$name] = 'decimal:' . $matches[2];
                continue;
            }

            // JSON 类型
            if ($baseType === 'json' || $baseType === 'jsonb') {
                $casts[$name] = 'array';
                continue;
            }

            // 从映射表获取
            if (isset($this->typeMapping[$baseType])) {
                $castType = $this->typeMapping[$baseType];

                // datetime 字段追加格式
                if ($castType === 'datetime') {
                    $casts[$name] = 'datetime:Y-m-d H:i:s';
                } else {
                    $casts[$name] = $castType;
                }
            }
        }

        return $casts;
    }

    /**
     * 收集数据库默认值
     *
     * 跳过规则：
     * - 可空字段的 NULL 默认值
     * - CURRENT_TIMESTAMP（由数据库处理）
     * - 明确为 NULL 的默认值
     */
    protected function collectDefaultAttributes(array $columns): array
    {
        $attributes = [];

        foreach ($columns as $column) {
            $name = $column['name'] ?? '';
            $default = $column['default'] ?? null;

            // 跳过 NULL 默认值
            if ($default === null || strtoupper((string) $default) === 'NULL') {
                continue;
            }

            // 数据库函数（如 CURRENT_TIMESTAMP）不适合作为 PHP 默认值
            if (str_contains(strtoupper((string) $default), 'CURRENT_TIMESTAMP')) {
                continue;
            }

            // 跳过可空字段的默认值（可选保持为空是正常的）
            if ($default === '' && ($column['null'] ?? false)) {
                continue;
            }

            // 类型转换
            if (is_numeric($default)) {
                $attributes[$name] = $default + 0;
            } elseif (in_array(strtolower((string) $default), ['true', '1'], true)) {
                $attributes[$name] = true;
            } elseif (in_array(strtolower((string) $default), ['false', '0'], true)) {
                $attributes[$name] = false;
            } else {
                $attributes[$name] = $default;
            }
        }

        return $attributes;
    }

    /**
     * 检测表是否包含时间戳字段
     *
     * 至少存在 created_at 或 updated_at 即认为使用了时间戳。
     */
    protected function detectTimestamps(array $columns): bool
    {
        foreach ($columns as $column) {
            $name = $column['name'] ?? '';
            if ($name === 'created_at' || $name === 'updated_at') {
                return true;
            }
        }
        return false;
    }
}
