<?php

namespace App\Modules\Core\Support;

/**
 * The date formats the "Date format" setting (core.date_format) may hold. The admin select,
 * the shared `dateFormat` prop, PDFs and mail all read the setting through this list, so a
 * value stored any other way (import, seeder, hand edit) never reaches a formatter unchecked.
 *
 * Keys are PHP date() patterns; resources/js/lib/date.ts keeps the same keys in DATE_FORMATS.
 */
final class DateFormats
{
    public const DEFAULT = 'M j, Y';

    /** @var array<string, string> pattern => example shown in the select */
    public const OPTIONS = [
        'M j, Y' => 'Oct 24, 2026',
        'j M Y' => '24 Oct 2026',
        'Y-m-d' => '2026-10-24',
        'd/m/Y' => '24/10/2026',
        'm/d/Y' => '10/24/2026',
        'd.m.Y' => '24.10.2026',
    ];

    public static function isSupported(mixed $format): bool
    {
        return is_string($format) && array_key_exists($format, self::OPTIONS);
    }

    /** The given format when it is on the list, otherwise the default. */
    public static function resolve(mixed $format): string
    {
        return self::isSupported($format) ? $format : self::DEFAULT;
    }

    /** The configured format, checked against the list. */
    public static function current(): string
    {
        return self::resolve(rescue(fn (): mixed => app(Settings::class)->get('core.date_format'), null, report: false));
    }
}
