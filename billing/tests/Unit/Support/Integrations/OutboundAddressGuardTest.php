<?php

use App\Data\Integrations\ResolvedAddress;
use App\Support\Integrations\OutboundAddressGuard;
use App\Support\Integrations\OutboundAddressRefused;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function guardResolving(array $ips): OutboundAddressGuard
{
    return new OutboundAddressGuard(fn (string $host): array => $ips);
}

beforeEach(function () {
    config(['integrations.outbound.allow_private_addresses' => true, 'integrations.outbound.allow_loopback' => false]);
});

test('a public host resolves to the address the connection is pinned to', function () {
    $address = guardResolving(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'])->resolve('WHM.Example.com', 2087);

    expect($address->host)->toBe('whm.example.com')
        ->and($address->ip)->toBe('93.184.216.34')
        ->and($address->curlConnectTo())->toBe('::93.184.216.34:2087')
        ->and((new ResolvedAddress('h', '2001:4860::1', 443))->curlConnectTo())->toBe('::[2001:4860::1]:443');
});

test('public IPv6 and IPv6 forms of public IPv4 addresses are allowed', function (string $ip) {
    expect(guardResolving([$ip])->resolve('panel.example.com', 2087)->ip)->toBe($ip);
})->with(['2001:4860::8888', '2606:4700::1111', '::ffff:93.184.216.34', '64:ff9b::5db8:d822', '2002:5db8:d822::1']);

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
    'benchmark v4' => '198.18.0.1',
    'benchmark v6' => '2001:2::1',
    'IETF protocol assignments' => '192.0.0.8',
    'TEST-NET-1' => '192.0.2.10',
    'TEST-NET-2' => '198.51.100.1',
    'TEST-NET-3' => '203.0.113.7',
    'documentation v6' => '2001:db8::1',
    'documentation v6 (RFC 9637)' => '3fff::1',
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
    expect(fn () => guardResolving(['93.184.216.34', '127.0.0.1'])->resolve('panel.example.com', 2087))
        ->toThrow(OutboundAddressRefused::class, 'resolves to 127.0.0.1, which is not allowed (loopback address)');
});

test('IP literals skip DNS, and bad or numeric hosts, bad ports and unresolvable names are refused', function (string $host) {
    $guard = new OutboundAddressGuard(fn (string $host): array => throw new LogicException('DNS must not be queried'));

    expect(fn () => $guard->resolve($host, 443))->toThrow(OutboundAddressRefused::class, 'not a valid host');
})->with(['bad host/x', '2130706433', '134744072', '0177.0.0.1', '127.1', '0x7f000001', '0x7f.1', 'panel.example.com.']);

test('literals, ports and empty answers', function () {
    $guard = new OutboundAddressGuard(fn (string $host): array => throw new LogicException('DNS must not be queried'));

    expect($guard->resolve('93.184.216.34', 443)->ip)->toBe('93.184.216.34')
        ->and($guard->resolve('[2001:4860::1]', 443)->curlConnectTo())->toBe('::[2001:4860::1]:443')
        ->and(fn () => $guard->resolve('93.184.216.34', 70000))->toThrow(OutboundAddressRefused::class, 'not a valid port')
        ->and(fn () => guardResolving([])->resolve('nowhere.invalid', 443))->toThrow(OutboundAddressRefused::class, 'could not be resolved');
});

/**
 * Sends one pinned request at a local listener and returns what the listener received. The listener never answers,
 * so the request times out after it has been delivered; reaching the listener at all proves where curl connected.
 */
function sendPinned(string $listenIp, string $url, bool $deadProxy = false): string
{
    $server = @stream_socket_server('tcp://'.(str_contains($listenIp, ':') ? "[{$listenIp}]" : $listenIp).':0');

    if ($server === false) {
        test()->markTestSkipped("Cannot listen on {$listenIp} here.");
    }

    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
    $guard = new OutboundAddressGuard;

    if ($deadProxy) {
        putenv('http_proxy=http://127.0.0.1:1');
    }

    try {
        $guard->pin(Http::timeout(1)->connectTimeout(1), new ResolvedAddress('pinned.invalid', $listenIp, $port))->get(str_replace('{port}', (string) $port, $url));
    } catch (ConnectionException) {
        // Expected: the listener accepts the connection but never answers.
    } finally {
        putenv('http_proxy');
    }

    $client = @stream_socket_accept($server, 1);
    $received = $client === false ? '' : (string) fread($client, 4096);
    fclose($server);

    return $received;
}

test('a pinned request reaches the checked address, whatever host and port the URL names, and bypasses proxies', function (string $listenIp, string $url, bool $deadProxy) {
    $received = sendPinned($listenIp, $url, $deadProxy);

    expect($received)->toStartWith('GET /probe HTTP/1.1');
})->with([
    'IPv4, the checked host and port' => ['127.0.0.1', 'http://pinned.invalid:{port}/probe', false],
    'the URL names another port' => ['127.0.0.1', 'http://pinned.invalid:81/probe', false],
    'the URL names another host' => ['127.0.0.1', 'http://elsewhere.invalid/probe', false],
    'a dead proxy in the environment' => ['127.0.0.1', 'http://pinned.invalid:{port}/probe', true],
    'IPv6 literal address' => ['::1', 'http://pinned.invalid:{port}/probe', false],
]);

test('a pinned request follows no redirects', function () {
    $guard = guardResolving(['93.184.216.34']);

    expect($guard->pin(Http::baseUrl('https://whm.example.com:2087'), $guard->resolve('whm.example.com', 2087))->getOptions()['allow_redirects'])->toBeFalse();
});

test('production never reaches loopback, whatever the install policy says', function () {
    config(['integrations.outbound.allow_loopback' => true]);
    app()->detectEnvironment(fn () => 'production');

    expect(guardResolving(['127.0.0.1'])->refusal('127.0.0.1'))->toBe('loopback')
        ->and(guardResolving(['::1'])->refusal('::1'))->toBe('loopback')
        ->and(fn () => guardResolving(['127.0.0.1'])->resolve('panel.example.test', 2087))->toThrow(OutboundAddressRefused::class, 'loopback');

    app()->detectEnvironment(fn () => 'local');
    expect(guardResolving(['127.0.0.1'])->refusal('127.0.0.1'))->toBeNull();
});
