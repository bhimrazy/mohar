<?php

namespace App\Support\Modules;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Base provider for every module in app/Modules/<Name>.
 *
 * By convention it loads, when present:
 *  - Config/<name>.php            merged as config('<name>.*')
 *  - Database/Migrations          migrations
 *  - routes/api.php               under /api/v1 with the "api" middleware group
 *  - routes/web.php               under the "web" middleware group
 *  - Resources/views              as the "<name>::" view namespace
 *  - Resources/lang/*.json        JSON translations (ADR 0028); billing/lang overrides them
 *  - Console/Commands/*.php       artisan commands
 * and calls schedule() once the scheduler is resolved.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /**
     * The module's lowercase key (config key, view namespace).
     */
    abstract public function moduleKey(): string;

    public function register(): void
    {
        $config = $this->modulePath('Config/'.$this->moduleKey().'.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, $this->moduleKey());
        }

        $this->registerModule();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom($this->modulePath('Database/Migrations'));

        if (is_dir($this->modulePath('Resources/views'))) {
            $this->loadViewsFrom($this->modulePath('Resources/views'), $this->moduleKey());
        }

        if (is_dir($this->modulePath('Resources/lang'))) {
            $this->loadJsonTranslationsFrom($this->modulePath('Resources/lang'));
        }

        if (! $this->app->routesAreCached()) {
            $this->loadModuleRoutes();
        }

        if ($this->app->runningInConsole()) {
            $this->commands($this->discoverCommands());
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $this->schedule($schedule);
        });

        $this->bootModule();
    }

    /**
     * Register container bindings for the module.
     */
    protected function registerModule(): void {}

    /**
     * Register event listeners, merge-field resolvers, etc.
     */
    protected function bootModule(): void {}

    /**
     * Register the module's scheduled automation tasks.
     */
    protected function schedule(Schedule $schedule): void {}

    protected function modulePath(string $path = ''): string
    {
        $directory = dirname((string) (new ReflectionClass(static::class))->getFileName());

        return $path === '' ? $directory : $directory.'/'.$path;
    }

    protected function loadModuleRoutes(): void
    {
        if (is_file($api = $this->modulePath('routes/api.php'))) {
            Route::prefix('api/v1')->middleware('api')->group($api);
        }

        if (is_file($web = $this->modulePath('routes/web.php'))) {
            Route::middleware('web')->group($web);
        }
    }

    /**
     * @return list<class-string>
     */
    protected function discoverCommands(): array
    {
        $directory = $this->modulePath('Console/Commands');

        if (! is_dir($directory)) {
            return [];
        }

        $namespace = (new ReflectionClass(static::class))->getNamespaceName().'\\Console\\Commands\\';

        return array_values(collect(glob($directory.'/*.php') ?: [])
            ->map(fn (string $file): string => $namespace.basename($file, '.php'))
            ->filter(fn (string $class): bool => class_exists($class))
            ->all());
    }
}
