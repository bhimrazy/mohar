<?php

namespace App\Modules\Migration\Support;

/**
 * The columns of a client file that are never imported (client-import spec §5.2).
 */
final class ClientColumns
{
    public const PRESETS = ['whmcs' => 'WHMCS clients export', 'generic' => 'Any spreadsheet'];

    /** Passwords, hashes, card and bank data, security answers and second-factor secrets are never imported. */
    private const FORBIDDEN = '/pass(word|wd|code)?\b|pwd|hash|card|\bcc\b|cvv|cvc|security ?(answer|question)|2fa|two ?factor|totp|secret|iban|bank ?account|sort ?code/i';

    public static function isForbidden(string $header): bool
    {
        return preg_match(self::FORBIDDEN, str_replace(['_', '-'], ' ', $header)) === 1;
    }
}
