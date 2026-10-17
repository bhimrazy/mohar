<?php

namespace App\Modules\Billing\Services\Tax;

/**
 * EU VAT identification number format validation (live VIES validation is out of scope for v1).
 */
final class VatNumber
{
    /** ISO country => VAT prefix and pattern of the part after the prefix. */
    private const PATTERNS = [
        'AT' => ['AT', 'U\d{8}'], 'BE' => ['BE', '[01]\d{9}'], 'BG' => ['BG', '\d{9,10}'], 'CY' => ['CY', '\d{8}[A-Z]'],
        'CZ' => ['CZ', '\d{8,10}'], 'DE' => ['DE', '\d{9}'], 'DK' => ['DK', '\d{8}'], 'EE' => ['EE', '\d{9}'],
        'GR' => ['EL', '\d{9}'], 'ES' => ['ES', '[0-9A-Z]\d{7}[0-9A-Z]'], 'FI' => ['FI', '\d{8}'],
        'FR' => ['FR', '[0-9A-Z]{2}\d{9}'], 'HR' => ['HR', '\d{11}'], 'HU' => ['HU', '\d{8}'],
        'IE' => ['IE', '\d[0-9A-Z+*]\d{5}[A-Z]{1,2}'], 'IT' => ['IT', '\d{11}'], 'LT' => ['LT', '(\d{9}|\d{12})'],
        'LU' => ['LU', '\d{8}'], 'LV' => ['LV', '\d{11}'], 'MT' => ['MT', '\d{8}'], 'NL' => ['NL', '\d{9}B\d{2}'],
        'PL' => ['PL', '\d{10}'], 'PT' => ['PT', '\d{9}'], 'RO' => ['RO', '\d{2,10}'], 'SE' => ['SE', '\d{12}'],
        'SI' => ['SI', '\d{8}'], 'SK' => ['SK', '\d{10}'],
    ];

    /**
     * @return list<string>
     */
    public static function euCountries(): array
    {
        return array_keys(self::PATTERNS);
    }

    public static function isEuCountry(?string $country): bool
    {
        return $country !== null && array_key_exists(strtoupper($country), self::PATTERNS);
    }

    public static function normalize(string $vatId): string
    {
        return strtoupper((string) preg_replace('/[\s.\-]/', '', $vatId));
    }

    public static function isValidFormat(?string $vatId, ?string $country): bool
    {
        if ($vatId === null || $country === null || ! self::isEuCountry($country)) {
            return false;
        }

        [$prefix, $pattern] = self::PATTERNS[strtoupper($country)];

        return preg_match('/^'.$prefix.$pattern.'$/', self::normalize($vatId)) === 1;
    }
}
