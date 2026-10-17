<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Discovers and registers every app/Modules/<Name>/<Name>ServiceProvider.
 *
 * Set MODULES=Billing,Services to load only those modules (Core is always loaded).
 * This lets a module's test suite run in isolation from unfinished sibling modules.
 */
class ModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ($this->enabledModules() as $module) {
            $provider = "App\\Modules\\{$module}\\{$module}ServiceProvider";

            if (class_exists($provider)) {
                $this->app->register($provider);
            }
        }
    }

    /**
     * @return list<string>
     */
    public static function enabledModules(): array
    {
        $available = collect(glob(app_path('Modules/*'), GLOB_ONLYDIR) ?: [])
            ->map(fn (string $path): string => basename($path))
            ->sortBy(fn (string $module): int => $module === 'Core' ? 0 : 1)
            ->values();

        $only = array_filter(array_map('trim', explode(',', (string) config('modules.only', ''))));

        if ($only === []) {
            return array_values($available->all());
        }

        return array_values($available
            ->filter(fn (string $module): bool => $module === 'Core' || in_array($module, $only, true))
            ->all());
    }
}
