<?php

namespace Mohar\Simulators\Providers\Whm;

/**
 * cPanel's username rules as the public WHM API 1 reference describes them for `createacct` and
 * `verify_new_username`, in one table. The reasons are our own wording; the reserved one matches the driver fixtures.
 */
final class UsernameRules
{
    public const MAX_LENGTH = 16;

    public const RESERVED = 'Sorry, that username is reserved.';

    /** System accounts and service names a new account may not take. */
    private const RESERVED_NAMES = [
        'abuse', 'admin', 'all', 'apache', 'bin', 'cpanel', 'daemon', 'dovecot', 'exim', 'ftp', 'mail', 'mailman',
        'mysql', 'named', 'nobody', 'postgres', 'postmaster', 'root', 'sys', 'system', 'virtfs', 'whm', 'www',
    ];

    /**
     * Why WHM would refuse the name, or null when it may be used (whether it is free is checked separately).
     */
    public static function violation(string $username): ?string
    {
        return match (true) {
            $username === '' || preg_match('/^[a-z][a-z0-9]*$/', $username) !== 1 => "The username “{$username}” is invalid: use lowercase letters and digits, starting with a letter.",
            strlen($username) > self::MAX_LENGTH => "The username “{$username}” is too long: use at most ".self::MAX_LENGTH.' characters.',
            in_array($username, self::RESERVED_NAMES, true), str_starts_with($username, 'test'), str_ends_with($username, 'assword') => self::RESERVED,
            default => null,
        };
    }

    /**
     * A strength rating from 0 to 100: length plus the kinds of character used. WHM's default minimum is 65.
     */
    public static function strength(string $password): int
    {
        $kinds = count(array_filter(['/[a-z]/', '/[A-Z]/', '/[0-9]/', '/[^A-Za-z0-9]/'], fn (string $pattern): bool => preg_match($pattern, $password) === 1));

        return min(100, strlen($password) * 5 + max(0, $kinds - 1) * 10);
    }
}
