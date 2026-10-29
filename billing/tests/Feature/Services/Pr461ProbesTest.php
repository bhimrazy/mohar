<?php

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Services\ServicesTestKit;

beforeEach(function () {
    ServicesTestKit::bind();
});

function p461audits(): int
{
    return ActivityLog::query()->where('description', 'Service password revealed in the client area')->count();
}

test('probe: staff signed in as the client cannot step up, so cannot reveal', function () {
    [$owner, $client] = actingAsClient();
    $service = Service::factory()->onServer()->create(['client_id' => $client->id, 'password' => 'Hosting-Secret-91']);
    $staff = actingAsStaff(['core.clients.view', 'core.clients.impersonate']);
    confirmPassword();
    $r = test()->post(route('admin.platform.clients.impersonate', $client), [], ['X-Inertia' => 'true']);
    test()->get((string) $r->headers->get('X-Inertia-Location'))->assertRedirect(route('client.dashboard'));
    $this->assertAuthenticatedAs($owner);

    // The page shows the action disabled with the reason, so the dialog never opens.
    $this->get(route('client.services.show', $service->id))->assertInertia(fn (Assert $p) => $p
        ->where('can.revealPassword', true)
        ->where('revealBlockedReason', "Staff signed in as a client can't reveal service passwords. Use the admin service page instead."));

    $this->postJson(route('client.services.password.reveal', $service->id))->assertStatus(423);
    $this->postJson(route('password.confirm.store'), ['password' => 'password'])->assertForbidden();
    $this->postJson(route('client.services.password.reveal', $service->id))->assertStatus(423)->assertJsonMissing(['password' => 'Hosting-Secret-91']);
    expect(p461audits())->toBe(0);
});

test('probe: pending, cancelled, fraud, completed offer no reveal', function (ServiceStatus $status) {
    [, $client] = actingAsClient();
    $service = Service::factory()->onServer()->create(['client_id' => $client->id, 'password' => 'X-Secret-1', 'status' => $status]);
    confirmPassword();
    $this->get(route('client.services.show', $service->id))->assertInertia(fn (Assert $p) => $p->where('can.revealPassword', false));
    $this->postJson(route('client.services.password.reveal', $service->id))->assertUnprocessable();
})->with([[ServiceStatus::Pending], [ServiceStatus::Cancelled], [ServiceStatus::Fraud], [ServiceStatus::Completed]]);

test('probe: partial reload of the service prop carries no password', function () {
    [, $client] = actingAsClient();
    $service = Service::factory()->onServer()->create(['client_id' => $client->id, 'password' => 'Partial-Secret-5']);
    confirmPassword();
    $first = $this->get(route('client.services.show', $service->id));
    $version = $first->viewData('page')['version'] ?? '';
    $r = $this->get(route('client.services.show', $service->id), ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version, 'X-Inertia-Partial-Component' => 'client/services/show', 'X-Inertia-Partial-Data' => 'service,can']);
    expect($r->getContent())->not->toContain('Partial-Secret-5');
});

test('probe: user on two accounts reveals only within the selected account', function () {
    [$user, $a] = actingAsClient();
    $b = Client::factory()->create();
    $b->users()->attach($user, ['is_owner' => false, 'permissions' => json_encode(['billing'])]);
    $sa = Service::factory()->onServer()->create(['client_id' => $a->id, 'password' => 'A-Secret']);
    $sb = Service::factory()->onServer()->create(['client_id' => $b->id, 'password' => 'B-Secret']);
    confirmPassword();
    $this->withSession([ClientContext::SESSION_KEY => $b->id, 'auth.password_confirmed_at' => now()->getTimestamp()]);
    $this->postJson(route('client.services.password.reveal', $sb->id))->assertForbidden();
    $this->postJson(route('client.services.password.reveal', $sa->id))->assertStatus(403);
    $this->withSession([ClientContext::SESSION_KEY => $a->id]);
    $this->postJson(route('client.services.password.reveal', $sb->id))->assertNotFound();
    $this->postJson(route('client.services.password.reveal', $sa->id))->assertOk()->assertExactJson(['password' => 'A-Secret']);
});

test('probe: an undecryptable stored password gives a clear error and no "revealed" audit', function () {
    [, $client] = actingAsClient();
    $service = Service::factory()->onServer()->create(['client_id' => $client->id, 'password' => 'Will-Break']);
    DB::table('services')->where('id', $service->id)->update(['password' => 'eyJpdiI6ImJyb2tlbiJ9']);
    confirmPassword();

    $this->postJson(route('client.services.password.reveal', $service->id))
        ->assertUnprocessable()
        ->assertJson(['message' => 'The stored password could not be read. Set a new one with Change password on this page.'])
        ->assertJsonMissingPath('password');
    expect(p461audits())->toBe(0);
});

test('review: the limit is the count hit() returns, so a stale tooManyAttempts read cannot let a 6th reveal through', function () {
    [, $client] = actingAsClient();
    $service = Service::factory()->onServer()->create(['client_id' => $client->id, 'password' => 'Race-Secret-6']);
    // A limiter whose read is always stale, as for parallel requests that all read the count before any of them hit.
    RateLimiter::swap(new class(app('cache')->store()) extends Illuminate\Cache\RateLimiter
    {
        public function tooManyAttempts($key, $maxAttempts): bool
        {
            return false;
        }
    });
    confirmPassword();

    foreach (range(1, 5) as $attempt) {
        $this->postJson(route('client.services.password.reveal', $service->id))->assertOk();
    }

    $this->postJson(route('client.services.password.reveal', $service->id))->assertTooManyRequests()->assertJsonMissingPath('password');
    expect(p461audits())->toBe(5);
});
