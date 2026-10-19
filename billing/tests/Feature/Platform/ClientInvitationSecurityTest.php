<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientInvitation;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Services\TemplateCatalog;
use App\Modules\Platform\Actions\Clients\AcceptClientInvitation;
use App\Modules\Platform\Actions\Clients\ManageClientLogins;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/*
 * Security review of #78 (client invitation tokens). An invitation is a one-time way to set the first password of a
 * login nobody has used yet. Once the login holder has set a password some other way, or the login is no longer the
 * one the link was mailed to, the link must stop working: it would otherwise reset the password and sign in without
 * the second factor.
 */

beforeEach(function () {
    app(TemplateCatalog::class)->install('en');
    Mail::fake();
    $this->client = Client::factory()->create(['company_name' => 'Acme Studio']);
});

function securityProbeInvite(Client $client, string $email = 'helper@example.test'): string
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

function securityProbeAccept(string $token, string $password = 'Invite-Passw0rd!x')
{
    return test()->post(route('platform.client-invitations.accept', $token), ['password' => $password, 'password_confirmation' => $password]);
}

it('stops the invitation link once the login set its own password and turned on two-factor', function () {
    $token = securityProbeInvite($this->client);
    $user = User::query()->where('email', 'helper@example.test')->sole();

    // The login holder ignores the invitation and uses "Forgot password", then enables 2FA.
    $status = Password::broker()->reset(
        ['email' => $user->email, 'token' => Password::broker()->createToken($user), 'password' => 'Own-Passw0rd!xyz', 'password_confirmation' => 'Own-Passw0rd!xyz'],
        fn (User $user, string $password) => $user->forceFill(['password' => $password])->save(),
    );
    expect($status)->toBe(Password::PASSWORD_RESET);
    $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_recovery_codes' => encrypt(json_encode(['code'])), 'two_factor_confirmed_at' => now()])->save();

    securityProbeAccept($token);

    // Expected: refused, password unchanged, nobody signed in without the second factor.
    $this->assertGuest();
    expect(password_verify('Own-Passw0rd!xyz', $user->fresh()->password))->toBeTrue();
});

it('never signs a two-factor login in from the invitation page', function () {
    $token = securityProbeInvite($this->client);
    User::query()->where('email', 'helper@example.test')->sole()
        ->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_recovery_codes' => encrypt(json_encode(['code'])), 'two_factor_confirmed_at' => now()])->save();

    securityProbeAccept($token);

    $this->assertGuest();
});

it('stops the invitation link when the login changes its email address', function () {
    $token = securityProbeInvite($this->client);
    $user = User::query()->where('email', 'helper@example.test')->sole();
    $user->forceFill(['email' => 'new-address@example.test', 'email_verified_at' => null])->save();

    securityProbeAccept($token);

    // Expected: the link mailed to the old address no longer sets the password, nor verifies the new address.
    $this->assertGuest();
    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('does not issue an invitation to an existing login, so it cannot reset that login password', function () {
    $existing = User::factory()->create(['email' => 'existing@example.test']);

    app(ManageClientLogins::class)->invite($this->client, 'existing@example.test', 'Existing', ['billing']);

    expect(ClientInvitation::query()->count())->toBe(0);
    expect(fn () => app(ManageClientLogins::class)->resendInvitation($this->client, $existing))
        ->toThrow(ValidationException::class);
});

it('scopes resending to a login of that account', function () {
    securityProbeInvite($this->client);
    $other = Client::factory()->create();
    $user = User::query()->where('email', 'helper@example.test')->sole();
    actingAsStaff(['core.users.manage', 'core.clients.view']);

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('admin.platform.clients.users.invitation', [$other, $user]))->assertNotFound();
});

it('requires the users permission to resend an invitation', function () {
    securityProbeInvite($this->client);
    $user = User::query()->where('email', 'helper@example.test')->sole();
    actingAsStaff(['core.clients.view', 'core.*']);

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('admin.platform.clients.users.invitation', [$this->client, $user]))->assertForbidden();
});

it('requires a recent password to resend an invitation', function () {
    securityProbeInvite($this->client);
    $user = User::query()->where('email', 'helper@example.test')->sole();
    $before = ClientInvitation::query()->sole()->token_hash;
    actingAsStaff(['core.users.manage', 'core.clients.view']);

    $this->post(route('admin.platform.clients.users.invitation', [$this->client, $user]))->assertSessionHasErrors('password_confirmation');
    expect(ClientInvitation::query()->sole()->token_hash)->toBe($before);
});

it('throttles accept attempts', function () {
    for ($i = 0; $i < 10; $i++) {
        securityProbeAccept(str_repeat('x', 48));
    }

    securityProbeAccept(str_repeat('y', 48))->assertStatus(429);
});

it('serialises concurrent accepts on PostgreSQL: the second waits for the lock and then sees the link used', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Row locks are PostgreSQL only.');
    }

    // A second connection with committed rows plays the other request.
    config(['database.connections.probe' => config('database.connections.pgsql')]);
    $other = DB::connection('probe');
    $suffix = bin2hex(random_bytes(4));
    $userId = $other->table('users')->insertGetId(['name' => 'Race', 'email' => "race-{$suffix}@example.test", 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
    $clientId = $other->table('clients')->insertGetId([...Client::factory()->raw(), 'created_at' => now(), 'updated_at' => now()]);
    $other->table('client_user')->insert(['client_id' => $clientId, 'user_id' => $userId, 'is_owner' => false, 'permissions' => '[]']);
    $token = 'race'.$suffix.str_repeat('a', 36);
    $invitationId = $other->table('client_invitations')->insertGetId(['client_id' => $clientId, 'user_id' => $userId, 'token_hash' => ClientInvitation::hashToken($token), 'email' => "race-{$suffix}@example.test", 'sent_at' => now(), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

    try {
        // The other request is mid-accept: it holds the row lock and has marked the link used, not yet committed.
        $other->beginTransaction();
        $other->table('client_invitations')->where('id', $invitationId)->lockForUpdate()->first();
        $other->table('client_invitations')->where('id', $invitationId)->update(['accepted_at' => now()]);

        DB::statement("SET lock_timeout = '500ms'");
        $blocked = false;
        try {
            DB::transaction(fn () => ClientInvitation::query()->lockForUpdate()->findOrFail($invitationId));
        } catch (QueryException $e) {
            $blocked = str_contains($e->getMessage(), 'lock timeout');
        }
        expect($blocked)->toBeTrue();

        $other->commit();
        DB::statement('SET lock_timeout = 0');

        expect(fn () => app(AcceptClientInvitation::class)
            ->handle(ClientInvitation::query()->findOrFail($invitationId), 'Second-Passw0rd!x'))
            ->toThrow(ValidationException::class);
    } finally {
        if ($other->transactionLevel() > 0) {
            $other->rollBack();
        }
        $other->table('client_invitations')->where('id', $invitationId)->delete();
        $other->table('client_user')->where('client_id', $clientId)->delete();
        $other->table('clients')->where('id', $clientId)->delete();
        $other->table('users')->where('id', $userId)->delete();
        $other->disconnect();
    }
});
