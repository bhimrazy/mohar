<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use App\Modules\Core\Support\LoginSessions;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        // Whoever held the login before the reset is signed out everywhere (security review 2026-10-29, M2).
        LoginSessions::rotateRememberToken($user);
        $user->forceFill([
            'password' => $input['password'],
        ])->save();
        LoginSessions::revoke($user);
    }
}
