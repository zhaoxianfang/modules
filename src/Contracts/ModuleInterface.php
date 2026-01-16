<?php

namespace zxf\Modules\Contracts;

interface ModuleInterface
{
    /**
     * Get the module name.
     */
    public function getName(): string;

    /**
     * Get the module path.
     */
    public function getPath(): string;

    /**
     * Get the module namespace.
     */
    public function getNamespace(): string;

    /**
     * Get the module priority (boot order).
     */
    public function getPriority(): int;

    /**
     * Check if the module is enabled.
     */
    public function isEnabled(): bool;

    /**
     * Enable the module.
     */
    public function enable(): void;

    /**
     * Disable the module.
     */
    public function disable(): void;

    /**
     * Register module services.
     */
    public function register(): void;

    /**
     * Boot the module.
     */
    public function boot(): void;

    /**
     * Get module providers.
     *
     * @return array<class-string>
     */
    public function getProviders(): array;

    /**
     * Get module migrations path.
     *
     * @return array<string>
     */
    public function getMigrations(): array;

    /**
     * Get module routes path.
     *
     * @return array<string>
     */
    public function getRoutes(): array;

    /**
     * Get module views path.
     *
     * @return array<string>
     */
    public function getViews(): array;

    /**
     * Get module config file path.
     *
     * @return array<string, string>
     */
    public function getConfig(): array;

    /**
     * Get module seeders classes.
     *
     * @return array<class-string>
     */
    public function getSeeders(): array;

    /**
     * Get module factories classes.
     *
     * @return array<class-string>
     */
    public function getFactories(): array;

    /**
     * Get module dependencies.
     *
     * @return array<string>
     */
    public function getDependencies(): array;

    /**
     * Get module middleware.
     *
     * @return array<string, array<string>>
     */
    public function getMiddleware(): array;

    /**
     * Get module translations path.
     *
     * @return array<string>
     */
    public function getTranslations(): array;

    /**
     * Install the module.
     * This method can be used to run migrations, seeders, publish resources, etc.
     */
    public function install(): void;

    /**
     * Uninstall the module.
     * This method can be used to rollback migrations, clean up resources, etc.
     */
    public function uninstall(): void;

    /**
     * Get module version.
     *
     * @return string
     */
    public function getVersion(): string;

    /**
     * Get module description.
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Get module author.
     *
     * @return string
     */
    public function getAuthor(): string;

    /**
     * Get module homepage.
     *
     * @return string
     */
    public function getHomepage(): string;

    /**
     * Get module license.
     *
     * @return string
     */
    public function getLicense(): string;

    /**
     * Get module requirements (PHP, Laravel, extensions, etc.).
     *
     * @return array<string, string>
     */
    public function getRequirements(): array;

    /**
     * Get module suggestions (optional dependencies).
     *
     * @return array<string>
     */
    public function getSuggestions(): array;

    /**
     * Get module tags for categorization.
     *
     * @return array<string>
     */
    public function getTags(): array;

    /**
     * Get extra module data.
     *
     * @return array<string, mixed>
     */
    public function getExtra(): array;

    /**
     * Check if module has a specific config file.
     */
    public function hasConfig(string $key): bool;

    /**
     * Get config value from module config files.
     *
     * @param string $key Config key (filename.key or filename.subkey)
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public function getConfigValue(string $key, mixed $default = null): mixed;

    /**
     * Get module information as array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}