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
use App\Modules\Core\Models\IntegrationCredential;
use App\Modules\Services\Drivers\Provisioning\LogDriver;
use App\Support\CalendarDate;
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

test('a platform settings section behind the step-up never puts the submitted secret into the session', function () {
    $this->withSession(['auth.password_confirmed_at' => 0]);

    $this->put(route('admin.platform.settings.update', 'support_inbound'), ['support' => ['inbound_secret' => 'inbound-secret-'.str_repeat('x', 30)]])
        ->assertSessionHasErrors('password_confirmation')->assertSessionMissing('_old_input');

    expect(json_encode(session()->all()))->not->toContain('inbound-secret-');
});
