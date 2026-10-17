<?php

use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Platform\Models\ImpersonationSession;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Currency::factory()->create();
    $this->client = Client::factory()->withOwner()->create();
    $this->owner = $this->client->users()->sole();
    confirmPassword();
});

/**
 * Start impersonating and follow the signed link; returns the signed URL.
 */
function impersonate(Client $client, array $data = []): string
{
    $response = test()->post(route('admin.platform.clients.impersonate', $client), $data, ['X-Inertia' => 'true']);
    $response->assertStatus(409);
    $url = (string) $response->headers->get('X-Inertia-Location');
    test()->get($url)->assertRedirect(route('client.dashboard'));

    return $url;
}

test('impersonation requires core.clients.impersonate', function () {
    actingAsStaff(['core.clients.view']);

    $this->post(route('admin.platform.clients.impersonate', $this->client))->assertForbidden();
    expect(ImpersonationSession::query()->count())->toBe(0);
});

test('staff sign in as the account owner, see the banner, and actions are logged with impersonator_id', function () {
    $staff = actingAsStaff(['core.clients.view', 'core.clients.impersonate']);

    impersonate($this->client);

    $this->assertAuthenticatedAs($this->owner);
    expect(session(ActivityLogger::IMPERSONATOR_SESSION_KEY))->toBe($staff->id);

    $this->get(route('client.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('client.id', $this->client->id)
            ->where('impersonation.staff', $staff->name)
            ->where('impersonation.user', $this->owner->email));

    $this->put(route('client.account.update'), [
        'first_name' => 'Changed', 'last_name' => 'ByStaff', 'email' => $this->client->email,
        'address_1' => '1 Main St', 'city' => 'Springfield', 'postcode' => '12345', 'country' => 'US',
    ])->assertRedirect();

    $entry = ActivityLog::query()->where('description', 'Client updated account details')->sole();
    expect($entry)->user_id->toBe($this->owner->id)->impersonator_id->toBe($staff->id);
    $this->assertDatabaseHas('activity_log', ['event' => 'impersonation.started', 'user_id' => $staff->id, 'client_id' => $this->client->id]);
});

test('while impersonating, password, email, 2FA and admin routes are blocked', function () {
    actingAsStaff(['core.clients.view', 'core.clients.impersonate']);
    impersonate($this->client);

    $this->put(route('user-password.update'), ['current_password' => 'password', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertForbidden();
    $this->patch(route('profile.update'), ['name' => 'X', 'email' => 'attacker@example.com'])->assertForbidden();
    $this->get(route('security.edit'))->assertForbidden();
    $this->get(route('admin.dashboard'))->assertForbidden();

    expect($this->owner->fresh()->email)->not->toBe('attacker@example.com');
});

test('returning to admin ends the session and restores the staff login', function () {
    $staff = actingAsStaff(['core.clients.view', 'core.clients.impersonate']);
    impersonate($this->client);

    $this->post(route('platform.impersonation.leave'))->assertRedirect(route('admin.clients.show', $this->client));

    $this->assertAuthenticatedAs($staff);
    expect(session(ActivityLogger::IMPERSONATOR_SESSION_KEY))->toBeNull()
        ->and(ImpersonationSession::query()->sole())->end_reason->toBe('returned')->ended_at->not->toBeNull();
    $this->assertDatabaseHas('activity_log', ['event' => 'impersonation.ended', 'user_id' => $staff->id]);
});

test('a session older than the time limit ends and returns to admin', function () {
    $staff = actingAsStaff(['core.clients.view', 'core.clients.impersonate']);
    impersonate($this->client);

    $this->travel(61)->minutes();

    $this->get(route('client.dashboard'))->assertRedirect(route('admin.clients.show', $this->client));
    $this->assertAuthenticatedAs($staff);
    expect(ImpersonationSession::query()->sole()->end_reason)->toBe('expired');
});

test('staff logins cannot be impersonated', function () {
    actingAsStaff(['core.clients.view', 'core.clients.impersonate']);
    $staffLogin = User::factory()->staff()->create();
    $this->client->users()->attach($staffLogin);

    $this->post(route('admin.platform.clients.impersonate', $this->client), ['user_id' => $staffLogin->id])->assertSessionHasErrors('user_id');
});

test('a chosen user must belong to the account', function () {
    actingAsStaff(['core.clients.view', 'core.clients.impersonate']);
    $stranger = User::factory()->create();

    $this->post(route('admin.platform.clients.impersonate', $this->client), ['user_id' => $stranger->id])->assertSessionHasErrors('user_id');
});

test('the signed link is single-use, staff-bound and tamper-proof', function () {
    $staff = actingAsStaff(['core.clients.view', 'core.clients.impersonate']);
    $response = $this->post(route('admin.platform.clients.impersonate', $this->client), [], ['X-Inertia' => 'true']);
    $url = (string) $response->headers->get('X-Inertia-Location');

    $this->get($url.'x')->assertForbidden();

    actingAsStaff(['core.clients.view', 'core.clients.impersonate']);
    $this->get($url)->assertForbidden();

    $this->actingAs($staff);
    $this->get($url)->assertRedirect(route('client.dashboard'));

    $this->post(route('platform.impersonation.leave'));
    $this->get($url)->assertForbidden();
});

test('the signed link expires', function () {
    actingAsStaff(['core.clients.view', 'core.clients.impersonate']);
    $response = $this->post(route('admin.platform.clients.impersonate', $this->client), [], ['X-Inertia' => 'true']);

    $this->travel(5)->minutes();

    $this->get((string) $response->headers->get('X-Inertia-Location'))->assertForbidden();
});
