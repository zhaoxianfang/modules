<?php

/**
 * 模块命令测试指南
 * 
 * 本文件用于测试模块命令的创建和注册功能
 * 
 * 测试步骤：
 * 1. 创建一个测试模块
 * 2. 验证模块中的默认命令是否生成
 * 3. 检查命令签名是否正确
 * 4. 测试命令是否可以执行
 * 5. 使用调试命令检查命令注册情况
 */

// === 测试步骤 ===

/*
步骤 1: 创建模块
-----------------
php artisan module:make Blog

预期结果：
- 在 app/Modules/ 目录下创建 Blog 模块
- 模块包含默认的命令文件: Console/Commands/BlogCommand.php
*/

/*
步骤 2: 检查生成的命令文件
---------------------------
cat app/Modules/Blog/Console/Commands/BlogCommand.php

预期结果：
- 命令命名空间: Modules\Blog\Console\Commands
- 类名: BlogCommand
- 命令签名: blog:command
- 命令描述: 模块 Blog 的示例命令
*/

/*
步骤 3: 使用调试命令检查
-----------------------
php artisan module:debug-commands --module=Blog

预期输出：
- 模块: Blog
- 状态: 已启用
- Console/Commands 目录: ✓ 存在
- 在目录中找到 1 个文件
  - BlogCommand.php
- 检查命令类: Modules\Blog\Console\Commands\BlogCommand
- ✓ 发现有效命令: Modules\Blog\Console\Commands\BlogCommand
- 签名: blog:command
- 已注册到 Artisan 的命令:
  ✓ blog:command
*/

/*
步骤 4: 列出所有可用命令
------------------------
php artisan list | grep blog

预期输出：
- blog:command             模块 Blog 的示例命令
*/

/*
步骤 5: 执行模块命令
-------------------
php artisan blog:command

预期输出：
- 执行命令...
- 命令执行完成！
*/

/*
步骤 6: 创建自定义命令
---------------------
php artisan module:make-command Blog TestCommand --command=blog:test

预期结果：
- 在 app/Modules/Blog/Console/Commands/ 创建 TestCommand.php
- 命令签名: blog:test
*/

/*
步骤 7: 测试自定义命令
-----------------------
php artisan blog:test

预期输出：
- 成功在模块 [Blog] 中创建命令 [TestCommand]
- 执行命令...
- 命令执行完成！
*/

/*
步骤 8: 检查所有模块的命令
---------------------------
php artisan module:debug-commands

预期输出：
- 显示所有模块的命令注册情况
- 包括命令签名、类名、描述等信息
*/

// === 常见问题排查 ===

/*
问题 1: 命令找不到
-----------------
错误信息: There are no commands defined in the "blog" namespace.

解决方案：
1. 检查模块是否启用: php artisan module:list
2. 检查命令文件是否存在: ls app/Modules/Blog/Console/Commands/
3. 使用调试命令: php artisan module:debug-commands --module=Blog
4. 检查命令签名是否正确: cat app/Modules/Blog/Console/Commands/BlogCommand.php | grep signature
5. 清除应用缓存: php artisan cache:clear && php artisan config:clear
6. 清除路由缓存: php artisan route:clear
*/

/*
问题 2: 命令签名错误
-------------------
错误信息: 命令无法识别或签名不正确

解决方案：
1. 打开命令文件检查 $signature 属性
2. 确保签名格式为: {模块名小写}:{命令名}
3. 示例: blog:command, admin:user, shop:product
4. 避免使用 module: 前缀（这是本包的保留前缀）
*/

/*
问题 3: 命令类未注册
-------------------
错误信息: 命令类存在但无法执行

解决方案：
1. 检查命令类是否继承 Illuminate\Console\Command
2. 检查命令类是否实现了 handle() 方法
3. 检查 handle() 方法是否返回 int 类型
4. 确保类不是抽象类
5. 检查类名和文件名是否一致
6. 运行 php artisan module:debug-commands 查看详细日志
*/

// === 命令签名最佳实践 ===

/*
推荐的命令签名格式：
-------------------
- 模块命令: {模块}:{功能}
  - blog:post
  - blog:comment
  - admin:user
  - admin:role
  - shop:order
  - shop:product

- 避免使用：
  - module:blog:command (不要添加 module: 前缀)
  - blogCommand (应使用冒号分隔)
  - blog_command (应使用横线分隔)
*/

// === 调试技巧 ===

/*
1. 启用详细日志
   在 .env 文件中设置:
   APP_DEBUG=true
   
   然后检查日志:
   tail -f storage/logs/laravel.log | grep -i "blog\|command"

2. 检查全局命令缓存
   在路由或控制器中:
   dd(\zxf\Modules\Support\ModuleAutoDiscovery::getGlobalCommands());

3. 手动测试命令类
   在 tinker 中:
   $command = app(\Modules\Blog\Console\Commands\BlogCommand::class);
   dd($command->getName());
   dd($command->getDescription());
*/

// === 性能优化建议 ===

/*
1. 生产环境关闭调试模式
   在 .env 文件中:
   APP_DEBUG=false

2. 使用缓存
   确保 config/modules.php 中:
   'discovery' => [
       'commands' => true,
   ]

3. 定期清理未使用的命令
   删除不再需要的命令文件以减少扫描时间
*/

// === 版本要求 ===

/*
- PHP >= 8.2
- Laravel >= 11.0
- zxf/modules >= 1.0.0

如果遇到问题，请确保满足以上版本要求。
*/
