<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 模块命名空间
    |--------------------------------------------------------------------------
    | 定义模块的根命名空间，所有模块类都将使用此前缀
    | 例如: 'Modules' 则模块类命名空间为 Modules\Blog\Http\Controllers\Controller
    |
    */
    'namespace' => 'Modules',

    /*
    |--------------------------------------------------------------------------
    | 模块路径
    |--------------------------------------------------------------------------
    | 定义模块存储的基础路径
    |
    */
    'path' => base_path('Modules'),

    /*
    |--------------------------------------------------------------------------
    | 路由中间件组
    |--------------------------------------------------------------------------
    | 定义不同路由文件自动加载的中间件组
    | 键为路由文件名（不含扩展名），值为中间件组名称数组
    | 例如: 'web' => ['web'] 表示 web.php 路由将自动应用 web 中间件组
    |
    */
    'middleware_groups' => [
        'web' => ['web'],
        'api' => ['api'],
        'admin' => ['web', 'admin'],
    ],

    /*
    |--------------------------------------------------------------------------
    | 路由控制器命名空间映射
    |--------------------------------------------------------------------------
    | 定义路由文件对应的控制器子命名空间
    | 例如: 'web' => 'Web' 表示 web.php 路由使用 Http\Controllers\Web 命名空间
    |
    */
    'route_controller_namespaces' => [
        'web' => 'Web',
        'api' => 'Api',
        'admin' => 'Admin',
    ],

    /*
    |--------------------------------------------------------------------------
    | 模块扫描路径
    |--------------------------------------------------------------------------
    | 定义扫描和查找模块的路径配置
    |
    */
    'scan' => [
        'paths' => [
            base_path('Modules'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 迁移路径
    |--------------------------------------------------------------------------
    | 定义模块迁移文件的存储和加载路径
    |
    */
    'paths' => [
        'migration' => 'Database/Migrations',
        'generator' => [
            'assets' => 'Resources/assets',
            'config' => 'Config',
            'command' => 'Console/Commands',
            'event' => 'Events',
            'listener' => 'Listeners',
            'migration' => 'Database/Migrations',
            'model' => 'Models',
            'observer' => 'Observers',
            'policy' => 'Policies',
            'provider' => 'Providers',
            'repository' => 'Repositories',
            'request' => 'Http/Requests',
            'resource' => 'Transformers',
            'route' => 'Routes',
            'seeder' => 'Database/Seeders',
            'test' => 'Tests',
            'controller' => 'Http/Controllers',
            'filter' => 'Http/Middleware',
            'lang' => 'Resources/lang',
            'views' => 'Resources/views',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 自动发现
    |--------------------------------------------------------------------------
    | 定义需要自动发现的模块组件
    |
    */
    'discovery' => [
        'routes' => true,
        'providers' => true,
        'commands' => true,
        'views' => true,
        'config' => true,
        'translations' => true,
        'migrations' => true,
    ],
];
