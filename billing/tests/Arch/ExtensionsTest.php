<?php

use PHPUnit\Framework\ExpectationFailedException;

/*
 * Extension and driver rules (ADR 0009, ADR 0012). Kept apart from ArchitectureTest.php so the arch plugin's
 * parsed-source cache stays small per file (see the Arch hook in tests/Pest.php).
 */

arch('nothing uses the removed module-internal provisioning SPI (ADR 0009)')
    ->expect('App')
    ->not->toUse([
        'App\Modules\Services\Drivers\Provisioning\ProvisioningDriver',
        'App\Modules\Services\Drivers\Provisioning\ServiceContext',
        'App\Modules\Services\Drivers\Provisioning\DriverMetadata',
        'App\Modules\Services\Drivers\Provisioning\DriverResult',
        'App\Modules\Services\Drivers\Provisioning\DriverRegistry',
        'App\Modules\Services\Drivers\Provisioning\UsageRow',
        'App\Modules\Services\Exceptions\RetryableProvisioningException',
        'App\Modules\Services\Support\Redactor',
    ]);

arch('registrar contracts and DTOs use no model and no module (ADR 0009 "Verification")')
    ->expect(['App\Contracts\Domains\Registrar', 'App\Data\Domains\Registrar'])
    ->not->toUse(['Illuminate\Database\Eloquent\Model', 'App\Models', 'App\Modules']);

arch('provisioning drivers see snapshots only: no models, no database')
    ->expect(['App\Modules\Services\Drivers\Provisioning', 'Tests\Fixtures\Extensions'])
    ->not->toUse(['Illuminate\Database\Eloquent\Model', 'Illuminate\Support\Facades\DB', 'App\Modules\Services\Models', 'App\Models']);

arch('an extension package uses the kernel only, never a module')
    ->expect('Tests\Fixtures\Extensions')
    ->not->toUse('App\Modules');

arch('driver registries are kernel classes, final, and never extended by modules (ADR 0012)')
    ->expect('App\Modules')
    ->not->toExtend('App\Support\Integrations\DriverRegistry');

arch('concrete driver registries are final')
    ->expect(['App\Support\Services\ProvisioningDriverRegistry', 'App\Support\Domains\RegistrarDriverRegistry'])
    ->toBeFinal();

/** What a record importer may not use (client-import spec §9, AC 2): it writes quietly and reaches no provider. */
function importerForbidden(): array
{
    return [
        'event', 'dispatch', 'Illuminate\Contracts\Events\Dispatcher', 'Illuminate\Support\Facades\Event', 'Illuminate\Support\Facades\Bus',
        'Illuminate\Support\Facades\Mail', 'Illuminate\Support\Facades\Notification', 'App\Contracts\Notifications\SystemMailer',
        'App\Contracts\Auth\AuthMailer', 'App\Contracts\Integrations\IntegrationDriver', 'App\Contracts\Services\Provisioning',
        'App\Contracts\Billing\GatewayDriver', 'App\Contracts\Billing\HostedPaymentDriver', 'App\Support\Services\ProvisioningDriverRegistry',
        'App\Contracts\Domains\Registrar', 'App\Support\Domains\RegistrarDriverRegistry',
        ...array_map(fn (string $area): string => 'App\\Events\\'.basename($area), array_filter(glob(dirname(__DIR__, 2).'/app/Events/*', GLOB_ONLYDIR) ?: [], fn (string $area): bool => basename($area) !== 'Migration')),
    ];
}

// Only namespaces that exist: Pest passes a whole list when one of its namespaces has no classes.
$importerNamespaces = array_map(fn (string $path): string => 'App\\Modules\\'.basename(dirname($path)).'\\Import', glob(dirname(__DIR__, 2).'/app/Modules/*/Import', GLOB_ONLYDIR) ?: []);

foreach ($importerNamespaces as $namespace) {
    arch("{$namespace} writes quietly and calls no driver")->expect($namespace)->not->toUse(importerForbidden());
}

test('the record-importer rule refuses an importer that dispatches an event', function () {
    expect(fn () => expect('Tests\Fixtures\Migration\Import')->not->toUse(importerForbidden()))->toThrow(ExpectationFailedException::class);
});

afterAll(fn () => releaseArchCache());
