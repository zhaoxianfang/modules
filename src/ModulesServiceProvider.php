<?php

namespace zxf\Modules;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ModulesServiceProvider extends ServiceProvider
{
    /**
     * 注册任何应用程序服务。
     */
    public function register(): void
    {
        // TODO
    }

    /**
     * 引导任何应用程序服务。
     */
    public function boot(): void
    {
        // 发布配置
        // 引入多模块命令
        // 自动发现并加载各个模块：模块服务提供者、路由、迁移、视图、配置、翻译、中间件、命令、事件、监听器、观察者等

        // TODO
    }

}