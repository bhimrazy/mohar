<?php

namespace App\Modules\Catalog\Support;

/**
 * Evaluates staff-entered validation patterns safely: invalid patterns never match and catastrophic
 * backtracking is cut off by a low PCRE backtrack limit instead of hanging the request.
 */
final class RegexMatcher
{
    private const BACKTRACK_LIMIT = 100000;

    /**
     * Normalise a pattern: accepts "/^[a-z]+$/i" style patterns or bare expressions like "^[a-z]+$".
     */
    public static function normalise(string $pattern): string
    {
        $pattern = trim($pattern);

        if (strlen($pattern) >= 2 && preg_match('/^([\/#~@%!|]).*\1[imsxuUD]*$/s', $pattern) === 1) {
            return $pattern;
        }

        return '~'.str_replace('~', '\~', $pattern).'~u';
    }

    public static function isValid(string $pattern): bool
    {
        return self::run(self::normalise($pattern), '') !== null;
    }

    public static function matches(string $pattern, string $subject): bool
    {
        return self::run(self::normalise($pattern), $subject) === true;
    }

    /**
     * @return bool|null null when the pattern is invalid or exceeded the backtrack limit
     */
    private static function run(string $pattern, string $subject): ?bool
    {
        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT);

        try {
            $result = @preg_match($pattern, $subject);
        } finally {
            ini_set('pcre.backtrack_limit', $previous === false ? '1000000' : $previous);
        }

        return $result === false || preg_last_error() !== PREG_NO_ERROR ? null : $result === 1;
    }
}
