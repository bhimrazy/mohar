<?php

/*
 * ADR 0023 §2: a new secret setting under its minimum is refused on the gateway, registrar and server forms; a
 * short value stored before that still loads, is flagged on its form and is listed by `integrations:short-secrets`.
 */

use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Modules\Services\Models\Server;
use App\Support\Domains\RegistrarDriverRegistry;
use App\Support\Integrations\StoredSecrets;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Domains\FakeRegistrar;
use Tests\Fakes\Domains\FakeRegistrarDriver;

beforeEach(function () {
    $driver = new class(new FakeRegistrar, 'pin_registrar') extends FakeRegistrarDriver
    {
        public function settingsFields(): array
        {
            return [
                new SettingField('api_key', 'API key', FieldType::Secret),
                new SettingField('pin', 'Reseller PIN', FieldType::Secret, minLength: 6),
            ];
        }
    };
    app(RegistrarDriverRegistry::class)->register('pin_registrar', fn () => $driver);
    actingAsStaff();
    confirmPassword();
});

function shortSecretGatewayPayload(string $secret): array
{
    return ['display_name' => 'Card', 'is_active' => true, 'show_on_order_form' => true, 'settings' => ['webhook_secret' => $secret]];
}

function shortSecretServerPayload(string $secret): array
{
    return [
        'name' => 'Panel 1', 'hostname' => 'panel1.example.net', 'driver' => 'log', 'username' => 'root', 'secure' => true,
        'max_accounts' => 0, 'is_active' => true, 'is_default' => false, 'nameservers' => [], 'monthly_cost' => 0,
        'currency' => 'NPR', 'settings' => ['webhook_secret' => $secret],
    ];
}

test('the gateway form refuses a new secret under 8 bytes, without flashing it, and saves one of 8', function () {
    $this->put(route('admin.billing.gateways.update', 'sandbox_card'), shortSecretGatewayPayload(' whsec7 '))
        ->assertSessionHasErrors(['settings.webhook_secret' => 'Webhook signing secret must be at least 8 characters. A shorter secret cannot be kept out of logs.']);

    expect(json_encode(session()->all()))->not->toContain('whsec7')
        ->and(Gateway::query()->where('key', 'sandbox_card')->value('config'))->toBeNull();

    $this->put(route('admin.billing.gateways.update', 'sandbox_card'), shortSecretGatewayPayload('whsec_08'))->assertSessionHasNoErrors();

    expect(Gateway::query()->where('key', 'sandbox_card')->sole()->setting('webhook_secret'))->toBe('whsec_08');
});

test('the registrar form refuses a short secret and accepts the shorter minimum a field declares', function () {
    $route = route('admin.domains.registrars.update', 'pin_registrar');

    $this->put($route, ['is_active' => true, 'test_mode' => false, 'settings' => ['api_key' => 'key-007', 'pin' => '12345']])
        ->assertSessionHasErrors(['settings.api_key', 'settings.pin']);
    $this->put($route, ['is_active' => true, 'test_mode' => false, 'settings' => ['api_key' => 'key-0008', 'pin' => '123456']])
        ->assertSessionHasNoErrors();

    expect(RegistrarAccount::query()->where('key', 'pin_registrar')->sole()->settings)->toBe(['api_key' => 'key-0008', 'pin' => '123456']);
});

test('the server form refuses a short secret driver setting', function () {
    Currency::factory()->create(['code' => 'NPR']);

    $this->post(route('admin.services.servers.store'), shortSecretServerPayload('hook-7!'))->assertSessionHasErrors('settings.webhook_secret');
    expect(Server::query()->count())->toBe(0);

    $this->post(route('admin.services.servers.store'), shortSecretServerPayload('hook-08!'))->assertSessionHasNoErrors();
    expect(Server::query()->sole()->settings['webhook_secret'])->toBe('hook-08!');
});

test('a short secret stored before the minimum still loads, is flagged by key only, and survives a save that keeps it', function () {
    Gateway::query()->create(['key' => 'sandbox_card', 'display_name' => 'Card', 'config' => ['webhook_secret' => 'q7~']]);
    RegistrarAccount::query()->create(['key' => 'pin_registrar', 'is_active' => true, 'settings' => ['api_key' => 'long-enough-key', 'pin' => 'k9~']]);
    $server = Server::factory()->create(['driver' => 'log', 'settings' => ['webhook_secret' => 'w4~']]);

    $this->get(route('admin.billing.gateways.edit', 'sandbox_card'))->assertOk()->assertDontSee('q7~')
        ->assertInertia(fn (Assert $page) => $page->where('gateway.secrets_short', ['webhook_secret'])->where('gateway.secrets_set.webhook_secret', true));
    $this->get(route('admin.domains.registrars.index'))->assertOk()->assertDontSee('k9~')
        ->assertInertia(fn (Assert $page) => $page->where('registrars', fn ($rows) => collect($rows)->firstWhere('key', 'pin_registrar')['secrets_short'] === ['pin']));
    $this->get(route('admin.services.servers.edit', $server))->assertOk()->assertDontSee('w4~')
        ->assertInertia(fn (Assert $page) => $page->where('server.settings.secrets_short', ['webhook_secret']));

    // A blank secret input keeps the stored value: unrelated edits are not blocked by it.
    $this->put(route('admin.billing.gateways.update', 'sandbox_card'), shortSecretGatewayPayload(''))->assertSessionHasNoErrors();
    expect(Gateway::query()->where('key', 'sandbox_card')->sole()->setting('webhook_secret'))->toBe('q7~');
});

test('integrations:short-secrets lists stored short secrets by name across gateways, registrars and servers, never by value', function () {
    expect(Artisan::call('integrations:short-secrets'))->toBe(0)
        ->and(Artisan::output())->toContain('No stored secret is shorter than its minimum length.');

    Gateway::query()->create(['key' => 'sandbox_card', 'display_name' => 'Sandbox card', 'config' => ['webhook_secret' => 'q7~']]);
    RegistrarAccount::query()->create(['key' => 'pin_registrar', 'is_active' => true, 'settings' => ['api_key' => 'long-enough-key', 'pin' => '12345']]);
    Server::factory()->create(['driver' => 'log', 'name' => 'Demo panel', 'settings' => ['webhook_secret' => 'w4~', 'region' => 'np']]);

    expect(Artisan::call('integrations:short-secrets'))->toBe(1);
    $output = Artisan::output();

    expect($output)->toContain('Sandbox card')->toContain('Webhook signing secret (webhook_secret)')
        ->toContain('Reseller PIN (pin)')->toContain('Demo panel')->toContain('Webhook secret (demo) (webhook_secret)')
        ->not->toContain('q7~')->not->toContain('12345')->not->toContain('w4~')->not->toContain('API key');
    expect(collect(app(StoredSecrets::class)->check()['short'])->pluck('minimum', 'key')->all())->toBe(['webhook_secret' => 8, 'pin' => 6]);
});

test('the server form refuses a short password, API token or access hash, flags a stored one by key, and a blank keeps it', function () {
    Currency::factory()->create(['code' => 'NPR']);
    $payload = [...shortSecretServerPayload('hook-08!'), 'password' => 'pw-7!ab', 'api_token' => 'tok', 'access_hash' => 'h4sh'];

    $this->post(route('admin.services.servers.store'), $payload)
        ->assertSessionHasErrors(['password' => 'Password must be at least 8 characters. A shorter secret cannot be kept out of logs.', 'api_token', 'access_hash']);
    expect(json_encode(session()->all()))->not->toContain('pw-7!ab')->and(Server::query()->count())->toBe(0);

    $server = Server::factory()->create(['driver' => 'log', 'name' => 'Old box', 'password' => 'abc', 'api_token' => 'long-enough-token']);

    $this->get(route('admin.services.servers.edit', $server))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('server.credentials_short', ['password'])->where('server.has_password', true));

    $this->put(route('admin.services.servers.update', $server), [...shortSecretServerPayload(''), 'hostname' => $server->hostname, 'password' => ''])->assertSessionHasNoErrors();
    expect($server->fresh()->password)->toBe('abc');
});

test('a stored secrets source key is checked and registered once', function () {
    $secrets = new StoredSecrets;
    $secrets->register('extra', fn (): array => []);

    expect(fn () => $secrets->register('extra', fn (): array => []))->toThrow(LogicException::class)
        ->and(fn () => $secrets->register('Bad Key', fn (): array => []))->toThrow(InvalidArgumentException::class);
});
