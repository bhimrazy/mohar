<?php

namespace App\Modules\Platform\Actions\Clients;

use App\Contracts\Notifications\SystemMailer;
use App\Data\Notifications\SensitiveValue;
use App\Enums\InvitationPurpose;
use App\Enums\SystemMailOutcome;
use App\Events\Core\TwoFactorReset;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientInvitation;
use App\Modules\Core\Support\AccountLinks;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\InvitationExpiry;
use App\Modules\Core\Support\LoginSessions;
use App\Modules\Platform\Notifications\ClientInvitationNotification;
use App\Modules\Platform\Support\AccountMail;
use App\Modules\Platform\Support\PlatformClientLoginInvitations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Logins (users) that can act for a client account: invite, per-area permissions, ownership transfer, removal.
 */
class ManageClientLogins
{
    public function __construct(private ActivityLogger $activity, private AccountMail $mail, private SystemMailer $mailer) {}

    /**
     * Give an email address access to the account. Unknown addresses get a new login and the invitation email (its
     * link sets the password). An existing login is attached only when it proved its email: one that signed up with
     * the address and never verified it is refused, since holding it proves nothing about the mailbox (security
     * review 2026-10-29, M2), and it may know its own password, so it is not sent an invitation either. Staff logins
     * cannot be attached.
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

        if (! $created && $user->email_verified_at === null) {
            throw ValidationException::withMessages(['email' => __('This address belongs to a login that has not verified it, so it can\'t be added. Ask them to sign in and verify their email address from their own account, then add it again.')]);
        }

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
     * Sends a new invitation to a never-used login that has not accepted one yet; the previous link stops working.
     *
     * @throws ValidationException
     */
    public function resendInvitation(Client $client, User $user): void
    {
        $invitation = ClientInvitation::query()->where('client_id', $client->id)->where('user_id', $user->id)->first();

        // An invitation only ever activates a never-used login: one with an open invitation, or (#443, after a send
        // that failed) an unverified one with none. Anything else gets a password reset instead.
        $refusal = match (true) {
            $client->status === 'closed' => __('This account is closed, so no invitation can be sent.'),
            $invitation === null && ! ClientInvitation::invitableWithoutOne($user) => __('This login has no open invitation and may already have a password (it verified its email, signed in or set one). Send a password reset instead.'),
            $invitation?->accepted_at !== null => __('This login has already accepted its invitation. Send a password reset instead.'),
            $invitation?->isVoid() === true => __('This login already has a password, two-factor authentication or a sign-in, so it can\'t be invited again. Send a password reset instead.'),
            default => null,
        };

        if ($refusal !== null) {
            throw ValidationException::withMessages(['user' => $refusal]);
        }

        if (($problem = AccountLinks::problem()) !== null) {
            throw ValidationException::withMessages(['user' => __('Invitation not sent: the link host isn\'t your site address. :problem', ['problem' => $problem])]);
        }

        // With no invitation row, "never signed in" does not prove nobody knows the password (a sign-up can commit and
        // fail before signing in). The link then comes with a new random password, other sessions end, and the
        // fingerprint is taken from that password.
        $sent = $invitation !== null ? $this->sendInvitation($user, $client) : DB::transaction(function () use ($user, $client): bool {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            if (! ClientInvitation::invitableWithoutOne($locked)) {
                throw ValidationException::withMessages(['user' => __('This login has no open invitation and may already have a password (it verified its email, signed in or set one). Send a password reset instead.')]);
            }

            LoginSessions::rotateRememberToken($locked);
            $locked->forceFill(['password' => Str::password(32)])->save();
            LoginSessions::revoke($locked);

            return $this->sendInvitation($locked, $client);
        });

        if ($sent) {
            $this->activity->log('Invitation sent again', $user, $client->id, ['email' => $user->email], 'client.invitation_resent');
        }
    }

    /**
     * Staff send the login a password-reset link (the normal reset email, through AuthMailer), for logins that can't
     * be invited again. A mail transport failure is recorded on AccountMail and refused with a message.
     *
     * @throws ValidationException
     */
    public function sendPasswordReset(Client $client, User $user): void
    {
        try {
            $status = Password::broker()->sendResetLink(['email' => $user->email]);
        } catch (TransportExceptionInterface $e) {
            $this->mail->transportFailed($e, 'auth.password_reset', $user->email, $client->id);

            throw ValidationException::withMessages(['user' => __('The password reset email could not be sent because the mail server could not be reached. Try again once mail works.')]);
        }

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages(['user' => __($status)]);
        }

        $this->activity->log('Password reset sent by staff', $user, $client->id, ['email' => $user->email], 'client.password_reset_sent');
    }

    /**
     * The invitation for a new login: its own single-use token (only its hash is stored), valid for the configured
     * number of days; a new invitation replaces the token, so an older link stops working. Accepting it sets the
     * password. When the template system refuses it (its link failed the checks) nothing is sent by any other path:
     * the refusal is a security event and staff see it on the form. The Platform notification is used only when no
     * template system is there to take it. When the mail transport fails, the invitation is put back as it was and the
     * failure is recorded on AccountMail (the action that called this is committed); the result is false.
     *
     * @throws ValidationException
     */
    private function sendInvitation(User $user, Client $client): bool
    {
        $template = PlatformClientLoginInvitations::template(InvitationPurpose::LoginAdded);

        try {
            $outcome = $this->mailInvitation($user, $client, $template, ($actor = auth()->user()) instanceof User ? $actor->id : null);
        } catch (TransportExceptionInterface $e) {
            $this->mail->transportFailed($e, $template, $user->email, $client->id);

            return false;
        }

        if ($outcome === SystemMailOutcome::Refused) {
            throw ValidationException::withMessages(['email' => __('The login was added, but the invitation was not sent: its link failed the security check. Check APP_URL, then send the invitation again.')]);
        }

        if ($outcome === SystemMailOutcome::Suppressed) {
            throw ValidationException::withMessages(['email' => __('The login was added, but the invitation was not sent: email is switched off, or the invitation template is turned off. Turn it on, then send the invitation again.')]);
        }

        return true;
    }

    /**
     * Mails a new invitation link with the given template, replacing the login's earlier link. When the template
     * system refuses it, or holds it because mail is off (Suppressed), the invitation is put back as it was (removed,
     * or the earlier link restored), so nothing looks sent that was not, and nothing else sends it. A refusal is also
     * logged as a security event. A mail transport failure puts it back the same way and is rethrown.
     *
     * @throws TransportExceptionInterface
     */
    public function mailInvitation(User $user, Client $client, string $templateKey, ?int $actorId): SystemMailOutcome
    {
        $token = Str::random(48);
        $expiresAt = now()->addDays(InvitationExpiry::days());
        $previous = ClientInvitation::query()->where('client_id', $client->id)->where('user_id', $user->id)->first();
        $invitation = ClientInvitation::query()->updateOrCreate(
            ['client_id' => $client->id, 'user_id' => $user->id],
            ['token_hash' => ClientInvitation::hashToken($token), 'email' => mb_strtolower($user->email), 'sent_at' => now(), 'expires_at' => $expiresAt, 'accepted_at' => null, 'invited_by' => $actorId,
                'password_fingerprint' => ClientInvitation::fingerprint($user->password)],
        );
        $url = AccountLinks::withAppOrigin(fn (): string => route('platform.client-invitations.show', $token));
        $putBack = fn () => $previous === null ? $invitation->delete() : $invitation->forceFill($previous->only(['token_hash', 'email', 'sent_at', 'expires_at', 'accepted_at', 'invited_by', 'password_fingerprint']))->save();

        try {
            $outcome = $this->mailer->send($templateKey, $user, [
                'user' => ['first_name' => Str::before(trim($user->name), ' '), 'email' => $user->email],
                'auth' => ['invite_url' => new SensitiveValue($url, secret: $token), 'expires_at' => $expiresAt, 'expires_in' => InvitationExpiry::phrase()],
            ], 'invite|'.$user->id.'|'.ClientInvitation::hashToken($token), $client->id, carriesSecret: true);

            if ($outcome === SystemMailOutcome::Unavailable) {
                $user->notify(new ClientInvitationNotification($client->display_name, $url, $expiresAt));
            }
        } catch (TransportExceptionInterface $e) {
            $putBack();

            throw $e;
        }

        if ($outcome === SystemMailOutcome::Refused || $outcome === SystemMailOutcome::Suppressed) {
            $putBack();
        }

        if ($outcome === SystemMailOutcome::Refused) {
            $this->activity->log('Invitation not sent: its link failed the security check', $user, $client->id, ['email' => $user->email], 'security');
        }

        return $outcome;
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

        if ($user->email_verified_at === null) {
            throw ValidationException::withMessages(['user' => __('Only a login that has verified its email address can own the account. An invited login verifies it by accepting the invitation.')]);
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
        // An open invitation for this account is cancelled with the access: its link stops working.
        ClientInvitation::query()->where('client_id', $client->id)->where('user_id', $user->id)->delete();
        $this->activity->log('Login removed from the account', $user, $client->id, ['email' => $user->email], 'client.user_removed');
    }
}
