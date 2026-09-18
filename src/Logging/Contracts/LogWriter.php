<?php

declare(strict_types=1);

namespace zxf\Modules\Logging\Contracts;

/**
 * 日志落库写入契约。
 *
 * DatabaseLogHandler 依赖此抽象而非具体的 Eloquent 实现，从而获得：
 * - 可替换的存储后端（如写入其它表 / 消息队列 / 外部存储系统）；
 * - 单元测试中可用假实现替换，验证「日志记录 → 属性」映射而无需真实数据库。
 *
 * @package zxf\Modules\Logging
 */
interface LogWriter
{
    /**
     * 将已归一化的日志属性写入存储。
     *
     * @param array<string, mixed> $attributes 与 logs 表字段一一对应的属性数组
     */
    public function write(array $attributes): void;
}
