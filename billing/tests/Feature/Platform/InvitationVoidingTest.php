<?php

use App\Contracts\Notifications\SystemMailer;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientInvitation;
use App\Modules\Core\Support\NullSystemMailer;
use App\Modules\Platform\Actions\Clients\ManageClientLogins;
use App\Modules\Platform\Actions\Clients\MergeClients;
use App\Modules\Platform\Notifications\ClientInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
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

test('ownership goes only to a login that verified its email address (M2)', function () {
    voidingInvite($this->client);
    $invited = User::query()->where('email', 'helper@example.com.np')->sole();
    $verified = User::factory()->create(['email' => 'accounts@example.com.np']);
    $this->client->users()->attach($verified, ['is_owner' => false, 'permissions' => '[]']);
    actingAsStaff(['core.clients.view', 'core.users.manage']);
    confirmPassword();

    $this->post(route('admin.platform.clients.users.transfer', [$this->client, $invited]))->assertSessionHasErrors('user');
    $this->post(route('admin.platform.clients.users.transfer', [$this->client, $verified]))->assertSessionHasNoErrors();

    expect($this->client->users()->wherePivot('is_owner', true)->pluck('users.id')->all())->toBe([$verified->id]);
});

test('accepting an invitation ends every other session of the login', function () {
    config(['session.driver' => 'database']);
    $token = voidingInvite($this->client);
    $user = User::query()->where('email', 'helper@example.com.np')->sole();
    DB::table('sessions')->insert(['id' => str_repeat('a', 40), 'user_id' => $user->id, 'ip_address' => '203.0.113.9', 'user_agent' => 'other', 'payload' => base64_encode(serialize([])), 'last_activity' => now()->getTimestamp()]);
    $remember = $user->remember_token;

    voidingAccept($token)->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('id', str_repeat('a', 40))->exists())->toBeFalse()
        ->and($user->fresh()->remember_token)->not->toBe($remember);
});

test('a merge that would move unverified logins is refused and names them; nothing moves (M2)', function () {
    $target = Client::factory()->withOwner()->create(['currency' => $this->client->currency]);
    $verified = User::factory()->create(['email' => 'ok@example.com.np']);
    $unverified = User::factory()->unverified()->create(['email' => 'unproven@example.com.np', 'last_login_at' => now()]);
    $this->client->users()->attach($verified, ['is_owner' => true]);
    $this->client->users()->attach($unverified, ['is_owner' => false, 'permissions' => '[]']);

    expect(fn () => app(MergeClients::class)->handle($this->client, $target))
        ->toThrow(ValidationException::class, 'These logins have not verified their email address, so they can\'t be moved to the other client: unproven@example.com.np.');

    expect($target->users()->pluck('users.id')->all())->not->toContain($verified->id)
        ->and($this->client->fresh()->merged_into_client_id)->toBeNull();

    $this->client->users()->detach($unverified);
    app(MergeClients::class)->handle($this->client, $target);
    expect($target->users()->pluck('users.id')->all())->toContain($verified->id);
});

test('the client page offers "Make owner" data only for verified logins', function () {
    voidingInvite($this->client);
    $verified = User::factory()->create(['email' => 'ok@example.com.np']);
    $this->client->users()->attach($verified, ['is_owner' => false, 'permissions' => '[]']);
    actingAsStaff(['core.clients.view']);

    $logins = collect($this->get(route('admin.clients.show', $this->client))->viewData('page')['props']['users'])->keyBy('email');

    expect($logins['helper@example.com.np']['email_verified'])->toBeFalse()
        ->and($logins['ok@example.com.np']['email_verified'])->toBeTrue();
});

test('a merge still moves a login staff invited that nobody has used, with its invitation', function () {
    voidingInvite($this->client);
    $target = Client::factory()->withOwner()->create(['currency' => $this->client->currency]);

    app(MergeClients::class)->handle($this->client, $target);

    $invited = User::query()->where('email', 'helper@example.com.np')->sole();
    expect($target->users()->whereKey($invited->id)->exists())->toBeTrue()
        ->and(ClientInvitation::query()->sole()->client_id)->toBe($target->id);
});
