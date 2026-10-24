<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

/**
 * A password sign-in with a one-time password past its expiry is refused after the password checked out (#60), so
 * the message says nothing to someone without the password. Passkey and social sign-in are not affected.
 */
final class RefuseExpiredPassword
{
    /**
     * @throws ValidationException
     */
    public static function check(mixed $user): void
    {
        if ($user instanceof User && $user->passwordExpired()) {
            throw ValidationException::withMessages([
                Fortify::username() => __('This one-time password has expired. Use "Forgot password", or ask the operator to run php artisan app:admin-password on the server.'),
            ]);
        }
    }
}
