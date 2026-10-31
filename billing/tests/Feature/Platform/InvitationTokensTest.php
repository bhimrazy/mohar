<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientInvitation;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Services\TemplateCatalog;
use App\Modules\Platform\Actions\Clients\ManageClientLogins;
use App\Modules\Platform\Actions\Staff\InviteStaff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Invitations carry their own single-use token (CTO decision 2026-10-19): only its hash is stored, it works for the
 * configured number of days, a new invitation or removing the login revokes it, and it goes out through the secret
 * policy (system button, masked log copy, never copied, always sent).
 */

beforeEach(function () {
    app(TemplateCatalog::class)->install('en');
    Mail::fake();
    $this->client = Client::factory()->create(['company_name' => 'Acme Studio']);
});

/**
 * Invites a new login and returns the token from the email that was sent.
 */
function inviteLogin(Client $client, string $email = 'helper@example.test'): string
{
    app(ManageClientLogins::class)->invite($client, $email, 'Hari Helper', ['billing']);

    $token = null;
    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use ($email, &$token): bool {
        if ($mail->hasTo($email) && preg_match('#/account-invitation/([A-Za-z0-9]+)#', $mail->plainText, $match)) {
            $token = $match[1];
        }

        return true;
    });

    return (string) $token;
}

it('invites a new login with its own hashed, single-use token and states when it expires', function () {
    $token = inviteLogin($this->client);
    $invitation = ClientInvitation::query()->sole();

    expect($token)->toHaveLength(48)
        ->and($invitation->token_hash)->toBe(ClientInvitation::hashToken($token))
        ->and(DB::table('client_invitations')->where('token_hash', $token)->exists())->toBeFalse()
        ->and(DB::table('password_reset_tokens')->count())->toBe(0)
        ->and($invitation->expires_at->toDateString())->toBe(now()->addDays(7)->toDateString());

    $logged = EmailMessage::query()->where('template_key', 'auth.user_invitation')->sole();
    expect($logged->has_redactions)->toBeTrue()
        ->and($logged->body_text)->toContain('for 7 days')->not->toContain($token);
});

it('sets the password once from the invitation and then refuses the link', function () {
    $token = inviteLogin($this->client);

    $this->get(route('platform.client-invitations.show', $token))
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertInertia(fn (Assert $page) => $page->component('auth/accept-account-invitation')->where('state', 'valid')->where('account', $this->client->display_name));

    $this->post(route('platform.client-invitations.accept', $token), ['password' => 'a-Long-passw0rd!', 'password_confirmation' => 'a-Long-passw0rd!'])
        ->assertRedirect(route('client.dashboard'));
    $this->assertAuthenticatedAs($user = User::query()->where('email', 'helper@example.test')->sole());
    expect(password_verify('a-Long-passw0rd!', $user->password))->toBeTrue();

    auth()->logout();
    $this->get(route('platform.client-invitations.show', $token))->assertInertia(fn (Assert $page) => $page->where('state', 'used'));
    $this->post(route('platform.client-invitations.accept', $token), ['password' => 'b-Long-passw0rd!', 'password_confirmation' => 'b-Long-passw0rd!'])
        ->assertSessionHasErrors('token');
    expect(password_verify('a-Long-passw0rd!', $user->fresh()->password))->toBeTrue();
});

it('refuses an expired invitation with a clear message', function () {
    $token = inviteLogin($this->client);
    $this->travel(8)->days();

    $this->get(route('platform.client-invitations.show', $token))->assertInertia(fn (Assert $page) => $page->where('state', 'expired'));
    $this->post(route('platform.client-invitations.accept', $token), ['password' => 'a-Long-passw0rd!', 'password_confirmation' => 'a-Long-passw0rd!'])
        ->assertSessionHasErrors(['token' => 'This invitation has expired. Ask the account owner or our team to send a new one.']);
});

it('uses the configured validity', function () {
    app(Settings::class)->set('platform.invitation_expiry_days', 3);

    inviteLogin($this->client);

    expect(ClientInvitation::query()->sole()->expires_at->toDateString())->toBe(now()->addDays(3)->toDateString())
        ->and(EmailMessage::query()->where('template_key', 'auth.user_invitation')->sole()->body_text)->toContain('for 3 days');
});

it('lets staff send a new invitation, which revokes the old link', function () {
    $old = inviteLogin($this->client);
    $user = User::query()->where('email', 'helper@example.test')->sole();
    $this->travel(8)->days();
    actingAsStaff(['core.users.manage', 'core.clients.view']);

    $this->get(route('admin.clients.show', $this->client))
        ->assertInertia(fn (Assert $page) => $page->where('users.0.invitation.state', 'expired'));
    $this->withSession(['auth.step_up_confirmed_at' => now()->getTimestamp()])
        ->post(route('admin.platform.clients.users.invitation', [$this->client, $user]))->assertRedirect();

    auth()->logout(); // the accept page is for guests
    $this->get(route('platform.client-invitations.show', $old))->assertInertia(fn (Assert $page) => $page->where('state', 'invalid'));
    expect(ClientInvitation::query()->sole()->isExpired())->toBeFalse()
        ->and(EmailMessage::query()->where('template_key', 'auth.user_invitation')->count())->toBe(2);
});

it('cancels the invitation when the login is removed', function () {
    $token = inviteLogin($this->client);

    app(ManageClientLogins::class)->remove($this->client, User::query()->where('email', 'helper@example.test')->sole());

    expect(ClientInvitation::query()->count())->toBe(0);
    $this->get(route('platform.client-invitations.show', $token))->assertInertia(fn (Assert $page) => $page->where('state', 'invalid'));
});

it('states the staff invitation expiry in days and as a date', function () {
    $admin = User::factory()->staff(['*'])->create();

    app(InviteStaff::class)->handle($admin, 'agent@example.test', 'Agent', Role::factory()->withPermissions(['support.tickets.view'])->create());

    expect(EmailMessage::query()->where('template_key', 'staff.invitation')->sole()->body_text)->toContain('for 7 days: until');
});

it('revokes a staff invitation link when it is sent again or cancelled', function () {
    $admin = User::factory()->staff(['*'])->create();
    $invite = app(InviteStaff::class);
    $tokenOf = function (): string {
        $token = '';
        Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use (&$token): bool {
            preg_match('#/staff-invitation/([A-Za-z0-9]+)#', $mail->plainText, $match);
            $token = $match[1] ?? $token;

            return true;
        });

        return $token;
    };

    $invitation = $invite->handle($admin, 'agent@example.test', 'Agent', Role::factory()->withPermissions(['support.tickets.view'])->create());
    $first = $tokenOf();
    $invite->resend($admin, $invitation->fresh());
    $second = $tokenOf();

    expect($second)->not->toBe($first);
    $this->get(route('platform.invitations.show', $first))->assertInertia(fn (Assert $page) => $page->where('state', 'invalid'));
    $this->get(route('platform.invitations.show', $second))->assertInertia(fn (Assert $page) => $page->where('state', 'valid'));

    $invitation->delete();
    $this->get(route('platform.invitations.show', $second))->assertInertia(fn (Assert $page) => $page->where('state', 'invalid'));
});

it('refuses to invite again a login that was invited before the upgrade, or that has been used, and offers a password reset', function () {
    $legacy = User::factory()->create(['email' => 'legacy@example.test']);
    $this->client->users()->attach($legacy, ['is_owner' => false, 'permissions' => '[]']);
    inviteLogin($this->client);
    $used = User::query()->where('email', 'helper@example.test')->sole();
    $used->forceFill(['last_login_at' => now()])->saveQuietly();
    $logins = app(ManageClientLogins::class);

    expect(fn () => $logins->resendInvitation($this->client, $legacy))->toThrow(ValidationException::class, 'This login has no open invitation')
        ->and(fn () => $logins->resendInvitation($this->client, $used))->toThrow(ValidationException::class, 'Send a password reset instead.');

    actingAsStaff(['core.users.manage', 'core.clients.view']);
    $this->withSession(['auth.step_up_confirmed_at' => now()->getTimestamp()])
        ->post(route('admin.platform.clients.users.password-reset', [$this->client, $legacy]))->assertRedirect()->assertSessionHasNoErrors();
    expect(EmailMessage::query()->where('template_key', 'auth.password_reset')->where('recipient', 'legacy@example.test')->exists())->toBeTrue();
});

it('voids the link when the login loses access to the account', function () {
    $token = inviteLogin($this->client);
    DB::table('client_user')->where('client_id', $this->client->id)->delete();

    $this->get(route('platform.client-invitations.show', $token))->assertInertia(fn (Assert $page) => $page->where('state', 'void'));
});
