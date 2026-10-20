<?php

namespace App\Modules\Orders\Actions;

use App\Models\User;
use App\Modules\Core\Enums\ClientOrigin;
use App\Modules\Core\Models\Client;

/**
 * Inline registration at checkout: a login user plus the client account it owns, created inside the
 * order transaction (mirrors App\Actions\Fortify\CreateNewUser). The caller dispatches
 * ClientRegistered/Registered after commit.
 */
class RegisterCheckoutClient
{
    /**
     * @param  array{first_name: string, last_name: string, email: string, password: string, company_name?: ?string, country?: ?string}  $input
     * @return array{0: User, 1: Client}
     */
    public function handle(array $input, string $currency): array
    {
        $email = mb_strtolower(trim($input['email']));

        $user = User::query()->create([
            'name' => trim($input['first_name'].' '.$input['last_name']),
            'email' => $email,
            'password' => $input['password'],
        ]);

        $client = Client::query()->forceCreate([
            'origin' => ClientOrigin::Checkout,
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'],
            'company_name' => $input['company_name'] ?? null,
            'email' => $email,
            'country' => $input['country'] ?? null,
            'currency' => $currency,
            'status' => 'active',
        ]);
        $client->users()->attach($user, ['is_owner' => true]);

        return [$user, $client];
    }
}
