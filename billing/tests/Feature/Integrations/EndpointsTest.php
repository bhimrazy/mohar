<?php

use App\Data\Integrations\Endpoint;
use App\Modules\Core\Install\CheckStatus;
use App\Modules\Core\Install\EnvironmentChecks;
use App\Modules\Platform\Support\PlatformDashboard;
use App\Support\Dashboard\Dashboard;
use App\Support\Integrations\Endpoints;
use App\Support\Integrations\OutboundAddressGuard;
use App\Support\Integrations\OutboundAddressRefused;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    app()->instance(OutboundAddressGuard::class, new OutboundAddressGuard(fn (string $host): array => ['sim.example.test' => ['93.184.216.34'], 'loop.example.test' => ['127.0.0.1']][$host] ?? []));
});

/**
 * A fresh Endpoints, so each test reads its own configuration and logs afresh.
 *
 * @param  array<string, mixed>|string|null  $overrides  as INTEGRATION_ENDPOINTS would hold them
 */
function endpoints(array|string|null $overrides = null, ?string $bundle = null): Endpoints
{
    config(['integrations.endpoints.overrides' => is_array($overrides) ? json_encode($overrides) : $overrides, 'integrations.endpoints.ca_bundle' => $bundle]);
    app()->forgetInstance(Endpoints::class);

    return app(Endpoints::class);
}

test('without an override a driver gets its own URL, unchanged, and no extra options', function () {
    endpoints();

    $endpoint = Endpoints::for('stripe.api', 'https://api.stripe.com');

    expect($endpoint)->toEqual(new Endpoint('https://api.stripe.com'))
        ->and(Endpoints::for('khalti.api', 'https://khalti.com/api/v2/')->baseUrl)->toBe('https://khalti.com/api/v2/');
});

test('an override points the driver at it, verified against the scenario CA and pinned by the guard, in one call', function () {
    $endpoint = endpoints(['stripe.api' => 'https://sim.example.test:8443/'], '/tmp/scenario-ca.pem')->endpoint('stripe.api', 'https://api.stripe.com');

    expect($endpoint->baseUrl)->toBe('https://sim.example.test:8443')
        ->and($endpoint->options['verify'])->toBe('/tmp/scenario-ca.pem')
        ->and($endpoint->options['curl'][CURLOPT_CONNECT_TO])->toBe(['::93.184.216.34:8443'])
        ->and($endpoint->options['proxy'])->toBe('')
        ->and($endpoint->options['allow_redirects'])->toBeFalse()
        ->and(endpoints(['stripe.api' => 'https://sim.example.test:8443'])->endpoint('khalti.api', 'https://khalti.com/api/v2/'))->toEqual(new Endpoint('https://khalti.com/api/v2/'));
});

test('the real Nepal defaults give the same path with and without an override', function (string $default, string $override, string $path) {
    $plain = endpoints()->endpoint('provider.api', $default);
    $simulated = endpoints(['provider.api' => $override])->endpoint('provider.api', $default);

    expect(parse_url($simulated->url($path), PHP_URL_PATH))->toBe(parse_url($plain->url($path), PHP_URL_PATH))
        ->and($plain->url($path))->toBe($default.$path);
})->with([
    'khalti initiate, override with slash' => ['https://khalti.com/api/v2/', 'https://sim.example.test:8443/api/v2/', 'epayment/initiate/'],
    'khalti initiate, override without slash' => ['https://khalti.com/api/v2/', 'https://sim.example.test:8443/api/v2', 'epayment/initiate/'],
    'esewa status' => ['https://esewa.com.np/api/epay/transaction/status/', 'https://sim.example.test:8443/api/epay/transaction/status', ''],
    'esewa form' => ['https://epay.esewa.com.np/api/epay/main/v2/form', 'https://sim.example.test:8443/api/epay/main/v2/form/', ''],
]);

test('an override never turns certificate verification off', function () {
    expect(endpoints(['stripe.api' => 'https://sim.example.test:8443'])->endpoint('stripe.api', 'https://api.stripe.com')->options['verify'])->toBeTrue();
});

test('an override to loopback is refused unless the install policy allows loopback', function () {
    config(['integrations.outbound.allow_loopback' => false]);
    expect(fn () => endpoints(['stripe.api' => 'https://loop.example.test:8443'])->endpoint('stripe.api', 'https://api.stripe.com'))->toThrow(OutboundAddressRefused::class, 'loopback');

    config(['integrations.outbound.allow_loopback' => true]);
    expect(endpoints(['stripe.api' => 'https://127.0.0.1:8443'])->endpoint('stripe.api', 'https://api.stripe.com')->options['curl'][CURLOPT_CONNECT_TO])->toBe(['::127.0.0.1:8443']);
});

test('an Endpoint tells a driver where and how to connect, never whether it was overridden', function () {
    $class = new ReflectionClass(Endpoint::class);

    expect(array_map(fn (ReflectionProperty $property): string => $property->getName(), $class->getProperties()))->toBe(['baseUrl', 'options'])
        ->and(array_map(fn (ReflectionMethod $method): string => $method->getName(), $class->getMethods()))->toBe(['__construct', 'url']);
});

test('only https base URLs without credentials, query or fragment are honoured; the rest are logged once', function (array|string $overrides, string $loggedKey) {
    Log::spy();
    $endpoints = endpoints($overrides);

    expect($endpoints->activeKeys())->toBe([])
        ->and($endpoints->endpoint('stripe.api', 'https://api.stripe.com'))->toEqual(new Endpoint('https://api.stripe.com'));
    $endpoints->endpoint('stripe.api', 'https://api.stripe.com');

    Log::shouldHaveReceived('warning')->once()->with(Mockery::pattern('/^Malformed integration endpoint overrides/'), ['keys' => [$loggedKey]]);
})->with([
    'plain http' => [['stripe.api' => 'http://sim.example.test:8080'], 'stripe.api'],
    'another scheme' => [['stripe.api' => 'file:///etc/passwd'], 'stripe.api'],
    'credentials' => [['stripe.api' => 'https://user:secret@sim.example.test'], 'stripe.api'],
    'query' => [['stripe.api' => 'https://sim.example.test/?x=1'], 'stripe.api'],
    'fragment' => [['stripe.api' => 'https://sim.example.test/#top'], 'stripe.api'],
    'malformed key' => [['Stripe API' => 'https://sim.example.test'], 'Stripe API'],
    'not a JSON object' => ['{not json', '(not a JSON object)'],
]);

test('overrides and loopback are honoured only in local and testing; every other environment fails closed', function (string $environment) {
    config(['integrations.outbound.allow_loopback' => true]);
    app()->detectEnvironment(fn () => $environment);
    Log::spy();
    $endpoints = endpoints(['stripe.api' => 'https://sim.example.test:8443', 'khalti.api' => 'https://sim.example.test:9443']);

    expect($endpoints->endpoint('stripe.api', 'https://api.stripe.com'))->toEqual(new Endpoint('https://api.stripe.com'))
        ->and($endpoints->activeKeys())->toBe([])
        ->and(app(OutboundAddressGuard::class)->refusal('127.0.0.1'))->toBe('loopback');
    $endpoints->report();

    Log::shouldHaveReceived('critical')->once()->with('Integration endpoint overrides are set outside a development environment and were ignored.', ['keys' => ['stripe.api', 'khalti.api']]);
})->with(['production', 'prod', 'Production', 'staging', 'live', '', 'Testing', 'demo']);

test('in local and testing active overrides are logged at boot as a warning, keys only', function (string $environment) {
    app()->detectEnvironment(fn () => $environment);
    Log::spy();

    endpoints(['stripe.api' => 'https://sim.example.test:8443'])->report();

    Log::shouldHaveReceived('warning')->once()->with('Integration endpoint overrides are active.', ['keys' => ['stripe.api']]);
})->with(['local', 'testing']);

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

test('the install checks fail on overrides set outside a development environment', function () {
    endpoints(['stripe.api' => 'https://sim.example.test:8443']);
    $named = fn () => collect(app(EnvironmentChecks::class)->run())->firstWhere('label', 'Integration endpoints');

    expect($named())->toBeNull();

    app()->detectEnvironment(fn () => 'production');
    expect($named()?->status)->toBe(CheckStatus::Failed)
        ->and($named()?->detail)->toContain('stripe.api');
});

test('the dashboard health check shows overrides only while set: amber in development, red elsewhere, keys only', function () {
    $check = function (): ?array {
        $dashboard = new Dashboard;
        app(PlatformDashboard::class)->registerIntegrationEndpoints($dashboard);

        return collect($dashboard->resolveHealth('admin', actingAsStaff(['core.settings.view'])))->firstWhere('key', 'platform.integration_endpoints');
    };

    endpoints();
    expect($check())->toBeNull();

    endpoints(['stripe.api' => 'https://sim.example.test:8443']);
    expect($check())->toMatchArray(['tone' => 'warning', 'value' => 'Overridden']);

    app()->detectEnvironment(fn () => 'production');
    expect($check())->toMatchArray(['tone' => 'danger'])
        ->and($check()['detail'])->toContain('stripe.api')->not->toContain('sim.example.test');
});
