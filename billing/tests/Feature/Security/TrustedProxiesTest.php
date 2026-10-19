<?php

use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Request::setTrustedHosts([]);
    Route::get('/_test/client', fn (Request $request) => [
        'ip' => $request->ip(),
        'secure' => $request->isSecure(),
        'host' => $request->getHost(),
        'base' => $request->getBaseUrl(),
        'link' => url('/somewhere'),
    ]);
});

afterEach(function () {
    // Symfony keeps trusted proxies and hosts in statics; the middleware resets them per request, this per test.
    Request::setTrustedProxies([], -1);
    Request::setTrustedHosts([]);
});

function appHost(): string
{
    return (string) parse_url((string) config('app.url'), PHP_URL_HOST);
}

/**
 * Production behaviour: tests (like local) skip TrustHosts, which is what rejects foreign hosts outside local.
 */
function enforceTrustedHosts(): void
{
    app()->bind(TrustHosts::class, fn ($app) => new class($app) extends TrustHosts
    {
        protected function shouldSpecifyTrustedHosts(): bool
        {
            return true;
        }
    });
}

/**
 * A plain-HTTP request for $host from $peer that claims, through forwarded headers, another client, HTTPS and a
 * foreign host and port.
 *
 * @param  array<string, string>  $headers
 */
function forwardedRequest(string $peer, ?string $host = null, array $headers = [], string $path = '/_test/client')
{
    return test()->withServerVariables(['REMOTE_ADDR' => $peer])
        ->withHeaders($headers + [
            'X-Forwarded-For' => '203.0.113.7',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'evil.test',
            'X-Forwarded-Port' => '8443',
        ])
        ->get('http://'.($host ?? appHost()).$path);
}

test('no proxy is trusted by default: forged client, scheme, host and port are ignored', function () {
    expect(config('trustedproxy.proxies'))->toBe([]);

    $response = forwardedRequest('172.18.0.3')->assertOk();

    expect($response->json())->toMatchArray(['ip' => '172.18.0.3', 'secure' => false, 'host' => appHost()])
        ->and(parse_url($response->json('link'), PHP_URL_HOST))->toBe(appHost())
        ->and($response->json('link'))->not->toContain('evil.test')->not->toContain('8443');
    forwardedRequest('172.18.0.3', path: '/up')->assertHeaderMissing('Strict-Transport-Security');
});

test('an on-forge.com host does not make every proxy trusted when none is configured', function () {
    forwardedRequest('172.18.0.3', 'tenant.on-forge.com')
        ->assertOk()
        ->assertJson(['ip' => '172.18.0.3', 'secure' => false]);

    enforceTrustedHosts();
    forwardedRequest('172.18.0.3', 'tenant.on-forge.com')->assertBadRequest();
});

test('a configured proxy range may set the client IP and scheme, and HTTPS then sends HSTS', function () {
    config(['trustedproxy.proxies' => '10.0.0.0/8, 172.18.0.0/16']);

    $response = forwardedRequest('172.18.0.3', headers: ['X-Forwarded-Host' => appHost(), 'X-Forwarded-Port' => '443']);

    expect($response->json())->toMatchArray(['ip' => '203.0.113.7', 'secure' => true, 'host' => appHost()])
        ->and(parse_url($response->json('link'), PHP_URL_HOST))->toBe(appHost());
    forwardedRequest('172.18.0.3', headers: ['X-Forwarded-Host' => appHost()], path: '/up')
        ->assertHeader('Strict-Transport-Security');
});

test('a trusted proxy cannot move a request to a foreign host', function () {
    config(['trustedproxy.proxies' => '172.18.0.0/16']);
    enforceTrustedHosts();

    forwardedRequest('172.18.0.3')->assertBadRequest();
    forwardedRequest('172.18.0.3', headers: ['X-Forwarded-Host' => appHost()])->assertOk();
});

test('forwarded headers from an address outside the configured range are ignored', function () {
    config(['trustedproxy.proxies' => '172.18.0.0/16']);

    forwardedRequest('198.51.100.20')->assertJson(['ip' => '198.51.100.20', 'secure' => false, 'host' => appHost()]);
});

test('in a forwarded chain the client is the rightmost address the proxy did not add', function () {
    config(['trustedproxy.proxies' => '172.18.0.0/16']);

    forwardedRequest('172.18.0.3', headers: ['X-Forwarded-For' => '6.6.6.6, 203.0.113.7', 'X-Forwarded-Host' => appHost()])
        ->assertJson(['ip' => '203.0.113.7']);
});

test('the Forwarded and X-Forwarded-Prefix headers are never trusted, even from a trusted proxy', function () {
    config(['trustedproxy.proxies' => '172.18.0.0/16']);

    $response = test()->withServerVariables(['REMOTE_ADDR' => '172.18.0.3'])
        ->withHeaders(['Forwarded' => 'for=6.6.6.6;proto=https;host=evil.test', 'X-Forwarded-Prefix' => '/evil'])
        ->get('http://'.appHost().'/_test/client');

    expect($response->json())->toMatchArray(['ip' => '172.18.0.3', 'secure' => false, 'base' => ''])
        ->and($response->json('link'))->not->toContain('/evil')->not->toContain('evil.test');
});
