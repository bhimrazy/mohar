<?php

use App\Support\Integrations\Endpoints;
use App\Support\Integrations\OutboundAddressGuard;
use App\Support\Integrations\OutboundAddressRefused;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    app()->instance(OutboundAddressGuard::class, new OutboundAddressGuard(fn (string $host): array => ['sim.example.test' => ['93.184.216.34']][$host] ?? []));
});

function endpoints(): Endpoints
{
    app()->forgetInstance(Endpoints::class);

    return app(Endpoints::class);
}

test('without an override a driver gets its own URL and no extra options', function () {
    config(['integrations.endpoints.overrides' => []]);

    expect(Endpoints::url('stripe.api', 'https://api.stripe.com'))->toBe('https://api.stripe.com')
        ->and(Endpoints::options('stripe.api'))->toBe([]);
});

test('an override from the environment points the driver at it, verified against the scenario CA and pinned by the guard', function () {
    config(['integrations.endpoints.overrides' => ['stripe.api' => 'https://sim.example.test:8443/'], 'integrations.endpoints.ca_bundle' => '/tmp/scenario-ca.pem']);

    $options = endpoints()->optionsFor('stripe.api');

    expect(endpoints()->urlFor('stripe.api', 'https://api.stripe.com'))->toBe('https://sim.example.test:8443')
        ->and(endpoints()->urlFor('khalti.api', 'https://khalti.com/api/v2'))->toBe('https://khalti.com/api/v2')
        ->and($options['verify'])->toBe('/tmp/scenario-ca.pem')
        ->and($options['curl'][CURLOPT_CONNECT_TO])->toBe(['::93.184.216.34:8443'])
        ->and($options['proxy'])->toBe('')
        ->and($options['allow_redirects'])->toBeFalse();
});

test('an override never turns certificate verification off', function () {
    config(['integrations.endpoints.overrides' => ['stripe.api' => 'https://sim.example.test:8443'], 'integrations.endpoints.ca_bundle' => null]);

    expect(endpoints()->optionsFor('stripe.api')['verify'])->toBeTrue();
});

test('an override to loopback is refused unless the install policy allows loopback', function () {
    config(['integrations.endpoints.overrides' => ['stripe.api' => 'https://127.0.0.1:8443'], 'integrations.outbound.allow_loopback' => false]);
    expect(fn () => endpoints()->optionsFor('stripe.api'))->toThrow(OutboundAddressRefused::class, 'loopback');

    config(['integrations.outbound.allow_loopback' => true]);
    expect(endpoints()->optionsFor('stripe.api')['curl'][CURLOPT_CONNECT_TO])->toBe(['::127.0.0.1:8443']);
});

test('malformed overrides are ignored', function () {
    config(['integrations.endpoints.overrides' => ['stripe.api' => 'file:///etc/passwd', 'khalti.api' => 'not a url', 7 => 'https://x.test']]);

    expect(endpoints()->activeKeys())->toBe([])
        ->and(endpoints()->urlFor('stripe.api', 'https://api.stripe.com'))->toBe('https://api.stripe.com');
});

test('in production every override is ignored and reported once as critical, keys only', function () {
    config(['integrations.endpoints.overrides' => ['stripe.api' => 'https://sim.example.test:8443', 'khalti.api' => 'https://sim.example.test:9443']]);
    app()->detectEnvironment(fn () => 'production');
    Log::spy();
    $endpoints = endpoints();

    expect($endpoints->urlFor('stripe.api', 'https://api.stripe.com'))->toBe('https://api.stripe.com')
        ->and($endpoints->optionsFor('stripe.api'))->toBe([])
        ->and($endpoints->activeKeys())->toBe([]);
    $endpoints->report();

    Log::shouldHaveReceived('critical')->once()->with('Integration endpoint overrides are set in production and were ignored.', ['keys' => ['stripe.api', 'khalti.api']]);
});

test('outside production active overrides are logged at boot as a warning, keys only', function () {
    config(['integrations.endpoints.overrides' => ['stripe.api' => 'https://sim.example.test:8443']]);
    Log::spy();

    endpoints()->report();

    Log::shouldHaveReceived('warning')->once()->with('Integration endpoint overrides are active.', ['keys' => ['stripe.api']]);
});

test('overrides have no settings key, so staff cannot set one', function () {
    $keys = [];

    foreach (app(SettingsRegistry::class)->sections() as $section) {
        foreach ($section['settings'] as $definition) {
            $keys[] = $definition->key;
        }
    }

    expect($keys)->not->toBeEmpty()
        ->and(array_values(array_filter($keys, fn (string $key) => str_starts_with($key, 'integrations.'))))->toBe([]);
});
