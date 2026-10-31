<?php

use App\Data\Integrations\SettingField;
use App\Support\Billing\GatewayDriverRegistry;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Registries\RegistrySnapshot;

/*
| The bundled gateways' registrations (ADR 0030 M1): driver keys in registration order (gateways.sort_order defaults
| to that position), each driver's name, capabilities, currencies and settings, their artisan commands, and what they
| put into the other kernel registries. Recorded before the move to app/Extensions, so the move proves it changed
| none of it. Class names are recorded without their namespace, which the move changes.
*/

test('the bundled gateways register the same drivers, settings, commands and registry entries as recorded', function () {
    $drivers = app(GatewayDriverRegistry::class);
    $gateways = [];

    foreach ($drivers->keys() as $position => $key) {
        $driver = $drivers->get($key);
        $gateways[$key] = [
            'position' => $position,
            'class' => class_basename($driver),
            'name' => $driver->name(),
            'capabilities' => $driver->capabilities(),
            'interfaces' => $drivers->capabilitiesOf($key),
            'currencies' => $driver->currencies(),
            'fields' => array_map(fn (SettingField $field): array => (array) $field, GatewayDriverRegistry::fieldsOf($driver)),
        ];
    }

    $commands = array_values(array_filter(
        array_keys(Artisan::all()),
        fn (string $name): bool => str_starts_with($name, 'payments:'),
    ));
    sort($commands);

    // Listeners and middleware are picked by namespace; the payment modules registered none (their providers only
    // register drivers), so the before-move recording has none to miss.
    $registries = RegistrySnapshot::capture('/stripe|razorpay|cybersource|khalti|esewa|connectips|payments_/i', 'App\\Extensions\\Gateways');

    $current = ['gateways' => $gateways, 'commands' => $commands, 'registries' => $registries];
    $path = dirname(__DIR__, 2).'/Fixtures/Registries/gateways.json';

    if (getenv('UPDATE_REGISTRY_SNAPSHOT') === '1') {
        file_put_contents($path, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }

    expect(json_decode((string) json_encode($current), true))->toBe(json_decode((string) file_get_contents($path), true));
});
