<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use zxf\Modules\Facades\Module;

/**
 * 创建模型命令
 *
 * 在指定模块中创建 Eloquent 模型类，支持以下功能：
 * - 从现有数据库表自动解析字段信息
 * - 自动生成 PHPDoc 属性注释（包括字段注释）
 * - 自动生成 fillable 属性（可批量赋值字段）
 * - 自动生成 casts() 方法（类型转换，datetime 字段使用 Carbon）
 * - 自动生成 attributes 属性（默认值）
 * - 可选创建对应的迁移文件和数据工厂
 *
 * 使用示例：
 * 1. 从表生成模型：php artisan module:make-model Logs User --table=users
 * 2. 创建新模型和迁移：php artisan module:make-model Blog Post --migration
 * 3. 覆盖已存在的模型：php artisan module:make-model Blog User --force
 */
class ModelMakeCommand extends Command
{
    /**
     * 数据库字段类型到 PHP 类型的映射
     *
     * 将各种数据库字段类型映射到 Laravel Eloquent 支持的转换类型
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
     *
     * @var array<string, string>
     */
    protected array $typeMapping = [
        // 整数类型
        'int' => 'integer',
        'integer' => 'integer',
        'tinyint' => 'integer',
        'smallint' => 'integer',
        'mediumint' => 'integer',
        'bigint' => 'integer',
        'bit' => 'boolean',

        // 浮点类型
        'float' => 'float',
        'double' => 'float',
        'decimal' => 'decimal',

        // 字符串类型
        'char' => 'string',
        'varchar' => 'string',
        'text' => 'string',
        'tinytext' => 'string',
        'mediumtext' => 'string',
        'longtext' => 'string',

        // 二进制类型
        'binary' => 'binary',
        'varbinary' => 'binary',
        'blob' => 'binary',
        'tinyblob' => 'binary',
        'mediumblob' => 'binary',
        'longblob' => 'binary',

        // 日期时间类型
        'date' => 'date',
        'datetime' => 'datetime',
        'timestamp' => 'datetime',
        'time' => 'string',
        'year' => 'integer',

        // JSON 类型
        'json' => 'array',
        'jsonb' => 'array',

        // 布尔类型
        'boolean' => 'boolean',
        'bool' => 'boolean',

        // 枚举类型
        'enum' => 'string',
        'set' => 'array',

        // UUID
        'uuid' => 'string',
        'char(36)' => 'string',
    ];

    /**
     * 命令签名
     *
     * 定义命令的名称、参数和选项
     *
     * 参数：
     * - module: 必需参数，指定目标模块的名称（首字母大写，如 Blog）
     * - name: 必需参数，指定要创建的模型类名（首字母大写，如 Post）
     *
     * 选项：
     * - --table: 从现有数据库表解析字段信息，自动生成模型属性
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
     * 在指定模块中创建 Eloquent 模型类，支持从数据库表自动生成完整的模型属性
     *
     * @var string
     */
    protected $description = '在指定模块中创建 Eloquent 模型类，支持从数据库表自动解析字段信息';

    /**
     * 执行命令
     *
     * 创建步骤：
     * 1. 验证模块是否存在
     * 2. 检查模型是否已存在（除非使用 --force）
     * 3. 如果指定了 --table，从数据库表解析字段信息
     * 4. 创建模型文件
     * 5. 可选：创建迁移文件
     * 6. 可选：创建数据工厂
     *
     * @return int
     */
    public function handle(): int
    {
        $moduleName = Str::studly($this->argument('module'));
        $modelName = Str::studly($this->argument('name'));
        $tableName = $this->option('table') ?: Str::snake(Str::plural($modelName));
        $createMigration = $this->option('migration');
        $createFactory = $this->option('factory');
        $force = $this->option('force');

        // 验证模块是否存在
        $module = Module::find($moduleName);

        if (! $module) {
            $this->error("模块 [{$moduleName}] 不存在");
            $this->line("提示：请先创建模块，使用 php artisan module:make {$moduleName}");
            return Command::FAILURE;
        }

        $modelPath = $module->getPath('Models/' . $modelName . '.php');

        // 检查模型是否已存在
        if (File::exists($modelPath) && ! $force) {
            $this->error("模块 [{$moduleName}] 中已存在模型类 [{$modelName}]");
            $this->line("文件位置: {$modelPath}");
            $this->line("提示：使用 --force 选项覆盖已存在的模型");
            return Command::FAILURE;
        }

        if (File::exists($modelPath) && $force) {
            $this->warn("正在覆盖模块 [{$moduleName}] 中已存在的模型类 [{$modelName}]");
        }

        $namespace = config('modules.namespace', 'Modules');

        // 从数据库表解析字段信息
        $columns = $this->getTableColumns($tableName);

        if (empty($columns)) {
            $this->warn("未找到表 [{$tableName}] 的字段信息，将创建基础模型");
            $this->line("");
        } else {
            $this->info("已从表 [{$tableName}] 解析到 " . count($columns) . " 个字段");
            $this->line("");
        }

        // 生成模型内容
        $modelContent = $this->generateModelContent(
            $moduleName,
            $modelName,
            $namespace,
            $tableName,
            $columns
        );

        // 确保模型目录存在
        $modelDir = $module->getPath('Models');
        if (! is_dir($modelDir)) {
            File::makeDirectory($modelDir, 0755, true);
        }

        // 写入模型文件
        if (! File::put($modelPath, $modelContent)) {
            $this->error("创建模型类 [{$modelName}] 失败");
            $this->line("提示：检查文件权限和磁盘空间");
            return Command::FAILURE;
        }

        $this->info("✓ 成功在模块 [{$moduleName}] 中创建模型类 [{$modelName}]");
        $this->line("模型位置: {$modelPath}");
        $this->line("数据表: {$tableName}");

        // 可选：创建迁移文件
        if ($createMigration) {
            $this->line("");
            $this->line("正在创建迁移文件...");
            $this->call('module:make-migration', [
                'module' => $moduleName,
                'name' => 'create_' . $tableName . '_table',
                '--create' => $tableName,
            ]);
        }

        // 可选：创建数据工厂
        if ($createFactory) {
            $this->line("");
            $this->line("正在创建数据工厂...");
            $this->call('module:make-seeder', [
                'module' => $moduleName,
                'name' => $modelName . 'Seeder',
            ]);
        }

        return Command::SUCCESS;
    }

    /**
     * 获取表的列信息
     *
     * @param string $tableName
     * @return array
     */
    protected function getTableColumns(string $tableName): array
    {
        try {
            // 安全校验表名格式（防止 SQL 注入和路径遍历）
            if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $tableName)) {
                $this->error("表名格式不合法: [{$tableName}]");
                return [];
            }

            // 使用 Laravel Schema facade 检查表是否存在
            if (! \Illuminate\Support\Facades\Schema::hasTable($tableName)) {
                return [];
            }

            // 获取列信息（包括注释）
            // 注意：由于表名已经过正则校验，可以直接使用（不是用户输入）
            $columns = DB::select("SHOW FULL COLUMNS FROM `{$tableName}`");

            $columnInfo = [];
            foreach ($columns as $column) {
                $columnInfo[$column->Field] = [
                    'name' => $column->Field,
                    'type' => $column->Type,
                    'null' => $column->Null === 'YES',
                    'key' => $column->Key,
                    'default' => $column->Default,
                    'extra' => $column->Extra,
                    'comment' => $column->Comment ?? '',
                ];
            }

            return $columnInfo;
        } catch (\Throwable $e) {
            $this->error("无法获取表 [{$tableName}] 的信息: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 生成模型内容
     *
     * @param string $moduleName
     * @param string $modelName
     * @param string $namespace
     * @param string $tableName
     * @param array $columns
     * @return string
     */
    protected function generateModelContent(
        string $moduleName,
        string $modelName,
        string $namespace,
        string $tableName,
        array $columns
    ): string {
        $lines = [];

        $modelDir = config('modules.paths.generator.model.path', 'Models');;
        // 开始类定义
        $lines[] = "<?php";
        $lines[] = "";
        $lines[] = "namespace {$namespace}\\{$moduleName}\\{$modelDir};";
        $lines[] = "";
        $lines[] = "use Carbon\Carbon;";
        $lines[] = "use Illuminate\\Database\\Eloquent\\Model;";
        $lines[] = "";
        $lines[] = "/**";
        $lines[] = " * {$modelName} 模型";
        $lines[] = " *";
        $lines[] = " * {$moduleName} 模块的数据模型";
        $lines[] = " * 继承 Eloquent Model，提供完整的数据库操作能力";
        $lines[] = " *";

        // 生成 PHPDoc 属性注释
        foreach ($columns as $column) {
            $phpDocType = $this->getPhpDocType($column);
            $comment = $column['comment'] ?? '';

            // 如果没有注释，使用默认值和额外信息
            if (empty($comment)) {
                if ($column['default'] !== null && $column['default'] !== '') {
                    $comment = $column['default'];
                }
                if ($column['extra']) {
                    $comment .= ($comment ? ' ' : '') . $column['extra'];
                }
            }

            $lines[] = " * @property {$phpDocType} \${$column['name']} {$comment}";
        }
        $lines[] = " */";
        $lines[] = "class {$modelName} extends Model";
        $lines[] = "{";
        $lines[] = "";
        $lines[] = "    /**";
        $lines[] = "     * 数据表名称";
        $lines[] = "     *";
        $lines[] = "     * 如果不指定，Eloquent 会使用模型类的复数形式（自动转换）";
        $lines[] = "     * 例如：{$modelName} => {$tableName} 表";
        $lines[] = "     *";
        $lines[] = "     * @var string";
        $lines[] = "     */";
        $lines[] = "    protected \$table = '{$tableName}';";
        $lines[] = "";
        $lines[] = "    /**";
        $lines[] = "     * 是否自动维护时间戳";
        $lines[] = "     *";
        $lines[] = "     * @var bool";
        $lines[] .= "     */";
        $lines[] = "    public \$timestamps = " . ($this->hasTimestamps($columns) ? 'true' : 'false') . ";";
        $lines[] = "";

        // 生成 fillable 属性
        $fillable = $this->getFillableColumns($columns);
        $lines[] = "    /**";
        $lines[] = "     * 可批量赋值的属性";
        $lines[] = "     *";
        $lines[] = "     * 这些属性可以通过 create()、update()、fill() 方法批量赋值";
        $lines[] = "     * 出于安全考虑，只列出允许批量赋值的字段";
        $lines[] .= "     *";
        $lines[] = "     * @var array<int, string>";
        $lines[] .= "     *";
        if (!empty($fillable)) {
            $lines[] = "     * @example 使用示例";
            $lines[] = "     * ```php";
            $lines[] = "     * {$modelName}::create([";
            $exampleFields = array_slice($fillable, 0, 3);
            foreach ($exampleFields as $field) {
                $lines[] = "     *     '{$field}' => '示例值',";
            }
            $lines[] = "     * ]);";
            $lines[] .= "     * ```";
        }
        $lines[] .= "     */";
        if (!empty($fillable)) {
            $fillableStr = "'" . implode("', '", $fillable) . "'";
            $lines[] = "    protected \$fillable = [ {$fillableStr} ];";
        } else {
            $lines[] = "    protected \$fillable = [];";
        }
        $lines[] = "";

        // 生成 casts 属性
        $casts = $this->getCasts($columns);
        $lines[] = "    /**";
        $lines[] = "     * 获取需要被类型转换的属性。";
        $lines[] .= "     * 支持的类型：array";
        $lines[] = "     * AsStringable::class、boolean、collection、date、datetime、immutable_date、immutable_datetime、";
        $lines[] = "     * decimal:<precision>、double、encrypted、encrypted:array、encrypted:collection、encrypted:object、";
        $lines[] .= "     * float、hashed、integer、object、real、string、timestamp";
        $lines[] .= "     *";
        $lines[] .= "     * @return array<string, string>";
        $lines[] .= "     */";
        $lines[] = "    protected function casts(): array";
        $lines[] = "    {";
        $lines[] = "        return [";
        foreach ($casts as $column => $cast) {
            $lines[] = "            '{$column}' => '{$cast}',";
        }
        $lines[] = "        ];";
        $lines[] = "    }";
        $lines[] = "";

        // 生成默认属性值
        $attributes = $this->getDefaultAttributes($columns);
        if (!empty($attributes)) {
            $lines[] = "    /**";
            $lines[] = "     * 默认属性值";
            $lines[] = "     *";
            $lines[] .= "     * 为模型属性设置默认值";
            $lines[] .= "     *";
            $lines[] .= "     * @var array<string, mixed>";
            $lines[] .= "     */";
            $lines[] = "    protected \$attributes = [";
            foreach ($attributes as $key => $value) {
                if (is_string($value)) {
                    $lines[] = "        '{$key}' => '{$value}',";
                } elseif (is_bool($value)) {
                    $lines[] = "        '{$key}' => " . ($value ? 'true' : 'false') . ",";
                } else {
                    $lines[] = "        '{$key}' => {$value},";
                }
            }
            $lines[] = "    ];";
            $lines[] = "";
        }

        $lines[] = "}";

        return implode("\n", $lines);
    }

    /**
     * 获取 PHPDoc 类型
     *
     * @param array $column
     * @return string
     */
    protected function getPhpDocType(array $column): string
    {
        $type = strtolower($column['type']);
        $nullable = $column['null'];

        // 解析类型
        if (preg_match('/^(\w+)/', $type, $matches)) {
            $baseType = $matches[1];
        } else {
            $baseType = $type;
        }

        // 查找映射
        $phpType = $this->typeMapping[$baseType] ?? 'mixed';

        // 特殊处理：datetime 和 timestamp 类型使用 Carbon
        if ($baseType === 'datetime' || $baseType === 'timestamp') {
            $phpType = 'Carbon';
        }

        // 特殊处理：date 类型使用 Carbon
        if ($baseType === 'date') {
            $phpType = 'Carbon';
        }

        // 特殊处理：json 类型
        if ($baseType === 'json' || $baseType === 'jsonb') {
            $phpType = 'object';
        }

        // 可空类型
        if ($nullable) {
            return $phpType . '|null';
        }

        return $phpType;
    }

    /**
     * 获取可批量赋值的字段
     *
     * @param array $columns
     * @return array
     */
    protected function getFillableColumns(array $columns): array
    {
        $fillable = [];

        foreach ($columns as $column) {
            $name = $column['name'];

            // 排除主键、时间戳字段和自动递增字段
            if ($name === 'id' || $name === 'created_at' || $name === 'updated_at') {
                continue;
            }

            // 排除自动递增字段
            if (stripos($column['extra'], 'auto_increment') !== false) {
                continue;
            }

            $fillable[] = $name;
        }

        return $fillable;
    }

    /**
     * 获取类型转换规则
     *
     * @param array $columns
     * @return array
     */
    protected function getCasts(array $columns): array
    {
        $casts = [];

        foreach ($columns as $column) {
            $name = $column['name'];
            $type = strtolower($column['type']);

            // 解析类型
            if (preg_match('/^(\w+)/', $type, $matches)) {
                $baseType = $matches[1];
            } else {
                $baseType = $type;
            }

            // 时间戳字段
            if ($name === 'created_at' || $name === 'updated_at') {
                $casts[$name] = 'datetime:Y-m-d H:i:s';
                continue;
            }

            // 获取映射
            if (isset($this->typeMapping[$baseType])) {
                $casts[$name] = $this->typeMapping[$baseType];
            }

            // JSON 类型特殊处理
            if ($baseType === 'json' || $baseType === 'jsonb') {
                $casts[$name] = 'array';
            }

            // decimal 类型处理
            if (preg_match('/decimal\((\d+),\s*(\d+)\)/', $type, $matches)) {
                $casts[$name] = 'decimal:' . $matches[2];
            }
        }

        return $casts;
    }

    /**
     * 获取默认属性值
     *
     * @param array $columns
     * @return array
     */
    protected function getDefaultAttributes(array $columns): array
    {
        $attributes = [];

        foreach ($columns as $column) {
            // 跳过有 NULL 默认值的字段
            if ($column['null']) {
                continue;
            }

            $name = $column['name'];
            $default = $column['default'];

            // 跳过无默认值的字段
            if ($default === null || $default === 'NULL') {
                continue;
            }

            // 处理不同的默认值类型
            if (is_numeric($default)) {
                $attributes[$name] = $default + 0; // 转换为数字
            } elseif (strtolower($default) === 'true' || strtolower($default) === '1') {
                $attributes[$name] = true;
            } elseif (strtolower($default) === 'false' || strtolower($default) === '0') {
                $attributes[$name] = false;
            } else {
                $attributes[$name] = $default;
            }
        }

        return $attributes;
    }

    /**
     * 检查是否有时间戳字段
     *
     * @param array $columns
     * @return bool
     */
    protected function hasTimestamps(array $columns): bool
    {
        return isset($columns['created_at']) || isset($columns['updated_at']);
    }
}
