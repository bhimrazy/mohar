<?php

namespace App\Support\Integrations;

use App\Data\Integrations\ResolvedAddress;
use Closure;
use Illuminate\Http\Client\PendingRequest;

/**
 * Guards outbound connections to hosts staff type in (control panel servers, registrar and gateway endpoints)
 * against server-side request forgery. resolve() looks the host up once, refuses it when any of its addresses is
 * loopback, unspecified, link-local, a cloud metadata address, multicast or reserved (private ranges are a policy
 * option), and returns the one IP to use; pin() makes an HTTP request connect to exactly that IP and follow no
 * redirects, so DNS rebinding cannot swap the target after the check.
 *
 * Callers own their policy: whether private ranges are allowed (a panel on the billing server's LAN is normal),
 * whether loopback is allowed (local development only), and which ports make sense for the protocol.
 *
 * @api
 */
final class OutboundAddressGuard
{
    /** Always refused, by reason. */
    private const REFUSED = [
        'unspecified' => ['0.0.0.0/8', '::/128'],
        'link-local' => ['169.254.0.0/16', 'fe80::/10'],
        'metadata' => ['100.100.100.200/32', 'fd00:ec2::254/128'],
        'multicast' => ['224.0.0.0/4', 'ff00::/8'],
        'reserved' => ['240.0.0.0/4', '192.0.0.0/24', '2001:db8::/32'],
    ];

    private const LOOPBACK = ['127.0.0.0/8', '::1/128'];

    private const PRIVATE = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', 'fc00::/7'];

    /**
     * @param  (Closure(string): list<string>)|null  $resolver  host => IPs; defaults to DNS A and AAAA records
     */
    public function __construct(private ?Closure $resolver = null) {}

    /**
     * @throws OutboundAddressRefused
     */
    public function resolve(string $host, int $port, bool $allowPrivate = true, bool $allowLoopback = false): ResolvedAddress
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B[]"));

        if ($port < 1 || $port > 65535) {
            throw new OutboundAddressRefused("Port {$port} is not a valid port.");
        }

        if ($host === '' || (filter_var($host, FILTER_VALIDATE_IP) === false && preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host) !== 1)) {
            throw new OutboundAddressRefused("\"{$host}\" is not a valid host name.");
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver ?? $this->lookup(...))($host);

        if ($ips === []) {
            throw new OutboundAddressRefused("{$host} could not be resolved.");
        }

        foreach ($ips as $ip) {
            $reason = $this->refusal($ip, $allowPrivate, $allowLoopback);

            if ($reason !== null) {
                throw new OutboundAddressRefused($ip === $host ? "{$host} is a {$reason} address, which is not allowed." : "{$host} resolves to {$ip}, a {$reason} address, which is not allowed.");
            }
        }

        return new ResolvedAddress($host, $ips[0], $port);
    }

    /**
     * Connect to the checked IP only, and never follow a redirect.
     */
    public function pin(PendingRequest $request, ResolvedAddress $address): PendingRequest
    {
        return $request->withoutRedirecting()->withOptions(['curl' => [CURLOPT_RESOLVE => [$address->curlResolve()]]]);
    }

    /**
     * Why an address is refused under the policy, or null when it is allowed.
     */
    public function refusal(string $ip, bool $allowPrivate = true, bool $allowLoopback = false): ?string
    {
        $binary = @inet_pton($ip);

        if ($binary === false) {
            return 'malformed';
        }

        // IPv4-mapped (::ffff:a.b.c.d) and NAT64 (64:ff9b::a.b.c.d) addresses are judged by the IPv4 address inside.
        if (strlen($binary) === 16 && (str_starts_with($binary, str_repeat("\0", 10)."\xff\xff") || str_starts_with($binary, "\x00\x64\xff\x9b".str_repeat("\0", 8)))) {
            $binary = substr($binary, 12);
        }

        foreach (self::REFUSED as $reason => $ranges) {
            if ($this->inAny($binary, $ranges)) {
                return $reason;
            }
        }

        if (! $allowLoopback && $this->inAny($binary, self::LOOPBACK)) {
            return 'loopback';
        }

        if (! $allowPrivate && $this->inAny($binary, self::PRIVATE)) {
            return 'private';
        }

        if ($binary === "\xff\xff\xff\xff") {
            return 'broadcast';
        }

        return null;
    }

    /**
     * @param  list<string>  $ranges
     */
    private function inAny(string $binary, array $ranges): bool
    {
        foreach ($ranges as $range) {
            [$network, $bits] = explode('/', $range);
            $networkBinary = (string) inet_pton($network);

            if (strlen($networkBinary) !== strlen($binary)) {
                continue;
            }

            $bytes = intdiv((int) $bits, 8);
            $remainder = (int) $bits % 8;

            if (substr($binary, 0, $bytes) !== substr($networkBinary, 0, $bytes)) {
                continue;
            }

            if ($remainder === 0) {
                return true;
            }

            $mask = (0xFF << (8 - $remainder)) & 0xFF;

            if ((ord($binary[$bytes]) & $mask) === (ord($networkBinary[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function lookup(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $ips = array_values(array_filter(array_map(fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null, $records)));

        return $ips !== [] ? $ips : (@gethostbynamel($host) ?: []);
    }
}
