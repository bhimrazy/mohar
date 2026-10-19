<?php

namespace App\Modules\Core\Actions;

use App\Data\Core\NewClientAccount;
use App\Events\Core\ClientRegistered;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;

/**
 * Opens a client account: the login, the client and the owner link in one transaction, then ClientRegistered and
 * Laravel's Registered once the outermost transaction commits (ADR 0018 §6, ADR 0011). Sign-up, checkout and sign-in
 * providers all use it. Whether sign-up is open is the caller's question (security.client_signup_enabled).
 */
final class RegisterClientAccount
{
    /**
     * @return array{0: User, 1: Client}
     */
    public function handle(NewClientAccount $account): array
    {
        $email = mb_strtolower(trim($account->email));

        [$user, $client] = DB::transaction(function () use ($account, $email): array {
            $user = new User(['name' => trim($account->firstName.' '.$account->lastName), 'email' => $email, 'password' => $account->password]);
            $user->email_verified_at = $account->emailVerifiedAt;
            $user->save();

            $client = Client::query()->create([
                'first_name' => $account->firstName,
                'last_name' => $account->lastName,
                'company_name' => $account->companyName,
                'email' => $email,
                'country' => $account->country,
                'currency' => $account->currency ?? Currency::default()->code,
                'status' => 'active',
            ]);
            $client->users()->attach($user, ['is_owner' => true]);

            return [$user, $client];
        });

        DB::afterCommit(function () use ($account, $user, $client): void {
            ClientRegistered::dispatch($client->id, $user->id);

            if ($account->announceRegistered) {
                event(new Registered($user));
            }
        });

        return [$user, $client];
    }
}
