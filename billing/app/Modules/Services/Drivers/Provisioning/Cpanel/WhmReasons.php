<?php

namespace App\Modules\Services\Drivers\Provisioning\Cpanel;

/**
 * Classifies WHM's free-text `metadata.reason` (spec §5). WHM has no error codes, so this is case-insensitive pattern
 * matching, tried in order; every pattern has a fixture test. The raw reason only ever reaches the module log; staff
 * and clients see the driver's own sentence for the kind.
 */
final class WhmReasons
{
    public const LICENCE = 'licence';

    public const AUTH = 'auth';

    public const RESERVED = 'reserved';

    public const DOMAIN_EXISTS = 'domain_exists';

    public const USERNAME_TAKEN = 'username_taken';

    public const PACKAGE = 'package';

    public const WEAK_PASSWORD = 'weak_password';

    public const THEME = 'theme';

    public const NO_ACCOUNT = 'no_account';

    public const UNKNOWN = 'unknown';

    /** Kind => pattern, in the order they are tried. */
    private const PATTERNS = [
        self::LICENCE => '/licen[cs]e/i',
        self::AUTH => '/access denied|permission denied|login failed|not authori[sz]ed|unauthori[sz]ed/i',
        self::RESERVED => '/reserved/i',
        self::DOMAIN_EXISTS => '/(domain|dns|zone)\b.*\b(already exists|already owned|already in use|exists on this server|already a)/i',
        self::USERNAME_TAKEN => '/(user ?name|account|user)\b.*\b(already exists|taken|in use|exists)/i',
        self::PACKAGE => '/(package|plan)\b.*\b(not exist|not found|not allowed|not available|cannot|invalid|unknown)|(not allowed|not permitted|cannot use).*\b(package|plan)\b/i',
        self::WEAK_PASSWORD => '/password.*(strength|weak|too short|not strong)|weak password/i',
        self::THEME => '/cpmod|\btheme\b/i',
        self::NO_ACCOUNT => '/does not exist|doesn\'t exist|no such (user|account)|not exist|not found/i',
    ];

    public static function classify(string $reason): string
    {
        foreach (self::PATTERNS as $kind => $pattern) {
            if (preg_match($pattern, $reason) === 1) {
                return $kind;
            }
        }

        return self::UNKNOWN;
    }
}
