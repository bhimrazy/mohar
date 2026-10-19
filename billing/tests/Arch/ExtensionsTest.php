<?php

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
    ->expect('App\Support\Services\ProvisioningDriverRegistry')
    ->toBeFinal();

afterAll(fn () => releaseArchCache());
