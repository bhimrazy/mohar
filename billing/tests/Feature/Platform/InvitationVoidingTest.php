<?php

use App\Contracts\Notifications\SystemMailer;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientInvitation;
use App\Modules\Core\Support\NullSystemMailer;
use App\Modules\Platform\Actions\Clients\ManageClientLogins;
use App\Modules\Platform\Notifications\ClientInvitationNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * #97: an invitation stops working once its login has a password other than the one it was mailed with, however that
 * password was written; and the client page shows the "Invited" badge only for a link that still works.
 */

beforeEach(function () {
    app()->instance(SystemMailer::class, new NullSystemMailer);
    Notification::fake();
    $this->client = Client::factory()->create(['company_name' => 'Himal Web']);
});

/**
 * Invites a new login and returns the token from the (fallback) invitation it was sent.
 */
function voidingInvite(Client $client): string
{
    app(ManageClientLogins::class)->invite($client, 'helper@example.com.np', 'Hari Helper', ['billing']);
    $token = '';
    Notification::assertSentTo(User::query()->where('email', 'helper@example.com.np')->sole(), ClientInvitationNotification::class, function (ClientInvitationNotification $mail) use (&$token): bool {
        $token = basename(parse_url($mail->acceptUrl, PHP_URL_PATH));

        return true;
    });

    return $token;
}

function voidingAccept(string $token)
{
    return test()->post(route('platform.client-invitations.accept', $token), ['password' => 'Invite-Passw0rd!x', 'password_confirmation' => 'Invite-Passw0rd!x']);
}

test('a password written without model events voids the invitation', function () {
    $token = voidingInvite($this->client);
    User::query()->where('email', 'helper@example.com.np')->update(['password' => Hash::make('Own-Passw0rd!xyz')]);

    voidingAccept($token)->assertSessionHasErrors('token');

    $this->assertGuest();
    expect(Hash::check('Own-Passw0rd!xyz', User::query()->where('email', 'helper@example.com.np')->sole()->password))->toBeTrue()
        ->and(ClientInvitation::query()->sole()->accepted_at)->toBeNull();
});

test('an invitation sent before fingerprints keeps working', function () {
    $token = voidingInvite($this->client);
    ClientInvitation::query()->update(['password_fingerprint' => null]);

    voidingAccept($token)->assertSessionHasNoErrors();

    expect(ClientInvitation::query()->sole()->accepted_at)->not->toBeNull();
});

test('the client page shows Invited only while the invitation still works for its login', function () {
    voidingInvite($this->client);
    actingAsStaff(['core.clients.view']);
    $invitation = fn () => $this->get(route('admin.clients.show', $this->client))->assertOk()->viewData('page')['props']['users'][0]['invitation'];

    expect($invitation())->toMatchArray(['state' => 'pending']);

    User::query()->where('email', 'helper@example.com.np')->update(['last_login_at' => now()]);
    expect($invitation())->toBeNull();

    $this->get(route('admin.clients.show', $this->client))->assertInertia(fn (Assert $page) => $page->missing('users.0.password')->missing('users.0.two_factor_secret'));
});
