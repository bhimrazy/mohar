<?php

namespace App\Modules\Core\Install;

use DateTimeZone;

/**
 * The regional choices of a first install: country, currency and company time zone. Nepal is the default market, so
 * a fresh install starts with NP, NPR and Asia/Kathmandu. `app:install` can change them with prompts or options; every
 * value stays editable afterwards in Admin (Settings → Company, Setup → Currencies).
 *
 * The installers read this and write only on a first install (an empty currencies table), so running the installer on
 * an existing installation never changes what it already uses. keepBuiltInDefaults() is for the demo seed, which has
 * always been in US dollars and writes no regional settings.
 */
final class RegionalDefaults
{
    public const COUNTRY = 'NP';

    public const CURRENCY = 'NPR';

    public const TIMEZONE = 'Asia/Kathmandu';

    /** Unambiguous day-month-year ("24 Oct 2026"), the order Nepal uses; the Bikram Sambat calendar is not supported. */
    public const DATE_FORMAT = 'j M Y';

    /** Marker setting written with the regional defaults: Billing reads it to apply its own defaults to the same install. */
    public const MARKER = 'core.regional_defaults';

    private string $country = self::COUNTRY;

    private string $currency = self::CURRENCY;

    private string $timezone = self::TIMEZONE;

    private bool $writesSettings = true;

    public function choose(string $country, string $currency, string $timezone): void
    {
        $this->country = strtoupper($country);
        $this->currency = strtoupper($currency);
        $this->timezone = $timezone;
        $this->writesSettings = true;
    }

    /** The demo seed: US dollars as the default currency and no regional settings stored. */
    public function keepBuiltInDefaults(): void
    {
        $this->country = 'US';
        $this->currency = DefaultCurrencies::DEMO_CODE;
        $this->timezone = 'UTC';
        $this->writesSettings = false;
    }

    public function country(): string
    {
        return $this->country;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function writesSettings(): bool
    {
        return $this->writesSettings;
    }

    public function isNepal(): bool
    {
        return $this->country === 'NP';
    }

    /** @return list<string> */
    public static function timezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    public static function isCountry(string $value): bool
    {
        return preg_match('/^[A-Za-z]{2}$/', $value) === 1;
    }

    public static function isTimezone(string $value): bool
    {
        return in_array($value, self::timezones(), true);
    }
}
