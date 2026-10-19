<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Data\Core\NewClientAccount;
use App\Models\User;
use App\Modules\Core\Actions\RegisterClientAccount;
use App\Modules\Core\Support\Settings;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private RegisterClientAccount $register, private Settings $settings) {}

    /**
     * Validate and create a newly registered user, with the client account it owns.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException when sign-up is closed (security.client_signup_enabled)
     */
    public function create(array $input): User
    {
        if (! self::signupOpen($this->settings)) {
            throw ValidationException::withMessages(['email' => __('Sign-up is closed. Contact us if you need an account.')]);
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        // Fortify's register controller dispatches Registered itself.
        [$user] = $this->register->handle(NewClientAccount::fromName($input['name'], $input['email'], $input['password'], announceRegistered: false));

        return $user;
    }

    /**
     * Whether visitors may create a client account themselves: /register and sign-in providers, not checkout.
     */
    public static function signupOpen(Settings $settings): bool
    {
        return filter_var($settings->get('security.client_signup_enabled', true), FILTER_VALIDATE_BOOL);
    }
}
