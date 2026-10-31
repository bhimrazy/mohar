<?php

namespace App\Support\Localization;

use Illuminate\Support\Facades\Cache;

/**
 * The JSON message catalogue of a locale (ADR 0028): `lang/<locale>.json` plus the `Resources/lang` folders modules
 * register, merged by Laravel's translation loader. Shared with React as the `translations` Inertia prop.
 */
final class Catalogue
{
    /**
     * @return array<string, string> English keys to translations; empty when the locale has no file.
     */
    public static function messages(string $locale): array
    {
        return self::entry($locale)['messages'];
    }

    /**
     * Changes whenever the catalogue content does, so a browser re-fetches only then.
     */
    public static function version(string $locale): string
    {
        return self::entry($locale)['version'];
    }

    /**
     * Read once and cached until `cache:clear` (run at deploy), so a request never stats or decodes the files.
     *
     * @return array{messages: array<string, string>, version: string}
     */
    private static function entry(string $locale): array
    {
        return Cache::rememberForever('i18n.catalogue.'.$locale, function () use ($locale): array {
            /** @var array<string, string> $messages */
            $messages = array_filter(
                app('translator')->getLoader()->load($locale, '*', '*'),
                fn (mixed $line): bool => is_string($line),
            );
            ksort($messages);

            return ['messages' => $messages, 'version' => substr(sha1(json_encode($messages, JSON_THROW_ON_ERROR)), 0, 12)];
        });
    }
}
