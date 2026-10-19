<?php

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Models\Server;
use App\Support\Integrations\OutboundAddressGuard;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\Cpanel\Whm;

/**
 * Security review 2026-10-19, M1 (probe P2, inverted): stored WHM credentials belong to the destination they were
 * saved for. A new hostname, address, port or driver needs them entered again, the move is audited, and Test
 * connection never sends a stored credential anywhere else.
 */
const COLLECTOR = 'collector.attacker.test';

beforeEach(function () {
    $this->server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null, 'settings' => ['login_method' => 'token', 'spki_pin' => str_repeat('A', 43).'=']]);
    $this->form = ['name' => 'Shared 1', 'hostname' => Whm::HOST, 'driver' => 'cpanel', 'username' => 'root', 'max_accounts' => 0, 'monthly_cost' => 0, 'currency' => Currency::factory()->create()->code, 'settings' => ['login_method' => 'token']];
    Whm::fake();
    // Both hosts resolve to a public address, so only the credential rule stands between the token and the collector.
    app()->instance(OutboundAddressGuard::class, new OutboundAddressGuard(fn (string $host): array => [Whm::IP]));
    actingAsStaff();
    confirmPassword();
});

/**
 * @return list<array{host: string, authorization: string}>
 */
function whmRequestsSent(): array
{
    return Http::recorded()->map(fn (array $pair): array => [
        'host' => parse_url($pair[0]->url(), PHP_URL_HOST),
        'authorization' => $pair[0]->header('Authorization')[0] ?? '',
    ])->values()->all();
}

test('probe P2: a new hostname without the token again is refused, and Test connection still goes to the saved host', function () {
    $this->put(route('admin.services.servers.update', $this->server), [...$this->form, 'hostname' => COLLECTOR])
        ->assertSessionHasErrors(['api_token' => 'The server\'s address changed: enter the API token again. The stored one is never sent to a new address.']);

    expect($this->server->refresh())->hostname->toBe(Whm::HOST)->api_token->toBe(Whm::TOKEN);

    $this->post(route('admin.services.servers.test', $this->server));

    expect(collect(whmRequestsSent())->pluck('host')->unique()->all())->toBe([Whm::HOST]);
});

test('probe P2: a port or address change also needs the credentials again', function (array $change) {
    $this->put(route('admin.services.servers.update', $this->server), [...$this->form, ...$change])->assertSessionHasErrors('api_token');

    expect($this->server->refresh()->api_token)->toBe(Whm::TOKEN);
})->with([
    'port' => [['port' => 2083]],
    'ip address' => [['ip_address' => '93.184.216.99']],
]);

test('probe P2: moving the server with a new token clears the pin, audits old and new address, and only the new token reaches the new host', function () {
    $this->put(route('admin.services.servers.update', $this->server), [...$this->form, 'hostname' => COLLECTOR, 'api_token' => 'NEW-TOKEN-FOR-THE-NEW-HOST'])
        ->assertSessionHasNoErrors();

    $this->server->refresh();
    $log = ActivityLog::query()->where('event', 'server.retargeted')->sole();
    expect($this->server)->hostname->toBe(COLLECTOR)->api_token->toBe('NEW-TOKEN-FOR-THE-NEW-HOST')
        ->and($this->server->settings['spki_pin'] ?? null)->toBeNull()
        ->and($log->description)->toBe('Server connection address changed')
        ->and($log->properties['from']['hostname'])->toBe(Whm::HOST)
        ->and($log->properties['to']['hostname'])->toBe(COLLECTOR)
        ->and($log->properties['cleared'])->toBe(['password', 'settings.spki_pin'])
        ->and(json_encode($log->properties))->not->toContain(Whm::TOKEN)->not->toContain('NEW-TOKEN');

    Http::swap(new Factory(app('events')));
    Http::fake(['*' => Http::response(Whm::fixture('version'))]);
    $this->post(route('admin.services.servers.test', $this->server));

    expect(whmRequestsSent())->not->toBeEmpty()
        ->and(collect(whmRequestsSent())->every(fn (array $sent): bool => $sent['host'] === COLLECTOR && ! str_contains($sent['authorization'], Whm::TOKEN)))->toBeTrue();
});

test('an edit that keeps the destination keeps the stored token and the pin', function () {
    $this->put(route('admin.services.servers.update', $this->server), [...$this->form, 'name' => 'Shared One', 'hostname' => strtoupper(Whm::HOST)])
        ->assertSessionHasNoErrors();

    expect($this->server->refresh())->name->toBe('Shared One')->api_token->toBe(Whm::TOKEN)
        ->and($this->server->settings['spki_pin'])->toBe(str_repeat('A', 43).'=')
        ->and(ActivityLog::query()->where('event', 'server.retargeted')->exists())->toBeFalse();
});

test('a write outside the form that moves the server drops its credentials and pin', function () {
    $this->server->update(['hostname' => COLLECTOR]);

    expect($this->server->refresh())->api_token->toBeNull()->password->toBeNull()
        ->and($this->server->settings['spki_pin'] ?? null)->toBeNull()
        ->and($this->server->settings['login_method'])->toBe('token');
});

test('a write outside the form that moves the server drops its secret driver settings too', function () {
    $server = Server::factory()->create(['driver' => 'log', 'hostname' => 'panel-a.example.net', 'settings' => ['region' => 'eu-1', 'webhook_secret' => 'Old-Host-S3cret']]);

    $server->update(['hostname' => 'panel-b.example.net']);
    expect($server->refresh()->settings)->toBe(['region' => 'eu-1']);

    // The same write without a move keeps the secret.
    $server->update(['settings' => ['region' => 'eu-1', 'webhook_secret' => 'New-Host-S3cret']]);
    $server->update(['name' => 'Renamed']);
    expect($server->refresh()->settings['webhook_secret'])->toBe('New-Host-S3cret');
});
