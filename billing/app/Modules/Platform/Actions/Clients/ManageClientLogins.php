<?php

namespace App\Modules\Platform\Actions\Clients;

use App\Contracts\Notifications\SystemMailer;
use App\Data\Notifications\SensitiveValue;
use App\Enums\SystemMailOutcome;
use App\Events\Core\TwoFactorReset;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\AccountLinks;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Platform\Support\AccountMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Logins (users) that can act for a client account: invite, per-area permissions, ownership transfer, removal.
 */
class ManageClientLogins
{
    public function __construct(private ActivityLogger $activity, private AccountMail $mail, private SystemMailer $mailer) {}

    /**
     * Give an email address access to the account. Unknown addresses get a new login and the invitation email (its
     * link sets the password); existing logins are told they have access. Staff logins cannot be attached.
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

        // A new login is invited with a one-time link: refuse before creating anything when no link could be mailed.
        if ($created && ($problem = AccountLinks::problem()) !== null) {
            throw ValidationException::withMessages(['email' => __('Invitation not sent: the link host isn\'t your site address. :problem', ['problem' => $problem])]);
        }

        $user = DB::transaction(function () use ($client, $user, $email, $name, $permissions): User {
            $user ??= User::query()->create(['name' => $name, 'email' => $email, 'password' => Str::password(32)]);
            $client->users()->attach($user, ['is_owner' => false, 'permissions' => json_encode($permissions)]);

            return $user;
        });

        $this->activity->log('Login added to the account', $user, $client->id, ['email' => $email, 'permissions' => $permissions, 'new_login' => $created], 'client.user_added');

        $owners = $client->users()->wherePivot('is_owner', true)->get()->reject(fn (User $owner): bool => $owner->is($user));
        $this->mail->notice('auth.login_added', $owners, ['account' => $client->display_name, 'email' => $email], 'client:'.$client->id.'|user:'.$user->id, __('A login was added to your account'), [
            __(':email can now sign in to the :account account.', ['email' => $email, 'account' => $client->display_name]),
        ], $client->id);

        if ($created) {
            $this->sendInvitation($user, $client);
        } else {
            $this->mail->notice('auth.access_granted', [$user], ['account' => $client->display_name], 'client:'.$client->id.'|user:'.$user->id, __('You were given access to a client account'), [
                __('Our team gave you access to the :account account.', ['account' => $client->display_name]),
            ], $client->id);
        }

        return $user;
    }

    /**
     * The invitation for a new login: its link is a password-reset link, so accepting it sets the password. When the
     * template system refuses it (its link failed the checks) nothing is sent by any other path: the refusal is a
     * security event and staff see it on the form. Laravel's notification is used only when no template system is
     * there to take it.
     *
     * @throws ValidationException
     */
    private function sendInvitation(User $user, Client $client): void
    {
        $token = Password::broker()->createToken($user);
        $url = AccountLinks::passwordReset($user, $token);
        $broker = (string) config('auth.defaults.passwords', 'users');

        $outcome = $this->mailer->send('auth.user_invitation', $user, [
            'user' => ['first_name' => Str::before(trim($user->name), ' '), 'email' => $user->email],
            'auth' => ['invite_url' => new SensitiveValue($url, secret: $token), 'expires_at' => now()->addMinutes((int) config("auth.passwords.{$broker}.expire", 60))],
        ], 'invite|'.$user->id.'|'.hash('sha256', $token), $client->id, carriesSecret: true);

        if ($outcome === SystemMailOutcome::Refused) {
            $this->activity->log('Invitation not sent: its link failed the security check', $user, $client->id, ['email' => $user->email], 'security');

            throw ValidationException::withMessages(['email' => __('The login was added, but the invitation was not sent: its link failed the security check. Check APP_URL, then send a password reset to this login.')]);
        }

        if ($outcome === SystemMailOutcome::Unavailable) {
            $user->sendPasswordResetNotification($token);
        }
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

        $previousOwners = $client->users()->wherePivot('is_owner', true)->get();

        DB::transaction(function () use ($client, $user): void {
            DB::table('client_user')->where('client_id', $client->id)->update(['is_owner' => false]);
            $client->users()->updateExistingPivot($user->id, ['is_owner' => true]);
        });

        $this->activity->log('Account ownership transferred', $user, $client->id, ['email' => $user->email], 'client.owner_transferred');

        $lines = [__('Ownership of the :account account was transferred to :email.', ['account' => $client->display_name, 'email' => $user->email])];
        $notice = ['account' => $client->display_name, 'email' => $user->email];
        $this->mail->notice('auth.owner_changed', $previousOwners->reject(fn (User $owner): bool => $owner->is($user)), $notice, 'client:'.$client->id.'|owner:'.$user->id, __('Your account has a new owner'), $lines, $client->id);
        $this->mail->notice('auth.owner_changed', [$user], $notice, 'client:'.$client->id.'|owner:'.$user->id, __('You are now the account owner'), $lines, $client->id);
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
        $this->mail->notice('auth.two_factor_reset', [$user], ['account' => $client->display_name], 'client:'.$client->id.'|user:'.$user->id, __('Your two-factor authentication was reset'), [
            __('Our team reset two-factor authentication on your account. Set it up again from your security settings.'),
        ], $client->id);
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
