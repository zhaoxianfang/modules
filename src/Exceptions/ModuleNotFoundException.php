<?php

declare(strict_types=1);

namespace zxf\Modules\Exceptions;

/**
 * 模块未找到异常
 *
 * 当请求的模块在仓库中不存在时抛出
 */
class ModuleNotFoundException extends \RuntimeException
{
    /**
     * 模块名称
     */
    protected string $moduleName;

    /**
     * 创建新实例
     *
     * @param string $moduleName 模块名称
     * @param string $message    错误消息
     * @param int    $code       错误码
     */
    public function __construct(string $moduleName, string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        $this->moduleName = $moduleName;

        if (empty($message)) {
            $message = "Module [{$moduleName}] not found.";
        }

        parent::__construct($message, $code, $previous);
    }

    /**
     * 获取模块名称
     */
    public function getModuleName(): string
    {
        return $this->moduleName;
    }
}
