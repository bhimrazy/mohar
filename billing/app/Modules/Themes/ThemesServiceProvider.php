<?php

namespace App\Modules\Themes;

use App\Contracts\Branding\BrandProvider;
use App\Modules\Themes\Http\Controllers\Admin\BrandAssetController;
use App\Modules\Themes\Http\Middleware\ShareTheme;
use App\Modules\Themes\Support\BrandAssets;
use App\Modules\Themes\Support\ThemeManager;
use App\Modules\Themes\Support\ThemeStylesheet;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Themes\ThemePreset;
use App\Support\Themes\ThemeRegistry;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;

/**
 * Themes: built-in presets (Resources/presets/*.json → ThemeRegistry), per-area theme/density
 * settings, custom themes from the admin editor, brand assets, and the runtime CSS variables.
 * Other modules or theme packs add presets with app(ThemeRegistry::class)->register(...).
 */
class ThemesServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'themes';
    }

    protected function registerModule(): void
    {
        $this->app->singleton(ThemeManager::class);
        $this->app->singleton(ThemeStylesheet::class);
        $this->app->singleton(BrandAssets::class);
        $this->app->singleton(BrandProvider::class, fn ($app) => $app->make(BrandAssets::class));
    }

    protected function bootModule(): void
    {
        $registry = $this->app->make(ThemeRegistry::class);

        foreach (['calm', 'operator', 'warm', 'slate'] as $key) {
            $path = $this->modulePath("Resources/presets/{$key}.json");
            /** @var array<string, mixed> $document */
            $document = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
            $registry->register(ThemePreset::fromDocument($document, (string) filemtime($path)));
        }

        $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', ShareTheme::class);

        // Public brand images: no session, cookies, CSRF or Inertia (cacheable, no session rows).
        if (! $this->app->routesAreCached()) {
            Route::get('branding/{kind}', [BrandAssetController::class, 'show'])
                ->whereIn('kind', BrandAssets::KINDS)
                ->name('branding.show');
        }

        $this->app->make(Navigation::class)->add('admin', new NavItem('Appearance', 'admin.appearance.edit', 'Palette', 'Setup', 80, 'themes.manage', 'admin.appearance.*'));

        $this->app->make(PermissionRegistry::class)->register('Appearance', [
            'themes.manage' => 'Manage themes, branding and appearance settings',
        ]);
    }
}
