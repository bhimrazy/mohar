<?php

namespace App\Modules\Themes\Http\Controllers\Admin;

use App\Events\Core\SettingsUpdated;
use App\Http\Controllers\Controller;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Themes\Http\Requests\AppearanceSettingsRequest;
use App\Modules\Themes\Http\Requests\BrandTextRequest;
use App\Modules\Themes\Models\Theme;
use App\Modules\Themes\Support\BrandAssets;
use App\Modules\Themes\Support\ThemeManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AppearanceController extends Controller
{
    public function __construct(
        private ThemeManager $themes,
        private Settings $settings,
        private ActivityLogger $activity,
    ) {}

    public function edit(Request $request, BrandAssets $brand): Response
    {
        $this->authorize('themes.manage');

        $custom = Theme::query()->get(['id', 'key', 'tokens'])->keyBy('key');
        $usages = $this->themes->usagesByKey();

        return Inertia::render('admin/settings/appearance', [
            'settings' => [
                'tagline' => $brand->tagline(),
                'default_admin' => $this->themes->areaDefault('admin'),
                'default_client' => $this->themes->areaDefault('client'),
                'default_public' => $this->themes->areaDefault('public'),
                'density' => $this->themes->density('public'),
                'allow_user_choice' => $this->themes->allowsUserChoice(),
            ],
            // The company name belongs to Core's settings (Settings → Company); shown read-only here.
            'companyName' => $brand->name(),
            'companySettingsUrl' => Route::has('admin.platform.settings.edit') && $request->user()?->hasPermission('core.settings.update')
                ? route('admin.platform.settings.edit', ['section' => 'company'])
                : null,
            'themes' => array_map(function (array $theme) use ($custom, $usages): array {
                $model = $custom->get($theme['key']);
                $tokens = $model !== null ? $model->tokens : $this->themes->find($theme['key'])?->tokens;

                return [
                    ...$theme,
                    'id' => $model?->id,
                    'usages' => $usages[$theme['key']] ?? [],
                    'swatches' => $this->swatches($tokens ?? []),
                ];
            }, $this->themes->options()),
            'densities' => ThemeManager::DENSITIES,
            'brand' => $brand->brand()->toArray(),
            'uploads' => array_combine(BrandAssets::KINDS, array_map(fn (string $kind): bool => $brand->path($kind) !== null, BrandAssets::KINDS)),
        ]);
    }

    public function update(AppearanceSettingsRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $this->save('Appearance settings updated', 'themes.appearance_updated', [
            'themes.default_admin' => $data['default_admin'],
            'themes.default_client' => $data['default_client'],
            'themes.default_public' => $data['default_public'],
            'themes.density' => $data['density'],
            'themes.allow_user_choice' => (bool) $data['allow_user_choice'],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Appearance settings saved.')]);

        return to_route('admin.appearance.edit');
    }

    public function updateBrand(BrandTextRequest $request): RedirectResponse
    {
        $this->save('Brand tagline updated', 'themes.brand_updated', [
            'themes.tagline' => trim((string) $request->validated('tagline')),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Brand saved.')]);

        return to_route('admin.appearance.edit');
    }

    /**
     * Persist settings, then audit the before/after diff and announce the changed keys.
     *
     * @param  array<string, mixed>  $values
     */
    private function save(string $description, string $event, array $values): void
    {
        $before = array_combine(array_keys($values), array_map(fn (string $key): mixed => $this->settings->get($key), array_keys($values)));
        $changed = array_keys(array_filter($values, fn (mixed $value, string $key): bool => $before[$key] !== $value, ARRAY_FILTER_USE_BOTH));

        if ($changed === []) {
            return;
        }

        $this->settings->setMany($values);
        $this->activity->log($description, properties: [
            'changes' => array_map(fn (string $key): array => ['from' => $before[$key], 'to' => $values[$key]], array_combine($changed, $changed)),
        ], event: $event);
        SettingsUpdated::dispatch($changed);
    }

    /**
     * A few representative colours for the theme picker cards.
     *
     * @param  array<mixed>  $tokens
     * @return array{light: list<string>, dark: list<string>}
     */
    private function swatches(array $tokens): array
    {
        $pick = fn (string $mode): array => array_map(
            fn (string $name): string => is_array($tokens[$mode] ?? null) && is_string($tokens[$mode][$name] ?? null) ? $tokens[$mode][$name] : '#000000',
            ['background', 'sidebar', 'primary', 'chart-2', 'success', 'danger'],
        );

        return ['light' => $pick('light'), 'dark' => $pick('dark')];
    }
}
