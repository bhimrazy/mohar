<?php

use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Service;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Services\ServicesTestKit;

/**
 * #44: clients see their stored hosting password on demand, behind step-up, audited and rate limited.
 */
beforeEach(function () {
    ServicesTestKit::bind();
    [$this->user, $this->client] = actingAsClient();
    $this->service = Service::factory()->onServer()->create(['client_id' => $this->client->id, 'password' => 'Hosting-Secret-91']);
});

function revealPasswordFor(Service $service): TestResponse
{
    return test()->postJson(route('client.services.password.reveal', $service->id));
}

function revealAudits(): int
{
    return ActivityLog::query()->where('description', 'Service password revealed in the client area')->count();
}

test('the owner sees the password after a recent confirmation; the reveal is audited without the value and never cached', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });
    confirmPassword();

    revealPasswordFor($this->service)
        ->assertOk()
        ->assertExactJson(['password' => 'Hosting-Secret-91'])
        ->assertHeader('Pragma', 'no-cache')
        ->assertHeader('Cache-Control', 'no-store, private');

    $audit = ActivityLog::query()->where('description', 'Service password revealed in the client area')->sole();
    expect($audit)->user_id->toBe($this->user->id)->client_id->toBe($this->client->id)->subject_id->toBe($this->service->id)
        ->and(json_encode($audit->getAttributes()))->not->toContain('Hosting-Secret-91')
        ->and(implode("\n", $logged))->not->toContain('Hosting-Secret-91');
    $this->get(route('client.services.password.reveal', $this->service->id))->assertMethodNotAllowed();
});

test('the service page offers the reveal but never carries the password', function () {
    confirmPassword();

    $page = $this->get(route('client.services.show', $this->service->id))
        ->assertInertia(fn (Assert $page) => $page->component('client/services/show')
            ->missing('service.password')
            ->where('can.revealPassword', true)
            ->where('revealBlockedReason', null));

    expect($page->getContent())->not->toContain('Hosting-Secret-91');
});

test('without a recent confirmation the reveal is refused and not audited', function (Closure $session) {
    $session();

    revealPasswordFor($this->service)->assertStatus(423)->assertJsonMissing(['password' => 'Hosting-Secret-91']);

    expect(revealAudits())->toBe(0);
})->with([
    'never confirmed' => [fn () => null],
    'confirmation expired' => [fn () => test()->withSession(['auth.password_confirmed_at' => now()->getTimestamp() - (int) config('auth.step_up_seconds') - 1])],
]);

test('a login without a password is told to set one', function () {
    $this->user->forceFill(['password' => null])->save();
    confirmPassword();

    revealPasswordFor($this->service)->assertStatus(423)->assertJson(['code' => 'password_not_set']);
    expect(revealAudits())->toBe(0);
});

test('another client\'s service is not found, even with a recent confirmation', function () {
    $foreign = Service::factory()->onServer()->create(['client_id' => Client::factory()->create()->id, 'password' => 'Foreign-Secret-77']);
    confirmPassword();

    revealPasswordFor($foreign)->assertNotFound()->assertJsonMissing(['password' => 'Foreign-Secret-77']);
    expect(revealAudits())->toBe(0);
});

test('a sub-user needs the services area of the account', function (array $areas, int $status) {
    $member = User::factory()->create();
    $this->client->users()->attach($member, ['is_owner' => false, 'permissions' => json_encode($areas)]);
    $this->actingAs($member);
    confirmPassword();

    revealPasswordFor($this->service)->assertStatus($status);
    expect(revealAudits())->toBe($status === 200 ? 1 : 0);
})->with([
    'billing only' => [['billing'], 403],
    'services' => [['services'], 200],
]);

test('five reveals per ten minutes per login, across services; asking before confirming uses none', function () {
    $second = Service::factory()->onServer()->create(['client_id' => $this->client->id]);
    foreach (range(1, 3) as $attempt) {
        revealPasswordFor($this->service)->assertStatus(423);
    }
    confirmPassword();

    foreach (range(1, 5) as $attempt) {
        revealPasswordFor($attempt % 2 === 0 ? $second : $this->service)->assertOk();
    }

    revealPasswordFor($second)->assertTooManyRequests()->assertHeader('Cache-Control', 'no-store, private')->assertJsonMissingPath('password');
    expect(revealAudits())->toBe(5);

    $this->travel(11)->minutes();
    confirmPassword();
    revealPasswordFor($this->service)->assertOk();
});

test('services without a stored password, or no longer live, offer no reveal', function (array $attributes) {
    $service = Service::factory()->onServer()->create(['client_id' => $this->client->id, ...$attributes]);
    confirmPassword();

    $this->get(route('client.services.show', $service->id))->assertInertia(fn (Assert $page) => $page->where('can.revealPassword', false));
    revealPasswordFor($service)->assertUnprocessable()->assertJsonMissingPath('password');
    expect(revealAudits())->toBe(0);
})->with([
    'SSO-only panel, no password stored' => [['password' => null]],
    'terminated' => [['status' => ServiceStatus::Terminated]],
]);
