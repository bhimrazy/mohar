<?php

/*
 * Follow-ups to #490's re-review (R4, R9): a driver that cannot be built is built and reported once per request or
 * job, and a registered but unavailable provisioning driver fails closed for server secrets and shows as unavailable.
 */

use App\Data\Integrations\SettingField;
use App\Data\Services\Provisioning\DriverMetadata;
use App\Enums\FieldType;
use App\Modules\Catalog\Models\Product;
use App\Modules\Services\Drivers\Provisioning\LogDriver;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Services\ServiceDrivers;
use App\Support\Domains\RegistrarDriverRegistry;
use App\Support\Services\ProvisioningDriverRegistry;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;

function registerUnavailablePanel(): void
{
    app(ProvisioningDriverRegistry::class)->register('cert_panel', fn () => new class extends LogDriver
    {
        public function key(): string
        {
            return 'cert_panel';
        }

        public function metadata(): DriverMetadata
        {
            return new DriverMetadata(requiresServer: true, serverFields: [
                new SettingField('panel_key', 'Panel key', FieldType::Secret),
                new SettingField('client_cert', 'Client certificate', FieldType::Certificate),
            ]);
        }
    });
}

test('a driver that cannot be built is built once per request or job, and tried again by the next job or request', function () {
    $calls = 0;
    app(RegistrarDriverRegistry::class)->register('broken', function () use (&$calls) {
        $calls++;

        throw new RuntimeException('always failing, not a LogicException');
    });
    $registry = app(RegistrarDriverRegistry::class);

    $registry->keys();
    $registry->keys();
    $registry->has('broken');
    $registry->unavailable();
    $registry->all();

    expect($calls)->toBe(1)
        ->and($registry->unavailable())->toHaveKey('broken');

    event(new JobProcessing('sync', Mockery::mock(Job::class)->shouldIgnoreMissing()));
    $registry->keys();
    $registry->keys();

    expect($calls)->toBe(2);

    // The end of a request (or console command) forgets it too.
    app()->terminate();
    $registry->keys();

    expect($calls)->toBe(3);
});

test('a server on an unavailable driver loses all its driver settings when its address changes outside the form', function () {
    registerUnavailablePanel();
    $server = Server::withoutEvents(fn () => Server::factory()->create(['driver' => 'cert_panel', 'settings' => ['panel_key' => 'PANELSECRET-1', 'region' => 'x']]));

    $server->update(['hostname' => 'other.example.test']);

    expect($server->fresh()->settings)->toBeNull();
});

test('service screens name an unavailable driver instead of showing manual setup', function () {
    registerUnavailablePanel();
    $product = Product::factory()->create(['provisioning_driver' => 'cert_panel']);
    $server = Server::withoutEvents(fn () => Server::factory()->create(['driver' => 'cert_panel']));
    $service = Service::factory()->onServer($server)->create(['product_id' => $product->id]);

    expect(app(ServiceDrivers::class)->present($service))
        ->toBe(['key' => 'cert_panel', 'name' => 'cert_panel (unavailable)', 'requires_server' => true, 'capabilities' => []]);
});
