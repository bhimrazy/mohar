<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/_test/client', fn (Request $request) => ['ip' => $request->ip(), 'secure' => $request->isSecure()]);
});

afterEach(function () {
    // Symfony keeps trusted proxies in a static; the middleware resets it per request, this resets it per test.
    Request::setTrustedProxies([], -1);
});

/**
 * A plain-HTTP request that arrives from $peer and claims, through forwarded headers, a client IP and HTTPS.
 */
function forwardedRequest(string $peer, string $uri = '/_test/client')
{
    return test()->withServerVariables(['REMOTE_ADDR' => $peer])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https'])
        ->get($uri);
}

test('no proxy is trusted by default, so forwarded headers are ignored', function () {
    expect(config('trustedproxy.proxies'))->toBeNull();

    forwardedRequest('172.18.0.3')->assertJson(['ip' => '172.18.0.3', 'secure' => false]);
    forwardedRequest('172.18.0.3', '/up')->assertHeaderMissing('Strict-Transport-Security');
});

test('a configured proxy range may set the client IP and scheme, and HTTPS then sends HSTS', function () {
    config(['trustedproxy.proxies' => '10.0.0.0/8, 172.18.0.0/16']);

    forwardedRequest('172.18.0.3')->assertJson(['ip' => '203.0.113.7', 'secure' => true]);
    forwardedRequest('172.18.0.3', '/up')->assertHeader('Strict-Transport-Security');
});

test('forwarded headers from an address outside the configured range are ignored', function () {
    config(['trustedproxy.proxies' => '172.18.0.0/16']);

    forwardedRequest('198.51.100.20')->assertJson(['ip' => '198.51.100.20', 'secure' => false]);
});

test('the forwarded prefix header is not trusted even from a trusted proxy', function () {
    config(['trustedproxy.proxies' => '172.18.0.0/16']);

    $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.3'])
        ->withHeaders(['X-Forwarded-Prefix' => '/evil'])
        ->get('/_test/client')
        ->assertOk();

    expect(Request::getTrustedHeaderSet() & Request::HEADER_X_FORWARDED_PREFIX)->toBe(0);
});
