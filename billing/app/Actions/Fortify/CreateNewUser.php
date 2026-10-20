<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Events\Core\ClientRegistered;
use App\Models\User;
use App\Modules\Core\Enums\ClientOrigin;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $email = Str::lower($input['email']);

        [$user, $client] = DB::transaction(function () use ($input, $email): array {
            $user = User::create([
                'name' => $input['name'],
                'email' => $email,
                'password' => $input['password'],
            ]);

            // Origin is never mass-assigned: each creation path names its own.
            $client = Client::query()->forceCreate([
                'origin' => ClientOrigin::SignUp,
                'first_name' => Str::before($input['name'], ' '),
                'last_name' => Str::contains($input['name'], ' ') ? Str::after($input['name'], ' ') : '',
                'email' => $email,
                'currency' => Currency::default()->code,
                'status' => 'active',
            ]);
            $client->users()->attach($user, ['is_owner' => true]);

            return [$user, $client];
        });

        ClientRegistered::dispatch($client->id, $user->id);

        return $user;
    }
}
