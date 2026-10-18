<?php

namespace App\Support\Integrations;

use App\Data\Integrations\ResolvedAddress;
use Closure;
use Illuminate\Http\Client\PendingRequest;

/**
 * Guards outbound connections to hosts that staff or clients type in (control panel servers, registrar and gateway
 * endpoints, webhooks) against server-side request forgery (ADR 0015).
 *
 * resolve() looks the host up once and refuses it when any of its addresses is not a public unicast address under
 * the install-wide policy (config `integrations.outbound`): loopback, unspecified, link-local, cloud metadata and
 * host endpoints, multicast, broadcast, reserved and benchmark ranges are always refused; private ranges and
 * loopback only when the policy says so. IPv6 is allow-listed (global unicast 2000::/3, plus ULA or ::1 when the
 * policy allows them), and every IPv6 form that carries an IPv4 address (mapped, compatible, 6to4, NAT64) is judged
 * by that IPv4 address. Teredo and local-use NAT64 are refused outright.
 *
 * pin() makes an HTTP request connect to exactly the checked IP, bypass any proxy configured for the process (a proxy
 * would resolve the name again and defeat the pin), and follow no redirects.
 *
 * @api
 */
final class OutboundAddressGuard
{
    /** Always refused, by reason. */
    private const REFUSED = [
        'unspecified' => ['0.0.0.0/8', '::/128'],
        'link-local' => ['169.254.0.0/16', 'fe80::/10'],
        'cloud metadata' => ['100.100.100.200/32', '168.63.129.16/32', 'fd00:ec2::254/128'],
        'multicast' => ['224.0.0.0/4', 'ff00::/8'],
        'reserved' => ['240.0.0.0/4', '192.0.0.0/24', '198.18.0.0/15', '2001:db8::/32'],
        'tunnelled' => ['2001::/32', '64:ff9b:1::/48'],
    ];

    private const LOOPBACK = ['127.0.0.0/8', '::1/128'];

    private const PRIVATE = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', 'fc00::/7'];

    private const GLOBAL_UNICAST_V6 = ['2000::/3'];

    /**
     * @param  (Closure(string): list<string>)|null  $resolver  host => IPs; defaults to DNS A and AAAA records
     */
    public function __construct(private ?Closure $resolver = null) {}

    /**
     * @throws OutboundAddressRefused
     */
    public function resolve(string $host, int $port): ResolvedAddress
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B[]"));

        if ($port < 1 || $port > 65535) {
            throw new OutboundAddressRefused("Port {$port} is not a valid port.");
        }

        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;

        if ($host === '' || (! $literal && preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host) !== 1)) {
            throw new OutboundAddressRefused("\"{$host}\" is not a valid host name.");
        }

        $ips = $literal ? [$host] : ($this->resolver ?? $this->lookup(...))($host);

        if ($ips === []) {
            throw new OutboundAddressRefused("{$host} could not be resolved.");
        }

        foreach ($ips as $ip) {
            $reason = $this->refusal($ip);

            if ($reason !== null) {
                throw new OutboundAddressRefused($literal ? "{$host} is a {$reason} address, which is not allowed." : "{$host} resolves to {$ip}, a {$reason} address, which is not allowed.");
            }
        }

        return new ResolvedAddress($host, $ips[0], $port);
    }

    /**
     * Connect to the checked IP only, never through a proxy, and never follow a redirect.
     */
    public function pin(PendingRequest $request, ResolvedAddress $address): PendingRequest
    {
        return $request->withoutRedirecting()->withOptions([
            'proxy' => '',
            'curl' => [CURLOPT_RESOLVE => [$address->curlResolve()], CURLOPT_NOPROXY => '*'],
        ]);
    }

    /**
     * Why an address is refused under the install policy, or null when it is allowed.
     */
    public function refusal(string $ip): ?string
    {
        $binary = @inet_pton($ip);

        if ($binary === false) {
            return 'malformed';
        }

        $embedded = $this->embeddedIpv4($binary);

        if ($embedded !== null) {
            $reason = $this->refusalOf($embedded);

            return $reason === null ? null : "{$reason} (inside an IPv6 address)";
        }

        return $this->refusalOf($binary);
    }

    private function refusalOf(string $binary): ?string
    {
        foreach (self::REFUSED as $reason => $ranges) {
            if ($this->inAny($binary, $ranges)) {
                return $reason;
            }
        }

        if ($this->inAny($binary, self::LOOPBACK)) {
            return config('integrations.outbound.allow_loopback', false) ? null : 'loopback';
        }

        if ($this->inAny($binary, self::PRIVATE)) {
            return config('integrations.outbound.allow_private_addresses', true) ? null : 'private';
        }

        if (strlen($binary) === 16 && ! $this->inAny($binary, self::GLOBAL_UNICAST_V6)) {
            return 'non-global';
        }

        return null;
    }

    /**
     * The IPv4 address an IPv6 address carries: IPv4-mapped (::ffff:a.b.c.d), IPv4-compatible (::a.b.c.d), NAT64
     * (64:ff9b::a.b.c.d) and 6to4 (2002:aabb:ccdd::).
     */
    private function embeddedIpv4(string $binary): ?string
    {
        if (strlen($binary) !== 16) {
            return null;
        }

        $zeros = str_repeat("\0", 10);

        return match (true) {
            str_starts_with($binary, $zeros."\xff\xff") => substr($binary, 12),
            str_starts_with($binary, $zeros."\0\0") && ! in_array(substr($binary, 12), ["\0\0\0\0", "\0\0\0\1"], true) => substr($binary, 12),
            str_starts_with($binary, "\x00\x64\xff\x9b".str_repeat("\0", 8)) => substr($binary, 12),
            str_starts_with($binary, "\x20\x02") => substr($binary, 2, 4),
            default => null,
        };
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
