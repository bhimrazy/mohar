<?php

namespace App\Modules\Themes\Support;

use App\Support\Themes\ThemeSchema;
use Illuminate\Support\Facades\Cache;

/**
 * Turns validated theme tokens into the CSS custom properties consumed by resources/css/app.css.
 * Output is cached per theme key + version + density. Values are re-checked against the schema
 * whitelist here (defence in depth), so nothing outside the allowed grammar reaches the page.
 * Mirrored client-side by tokensToCssVars() in resources/js/lib/theme-tokens.ts (editor preview).
 */
class ThemeStylesheet
{
    /** Bump when the generated CSS shape changes. */
    public const RENDERER_VERSION = 1;

    public function css(ResolvedTheme $theme, string $density = 'theme'): string
    {
        $key = sprintf('themes.css:%s:%s:%s:%d', $theme->key, $theme->version, $density, self::RENDERER_VERSION);

        return Cache::rememberForever($key, fn (): string => $this->render($theme->tokens, $density));
    }

    /**
     * @param  array{shared: array<string, string|int>, light: array<string, string>, dark: array<string, string>}  $tokens
     */
    public function render(array $tokens, string $density = 'theme'): string
    {
        $root = $this->sharedVariables($tokens['shared'], $density) + $this->modeVariables($tokens['light']);

        // html:root outranks the fallback :root/.dark blocks in app.css, which load later.
        return 'html:root{'.$this->declarations($root).'}'
            .'html:root.dark{'.$this->declarations($this->modeVariables($tokens['dark'])).'}';
    }

    /**
     * @param  array<string, string|int>  $shared
     * @return array<string, string>
     */
    public function sharedVariables(array $shared, string $density = 'theme'): array
    {
        $shared = [...$shared, ...(ThemeSchema::DENSITIES[$density] ?? [])];
        $variables = [];

        foreach (ThemeSchema::SHARED as $name => $type) {
            $value = $shared[$name] ?? null;

            if (! ThemeSchema::valid($type, $value)) {
                continue;
            }

            match ($name) {
                'font-sans' => $variables['--app-font-sans'] = ThemeSchema::FONTS[$value][2],
                'font-mono' => $variables['--app-font-mono'] = ThemeSchema::FONTS[$value][2],
                'font-display' => $variables['--app-font-display'] = ThemeSchema::FONTS[$value][2],
                'font-numeric' => $variables['--app-font-numeric'] = ThemeSchema::FONTS[$value][2],
                'font-features' => $variables['--font-features'] = ThemeSchema::FONT_FEATURES[$value],
                default => $variables["--{$name}"] = (string) $value,
            };
        }

        return $variables;
    }

    /**
     * @param  array<string, string>  $mode
     * @return array<string, string>
     */
    public function modeVariables(array $mode): array
    {
        $variables = [];

        foreach (ThemeSchema::modeTypes() as $name => $type) {
            if (ThemeSchema::valid($type, $mode[$name] ?? null)) {
                $variables["--{$name}"] = $mode[$name];
            }
        }

        // The kit's destructive tone follows the theme's danger colour.
        if (isset($variables['--danger'])) {
            $variables['--destructive'] ??= $variables['--danger'];
        }

        return $variables;
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function declarations(array $variables): string
    {
        $css = '';

        foreach ($variables as $name => $value) {
            // Never emit anything that could close the declaration, rule or <style> element.
            if (preg_match('/[;{}<>\\\\]|url\(|expression\(|@import/i', $value) === 1) {
                continue;
            }

            $css .= "{$name}:{$value};";
        }

        return $css;
    }
}
