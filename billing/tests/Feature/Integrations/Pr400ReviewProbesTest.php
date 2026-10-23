<?php

/*
 * Review-board probes for PR #400 (ADR 0023 step 3c), adopted as regression tests. P7 to P10 failed at 2d7e7ba3;
 * P9 and P10 now assert the fixed behaviour (exit 2 for an unreadable record, the `invalid-key` demo trigger).
 */

use App\Data\Integrations\SettingField;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Support\ServerSettings;
use App\Support\Domains\RegistrarDriverRegistry;
use App\Support\Services\ProvisioningDriverRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    actingAsStaff();
    confirmPassword();
});

function rb400Server(array $over = []): array
{
    return [
        'name' => 'Panel 1', 'hostname' => 'panel1.example.net', 'driver' => 'log', 'username' => 'root', 'secure' => true,
        'max_accounts' => 0, 'is_active' => true, 'is_default' => false, 'nameservers' => [], 'monthly_cost' => 0,
        'currency' => 'NPR', ...$over,
    ];
}

test('P1 every registered driver field round-trips through toArray/fromArray', function () {
    $fields = [];
    foreach (app(GatewayManager::class)->drivers() as $driver) {
        $fields = [...$fields, ...array_map(SettingField::fromArray(...), $driver->settingsFields())];
    }
    $registrars = app(RegistrarDriverRegistry::class);
    foreach (['log', 'resellerclub'] as $key) {
        if ($registrars->has($key)) {
            $fields = [...$fields, ...$registrars->fields($key)];
        }
    }
    $prov = app(ProvisioningDriverRegistry::class);
    foreach ($prov->keys() as $key) {
        $fields = [...$fields, ...$prov->metadata($key)->serverFields];
    }

    foreach ($fields as $field) {
        $again = SettingField::fromArray($field->toArray());
        expect($again->toArray())->toBe($field->toArray());
    }
    expect(count($fields))->toBeGreaterThan(3);
});

test('P2 gateway: non-string and whitespace-only secrets cannot bypass; whitespace keeps the stored value', function () {
    Gateway::query()->create(['key' => 'sandbox_card', 'display_name' => 'Card', 'config' => ['webhook_secret' => 'long-enough-1']]);
    $route = route('admin.billing.gateways.update', 'sandbox_card');
    $base = ['display_name' => 'Card', 'is_active' => true, 'show_on_order_form' => true];

    $this->put($route, [...$base, 'settings' => ['webhook_secret' => 1234]])->assertSessionHasErrors('settings.webhook_secret');
    $this->put($route, [...$base, 'settings' => ['webhook_secret' => ['abc']]])->assertSessionHasErrors('settings.webhook_secret');
    $this->put($route, [...$base, 'settings' => ['webhook_secret' => true]])->assertSessionHasErrors('settings.webhook_secret');
    $this->put($route, [...$base, 'settings' => ['webhook_secret' => "   \t  "]])->assertSessionHasNoErrors();

    expect(Gateway::query()->where('key', 'sandbox_card')->sole()->setting('webhook_secret'))->toBe('long-enough-1');
});

test('P3 bytes, not characters: a 3-character, 9-byte value is accepted (CTO decision 2)', function () {
    $this->put(route('admin.billing.gateways.update', 'sandbox_card'), ['display_name' => 'Card', 'is_active' => true, 'show_on_order_form' => true, 'settings' => ['webhook_secret' => '€€€']])
        ->assertSessionHasNoErrors();
    expect(Gateway::query()->where('key', 'sandbox_card')->sole()->setting('webhook_secret'))->toBe('€€€');
});

test('P4 registrar: refused short secret is not flashed and not in the message', function () {
    $this->from(route('admin.domains.registrars.index'))
        ->put(route('admin.domains.registrars.update', 'log'), ['is_active' => true, 'test_mode' => false, 'settings' => ['api_key' => 'zq9w']])
        ->assertSessionHasErrors('settings.api_key');

    expect(json_encode(session()->all()))->not->toContain('zq9w');
    expect(RegistrarAccount::query()->where('key', 'log')->exists())->toBeFalse();
});

test('P5 server driver setting: refused short secret is not flashed and not in the message', function () {
    Currency::factory()->create(['code' => 'NPR']);
    $this->from(route('admin.services.servers.create'))
        ->post(route('admin.services.servers.store'), rb400Server(['settings' => ['webhook_secret' => 'zq9w']]))
        ->assertSessionHasErrors('settings.webhook_secret');

    expect(json_encode(session()->all()))->not->toContain('zq9w');
});

test('P6 ServerSettings::put refuses a short secret without echoing it', function () {
    $server = Server::factory()->create(['driver' => 'log']);

    try {
        app(ServerSettings::class)->put($server, 'webhook_secret', 'zq9w');
        $this->fail('put() accepted a short secret');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('at least 8')->not->toContain('zq9w');
    }
    expect($server->fresh()->settings)->toBeNull();
});

test('P7 CTO decision 1: the server row password is refused under 8 bytes', function () {
    Currency::factory()->create(['code' => 'NPR']);
    $this->post(route('admin.services.servers.store'), rb400Server(['password' => 'abc', 'api_token' => 'tok']))
        ->assertSessionHasErrors(['password', 'api_token']);
});

test('P8 CTO decision 1: a stored short server credential is listed by the command', function () {
    Server::factory()->create(['driver' => 'log', 'name' => 'Old box', 'password' => 'abc']);

    expect(Artisan::call('integrations:short-secrets'))->toBe(1);
    expect(Artisan::output())->toContain('Old box')->not->toContain('abc ');
});

test('P9 the command survives an undecryptable row, reports it without its ciphertext, and still checks the other sources', function () {
    $gateway = Gateway::query()->create(['key' => 'sandbox_card', 'display_name' => 'Card', 'config' => ['webhook_secret' => 'long-enough-1']]);
    DB::table($gateway->getTable())->where('id', $gateway->id)->update(['config' => 'not-a-ciphertext']);
    Server::factory()->create(['driver' => 'log', 'name' => 'Demo panel', 'settings' => ['webhook_secret' => 'w4v']]);

    $code = Artisan::call('integrations:short-secrets');
    $output = Artisan::output();

    expect($output)->toContain('Demo panel')->toContain('Webhook secret (demo) (webhook_secret)')
        ->toContain('Cannot be decrypted')->not->toContain('not-a-ciphertext')
        ->and($code)->toBe(2);
});

test('P10 the log registrar demo failure trigger can be saved, and fails the connection test', function () {
    $this->put(route('admin.domains.registrars.update', 'log'), ['is_active' => true, 'test_mode' => false, 'settings' => ['api_key' => 'invalid-key']])
        ->assertSessionHasNoErrors();

    $this->post(route('admin.domains.registrars.test', 'log'))->assertSessionHas('inertia.flash_data.toast.type', 'error');
});
