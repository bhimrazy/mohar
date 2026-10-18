<?php

use App\Support\Integrations\OutboundAddressGuard;
use App\Support\Integrations\OutboundAddressRefused;
use Illuminate\Http\Client\Factory;
use Tests\TestCase;

uses(TestCase::class);

function guardResolving(array $ips): OutboundAddressGuard
{
    return new OutboundAddressGuard(fn (string $host): array => $ips);
}

beforeEach(function () {
    config(['integrations.outbound.allow_private_addresses' => true, 'integrations.outbound.allow_loopback' => false]);
});

test('a public host resolves to the IP the connection is pinned to', function () {
    $address = guardResolving(['203.0.113.7', '2001:4860::8888'])->resolve('WHM.Example.com', 2087);

    expect($address->host)->toBe('whm.example.com')
        ->and($address->ip)->toBe('203.0.113.7')
        ->and($address->curlResolve())->toBe('whm.example.com:2087:203.0.113.7');
});

test('public IPv6 and IPv6 forms of public IPv4 addresses are allowed', function (string $ip) {
    expect(guardResolving([$ip])->resolve('panel.example.com', 2087)->ip)->toBe($ip);
})->with(['2001:4860::8888', '2606:4700::1111', '::ffff:203.0.113.7', '64:ff9b::cb00:7107', '2002:cb00:7107::1']);

test('addresses that are never public are refused whatever the policy', function (string $ip) {
    config(['integrations.outbound.allow_private_addresses' => true, 'integrations.outbound.allow_loopback' => true]);

    expect(guardResolving([$ip])->refusal($ip))->not->toBeNull()
        ->and(fn () => guardResolving([$ip])->resolve('panel.example.com', 2087))->toThrow(OutboundAddressRefused::class);
})->with([
    'unspecified v4' => '0.0.0.0',
    'unspecified v6' => '::',
    'link-local v4 / metadata' => '169.254.169.254',
    'link-local v6' => 'fe80::1',
    'Alibaba metadata' => '100.100.100.200',
    'Azure wire server' => '168.63.129.16',
    'AWS IPv6 metadata' => 'fd00:ec2::254',
    'multicast v4' => '224.0.0.1',
    'multicast v6' => 'ff02::1',
    'broadcast' => '255.255.255.255',
    'reserved' => '240.0.0.1',
    'benchmark' => '198.18.0.1',
    'IETF protocol assignments' => '192.0.0.8',
    'documentation v6' => '2001:db8::1',
    'Teredo' => '2001:0:4136:e378::1',
    'local-use NAT64' => '64:ff9b:1::7f00:1',
    'site-local v6' => 'fec0::1',
    'mapped link-local' => '::ffff:169.254.169.254',
    '6to4 metadata' => '2002:a9fe:a9fe::',
]);

test('loopback is refused in every IPv4 and IPv6 spelling unless the install policy allows it', function (string $ip) {
    expect(guardResolving([$ip])->refusal($ip))->toContain('loopback');

    config(['integrations.outbound.allow_loopback' => true]);

    expect(guardResolving([$ip])->refusal($ip))->toBeNull();
})->with(['127.0.0.1', '127.1.2.3', '::1', '::ffff:127.0.0.1', '::7f00:1', '::127.0.0.1', '2002:7f00:1::', '64:ff9b::7f00:1']);

test('private ranges follow the install policy', function (string $ip) {
    expect(guardResolving([$ip])->resolve('panel.lan', 2087)->ip)->toBe($ip);

    config(['integrations.outbound.allow_private_addresses' => false]);

    expect(fn () => guardResolving([$ip])->resolve('panel.lan', 2087))->toThrow(OutboundAddressRefused::class, 'private');
})->with(['10.1.2.3', '172.16.0.9', '192.168.1.10', '100.64.0.1', 'fd12::1', '::ffff:10.1.2.3', '2002:0a01:0203::']);

test('one refused address among several refuses the host, so a rebinding record cannot slip through', function () {
    expect(fn () => guardResolving(['203.0.113.7', '127.0.0.1'])->resolve('panel.example.com', 2087))
        ->toThrow(OutboundAddressRefused::class, 'resolves to 127.0.0.1, a loopback address');
});

test('IP literals skip DNS, and bad hosts, bad ports and unresolvable names are refused', function () {
    $guard = new OutboundAddressGuard(fn (string $host): array => throw new LogicException('DNS must not be queried'));

    expect($guard->resolve('203.0.113.9', 443)->ip)->toBe('203.0.113.9')
        ->and($guard->resolve('[2001:4860::1]', 443)->curlResolve())->toBe('2001:4860::1:443:[2001:4860::1]')
        ->and(fn () => $guard->resolve('bad host/x', 443))->toThrow(OutboundAddressRefused::class, 'not a valid host')
        ->and(fn () => $guard->resolve('203.0.113.9', 70000))->toThrow(OutboundAddressRefused::class, 'not a valid port')
        ->and(fn () => guardResolving([])->resolve('nowhere.invalid', 443))->toThrow(OutboundAddressRefused::class, 'could not be resolved');
});

test('a pinned request connects to the checked IP, bypasses proxies and follows no redirects', function () {
    $guard = guardResolving(['203.0.113.7']);
    $options = $guard->pin((new Factory)->baseUrl('https://whm.example.com:2087'), $guard->resolve('whm.example.com', 2087))->getOptions();

    expect($options['curl'][CURLOPT_RESOLVE])->toBe(['whm.example.com:2087:203.0.113.7'])
        ->and($options['curl'][CURLOPT_NOPROXY])->toBe('*')
        ->and($options['proxy'])->toBe('')
        ->and($options['allow_redirects'])->toBeFalse();
});
