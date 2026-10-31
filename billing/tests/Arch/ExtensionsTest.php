<?php

use PHPUnit\Framework\ExpectationFailedException;

/*
 * Extension and driver rules (ADR 0009, ADR 0012). Kept apart from ArchitectureTest.php so the arch plugin's
 * parsed-source cache stays small per file (see the Arch hook in tests/Pest.php). The whole-app rules (removed SPIs,
 * registry extension) are text scans in RemovedSpiScanTest.php: as arch rules they parsed all of app/.
 */

arch('App\Modules\Domains\Drivers\Registrars sees snapshots only: no models, no database')
    ->expect('App\Modules\Domains\Drivers\Registrars')
    ->not->toUse(['Illuminate\Database\Eloquent\Model', 'Illuminate\Support\Facades\DB', 'App\Modules\Domains\Models', 'App\Models']);

// One target per rule: a not->toUse() over several targets passes when any one of them is clean (issue #275).
foreach (['App\Contracts\Domains\Registrar', 'App\Data\Domains\Registrar'] as $registrarKernel) {
    arch("{$registrarKernel} uses no model and no module (ADR 0009 \"Verification\")")
        ->expect($registrarKernel)
        ->not->toUse(['Illuminate\Database\Eloquent\Model', 'App\Models', 'App\Modules']);
}

foreach (['App\Modules\Services\Drivers\Provisioning', 'Tests\Fixtures\Extensions'] as $driverCode) {
    arch("{$driverCode} sees snapshots only: no models, no database")
        ->expect($driverCode)
        ->not->toUse(['Illuminate\Database\Eloquent\Model', 'Illuminate\Support\Facades\DB', 'App\Modules\Services\Models', 'App\Models']);
}

arch('an extension package uses the kernel only, never a module')
    ->expect('Tests\Fixtures\Extensions')
    ->not->toUse('App\Modules');

arch('concrete driver registries are final')
    ->expect(['App\Support\Services\ProvisioningDriverRegistry', 'App\Support\Domains\RegistrarDriverRegistry', 'App\Support\Billing\GatewayDriverRegistry'])
    ->toBeFinal();

test('production code does not call a conformance kit', function () {
    // ADR 0009 Amendment (#515): kits (App\Support\<Area>\*Conformance) are for tests; ReportsTest guards ReportConformance too.
    $callers = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php' || preg_match('/Conformance(Failure)?\.php$/', $file->getFilename()) === 1) {
            continue;
        }

        if (preg_match('/\b\w+Conformance::|^use\s+App\\\\Support\\\\[\w\\\\]+Conformance;/m', (string) file_get_contents($file->getPathname())) === 1) {
            $callers[] = $file->getPathname();
        }
    }

    expect($callers)->toBe([]);
});

/**
 * What a record importer may not use (client-import spec §9, AC 2; invoice-domain-import spec AC 2): it writes
 * quietly and reaches no provider, neither through a kernel contract nor through its module's drivers, jobs or mail.
 */
function importerForbidden(): array
{
    $app = dirname(__DIR__, 2).'/app';
    $namespace = fn (string $path): string => 'App\\'.str_replace('/', '\\', substr($path, strlen($app) + 1));

    return [
        'event', 'dispatch', 'dispatch_sync', 'Illuminate\Contracts\Events\Dispatcher', 'Illuminate\Contracts\Bus', 'Illuminate\Contracts\Queue',
        'Illuminate\Contracts\Mail', 'Illuminate\Contracts\Notifications', 'Illuminate\Http\Client', 'Illuminate\Support\Facades\Http',
        'Illuminate\Support\Facades\Event', 'Illuminate\Support\Facades\Bus', 'Illuminate\Support\Facades\Queue', 'Illuminate\Support\Facades\Mail', 'Illuminate\Support\Facades\Notification', 'App\Contracts\Notifications\SystemMailer',
        'App\Contracts\Auth\AuthMailer', 'App\Contracts\Integrations\IntegrationDriver', 'App\Contracts\Services\Provisioning',
        'App\Contracts\Billing\GatewayDriver', 'App\Contracts\Billing\HostedPaymentDriver', 'App\Contracts\Billing\GatewayRegistry', 'App\Support\Billing\GatewayDriverRegistry', 'App\Support\Billing\GatewayHttp', 'App\Support\Services\ProvisioningDriverRegistry',
        'App\Contracts\Domains\Registrar', 'App\Support\Domains\RegistrarDriverRegistry', 'App\Support\Integrations\DriverRegistry',
        ...array_map(fn (string $area): string => 'App\\Events\\'.basename($area), array_filter(glob($app.'/Events/*', GLOB_ONLYDIR) ?: [], fn (string $area): bool => basename($area) !== 'Migration')),
        // No GLOB_BRACE: musl builds do not define it.
        ...array_map($namespace, array_merge(...array_map(fn (string $kind): array => glob("{$app}/Modules/*/{$kind}", GLOB_ONLYDIR) ?: [], ['Drivers', 'Jobs', 'Mail', 'Notifications']))),
    ];
}

// Only namespaces that exist: Pest passes a whole list when one of its namespaces has no classes.
$importerNamespaces = array_map(fn (string $path): string => 'App\\Modules\\'.basename(dirname($path)).'\\Import', glob(dirname(__DIR__, 2).'/app/Modules/*/Import', GLOB_ONLYDIR) ?: []);

foreach ($importerNamespaces as $namespace) {
    arch("{$namespace} writes quietly and calls no driver")->expect($namespace)->not->toUse(importerForbidden());
}

// One probe per route to a provider or a job: each must be refused on its own.
foreach (['EventfulImporter' => 'dispatches an event', 'RenewingImporter' => "queues its module's job", 'NoticeImporter' => "uses its module's notification",
    'HttpImporter' => 'calls the Http facade', 'GatewayHttpImporter' => 'calls a provider through GatewayHttp', 'BusImporter' => 'queues through the bus contract', 'SyncImporter' => 'calls dispatch_sync()'] as $probe => $route) {
    test("the record-importer rule refuses an importer that {$route}", function () use ($probe) {
        expect(fn () => expect("Tests\\Fixtures\\Migration\\Import\\{$probe}")->not->toUse(importerForbidden()))->toThrow(ExpectationFailedException::class);
    });
}

test('every arch target in this file matches at least one class', function () use ($importerNamespaces) {
    expect($importerNamespaces)->not->toBe([])
        ->and(archTargetsWithoutClasses([
            'App\Contracts\Domains\Registrar', 'App\Data\Domains\Registrar', 'App\Modules\Domains\Drivers\Registrars', 'App\Modules\Services\Drivers\Provisioning', 'Tests\Fixtures\Extensions', ...$importerNamespaces,
            // Single-class targets: a rename would otherwise leave the rule checking nothing.
            'App\Support\Services\ProvisioningDriverRegistry', 'App\Support\Domains\RegistrarDriverRegistry', 'App\Support\Integrations\DriverRegistry',
            'App\Support\Billing\GatewayDriverRegistry', 'App\Support\Billing\GatewayHttp',
        ]))->toBe([]);
});

afterAll(fn () => releaseArchCache());
