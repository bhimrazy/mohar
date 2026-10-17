<?php

namespace App\Modules\Platform\Actions\Clients;

use App\Events\Core\TwoFactorReset;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Platform\Notifications\SecurityNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Logins (users) that can act for a client account: invite, per-area permissions, ownership transfer, removal.
 */
class ManageClientLogins
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * Give an email address access to the account. Unknown addresses get a new login and a
     * "set your password" email; staff logins cannot be attached to client accounts.
     *
     * @param  list<string>  $permissions
     *
     * @throws ValidationException
     */
    public function invite(Client $client, string $email, string $name, array $permissions): User
    {
        $email = mb_strtolower(trim($email));
        $user = User::query()->where('email', $email)->first();

        if ($user?->is_staff) {
            throw ValidationException::withMessages(['email' => __('Staff logins cannot be added to client accounts.')]);
        }

        if ($user !== null && $client->users()->whereKey($user->id)->exists()) {
            throw ValidationException::withMessages(['email' => __('This login already has access to the account.')]);
        }

        $created = $user === null;

        $user = DB::transaction(function () use ($client, $user, $email, $name, $permissions): User {
            $user ??= User::query()->create(['name' => $name, 'email' => $email, 'password' => Str::password(32)]);
            $client->users()->attach($user, ['is_owner' => false, 'permissions' => json_encode($permissions)]);

            return $user;
        });

        if ($created) {
            Password::broker()->sendResetLink(['email' => $email]);
        }

        $this->activity->log('Login added to the account', $user, $client->id, ['email' => $email, 'permissions' => $permissions, 'new_login' => $created], 'client.user_added');

        return $user;
    }

    /**
     * @param  list<string>  $permissions
     */
    public function updatePermissions(Client $client, User $user, array $permissions): void
    {
        $client->users()->updateExistingPivot($user->id, ['permissions' => json_encode($permissions)]);
        $this->activity->log('Login permissions changed', $user, $client->id, ['email' => $user->email, 'permissions' => $permissions], 'client.user_permissions_changed');
    }

    /**
     * @throws ValidationException
     */
    public function transferOwnership(Client $client, User $user): void
    {
        if ($user->is_staff) {
            throw ValidationException::withMessages(['user' => __('Staff logins cannot own client accounts.')]);
        }

        DB::transaction(function () use ($client, $user): void {
            DB::table('client_user')->where('client_id', $client->id)->update(['is_owner' => false]);
            $client->users()->updateExistingPivot($user->id, ['is_owner' => true]);
        });

        $this->activity->log('Account ownership transferred', $user, $client->id, ['email' => $user->email], 'client.owner_transferred');
    }

    /**
     * Clear a client login's two-factor authentication (e.g. lost device) and tell them by email.
     *
     * @throws ValidationException
     */
    public function resetTwoFactor(Client $client, User $user, User $actor): void
    {
        if ($user->is_staff) {
            throw ValidationException::withMessages(['user' => __('Staff two-factor authentication is reset from the staff page.')]);
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

        $this->activity->log('Two-factor authentication reset for a client login', $user, $client->id, ['email' => $user->email], 'client.user_two_factor_reset');
        $user->notify(new SecurityNotice(__('Your two-factor authentication was reset'), [
            __('Our team reset two-factor authentication on your account. Set it up again from your security settings.'),
        ]));
        TwoFactorReset::dispatch($user->id, $actor->id);
    }

    /**
     * @throws ValidationException
     */
    public function remove(Client $client, User $user): void
    {
        $pivot = $client->users()->whereKey($user->id)->first()?->getRelation('pivot');

        if ($pivot !== null && $pivot->getAttribute('is_owner')) {
            throw ValidationException::withMessages(['user' => __('Transfer ownership to another login before removing the owner.')]);
        }

        $client->users()->detach($user->id);
        $this->activity->log('Login removed from the account', $user, $client->id, ['email' => $user->email], 'client.user_removed');
    }
}
