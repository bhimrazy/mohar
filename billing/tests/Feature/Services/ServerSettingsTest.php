<?php

use App\Contracts\Services\Provisioning\ProvisioningDriver;
use App\Data\Integrations\SettingField;
use App\Data\Services\Provisioning\DriverMetadata;
use App\Data\Services\Provisioning\DriverResult;
use App\Data\Services\Provisioning\ProvisioningContext;
use App\Enums\FieldType;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Services\ModuleCommandDispatcher;
use App\Modules\Services\Services\ProvisioningSnapshots;
use App\Support\Services\ProvisioningDriverRegistry;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Services\ServicesTestKit;

beforeEach(function () {
    Currency::factory()->create(['code' => 'USD']);
    $this->kit = ServicesTestKit::bind();
    app(ProvisioningDriverRegistry::class)->register('settings_panel', fn () => new class implements ProvisioningDriver
    {
        public function key(): string
        {
            return 'settings_panel';
        }

        public function name(): string
        {
            return 'Settings panel';
        }

        public function metadata(): DriverMetadata
        {
            return new DriverMetadata(requiresServer: true, serverFields: [
                new SettingField('region', 'Region', required: true),
                new SettingField('reseller_secret', 'Reseller secret', FieldType::Secret, required: true),
                new SettingField('verify_pin', 'Pin the certificate', FieldType::Boolean),
            ]);
        }

        public function create(ProvisioningContext $context): DriverResult
        {
            return DriverResult::ok(request: ['region' => $context->server?->settings['region'] ?? null, 'auth_blob' => 'x', 'note' => 'sent '.($context->server?->settings['reseller_secret'] ?? '')]);
        }

        public function suspend(ProvisioningContext $context, string $reason): DriverResult
        {
            return DriverResult::ok();
        }

        public function unsuspend(ProvisioningContext $context): DriverResult
        {
            return DriverResult::ok();
        }

        public function terminate(ProvisioningContext $context): DriverResult
        {
            return DriverResult::ok();
        }
    });
    actingAsStaff(['services.servers.view', 'services.servers.manage']);
    $this->withSession(['auth.password_confirmed_at' => time()]);
});

function settingsServerPayload(array $settings): array
{
    return [
        'name' => 'Panel 1', 'hostname' => 'panel1.example.net', 'driver' => 'settings_panel', 'username' => 'root',
        'secure' => true, 'max_accounts' => 0, 'is_active' => true, 'is_default' => false, 'nameservers' => [],
        'monthly_cost' => 0, 'currency' => 'USD', 'settings' => $settings,
    ];
}

test('driver server settings are validated, stored encrypted and never sent back', function () {
    $this->post(route('admin.services.servers.store'), settingsServerPayload(['region' => '', 'reseller_secret' => '']))
        ->assertSessionHasErrors(['settings.region', 'settings.reseller_secret']);

    $this->post(route('admin.services.servers.store'), settingsServerPayload(['region' => 'eu-1', 'reseller_secret' => 'Reseller-S3cret', 'verify_pin' => '1']))->assertRedirect();

    $server = Server::query()->sole();
    expect($server->settings)->toBe(['region' => 'eu-1', 'reseller_secret' => 'Reseller-S3cret', 'verify_pin' => true])
        ->and((string) $server->getRawOriginal('settings'))->not->toContain('Reseller-S3cret')->not->toContain('eu-1');

    $this->get(route('admin.services.servers.edit', $server))->assertInertia(fn (Assert $page) => $page
        ->where('server.settings', ['values' => ['region' => 'eu-1', 'verify_pin' => '1'], 'secrets_set' => ['reseller_secret' => true]])
        ->where('driverFields.settings_panel.1', fn ($field) => $field['type'] === 'secret' && $field['secret'] === true)
        ->where('driverFields.log.0.key', 'region'));
    $this->get(route('admin.services.servers.edit', $server))->assertDontSee('Reseller-S3cret');
    $this->get(route('admin.services.servers.index'))->assertDontSee('Reseller-S3cret');
});

test('a blank secret keeps the stored value, and changes are audited with secrets as rotated', function () {
    $this->post(route('admin.services.servers.store'), settingsServerPayload(['region' => 'eu-1', 'reseller_secret' => 'First-S3cret']));
    $server = Server::query()->sole();

    $this->put(route('admin.services.servers.update', $server), settingsServerPayload(['region' => 'us-2', 'reseller_secret' => '']))->assertSessionHasNoErrors();
    expect($server->refresh()->settings)->toMatchArray(['region' => 'us-2', 'reseller_secret' => 'First-S3cret']);

    $this->put(route('admin.services.servers.update', $server), settingsServerPayload(['region' => 'us-2', 'reseller_secret' => 'Second-S3cret']));

    $changes = ActivityLog::query()->where('description', 'Server Panel 1 settings changed')->orderBy('id')->pluck('properties')->map(fn ($properties) => $properties['changes'])->all();
    expect($changes)->toBe([
        ['settings.region' => ['from' => 'eu-1', 'to' => 'us-2']],
        ['settings.reseller_secret' => 'rotated'],
    ])->and(json_encode($changes))->not->toContain('S3cret');
});

test('drivers receive the settings, and an echoed secret setting is redacted from the module log', function () {
    $server = Server::factory()->create(['driver' => 'settings_panel', 'settings' => ['region' => 'eu-1', 'reseller_secret' => 'Echoed-S3cret-77']]);
    $connection = app(ProvisioningSnapshots::class)->connection($server);
    $this->kit->priceBook->addProduct(41, 'Panel', driver: 'settings_panel');
    $service = Service::factory()->pending()->create(['product_id' => 41, 'server_id' => $server->id]);

    app(ModuleCommandDispatcher::class)->runNow($service, ModuleCommandName::Create);

    expect($connection->settings)->toBe(['region' => 'eu-1', 'reseller_secret' => 'Echoed-S3cret-77'])
        ->and($connection->secretSettingKeys)->toBe(['reseller_secret'])
        ->and($connection->secrets())->toContain('Echoed-S3cret-77')
        ->and(json_encode(ModuleCommand::query()->where('service_id', $service->id)->sole()->request))->toContain('eu-1')->not->toContain('Echoed-S3cret-77');
});
