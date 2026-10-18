<?php

use App\Support\Integrations\OutboundAddressGuard;
use App\Support\Integrations\OutboundAddressRefused;
use Illuminate\Http\Client\Factory;

function guardResolving(array $ips): OutboundAddressGuard
{
    return new OutboundAddressGuard(fn (string $host): array => $ips);
}

test('a public host resolves to the IP the connection is pinned to', function () {
    $address = guardResolving(['203.0.113.7', '2001:4860::8888'])->resolve('WHM.Example.com', 2087);

    expect($address->host)->toBe('whm.example.com')
        ->and($address->ip)->toBe('203.0.113.7')
        ->and($address->curlResolve())->toBe('whm.example.com:2087:203.0.113.7');
});

test('loopback, unspecified, link-local, metadata, multicast and reserved addresses are always refused', function (string $ip) {
    expect(fn () => guardResolving([$ip])->resolve('panel.example.com', 2087))->toThrow(OutboundAddressRefused::class);
})->with(['127.0.0.1', '::1', '0.0.0.0', '169.254.169.254', 'fe80::1', '100.100.100.200', 'fd00:ec2::254', '224.0.0.1', 'ff02::1', '255.255.255.255', '::ffff:127.0.0.1', '64:ff9b::a9fe:a9fe']);

test('one refused address among several refuses the host, so a rebinding record cannot slip through', function () {
    expect(fn () => guardResolving(['203.0.113.7', '127.0.0.1'])->resolve('panel.example.com', 2087))
        ->toThrow(OutboundAddressRefused::class, 'resolves to 127.0.0.1, a loopback address');
});

test('private ranges follow the policy, and loopback is allowed only when asked', function () {
    $guard = guardResolving(['10.1.2.3']);

    expect($guard->resolve('panel.lan', 2087)->ip)->toBe('10.1.2.3')
        ->and(fn () => $guard->resolve('panel.lan', 2087, allowPrivate: false))->toThrow(OutboundAddressRefused::class, 'private')
        ->and(fn () => guardResolving(['fd12::1'])->resolve('panel.lan', 2087, allowPrivate: false))->toThrow(OutboundAddressRefused::class)
        ->and(guardResolving(['127.0.0.1'])->resolve('localhost', 2087, allowLoopback: true)->ip)->toBe('127.0.0.1');
});

test('IP literals skip DNS, and bad hosts, bad ports and unresolvable names are refused', function () {
    $guard = new OutboundAddressGuard(fn (string $host): array => throw new LogicException('DNS must not be queried'));

    expect($guard->resolve('203.0.113.9', 443)->ip)->toBe('203.0.113.9')
        ->and($guard->resolve('[2001:4860::1]', 443)->curlResolve())->toBe('2001:4860::1:443:[2001:4860::1]')
        ->and(fn () => $guard->resolve('bad host/x', 443))->toThrow(OutboundAddressRefused::class, 'not a valid host')
        ->and(fn () => $guard->resolve('203.0.113.9', 70000))->toThrow(OutboundAddressRefused::class, 'not a valid port')
        ->and(fn () => guardResolving([])->resolve('nowhere.invalid', 443))->toThrow(OutboundAddressRefused::class, 'could not be resolved');
});

test('a pinned request connects to the checked IP and follows no redirects', function () {
    $guard = guardResolving(['203.0.113.7']);
    $request = $guard->pin((new Factory)->baseUrl('https://whm.example.com:2087'), $guard->resolve('whm.example.com', 2087));

    expect($request->getOptions()['curl'][CURLOPT_RESOLVE])->toBe(['whm.example.com:2087:203.0.113.7'])
        ->and($request->getOptions()['allow_redirects'])->toBeFalse();
});
