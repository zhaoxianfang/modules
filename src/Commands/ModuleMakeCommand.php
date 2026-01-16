<?php

namespace zxf\Modules\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use zxf\Modules\Support\StubGenerator;

/**
 * 创建新模块命令
 *
 * 使用 stubs 模板创建模块目录结构和文件
 */
class ModuleMakeCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'module:make
                            {name : 模块名称}
                            {--force : 覆盖已存在的模块}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '创建一个新的模块';

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $name = Str::studly($this->argument('name'));
        $force = $this->option('force');

        // 创建 Stub 生成器
        $stubGenerator = new StubGenerator($name);
        $modulePath = $stubGenerator->getModulePath();

        if (is_dir($modulePath) && ! $force) {
            $this->error("Module [{$name}] already exists.");

            return Command::FAILURE;
        }

        $this->info("Creating module [{$name}]...");

        // 创建目录结构
        $this->createModuleStructure($stubGenerator);

        // 创建服务提供者
        $this->createServiceProvider($stubGenerator);

        // 创建配置文件
        $this->createConfigFile($stubGenerator);

        // 创建路由文件
        $this->createRouteFiles($stubGenerator);

        // 创建基础控制器
        $this->createBaseController($stubGenerator);

        // 创建示例控制器
        $this->createExampleControllers($stubGenerator);

        // 创建示例视图
        $this->createExampleView($stubGenerator);

        // 创建 README
        $this->createReadme($stubGenerator);

        $this->info("Module [{$name}] created successfully.");

        return Command::SUCCESS;
    }

    /**
     * 创建模块目录结构
     *
     * @param StubGenerator $generator
     * @return void
     */
    protected function createModuleStructure(StubGenerator $generator): void
    {
        $this->info("Creating module structure...");

        $directories = [
            'Config',
            'Routes',
            'Providers',
            'Console/Commands',
            'Http/Controllers/Web',
            'Http/Controllers/Api',
            'Http/Controllers/Admin',
            'Http/Middleware',
            'Http/Requests',
            'Http/Resources',
            'Database/Migrations',
            'Database/Seeders',
            'Models',
            'Resources/views',
            'Resources/views/layouts',
            'Resources/assets',
            'Resources/lang',
            'Events',
            'Listeners',
            'Observers',
            'Policies',
            'Repositories',
            'Tests',
        ];

        $generator->generateDirectories($directories);
    }

    /**
     * 创建服务提供者
     *
     * @param StubGenerator $generator
     * @return void
     */
    protected function createServiceProvider(StubGenerator $generator): void
    {
        $this->info("Creating service provider...");

        $moduleName = $generator->getModuleName();
        $stubGenerator = new StubGenerator($moduleName);

        $stubGenerator->addReplacement('{{CLASS}}', $moduleName . 'ServiceProvider');

        $stubGenerator->generate(
            'provider.stub',
            'Providers/' . $moduleName . 'ServiceProvider.php'
        );
    }

    /**
     * 创建配置文件
     *
     * @param StubGenerator $generator
     * @return void
     */
    protected function createConfigFile(StubGenerator $generator): void
    {
        $this->info("Creating config file...");

        $generator->generate('config.stub', 'Config/config.php');
    }

    /**
     * 创建路由文件
     *
     * @param StubGenerator $generator
     * @return void
     */
    protected function createRouteFiles(StubGenerator $generator): void
    {
        $this->info("Creating route files...");

        $files = [
            'route/web.stub' => 'Routes/web.php',
            'route/api.stub' => 'Routes/api.php',
            'route/admin.stub' => 'Routes/admin.php',
        ];

        $generator->generateFiles($files);
    }

    /**
     * 创建基础控制器
     *
     * @param StubGenerator $generator
     * @return void
     */
    protected function createBaseController(StubGenerator $generator): void
    {
        $this->info("Creating base controller...");

        $moduleName = $generator->getModuleName();
        $stubGenerator = new StubGenerator($moduleName);

        $stubGenerator->addReplacement('{{CLASS}}', 'Controller');
        $stubGenerator->addReplacement('{{NAMESPACE}}', $stubGenerator->getModulePath());

        $stubGenerator->generate(
            'controller.base.stub',
            'Http/Controllers/Controller.php'
        );
    }

    /**
     * 创建示例控制器
     *
     * @param StubGenerator $generator
     * @return void
     */
    protected function createExampleControllers(StubGenerator $generator): void
    {
        $this->info("Creating example controllers...");

        $moduleName = $generator->getModuleName();
        $namespace = config('modules.namespace', 'Modules');

        // 创建 Web 控制器
        $webStub = new StubGenerator($moduleName);
        $webStub->addReplacement('{{CLASS}}', 'TestController');
        $webStub->addReplacement('{{NAMESPACE}}', $namespace . '\\' . $moduleName);
        $webStub->generate('controller.stub', 'Http/Controllers/Web/TestController.php');

        // 创建 API 控制器
        $apiStub = new StubGenerator($moduleName);
        $apiStub->addReplacement('{{CLASS}}', 'TestController');
        $apiStub->addReplacement('{{NAMESPACE}}', $namespace . '\\' . $moduleName);
        $apiStub->generate('controller.stub', 'Http/Controllers/Api/TestController.php');

        // 创建 Admin 控制器
        $adminStub = new StubGenerator($moduleName);
        $adminStub->addReplacement('{{CLASS}}', 'TestController');
        $adminStub->addReplacement('{{NAMESPACE}}', $namespace . '\\' . $moduleName);
        $adminStub->generate('controller.stub', 'Http/Controllers/Admin/TestController.php');
    }

    /**
     * 创建示例视图
     *
     * @param StubGenerator $generator
     * @return void
     */
    protected function createExampleView(StubGenerator $generator): void
    {
        $this->info("Creating example view...");

        $generator->generate('view.stub', 'Resources/views/welcome.blade.php');
    }

    /**
     * 创建 README 文件
     *
     * @param StubGenerator $generator
     * @return void
     */
    protected function createReadme(StubGenerator $generator): void
    {
        $this->info("Creating README...");

        $generator->generate('readme.stub', 'README.md');
    }
}
