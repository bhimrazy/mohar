<?php

namespace App\Support\Security;

use Illuminate\Validation\Rules\Password;

/**
 * The production password rule: Password::defaults() in production, and always for the first full administrator
 * created by `app:install`, whatever the environment.
 */
final class PasswordPolicy
{
    /** Minimum length of a production password. */
    public const MIN_LENGTH = 12;

    /**
     * At least 12 characters with upper- and lower-case letters, a number and a symbol, and not found in a known
     * breach (Have I Been Pwned range API; the check passes when the API cannot be reached).
     */
    public static function production(): Password
    {
        return Password::min(self::MIN_LENGTH)
            ->mixedCase()
            ->letters()
            ->numbers()
            ->symbols()
            ->uncompromised();
    }
}
