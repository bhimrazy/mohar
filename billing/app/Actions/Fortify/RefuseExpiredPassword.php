<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

/**
 * A one-time password past its expiry no longer works (#60, ADR 0018 amendment #459). It is checked only after the
 * password itself checked out, so the message tells nothing to someone without it:
 * - at password sign-in, before the two-factor challenge (RememberValidatedLogin) and on Fortify's fallback path
 *   (SignInValidatedUser);
 * - when a two-factor challenge started before the expiry is completed after it (FortifyServiceProvider listener);
 * - at confirm-password step-up, even in a session opened before the expiry.
 * Passkey sign-in does not use the password and is not affected.
 */
final class RefuseExpiredPassword
{
    /**
     * @param  string|null  $field  the form field for the message; the login form's username field by default
     * @param  string|null  $redirectTo  where a refused request goes instead of back (the 2FA page cannot show it)
     *
     * @throws ValidationException
     */
    public static function check(mixed $user, string $stage, ?string $field = null, ?string $redirectTo = null): void
    {
        if (! $user instanceof User || ! $user->passwordExpired()) {
            return;
        }

        // No password, code or token in the entry: who, and at which step.
        app(ActivityLogger::class)->log('Expired one-time password refused', $user, properties: ['stage' => $stage], event: 'auth.password_expired', actor: $user);

        $refusal = ValidationException::withMessages([
            $field ?? Fortify::username() => __('This one-time password has expired. Use "Forgot password", or ask the operator to run php artisan app:admin-password on the server.'),
        ]);

        throw $redirectTo === null ? $refusal : $refusal->redirectTo($redirectTo);
    }
}
