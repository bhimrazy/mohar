<?php

namespace App\Modules\Domains\Support;

/**
 * Nameserver list helpers: lowercase, trimmed, de-duplicated hostnames; a valid set has 2–5 entries.
 */
final class Nameservers
{
    public const MIN = 2;

    public const MAX = 5;

    /**
     * @param  array<int|string, mixed>  $nameservers
     * @return list<string>
     */
    public static function normalise(array $nameservers): array
    {
        $clean = [];

        foreach ($nameservers as $nameserver) {
            $value = rtrim(mb_strtolower(trim((string) $nameserver)), '.');

            if ($value !== '' && ! in_array($value, $clean, true)) {
                $clean[] = $value;
            }
        }

        return $clean;
    }

    /**
     * @param  list<string>  $nameservers
     */
    public static function isValidSet(array $nameservers): bool
    {
        if (count($nameservers) < self::MIN || count($nameservers) > self::MAX) {
            return false;
        }

        foreach ($nameservers as $nameserver) {
            if (! DomainName::isValidHostname($nameserver)) {
                return false;
            }
        }

        return true;
    }
}
