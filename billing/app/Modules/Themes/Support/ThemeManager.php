<?php

namespace App\Modules\Themes\Support;

use App\Models\User;
use App\Modules\Core\Support\Settings;
use App\Modules\Themes\Models\Theme;
use App\Support\Themes\ThemeRegistry;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Resolves which theme and density a request renders with, and lists the selectable themes.
 *
 * Areas: "admin" (staff panel), "client" (client area) and "public" (storefront, login pages).
 * Staff may override the admin theme/density with their own preference when the
 * "themes.allow_user_choice" setting is on; client-area users always get the area theme.
 */
class ThemeManager
{
    public const AREAS = ['admin', 'client', 'public'];

    public const DENSITIES = ['theme', 'compact', 'comfortable', 'spacious'];

    public const FALLBACK = 'calm';

    /** @var array<string, ResolvedTheme> Presets only. */
    private array $resolved = [];

    public function __construct(private ThemeRegistry $registry, private Settings $settings) {}

    public static function areaFor(Request $request): string
    {
        $name = (string) $request->route()?->getName();
        $explicit = $request->route()?->defaults['theme_area'] ?? null;

        if (in_array($explicit, self::AREAS, true)) {
            return $explicit;
        }

        return match (true) {
            str_starts_with($name, 'admin.'), $name === 'dashboard' => 'admin',
            str_starts_with($name, 'client.') => 'client',
            in_array($name, ['profile.edit', 'security.edit', 'appearance.edit'], true) => $request->user()?->is_staff ? 'admin' : 'client',
            default => 'public',
        };
    }

    public function find(string $key): ?ResolvedTheme
    {
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        if ($preset = $this->registry->get($key)) {
            return $this->resolved[$key] = new ResolvedTheme($preset->key, $preset->name, $preset->tokens, 'p'.$preset->version, false, $preset->description);
        }

        // Custom themes are not memoised: they can change between requests on a long-lived worker.
        $theme = str_starts_with($key, 'custom-') ? Theme::query()->where('key', $key)->first() : null;

        return $theme === null ? null : $this->fromModel($theme);
    }

    public function fromModel(Theme $theme): ResolvedTheme
    {
        return new ResolvedTheme($theme->key, $theme->name, $theme->tokens, 'c'.$theme->version, true);
    }

    public function exists(string $key): bool
    {
        return $this->find($key) !== null;
    }

    /**
     * The theme for an area, honouring the staff preference when allowed.
     */
    public function resolve(string $area, ?User $user = null, ?string $override = null): ResolvedTheme
    {
        $candidates = [
            $override,
            $area === 'admin' && $user?->is_staff && $this->allowsUserChoice() ? self::preference($user, 'theme') : null,
            $this->areaDefault($area),
            self::FALLBACK,
        ];

        foreach ($candidates as $key) {
            if (is_string($key) && $key !== '' && ($theme = $this->find($key))) {
                return $theme;
            }
        }

        $first = array_key_first($this->registry->all());

        return $this->find((string) $first) ?? throw new RuntimeException('No theme presets are registered.');
    }

    public function density(string $area, ?User $user = null): string
    {
        $preference = $area === 'admin' && $user?->is_staff && $this->allowsUserChoice() ? self::preference($user, 'density') : null;
        $density = $preference ?: (string) $this->settings->get('themes.density', 'theme');

        return in_array($density, self::DENSITIES, true) ? $density : 'theme';
    }

    /**
     * A user's stored preference ("theme" or "density"); tolerant of partially loaded models.
     */
    public static function preference(User $user, string $kind): ?string
    {
        $value = $user->getAttributes()["{$kind}_preference"] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function areaDefault(string $area): string
    {
        return (string) $this->settings->get("themes.default_{$area}", self::FALLBACK);
    }

    public function allowsUserChoice(): bool
    {
        return (bool) $this->settings->get('themes.allow_user_choice', true);
    }

    /**
     * Presets first, then custom themes.
     *
     * @return list<array{key: string, name: string, description: string, custom: bool}>
     */
    public function options(): array
    {
        $presets = array_map(fn ($preset): array => [
            'key' => $preset->key,
            'name' => $preset->name,
            'description' => $preset->description,
            'custom' => false,
        ], array_values($this->registry->all()));

        $custom = Theme::query()->orderBy('name')->get(['key', 'name', 'base_preset'])->map(fn (Theme $theme): array => [
            'key' => $theme->key,
            'name' => $theme->name,
            'description' => $theme->base_preset ? "Custom, based on {$theme->base_preset}" : 'Custom',
            'custom' => true,
        ])->all();

        return [...$presets, ...array_values($custom)];
    }

    /**
     * Where a theme is in use (area defaults and staff preferences) — empty when unused.
     *
     * @return list<string>
     */
    public function usages(string $key): array
    {
        return $this->usagesByKey([$key])[$key] ?? [];
    }

    /**
     * Usages for many themes with a single query (Appearance page).
     *
     * @param  list<string>|null  $keys  null = every key in use
     * @return array<string, list<string>>
     */
    public function usagesByKey(?array $keys = null): array
    {
        $usages = [];

        foreach (self::AREAS as $area) {
            $usages[$this->areaDefault($area)][] = match ($area) {
                'admin' => 'Admin default',
                'client' => 'Client area default',
                default => 'Storefront default',
            };
        }

        $preferences = User::query()
            ->whereNotNull('theme_preference')
            ->when($keys !== null, fn ($query) => $query->whereIn('theme_preference', $keys))
            ->selectRaw('theme_preference, count(*) as total')
            ->groupBy('theme_preference')
            ->pluck('total', 'theme_preference');

        foreach ($preferences as $key => $total) {
            $usages[(string) $key][] = trans_choice(':count staff preference|:count staff preferences', (int) $total);
        }

        return $keys === null ? $usages : array_intersect_key($usages, array_flip($keys));
    }

    /**
     * A preset's tokens, for duplicating into a custom theme.
     *
     * @return array{shared: array<string, string|int>, light: array<string, string>, dark: array<string, string>}
     */
    public function presetDocument(string $key): array
    {
        return ($this->registry->get($key) ?? throw new RuntimeException("Unknown preset [{$key}]."))->tokens;
    }
}
