<?php

/*
 * Security review of PR #80 (production compose stack). Fails until the app stops trusting X-Forwarded-Port.
 * The app runs behind deploy/caddy/Caddyfile with TRUSTED_PROXIES = the edge network's range. Caddy sets or
 * strips X-Forwarded-For, -Proto and -Host only (caddyhttp/reverseproxy addForwardedHeaders, v2.11.7); any other
 * forwarded header a client sends reaches the app unchanged. These tests send what Caddy would forward for a
 * client that adds its own X-Forwarded-Port.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Request::setTrustedHosts([]);
    // The compose default: TRUSTED_PROXIES = ${EDGE_SUBNET:-172.30.10.0/24}.
    config(['trustedproxy.proxies' => '172.30.10.0/24']);
    // As in production: APP_URL is https:// + APP_DOMAIN, and every generated URL uses it.
    config(['app.url' => 'https://billing.example.test']);
    URL::forceRootUrl('https://billing.example.test');
    URL::forceScheme('https');

    Route::get('/_probe/edge', fn (Request $request) => [
        'port' => $request->getPort(),
        'url' => $request->url(),
        'full' => $request->fullUrl(),
    ]);
    Route::get('/_probe/signed', fn () => 'ok')->name('probe.signed')->middleware('signed');
    app('router')->getRoutes()->refreshNameLookups();
});

afterEach(function () {
    Request::setTrustedProxies([], -1);
    Request::setTrustedHosts([]);
});

/**
 * A request as Caddy forwards it on the edge network: its own For/Proto/Host, plus whatever else the client sent.
 *
 * @param  array<string, string>  $clientHeaders
 */
function throughCaddy(string $path, array $clientHeaders = [])
{
    $host = 'billing.example.test';

    return test()->withServerVariables(['REMOTE_ADDR' => '172.30.10.2'])
        ->withHeaders([
            'X-Forwarded-For' => '203.0.113.7',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => $host,
        ] + $clientHeaders)
        ->get('http://'.$host.$path);
}

test('a client-sent X-Forwarded-Port passed through Caddy does not change the request port or URL', function () {
    $response = throughCaddy('/_probe/edge', ['X-Forwarded-Port' => '1337'])->assertOk();

    expect($response->json('port'))->toBe(443)
        ->and($response->json('url'))->not->toContain(':1337')
        ->and($response->json('full'))->not->toContain(':1337');
});

test('a client-sent X-Forwarded-Port does not break signed links behind Caddy', function () {
    $link = URL::temporarySignedRoute('probe.signed', now()->addMinutes(5));
    $path = parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY);

    throughCaddy($path)->assertOk();
    throughCaddy($path, ['X-Forwarded-Port' => '1337'])->assertOk();
});
