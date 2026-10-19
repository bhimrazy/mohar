<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientInvitation;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Services\TemplateCatalog;
use App\Modules\Platform\Actions\Clients\ManageClientLogins;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;

/*
 * Security re-check of #78: the invitation link must die however the login gets a password, another address or a
 * second factor, including writes that skip model events, and the voiding must hold at accept time.
 */

beforeEach(function () {
    app(TemplateCatalog::class)->install('en');
    Mail::fake();
    $this->client = Client::factory()->create(['company_name' => 'Acme Studio']);
});

function recheckInvite(Client $client, string $email = 'helper@example.test'): string
{
    app(ManageClientLogins::class)->invite($client, $email, 'Hari Helper', ['billing']);
    $token = '';
    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use ($email, &$token): bool {
        if ($mail->hasTo($email) && preg_match('#/account-invitation/([A-Za-z0-9]+)#', $mail->plainText, $match)) {
            $token = $match[1];
        }

        return true;
    });

    return $token;
}

function recheckAccept(string $token)
{
    return test()->post(route('platform.client-invitations.accept', $token), ['password' => 'Invite-Passw0rd!x', 'password_confirmation' => 'Invite-Passw0rd!x']);
}

function recheckLogin(): User
{
    return User::query()->where('email', 'helper@example.test')->sole();
}

it('voids the link when 2FA is written without model events (query builder)', function () {
    $token = recheckInvite($this->client);
    User::query()->whereKey(recheckLogin()->id)->update(['two_factor_secret' => encrypt('s'), 'two_factor_confirmed_at' => now()]);

    recheckAccept($token)->assertSessionHasErrors('token');
    $this->assertGuest();
});

it('voids the link when 2FA is saved quietly', function () {
    $token = recheckInvite($this->client);
    recheckLogin()->forceFill(['two_factor_secret' => encrypt('s')])->saveQuietly();

    recheckAccept($token)->assertSessionHasErrors('token');
    $this->assertGuest();
});

it('voids the link when the email is changed quietly', function () {
    $token = recheckInvite($this->client);
    recheckLogin()->forceFill(['email' => 'other@example.test'])->saveQuietly();
    User::query()->where('email', 'other@example.test')->update(['email_verified_at' => null]);

    recheckAccept($token)->assertSessionHasErrors('token');
    $this->assertGuest();
    expect(User::query()->where('email', 'other@example.test')->sole()->email_verified_at)->toBeNull();
});

it('voids the link when the password is changed quietly', function () {
    $token = recheckInvite($this->client);
    $this->travel(1)->minutes();
    recheckLogin()->forceFill(['password' => 'Own-Passw0rd!xyz'])->saveQuietly();

    recheckAccept($token)->assertSessionHasErrors('token');
    expect(password_verify('Own-Passw0rd!xyz', recheckLogin()->password))->toBeTrue();
});

it('deletes the link when Fortify resets the password over HTTP', function () {
    $token = recheckInvite($this->client);
    $user = recheckLogin();

    $this->post(route('password.update'), ['token' => Password::broker()->createToken($user), 'email' => $user->email, 'password' => 'Own-Passw0rd!xyz', 'password_confirmation' => 'Own-Passw0rd!xyz'])->assertSessionHasNoErrors();

    expect(ClientInvitation::query()->count())->toBe(0);
    recheckAccept($token)->assertNotFound();
});

it('deletes the link when Fortify enables 2FA', function () {
    $token = recheckInvite($this->client);
    app(EnableTwoFactorAuthentication::class)(recheckLogin());

    expect(ClientInvitation::query()->count())->toBe(0);
    recheckAccept($token)->assertNotFound();
    $this->assertGuest();
});

it('refuses at accept time when 2FA was turned on after the page was opened', function () {
    $token = recheckInvite($this->client);
    $this->get(route('platform.client-invitations.show', $token))->assertInertia(fn ($page) => $page->where('state', 'valid'));

    // Between opening the page and submitting it, 2FA appears without touching the invitation row.
    User::query()->whereKey(recheckLogin()->id)->update(['two_factor_secret' => encrypt('s'), 'two_factor_confirmed_at' => now()]);

    recheckAccept($token)->assertSessionHasErrors('token');
    $this->assertGuest();
    expect(ClientInvitation::query()->sole()->accepted_at)->toBeNull();
});

it('voids the link once the login signed in some other way', function () {
    $token = recheckInvite($this->client);
    recheckLogin()->forceFill(['last_login_at' => now()])->saveQuietly();

    recheckAccept($token)->assertSessionHasErrors('token');
    $this->assertGuest();
});

it('limits the staff password-reset action to core.users.manage, a recent password, the account and a throttle', function () {
    recheckInvite($this->client);
    $user = recheckLogin();
    $other = Client::factory()->create();
    $route = fn (Client $client) => route('admin.platform.clients.users.password-reset', [$client, $user]);

    actingAsStaff(['core.*', 'core.clients.view']);
    $this->withSession(['auth.password_confirmed_at' => time()])->post($route($this->client))->assertForbidden();

    actingAsStaff(['core.users.manage', 'core.clients.view']);
    $this->withSession(['auth.password_confirmed_at' => 0])->post($route($this->client))->assertSessionHasErrors('password_confirmation');
    $this->withSession(['auth.password_confirmed_at' => time()])->post($route($other))->assertNotFound();

    $this->post($route($this->client))->assertRedirect(route('admin.clients.show', $this->client));
    $logged = EmailMessage::query()->where('template_key', 'auth.password_reset')->sole();
    expect($logged->has_redactions)->toBeTrue();

    for ($i = 0; $i < 4; $i++) { // 404 and the send above count: 6 in all
        $this->post($route($this->client));
    }
    $this->post($route($this->client))->assertStatus(429);
});
