<?php

use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Currency;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Migration\ClientCsv;

/**
 * Review probes for PR #299 (#272). Each test states the behaviour the review expects.
 */
beforeEach(function () {
    Storage::fake('objects');
    Currency::factory()->create(['code' => 'NPR', 'is_default' => true]);
    $this->staff = actingAsStaff();
    confirmPassword();
    $this->run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'not-an-email')])));
    $this->url = route('admin.migration.imports.errors', $this->run);
    $this->flushSession();
});

function errorsDownloads(): int
{
    return ActivityLog::query()->where('event', 'migration.errors_downloaded')->count();
}

test('probe: HEAD without a recent password redirects and writes no audit row', function () {
    $this->withSession(['auth.password_confirmed_at' => 0]);
    $this->call('HEAD', $this->url)->assertRedirect(route('password.confirm'));
    expect(errorsDownloads())->toBe(0);
});

// Fails at 35f526ad: a HEAD reaches the controller, which logs "Client import errors downloaded" before streaming.
test('probe: HEAD with a recent password does not record a download', function () {
    confirmPassword();
    $this->call('HEAD', $this->url);
    expect(errorsDownloads())->toBe(0);
});

test('probe: method override cannot turn a POST into a safe request', function () {
    $this->withSession(['auth.password_confirmed_at' => 0]);
    $this->post($this->url, ['_method' => 'GET'], ['X-Step-Up-Check' => '1'])->assertStatus(405);
    $this->post($this->url.'?_method=HEAD', [], ['X-Step-Up-Check' => '1'])->assertStatus(405);
    $this->post(route('admin.migration.imports.accept', $this->run), ['_method' => 'OPTIONS'], ['X-Step-Up-Check' => '1'])
        ->assertStatus(200)->assertContent('');
    expect(errorsDownloads())->toBe(0);
});

test('probe: a trailing slash or upper-case path still hits step-up', function () {
    $this->withSession(['auth.password_confirmed_at' => 0]);
    $this->get($this->url.'/')->assertRedirect(route('password.confirm'));
    expect(errorsDownloads())->toBe(0);
});

test('probe: a cross-origin preflight for the probe header is not allowed', function () {
    $response = $this->call('OPTIONS', $this->url, server: [
        'HTTP_ORIGIN' => 'https://evil.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-step-up-check',
    ]);
    expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});

test('probe: the 423 answer carries nothing but the message', function () {
    config(['app.debug' => false]);
    $this->withSession(['auth.password_confirmed_at' => 0]);
    $body = $this->getJson($this->url, ['X-Step-Up-Check' => '1'])->assertStatus(423)->json();
    expect(array_keys($body))->toBe(['message']);
});

test('probe: the probe answers before authorization (staff without the permission gets 204)', function () {
    $this->actingAs(User::factory()->staff(['clients.view'])->create());
    confirmPassword();
    $this->get($this->url, ['X-Step-Up-Check' => '1'])->assertNoContent();
    $this->get($this->url)->assertForbidden();
});

test('probe: the remembered URL is always this app, never another host', function () {
    $this->withSession(['auth.password_confirmed_at' => 0]);
    $this->get($this->url.'?next=https://evil.example', ['Host' => 'localhost'])->assertRedirect(route('password.confirm'));
    expect(parse_url(session('url.intended'), PHP_URL_HOST))->toBe(parse_url(config('app.url'), PHP_URL_HOST));
});

test('probe: a confirm through the dialog (JSON) leaves the remembered file URL behind', function () {
    $this->withSession(['auth.password_confirmed_at' => 0]);
    $this->get($this->url)->assertRedirect(route('password.confirm'));
    $this->postJson(route('password.confirm.store'), ['password' => 'password'])->assertCreated();
    // Stale: a later confirm on the full page lands on the CSV instead of the default.
    expect(session('url.intended'))->toBe($this->url);
});

test('probe: signing out drops the remembered file URL, so login lands on the default', function () {
    $this->withSession(['auth.password_confirmed_at' => 0]);
    $this->get($this->url)->assertRedirect(route('password.confirm'));
    $this->post(route('logout'));
    expect(session('url.intended'))->toBeNull();
});
