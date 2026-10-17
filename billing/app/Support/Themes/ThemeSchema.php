<?php

namespace App\Support\Themes;

/**
 * The theme token contract (schema "billing-theme", version 1): the single source of truth for
 * what a theme may contain. Every value is checked against a strict per-type whitelist so tokens
 * can be emitted as CSS custom properties without escaping concerns (no url(), no ; or }).
 *
 * Document shape:
 *   {"schema": "billing-theme", "version": 1, "name": "…", "tokens": {
 *       "shared": {font-*, radius, density, type scale…},
 *       "light":  {colours, chart palette, elevation…},
 *       "dark":   {same keys as light}}}
 *
 * The frontend mirror (contrast + token→CSS) lives in resources/js/lib/theme-tokens.ts.
 */
final class ThemeSchema
{
    public const NAME = 'billing-theme';

    public const VERSION = 1;

    public const KEY_PATTERN = '/^[a-z][a-z0-9-]{1,47}$/';

    /** Maximum size of an imported theme document. */
    public const MAX_DOCUMENT_BYTES = 32_768;

    /**
     * Self-hosted font allow-list (vite.config.ts → laravel-vite-plugin fonts). key => [label, kind, CSS stack].
     *
     * @var array<string, array{0: string, 1: 'sans'|'mono', 2: string}>
     */
    public const FONTS = [
        'inter' => ['Inter', 'sans', "var(--font-inter, 'Inter'), ui-sans-serif, system-ui, sans-serif"],
        'ibm-plex-sans' => ['IBM Plex Sans', 'sans', "var(--font-ibm-plex-sans, 'IBM Plex Sans'), ui-sans-serif, system-ui, sans-serif"],
        'figtree' => ['Figtree', 'sans', "var(--font-figtree, 'Figtree'), ui-sans-serif, system-ui, sans-serif"],
        'geist' => ['Geist', 'sans', "var(--font-geist, 'Geist'), ui-sans-serif, system-ui, sans-serif"],
        'instrument-sans' => ['Instrument Sans', 'sans', "var(--font-instrument-sans, 'Instrument Sans'), ui-sans-serif, system-ui, sans-serif"],
        'system' => ['System UI', 'sans', 'ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, sans-serif'],
        'ibm-plex-mono' => ['IBM Plex Mono', 'mono', "var(--font-ibm-plex-mono, 'IBM Plex Mono'), ui-monospace, SFMono-Regular, Menlo, Consolas, monospace"],
        'geist-mono' => ['Geist Mono', 'mono', "var(--font-geist-mono, 'Geist Mono'), ui-monospace, SFMono-Regular, Menlo, Consolas, monospace"],
        'system-mono' => ['System monospace', 'mono', 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace'],
    ];

    /** OpenType feature presets (value => CSS font-feature-settings). */
    public const FONT_FEATURES = [
        'normal' => 'normal',
        'cv11-ss03' => "'cv11' 1, 'ss03' 1",
        'ss01' => "'ss01' 1",
        'tnum' => "'tnum' 1",
    ];

    /**
     * Theme-wide tokens: name => type.
     *
     * @var array<string, string>
     */
    public const SHARED = [
        'font-sans' => 'font-sans',
        'font-mono' => 'font-mono',
        'font-display' => 'font-sans',
        'font-numeric' => 'font',
        'font-features' => 'features',
        'root-font-size' => 'percent',
        'heading-weight' => 'weight',
        'heading-tracking' => 'length',
        'eyebrow-size' => 'length',
        'eyebrow-weight' => 'weight',
        'eyebrow-case' => 'case',
        'eyebrow-tracking' => 'length',
        'radius' => 'length',
        'badge-radius' => 'length',
        'shell-gap' => 'length',
        'shell-radius' => 'length',
        'cell-py' => 'length',
        'table-head-h' => 'length',
        'card-p' => 'length',
        'stat-p' => 'length',
        'page-gap' => 'length',
        'nav-item-h' => 'length',
    ];

    /** Colour tokens defined for both light and dark. */
    public const COLORS = [
        'background', 'foreground', 'card', 'card-foreground', 'popover', 'popover-foreground',
        'primary', 'primary-foreground', 'secondary', 'secondary-foreground', 'muted', 'muted-foreground',
        'accent', 'accent-foreground', 'destructive', 'destructive-foreground', 'border', 'input', 'ring',
        'sidebar', 'sidebar-foreground', 'sidebar-primary', 'sidebar-primary-foreground', 'sidebar-accent',
        'sidebar-accent-foreground', 'sidebar-border', 'sidebar-ring',
        'success', 'success-foreground', 'success-subtle', 'success-text',
        'warning', 'warning-foreground', 'warning-subtle', 'warning-text',
        'danger', 'danger-foreground', 'danger-subtle', 'danger-text',
        'info', 'info-foreground', 'info-subtle', 'info-text',
        'chart-1', 'chart-2', 'chart-3', 'chart-4', 'chart-5', 'chart-6', 'chart-grid',
        'shell-border',
    ];

    /** Shadow tokens defined for both light and dark. */
    public const SHADOWS = ['elevation-card', 'elevation-hover', 'shell-shadow'];

    /**
     * Pairs that must meet WCAG AA in both modes: [foreground, background, minimum ratio].
     * Text pairs need 4.5:1, chart marks 3:1 against the card (WCAG 1.4.11).
     *
     * @var list<array{0: string, 1: string, 2: float}>
     */
    public const CONTRAST_PAIRS = [
        ['foreground', 'background', 4.5],
        ['card-foreground', 'card', 4.5],
        ['popover-foreground', 'popover', 4.5],
        ['muted-foreground', 'background', 4.5],
        ['muted-foreground', 'card', 4.5],
        ['muted-foreground', 'muted', 4.5],
        ['primary-foreground', 'primary', 4.5],
        ['secondary-foreground', 'secondary', 4.5],
        ['accent-foreground', 'accent', 4.5],
        ['sidebar-foreground', 'sidebar', 4.5],
        ['sidebar-accent-foreground', 'sidebar-accent', 4.5],
        ['sidebar-primary-foreground', 'sidebar-primary', 4.5],
        ['success-text', 'success-subtle', 4.5],
        ['warning-text', 'warning-subtle', 4.5],
        ['danger-text', 'danger-subtle', 4.5],
        ['info-text', 'info-subtle', 4.5],
        ['success-text', 'card', 4.5],
        ['danger-text', 'card', 4.5],
        ['destructive-foreground', 'card', 4.5],
        ['chart-1', 'card', 3.0],
        ['chart-2', 'card', 3.0],
        ['chart-3', 'card', 3.0],
        ['chart-4', 'card', 3.0],
        ['chart-5', 'card', 3.0],
        ['chart-6', 'card', 3.0],
    ];

    /** Density presets that override the theme's own spacing tokens. */
    public const DENSITIES = [
        'compact' => ['cell-py' => '0.4375rem', 'table-head-h' => '2rem', 'card-p' => '1rem', 'stat-p' => '0.875rem', 'page-gap' => '1rem', 'nav-item-h' => '1.875rem'],
        'comfortable' => ['cell-py' => '0.625rem', 'table-head-h' => '2.25rem', 'card-p' => '1.25rem', 'stat-p' => '1.125rem', 'page-gap' => '1.5rem', 'nav-item-h' => '2rem'],
        'spacious' => ['cell-py' => '0.75rem', 'table-head-h' => '2.5rem', 'card-p' => '1.5rem', 'stat-p' => '1.25rem', 'page-gap' => '1.75rem', 'nav-item-h' => '2.25rem'],
    ];

    private const PATTERNS = [
        'color' => '/^#[0-9a-fA-F]{6}$/',
        'length' => '/^(0|-?\d{1,4}(\.\d{1,4})?(rem|px|em))$/',
        'percent' => '/^\d{2,3}(\.\d{1,2})?%$/',
    ];

    /** One shadow layer: 2–4 lengths and a colour; up to three layers, or "none". */
    private const SHADOW_LAYER = '(?:-?\d{1,3}(?:\.\d{1,3})?(?:px|rem)?\s+){2,4}(?:rgb\(\d{1,3} \d{1,3} \d{1,3} \/ (?:0|1|0?\.\d{1,3})\)|transparent|#[0-9a-fA-F]{6})';

    /**
     * Validate a token set. Returns [path => message]; empty when valid. Unknown keys are errors.
     *
     * @param  array<mixed>  $tokens
     * @return array<string, string>
     */
    public static function validate(array $tokens): array
    {
        $errors = [];

        foreach (array_diff(array_keys($tokens), ['shared', 'light', 'dark']) as $unknown) {
            $errors["tokens.{$unknown}"] = 'Unknown token group.';
        }

        foreach (['shared', 'light', 'dark'] as $group) {
            if (! isset($tokens[$group]) || ! is_array($tokens[$group])) {
                $errors["tokens.{$group}"] = 'Missing token group.';

                continue;
            }

            $types = $group === 'shared' ? self::SHARED : self::modeTypes();

            foreach (array_diff(array_keys($tokens[$group]), array_keys($types)) as $unknown) {
                $errors["tokens.{$group}.{$unknown}"] = 'Unknown token.';
            }

            foreach ($types as $name => $type) {
                if (! array_key_exists($name, $tokens[$group])) {
                    $errors["tokens.{$group}.{$name}"] = 'Missing token.';

                    continue;
                }

                if (! self::valid($type, $tokens[$group][$name])) {
                    $errors["tokens.{$group}.{$name}"] = self::message($type);
                }
            }
        }

        return $errors;
    }

    /**
     * Key pairs below WCAG AA, per mode. Assumes validate() passed.
     *
     * @param  array<mixed>  $tokens
     * @return list<array{mode: string, foreground: string, background: string, ratio: float, minimum: float}>
     */
    public static function contrastFailures(array $tokens): array
    {
        $failures = [];

        foreach (['light', 'dark'] as $mode) {
            foreach (self::CONTRAST_PAIRS as [$foreground, $background, $minimum]) {
                $colors = is_array($tokens[$mode] ?? null) ? $tokens[$mode] : [];
                $fg = $colors[$foreground] ?? null;
                $bg = $colors[$background] ?? null;

                if (! self::valid('color', $fg) || ! self::valid('color', $bg)) {
                    continue;
                }

                $ratio = Contrast::ratio((string) $fg, (string) $bg);

                if ($ratio + 1e-9 < $minimum) {
                    $failures[] = ['mode' => $mode, 'foreground' => $foreground, 'background' => $background, 'ratio' => round($ratio, 2), 'minimum' => $minimum];
                }
            }
        }

        return $failures;
    }

    /**
     * @return array<string, string>
     */
    public static function modeTypes(): array
    {
        return [
            ...array_fill_keys(self::COLORS, 'color'),
            ...array_fill_keys(self::SHADOWS, 'shadow'),
        ];
    }

    public static function valid(string $type, mixed $value): bool
    {
        return match ($type) {
            'color', 'length' => is_string($value) && preg_match(self::PATTERNS[$type], $value) === 1,
            'percent' => is_string($value) && preg_match(self::PATTERNS['percent'], $value) === 1
                && (float) $value >= 75 && (float) $value <= 125,
            'weight' => is_int($value) && $value >= 300 && $value <= 800 && $value % 100 === 0,
            'case' => in_array($value, ['none', 'uppercase'], true),
            'features' => is_string($value) && array_key_exists($value, self::FONT_FEATURES),
            'font' => is_string($value) && array_key_exists($value, self::FONTS),
            'font-sans' => is_string($value) && (self::FONTS[$value][1] ?? null) === 'sans',
            'font-mono' => is_string($value) && (self::FONTS[$value][1] ?? null) === 'mono',
            'shadow' => is_string($value) && strlen($value) <= 200
                && ($value === 'none' || preg_match('/^'.self::SHADOW_LAYER.'(?:,\s*'.self::SHADOW_LAYER.'){0,2}$/', $value) === 1),
            default => false,
        };
    }

    /**
     * CSS font stack for an allow-listed font key.
     */
    public static function fontStack(mixed $key): ?string
    {
        return is_string($key) && isset(self::FONTS[$key]) ? self::FONTS[$key][2] : null;
    }

    /**
     * CSS font-feature-settings for an allow-listed preset.
     */
    public static function fontFeatures(mixed $key): ?string
    {
        return is_string($key) && isset(self::FONT_FEATURES[$key]) ? self::FONT_FEATURES[$key] : null;
    }

    private static function message(string $type): string
    {
        return match ($type) {
            'color' => 'Use a hex colour like #4F4BC4.',
            'length' => 'Use a length like 0.5rem, 12px or -0.02em.',
            'percent' => 'Use a percentage between 75% and 125%.',
            'weight' => 'Use a font weight between 300 and 800.',
            'case' => 'Use "none" or "uppercase".',
            'features' => 'Choose one of the supported font feature sets.',
            'font', 'font-sans', 'font-mono' => 'Choose one of the installed fonts.',
            'shadow' => 'Use "none" or up to three shadows like 0 1px 2px rgb(16 24 40 / 0.05).',
            default => 'Invalid value.',
        };
    }

    /**
     * Schema metadata for the theme editor and the published JSON Schema.
     *
     * @return array<string, mixed>
     */
    public static function describe(): array
    {
        return [
            'name' => self::NAME,
            'version' => self::VERSION,
            'shared' => self::SHARED,
            'mode' => self::modeTypes(),
            'contrastPairs' => self::CONTRAST_PAIRS,
            'fonts' => array_map(fn (array $font): array => ['label' => $font[0], 'kind' => $font[1], 'stack' => $font[2]], self::FONTS),
            'fontFeatures' => self::FONT_FEATURES,
            'densities' => self::DENSITIES,
        ];
    }

    /**
     * JSON Schema (draft 2020-12) for exported/imported theme documents.
     *
     * @return array<string, mixed>
     */
    public static function jsonSchema(): array
    {
        $property = fn (string $type): array => match ($type) {
            'color' => ['type' => 'string', 'pattern' => trim(self::PATTERNS['color'], '/')],
            'length' => ['type' => 'string', 'pattern' => trim(self::PATTERNS['length'], '/')],
            'percent' => ['type' => 'string', 'pattern' => trim(self::PATTERNS['percent'], '/')],
            'weight' => ['type' => 'integer', 'minimum' => 300, 'maximum' => 800, 'multipleOf' => 100],
            'case' => ['enum' => ['none', 'uppercase']],
            'features' => ['enum' => array_keys(self::FONT_FEATURES)],
            'font' => ['enum' => array_keys(self::FONTS)],
            'font-sans' => ['enum' => array_keys(array_filter(self::FONTS, fn (array $font): bool => $font[1] === 'sans'))],
            'font-mono' => ['enum' => array_keys(array_filter(self::FONTS, fn (array $font): bool => $font[1] === 'mono'))],
            'shadow' => ['type' => 'string', 'maxLength' => 200],
            default => [],
        };
        $group = fn (array $types): array => [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array_keys($types),
            'properties' => array_map($property, $types),
        ];

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://billing.local/schemas/theme.v1.json',
            'title' => 'Billing theme',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['schema', 'version', 'name', 'tokens'],
            'properties' => [
                'schema' => ['const' => self::NAME],
                'version' => ['const' => self::VERSION],
                'key' => ['type' => 'string', 'pattern' => trim(self::KEY_PATTERN, '/')],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 60],
                'description' => ['type' => 'string', 'maxLength' => 200],
                'base' => ['type' => 'string', 'maxLength' => 48],
                'tokens' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['shared', 'light', 'dark'],
                    'properties' => [
                        'shared' => $group(self::SHARED),
                        'light' => $group(self::modeTypes()),
                        'dark' => $group(self::modeTypes()),
                    ],
                ],
            ],
        ];
    }
}
