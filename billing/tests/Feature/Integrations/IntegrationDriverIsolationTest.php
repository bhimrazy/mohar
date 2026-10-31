<?php

/*
 * Follow-ups to #489 (re-review N1-N3): one invalid driver is reported and left out instead of breaking the pages and
 * checks that cover every driver, and a step-up refusal never puts submitted secrets into the session.
 */

use App\Contracts\Billing\GatewayRegistry;
use App\Data\Integrations\SettingField;
use App\Data\Services\Provisioning\DriverMetadata;
use App\Enums\FieldType;
use App\Modules\Billing\Actions\CheckGatewayCredentials;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Models\IntegrationCredential;
use App\Modules\Services\Drivers\Provisioning\LogDriver;
use App\Support\CalendarDate;
use App\Support\Dashboard\Dashboard;
use App\Support\Domains\RegistrarDriverRegistry;
use App\Support\Services\ProvisioningDriverRegistry;
use Tests\Fakes\Billing\FakeSigningGatewayDriver;
use Tests\Fakes\Domains\FakeRegistrar;
use Tests\Fakes\Domains\FakeRegistrarDriver;

beforeEach(function () {
    actingAsStaff();
    confirmPassword();
});

test('a registrar driver declaring a certificate is left out of the registrars page instead of breaking it', function () {
    app(RegistrarDriverRegistry::class)->register('cert_registrar', fn () => new class(new FakeRegistrar, 'cert_registrar') extends FakeRegistrarDriver
    {
        public function settingsFields(): array
        {
            return [new SettingField('client_cert', 'Client certificate', FieldType::Certificate)];
        }
    });

    $this->withoutExceptionHandling();
    $this->get(route('admin.domains.registrars.index'))->assertOk();

    expect(app(RegistrarDriverRegistry::class)->keys())->not->toContain('cert_registrar')->toContain('log')
        ->and(fn () => app(RegistrarDriverRegistry::class)->get('cert_registrar'))->toThrow(LogicException::class, 'declares the certificate setting');
});

test('a provisioning driver declaring a certificate is left out of the server form instead of breaking it', function () {
    app(ProvisioningDriverRegistry::class)->register('cert_panel', fn () => new class extends LogDriver
    {
        public function key(): string
        {
            return 'cert_panel';
        }

        public function metadata(): DriverMetadata
        {
            return new DriverMetadata(requiresServer: true, serverFields: [new SettingField('client_cert', 'Client certificate', FieldType::Certificate)]);
        }
    });

    $this->withoutExceptionHandling();
    $this->get(route('admin.services.servers.create'))->assertOk();

    expect(app(ProvisioningDriverRegistry::class)->keys())->not->toContain('cert_panel')->toContain('log');
});

test('a gateway driver with a reserved setting key does not stop the certificate check or health line for the others', function () {
    app(GatewayRegistry::class)->register(new FakeSigningGatewayDriver);
    app(GatewayRegistry::class)->register(new class extends FakeSigningGatewayDriver
    {
        public function key(): string
        {
            return 'underscore_gw';
        }

        public function settingsFields(): array
        {
            return [['key' => '_legacy', 'label' => 'Legacy', 'type' => 'string']];
        }
    });
    Gateway::query()->create(['key' => 'fake_signing', 'display_name' => 'connectIPS', 'is_active' => true, 'show_on_order_form' => true, 'config' => ['merchant_id' => 'M-1']]);
    IntegrationCredential::query()->create(['family' => 'gateway', 'owner_key' => 'fake_signing', 'setting_key' => 'signing_key', 'serial' => '1', 'not_after' => now()->addDays(5)]);
    $today = CalendarDate::parse(now()->toDateString());

    expect(app(CheckGatewayCredentials::class)->handle($today))->toBe(1)
        ->and(app(CheckGatewayCredentials::class)->health($today))->tone->toBe('warning');
});

test('the health line counts only certificate settings the driver still declares', function () {
    app(GatewayRegistry::class)->register(new FakeSigningGatewayDriver);
    Gateway::query()->create(['key' => 'fake_signing', 'display_name' => 'connectIPS', 'is_active' => true, 'show_on_order_form' => true, 'config' => []]);
    IntegrationCredential::query()->create(['family' => 'gateway', 'owner_key' => 'fake_signing', 'setting_key' => 'dropped_key', 'serial' => '1', 'not_after' => now()->subDay()]);
    $today = CalendarDate::parse(now()->toDateString());

    expect(app(CheckGatewayCredentials::class)->health($today))->toBeNull();

    IntegrationCredential::query()->create(['family' => 'gateway', 'owner_key' => 'fake_signing', 'setting_key' => 'signing_key', 'serial' => '2', 'not_after' => now()->addDays(100)]);

    expect(app(CheckGatewayCredentials::class)->health($today))->tone->toBe('success');
});

test('a step-up refusal on a platform settings section keeps no old input, as a form post, an Inertia visit or JSON', function () {
    $this->withSession(['auth.step_up_confirmed_at' => 0]);
    $secret = ['support' => ['inbound_secret' => 'inbound-secret-'.str_repeat('x', 30)]];

    $this->put(route('admin.platform.settings.update', 'support_inbound'), $secret)
        ->assertSessionHasErrors('password_confirmation')->assertSessionMissing('_old_input');
    $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml'])
        ->put(route('admin.platform.settings.update', 'support_inbound'), $secret)
        ->assertRedirect()->assertSessionHasErrors('password_confirmation')->assertSessionMissing('_old_input');
    $this->putJson(route('admin.platform.settings.update', 'support_inbound'), $secret)
        ->assertStatus(422)->assertJsonValidationErrors('password_confirmation');

    expect(json_encode(session()->all()))->not->toContain('inbound-secret-');
});

test('a confirmed platform settings save that fails validation does not flash the secret, as a form post or an Inertia visit', function () {
    $tooShort = ['support' => ['inbound_secret' => 'leak-probe-'.str_repeat('y', 10)]];

    $this->put(route('admin.platform.settings.update', 'support_inbound'), $tooShort)->assertSessionHasErrors();
    $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml'])
        ->put(route('admin.platform.settings.update', 'support_inbound'), $tooShort);

    expect(json_encode(session()->all()))->not->toContain('leak-probe-');
});

test('a driver that failed to build once is checked again by the next request or job, while one failing the family checks stays hidden for a minute', function () {
    $calls = 0;
    app(RegistrarDriverRegistry::class)->register('flaky', function () use (&$calls) {
        if (++$calls === 1) {
            throw new RuntimeException('transient');
        }

        return new FakeRegistrarDriver(new FakeRegistrar, 'flaky');
    });

    expect(app(RegistrarDriverRegistry::class)->keys())->not->toContain('flaky')
        ->and(app(RegistrarDriverRegistry::class)->keys())->not->toContain('flaky');

    app(RegistrarDriverRegistry::class)->forgetTransientFailures();

    expect(app(RegistrarDriverRegistry::class)->keys())->toContain('flaky')
        ->and(app(RegistrarDriverRegistry::class)->has('flaky'))->toBeTrue();

    $declares = true;
    app(RegistrarDriverRegistry::class)->register('cert_later', function () use (&$declares) {
        return new class(new FakeRegistrar, 'cert_later', $declares) extends FakeRegistrarDriver
        {
            public function __construct(FakeRegistrar $script, string $key, private bool $declares)
            {
                parent::__construct($script, $key);
            }

            public function settingsFields(): array
            {
                return $this->declares ? [new SettingField('client_cert', 'Client certificate', FieldType::Certificate)] : [];
            }
        };
    });

    expect(app(RegistrarDriverRegistry::class)->has('cert_later'))->toBeFalse()
        ->and(app(RegistrarDriverRegistry::class)->isRegistered('cert_later'))->toBeTrue()
        ->and(app(RegistrarDriverRegistry::class)->unavailable())->toHaveKey('cert_later');

    $declares = false;
    expect(app(RegistrarDriverRegistry::class)->has('cert_later'))->toBeFalse();

    $this->travel(61)->seconds();
    expect(app(RegistrarDriverRegistry::class)->has('cert_later'))->toBeTrue();
});

test('staff see unavailable drivers on the dashboard health card, with the reason', function () {
    app(ProvisioningDriverRegistry::class)->register('cert_panel', fn () => new class extends LogDriver
    {
        public function key(): string
        {
            return 'cert_panel';
        }

        public function metadata(): DriverMetadata
        {
            return new DriverMetadata(requiresServer: true, serverFields: [new SettingField('client_cert', 'Client certificate', FieldType::Certificate)]);
        }
    });

    $health = collect(app(Dashboard::class)->resolveHealth('admin', auth()->user()))->firstWhere('key', 'core.integration_drivers');

    expect($health)->tone->toBe('danger')->value->toBe('1 unavailable')
        ->detail->toContain('Provisioning cert_panel: The provisioning driver [cert_panel] declares the certificate setting [client_cert]');
});

test('a product bound to an unavailable driver sends none of its settings to the browser and keeps them when saved blank', function () {
    Currency::factory()->create();
    app(ProvisioningDriverRegistry::class)->register('cert_panel', fn () => new class extends LogDriver
    {
        public function key(): string
        {
            return 'cert_panel';
        }

        public function metadata(): DriverMetadata
        {
            return new DriverMetadata(requiresServer: true, productConfigFields: [
                new SettingField('access_hash', 'Access hash', FieldType::Secret),
                new SettingField('client_cert', 'Client certificate', FieldType::Certificate),
            ]);
        }
    });
    $product = Product::factory()->create(['provisioning_driver' => 'cert_panel', 'driver_config' => ['access_hash' => 'HASHVALUE-777', 'plan' => 'x']]);

    $this->get(route('admin.catalog.products.edit', $product))->assertOk()->assertDontSee('HASHVALUE-777');

    $this->put(route('admin.catalog.products.provisioning.update', $product), [
        'provisioning_driver' => 'cert_panel', 'server_group_id' => null,
        'driver_config' => [['key' => 'access_hash', 'value' => ''], ['key' => 'plan', 'value' => '']],
    ])->assertSessionHasNoErrors();

    expect($product->fresh()->driver_config)->toMatchArray(['access_hash' => 'HASHVALUE-777', 'plan' => 'x']);

    $other = Product::factory()->create();
    $this->put(route('admin.catalog.products.provisioning.update', $other), ['provisioning_driver' => 'cert_panel', 'server_group_id' => null, 'driver_config' => []])
        ->assertSessionHasErrors('provisioning_driver');
});
