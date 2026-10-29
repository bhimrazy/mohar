<?php

namespace App\Concerns;

use App\Actions\Fortify\PasswordGuesses;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::default(), 'confirmed'];
    }

    /**
     * Get the validation rules used to validate the current password. A wrong one counts against the login's
     * wrong-password budget, shared with step-up (PasswordGuesses, #522). An expired one-time password is not
     * accepted as one (#459): the way back is "Forgot password" or app:admin-password.
     *
     * @return array<int, Password|ValidationRule|Closure|array<mixed>|string>
     */
    protected function currentPasswordRules(): array
    {
        return ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
            $user = Auth::user();

            if (! $user instanceof User) {
                $fail(__('validation.current_password'));

                return;
            }

            PasswordGuesses::ensureAllowed($user, request(), $attribute);

            if (! is_string($value) || ! Hash::check($value, (string) $user->getAuthPassword())) {
                PasswordGuesses::failed($user, request());
                $fail(__('validation.current_password'));
            }
        }, function (string $attribute, mixed $value, Closure $fail): void {
            if (Auth::user() instanceof User && Auth::user()->passwordExpired()) {
                $fail(__('This one-time password has expired. Use "Forgot password", or ask the operator to run php artisan app:admin-password on the server.'));
            }
        }];
    }
}
