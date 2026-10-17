<?php

namespace App\Modules\Themes\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\Settings;
use App\Modules\Themes\Http\Requests\AppearanceSettingsRequest;
use App\Modules\Themes\Models\Theme;
use App\Modules\Themes\Support\BrandAssets;
use App\Modules\Themes\Support\ThemeManager;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AppearanceController extends Controller
{
    public function __construct(private ThemeManager $themes, private Settings $settings) {}

    public function edit(BrandAssets $brand): Response
    {
        $this->authorize('themes.manage');

        $custom = Theme::query()->pluck('id', 'key');

        return Inertia::render('admin/settings/appearance', [
            'settings' => [
                'company_name' => $brand->name(),
                'tagline' => $brand->tagline(),
                'default_admin' => $this->themes->areaDefault('admin'),
                'default_client' => $this->themes->areaDefault('client'),
                'default_public' => $this->themes->areaDefault('public'),
                'density' => $this->themes->density('public'),
                'allow_user_choice' => $this->themes->allowsUserChoice(),
            ],
            'themes' => array_map(fn (array $theme): array => [
                ...$theme,
                'id' => $custom[$theme['key']] ?? null,
                'usages' => $this->themes->usages($theme['key']),
                'swatches' => $this->swatches($theme['key']),
            ], $this->themes->options()),
            'densities' => ThemeManager::DENSITIES,
            'brand' => $brand->brand()->toArray(),
            'uploads' => array_combine(BrandAssets::KINDS, array_map(fn (string $kind): bool => $brand->path($kind) !== null, BrandAssets::KINDS)),
        ]);
    }

    public function update(AppearanceSettingsRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $this->settings->setMany([
            'core.company_name' => $data['company_name'],
            'themes.tagline' => (string) ($data['tagline'] ?? ''),
            'themes.default_admin' => $data['default_admin'],
            'themes.default_client' => $data['default_client'],
            'themes.default_public' => $data['default_public'],
            'themes.density' => $data['density'],
            'themes.allow_user_choice' => (bool) $data['allow_user_choice'],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Appearance settings saved.')]);

        return to_route('admin.settings.appearance');
    }

    /**
     * A few representative colours for the theme picker cards.
     *
     * @return array{light: list<string>, dark: list<string>}
     */
    private function swatches(string $key): array
    {
        $tokens = $this->themes->find($key)?->tokens;
        $pick = fn (string $mode): array => array_map(
            fn (string $name): string => (string) ($tokens[$mode][$name] ?? '#000000'),
            ['background', 'sidebar', 'primary', 'chart-2', 'success', 'danger'],
        );

        return ['light' => $pick('light'), 'dark' => $pick('dark')];
    }
}
