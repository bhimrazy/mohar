<?php

namespace App\Support\Localization;

use Illuminate\Translation\FileLoader;

/**
 * The JSON message catalogue of a locale (ADR 0028): `lang/<locale>.json` plus the `Resources/lang` folders modules
 * register, merged by Laravel's translation loader. Shared with React as the `translations` Inertia prop.
 *
 * `php artisan optimize` writes every locale, with a version hash of its content, to `bootstrap/cache/i18n.php`
 * (`i18n:cache`) and `optimize:clear` removes it. Without that file the catalogue is read from `lang/` once per
 * request in classic PHP, and once per worker in a long-running worker (Octane, queue:work): restart workers after
 * changing `lang/`.
 *
 * Internal to the app (not `@api`): modules contribute through `Resources/lang`, not through this class.
 */
final class Catalogue
{
    /** @var array<string, array{messages: array<string, string>, version: string}>|null */
    private static ?array $built = null;

    /**
     * @return array<string, string> English keys to translations; empty when the locale has no file.
     */
    public static function messages(string $locale): array
    {
        return self::entry($locale)['messages'];
    }

    /**
     * Changes whenever the catalogue content does; the Inertia once-prop key includes it, so a browser re-fetches
     * only then.
     */
    public static function version(string $locale): string
    {
        return self::entry($locale)['version'];
    }

    public static function cachePath(): string
    {
        return app()->bootstrapPath('cache/i18n.php');
    }

    /**
     * Every locale that has a JSON file in any registered path.
     *
     * @return array<string, array{messages: array<string, string>, version: string}>
     */
    public static function buildAll(): array
    {
        $loader = app('translator')->getLoader();
        $locales = [];

        foreach ($loader instanceof FileLoader ? $loader->jsonPaths() : [] as $path) {
            foreach (glob($path.'/*.json') ?: [] as $file) {
                $locales[basename($file, '.json')] = true;
            }
        }

        $all = [];
        foreach (array_keys($locales) as $locale) {
            $all[$locale] = self::build($locale);
        }

        return $all;
    }

    /**
     * Forgets the in-process copy (tests, and after the cache file changes).
     */
    public static function flush(): void
    {
        self::$built = null;
    }

    /**
     * @return array{messages: array<string, string>, version: string}
     */
    private static function entry(string $locale): array
    {
        self::$built ??= is_file($file = self::cachePath()) ? (array) require $file : [];

        return self::$built[$locale] ??= self::build($locale);
    }

    /**
     * @return array{messages: array<string, string>, version: string}
     */
    private static function build(string $locale): array
    {
        /** @var array<string, string> $messages */
        $messages = array_filter(
            app('translator')->getLoader()->load($locale, '*', '*'),
            fn (mixed $line): bool => is_string($line),
        );
        ksort($messages);

        return ['messages' => $messages, 'version' => substr(sha1(json_encode($messages, JSON_THROW_ON_ERROR)), 0, 12)];
    }
}
