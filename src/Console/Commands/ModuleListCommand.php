<?php

namespace zxf\Modules\Console\Commands;

use Illuminate\Console\Command;
use zxf\Modules\Managers\ModuleManager;

class ModuleListCommand extends Command
{
    /**
     * 控制台命令的名称和签名。
     *
     * @var string
     */
    protected $signature = 'module:list {--detail : 显示详细信息} {--json : 以 JSON 格式输出}';

    /**
     * 控制台命令的描述。
     *
     * @var string
     */
    protected $description = '列出所有模块';

    /**
     * 执行控制台命令。
     */
    public function handle(ModuleManager $manager): int
    {
        $modules = $manager->all();

        if (empty($modules)) {
            $this->info('No modules found.');
            return Command::SUCCESS;
        }

        if ($this->option('json')) {
            return $this->displayJson($modules);
        }

        if ($this->option('detail')) {
            return $this->displayDetailed($modules);
        }

        return $this->displayTable($modules);
    }

    /**
     * 以表格形式显示模块。
     *
     * @param array<string, \zxf\Modules\Contracts\ModuleInterface> $modules
     */
    protected function displayTable(array $modules): int
    {
        $rows = [];
        $enabledCount = 0;
        $disabledCount = 0;

        foreach ($modules as $module) {
            $status = $module->isEnabled() ? '<fg=green>✓ Enabled</>' : '<fg=red>✗ Disabled</>';
            if ($module->isEnabled()) {
                $enabledCount++;
            } else {
                $disabledCount++;
            }
            
            $rows[] = [
                '<fg=cyan>' . $module->getName() . '</>',
                $module->getNamespace(),
                $status,
                '<fg=yellow>' . $module->getPriority() . '</>',
                '<fg=magenta>' . $module->getVersion() . '</>',
            ];
        }

        $this->info(sprintf('Found %d module(s): %d enabled, %d disabled', count($modules), $enabledCount, $disabledCount));
        $this->newLine();
        
        $this->table(['Name', 'Namespace', 'Status', 'Priority', 'Version'], $rows);
        
        $this->newLine();
        $this->line('Use <fg=yellow>module:list --detail</> for detailed information or <fg=yellow>module:list --json</> for JSON output.');

        return Command::SUCCESS;
    }

    /**
     * 显示详细的模块信息。
     *
     * @param array<string, \zxf\Modules\Contracts\ModuleInterface> $modules
     */
    protected function displayDetailed(array $modules): int
    {
        $this->info(sprintf('Detailed information for %d module(s):', count($modules)));
        $this->newLine();
        
        foreach ($modules as $index => $module) {
            $this->line('<fg=blue>═══════════════════════════════════════════════════════════════════════════════</>');
            $this->info(sprintf('Module #%d: <fg=cyan;options=bold>%s</>', $index + 1, $module->getName()));
            $this->line('<fg=blue>───────────────────────────────────────────────────────────────────────────────</>');
            
            $this->line(sprintf('  <fg=yellow>Namespace:</>    %s', $module->getNamespace()));
            $this->line(sprintf('  <fg=yellow>Path:</>         %s', $module->getPath()));
            $this->line(sprintf('  <fg=yellow>Version:</>      <fg=magenta>%s</>', $module->getVersion()));
            $this->line(sprintf('  <fg=yellow>Description:</>  %s', $module->getDescription()));
            $this->line(sprintf('  <fg=yellow>Author:</>       %s', $module->getAuthor() ?: '<fg=gray>N/A</>'));
            $this->line(sprintf('  <fg=yellow>Homepage:</>     %s', $module->getHomepage() ?: '<fg=gray>N/A</>'));
            $this->line(sprintf('  <fg=yellow>License:</>      %s', $module->getLicense()));
            $this->line(sprintf('  <fg=yellow>Status:</>       %s', $module->isEnabled() ? '<fg=green;options=bold>✓ Enabled</>' : '<fg=red;options=bold>✗ Disabled</>'));
            $this->line(sprintf('  <fg=yellow>Priority:</>     <fg=cyan>%d</>', $module->getPriority()));
            $this->line(sprintf('  <fg=yellow>Dependencies:</> %s', empty($module->getDependencies()) ? '<fg=gray>None</>' : '<fg=green>' . implode(', ', $module->getDependencies()) . '</>'));
            $this->line(sprintf('  <fg=yellow>Requirements:</> %s', empty($module->getRequirements()) ? '<fg=gray>None</>' : '<fg=blue>' . implode(', ', array_keys($module->getRequirements())) . '</>'));
            $this->line(sprintf('  <fg=yellow>Tags:</>         %s', empty($module->getTags()) ? '<fg=gray>None</>' : '<fg=blue>' . implode(', ', $module->getTags()) . '</>'));
            
            $this->line('');
            $this->line('  <fg=yellow>Module Resources:</>');
            $this->line(sprintf('    <fg=gray>Providers:</>   %d', count($module->getProviders())));
            $this->line(sprintf('    <fg=gray>Migrations:</>  %d', count($module->getMigrations())));
            $this->line(sprintf('    <fg=gray>Routes:</>      %d', count($module->getRoutes())));
            $this->line(sprintf('    <fg=gray>Views:</>       %d', count($module->getViews())));
            $this->line(sprintf('    <fg=gray>Config files:</> %d', count($module->getConfig())));
            $this->line(sprintf('    <fg=gray>Seeders:</>     %d', count($module->getSeeders())));
            $this->line(sprintf('    <fg=gray>Factories:</>   %d', count($module->getFactories())));
            $this->line(sprintf('    <fg=gray>Translations:</> %d', count($module->getTranslations())));
            
            if ($index < count($modules) - 1) {
                $this->newLine();
            }
        }
        
        $this->line('<fg=blue>═══════════════════════════════════════════════════════════════════════════════</>');
        $this->newLine();
        $this->line('Use <fg=yellow>module:list</> for table view or <fg=yellow>module:list --json</> for JSON output.');

        return Command::SUCCESS;
    }

    /**
     * 以 JSON 格式显示模块。
     *
     * @param array<string, \zxf\Modules\Contracts\ModuleInterface> $modules
     */
    protected function displayJson(array $modules): int
    {
        $data = [];

        foreach ($modules as $module) {
            $data[] = $module->toArray();
        }

        $this->output->write(json_encode($data, JSON_PRETTY_PRINT));

        return Command::SUCCESS;
    }
}