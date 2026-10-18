<?php

namespace App\Support\Themes;

use InvalidArgumentException;

/**
 * Built-in theme presets. Modules and theme packs register theirs in bootModule():
 *
 *   app(ThemeRegistry::class)->register(ThemePreset::fromDocument(json_decode(file_get_contents($path), true)));
 *
 * Presets are validated against ThemeSchema on registration, so a broken pack fails loudly in dev/CI
 * instead of rendering a half-styled page.
 */
class ThemeRegistry
{
    /** @var array<string, ThemePreset> */
    private array $presets = [];

    public function register(ThemePreset $preset): void
    {
        // "custom-*" keys are reserved for themes saved in the admin editor.
        if (preg_match(ThemeSchema::KEY_PATTERN, $preset->key) !== 1 || str_starts_with($preset->key, 'custom-')) {
            throw new InvalidArgumentException("Invalid theme key [{$preset->key}].");
        }

        $errors = ThemeSchema::validate($preset->tokens);

        if ($errors !== []) {
            throw new InvalidArgumentException("Theme preset [{$preset->key}] is invalid: ".implode('; ', array_map(
                fn (string $path, string $message): string => "{$path}: {$message}",
                array_keys($errors),
                $errors,
            )));
        }

        $this->presets[$preset->key] = $preset;
    }

    public function has(string $key): bool
    {
        return isset($this->presets[$key]);
    }

    public function get(string $key): ?ThemePreset
    {
        return $this->presets[$key] ?? null;
    }

    /**
     * @return array<string, ThemePreset>
     */
    public function all(): array
    {
        return $this->presets;
    }
}
