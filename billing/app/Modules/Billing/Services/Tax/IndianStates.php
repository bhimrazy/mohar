<?php

namespace App\Modules\Billing\Services\Tax;

/**
 * Indian states / union territories for GST place-of-supply: ISO 3166-2:IN code, GST state code and name.
 * Client addresses may hold any of the three ("MH", "27", "Maharashtra"); normalize() returns the ISO code.
 */
final class IndianStates
{
    /** ISO code => [GST state code, name] */
    private const STATES = [
        'JK' => ['01', 'Jammu and Kashmir'], 'HP' => ['02', 'Himachal Pradesh'], 'PB' => ['03', 'Punjab'],
        'CH' => ['04', 'Chandigarh'], 'UK' => ['05', 'Uttarakhand'], 'HR' => ['06', 'Haryana'], 'DL' => ['07', 'Delhi'],
        'RJ' => ['08', 'Rajasthan'], 'UP' => ['09', 'Uttar Pradesh'], 'BR' => ['10', 'Bihar'], 'SK' => ['11', 'Sikkim'],
        'AR' => ['12', 'Arunachal Pradesh'], 'NL' => ['13', 'Nagaland'], 'MN' => ['14', 'Manipur'], 'MZ' => ['15', 'Mizoram'],
        'TR' => ['16', 'Tripura'], 'ML' => ['17', 'Meghalaya'], 'AS' => ['18', 'Assam'], 'WB' => ['19', 'West Bengal'],
        'JH' => ['20', 'Jharkhand'], 'OR' => ['21', 'Odisha'], 'CT' => ['22', 'Chhattisgarh'], 'MP' => ['23', 'Madhya Pradesh'],
        'GJ' => ['24', 'Gujarat'], 'DH' => ['26', 'Dadra and Nagar Haveli and Daman and Diu'], 'MH' => ['27', 'Maharashtra'],
        'KA' => ['29', 'Karnataka'], 'GA' => ['30', 'Goa'], 'LD' => ['31', 'Lakshadweep'], 'KL' => ['32', 'Kerala'],
        'TN' => ['33', 'Tamil Nadu'], 'PY' => ['34', 'Puducherry'], 'AN' => ['35', 'Andaman and Nicobar Islands'],
        'TG' => ['36', 'Telangana'], 'AP' => ['37', 'Andhra Pradesh'], 'LA' => ['38', 'Ladakh'],
    ];

    /** GST codes that are valid in a GSTIN but are not states: Other Territory and Centre Jurisdiction. */
    private const NON_STATE_GST_CODES = ['97', '99'];

    private const GSTIN = '/^(\d{2})[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';

    /**
     * ISO code for a state given as ISO code, GST code or name; null when unknown.
     */
    public static function normalize(?string $state): ?string
    {
        if ($state === null || trim($state) === '') {
            return null;
        }

        $value = strtoupper(trim($state));

        if (isset(self::STATES[$value])) {
            return $value;
        }

        foreach (self::STATES as $iso => [$gstCode, $name]) {
            if ($value === $gstCode || $value === strtoupper($name)) {
                return $iso;
            }
        }

        return null;
    }

    public static function isValidGstin(?string $gstin): bool
    {
        return $gstin !== null && preg_match(self::GSTIN, strtoupper(trim($gstin)), $matches) === 1
            && (self::byGstCode($matches[1]) !== null || in_array($matches[1], self::NON_STATE_GST_CODES, true));
    }

    /**
     * State of registration encoded in a GSTIN (first two digits), as ISO code; null for the non-state codes 97 and 99.
     */
    public static function stateFromGstin(?string $gstin): ?string
    {
        return self::isValidGstin($gstin) ? self::byGstCode(substr(strtoupper(trim((string) $gstin)), 0, 2)) : null;
    }

    /**
     * Place of supply: a registered business (valid GSTIN) is taxed by its GSTIN state, consumers by address.
     */
    public static function placeOfSupply(?string $state, ?string $gstin): ?string
    {
        return self::stateFromGstin($gstin) ?? self::normalize($state);
    }

    /**
     * "Maharashtra (27)" — place-of-supply label for tax invoices.
     */
    public static function label(string $iso): string
    {
        $state = self::STATES[$iso] ?? null;

        return $state === null ? $iso : "{$state[1]} ({$state[0]})";
    }

    /**
     * @return array<string, string> ISO code => name
     */
    public static function options(): array
    {
        return array_map(fn (array $state): string => $state[1], self::STATES);
    }

    private static function byGstCode(string $code): ?string
    {
        foreach (self::STATES as $iso => [$gstCode]) {
            if ($gstCode === $code) {
                return $iso;
            }
        }

        return null;
    }
}
