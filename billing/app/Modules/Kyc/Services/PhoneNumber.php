<?php

namespace App\Modules\Kyc\Services;

/**
 * Normalises a phone number to "+<country code> <digits>". A number without a prefix gets the default country code
 * (a leading trunk 0 is dropped). Nepal (+977): mobile numbers are 10 digits starting with 9, landlines 8 or 9 digits.
 */
final class PhoneNumber
{
    /**
     * The normalised number, or null when it is not a valid number.
     */
    public static function normalise(?string $input, string $defaultCode): ?string
    {
        $raw = trim((string) $input);

        if ($raw === '' || preg_match('/[^\d\s\-().+]/', $raw) === 1) {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', $raw);
        $code = (string) preg_replace('/\D/', '', $defaultCode);

        if (str_starts_with($raw, '+') || str_starts_with($digits, '00')) {
            $digits = ltrim(str_starts_with($raw, '+') ? $digits : substr($digits, 2), '0');
        } else {
            $digits = $code.ltrim($digits, '0');
        }

        if (str_starts_with($digits, '977')) {
            $national = substr($digits, 3);

            return preg_match('/^(9\d{9}|[1-8]\d{7,8})$/', $national) === 1 ? '+977 '.$national : null;
        }

        return preg_match('/^\d{8,15}$/', $digits) === 1 ? '+'.$digits : null;
    }
}
