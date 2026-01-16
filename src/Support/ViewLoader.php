<?php

namespace zxf\Modules\Support;

use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use zxf\Modules\Contracts\ModuleInterface;

/**
 * 视图加载器类
 *
 * 负责加载和管理模块视图
 * 支持通过模块命名空间访问视图，例如: blog::list.test 表示加载 blog 模块下的 list/test.blade.php
 */
class ViewLoader
{
    /**
     * 加载模块视图
     *
     * @param ModuleInterface $module
     * @return void
     */
    public static function load(ModuleInterface $module): void
    {
        if (! config('modules.views.enabled', true)) {
            return;
        }

        $viewsPath = $module->getViewsPath();

        if (! is_dir($viewsPath)) {
            return;
        }

        $namespace = self::getNamespace($module);

        View::addNamespace($namespace, $viewsPath);
    }

    /**
     * 获取模块视图命名空间
     *
     * @param ModuleInterface $module
     * @return string
     */
    public static function getNamespace(ModuleInterface $module): string
    {
        $format = config('modules.views.namespace_format', 'lower');

        return match ($format) {
            'studly' => $module->getName(),
            'camel' => $module->getCamelName(),
            default => $module->getLowerName(),
        };
    }

    /**
     * 解析视图名称
     *
     * 将视图名称解析为完整的视图路径
     * 例如: blog::list.test -> Blog 模块的 Resources/views/list/test.blade.php
     *
     * @param string $view
     * @return array [namespace, view]
     */
    public static function parseView(string $view): array
    {
        if (str_contains($view, '::')) {
            return explode('::', $view, 2);
        }

        return [null, $view];
    }

    /**
     * 检查视图是否存在
     *
     * @param string $view
     * @return bool
     */
    public static function exists(string $view): bool
    {
        [$namespace, $viewName] = self::parseView($view);

        if ($namespace) {
            return View::exists("{$namespace}::{$viewName}");
        }

        return View::exists($viewName);
    }

    /**
     * 获取视图路径
     *
     * @param ModuleInterface $module
     * @param string $view
     * @return string
     */
    public static function getViewPath(ModuleInterface $module, string $view): string
    {
        // 将点号转换为路径分隔符
        $viewPath = str_replace('.', '/', $view);

        return $module->getViewsPath() . '/' . $viewPath . '.blade.php';
    }

    /**
     * 查找模块视图
     *
     * @param ModuleInterface $module
     * @param string $pattern
     * @return array
     */
    public static function findViews(ModuleInterface $module, string $pattern = '*'): array
    {
        $viewsPath = $module->getViewsPath();

        if (! is_dir($viewsPath)) {
            return [];
        }

        $files = glob($viewsPath . '/' . $pattern . '.blade.php');
        $views = [];

        foreach ($files as $file) {
            $relativePath = str_replace([$viewsPath . '/', '.blade.php'], '', $file);
            $views[] = str_replace('/', '.', $relativePath);
        }

        return $views;
    }

    /**
     * 获取模块所有视图
     *
     * @param ModuleInterface $module
     * @return array
     */
    public static function getAllViews(ModuleInterface $module): array
    {
        return self::findViews($module, '**/*');
    }
}
