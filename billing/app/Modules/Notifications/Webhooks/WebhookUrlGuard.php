<?php

namespace App\Modules\Notifications\Webhooks;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * SSRF guard for outbound webhooks (spec §8). URLs must be https (http only in local), and the host is
 * resolved at send time: if any address is private, loopback, link-local, CGNAT, multicast, reserved or a
 * cloud metadata address, the delivery is refused. The vetted addresses are pinned for the connection,
 * so a DNS answer cannot change between the check and the request (rebinding).
 */
class WebhookUrlGuard
{
    /** Ranges that must never receive webhooks. */
    public const BLOCKED_RANGES = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    public function __construct(private HostResolver $resolver) {}

    /**
     * Static checks used when an endpoint is saved (no DNS): scheme, host, no credentials, no IP literal in a blocked range.
     */
    public function problemWith(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'Enter a full URL, e.g. https://hooks.example.com/billing.';
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'https' && ! ($scheme === 'http' && app()->environment('local'))) {
            return 'Webhook URLs must use https.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Remove the username and password from the URL; requests are signed instead.';
        }

        $host = trim($parts['host'], '[]');

        if (! $this->allowPrivate() && (strtolower($host) === 'localhost' || str_ends_with(strtolower($host), '.localhost')
            || (filter_var($host, FILTER_VALIDATE_IP) !== false && $this->isBlocked($host)))) {
            return 'Private, loopback and link-local addresses are not allowed.';
        }

        return null;
    }

    /**
     * Resolves and vets the host right before sending.
     *
     * @return array{host: string, port: int, addresses: list<string>}
     *
     * @throws UnsafeWebhookTarget
     */
    public function vet(string $url): array
    {
        if (($problem = $this->problemWith($url)) !== null) {
            throw new UnsafeWebhookTarget($problem);
        }

        $parts = parse_url($url);
        $host = trim(is_array($parts) ? ($parts['host'] ?? '') : '', '[]');
        $port = is_array($parts) ? ($parts['port'] ?? (strtolower($parts['scheme'] ?? '') === 'https' ? 443 : 80)) : 443;
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new UnsafeWebhookTarget("The host {$host} does not resolve.");
        }

        if (! $this->allowPrivate()) {
            foreach ($addresses as $address) {
                if ($this->isBlocked($address)) {
                    throw new UnsafeWebhookTarget("Refused: {$host} resolves to a private or reserved address ({$address}).");
                }
            }
        }

        return ['host' => $host, 'port' => $port, 'addresses' => $addresses];
    }

    public function isBlocked(string $address): bool
    {
        // IPv4-mapped/compatible IPv6 (::ffff:10.0.0.5) is checked as the IPv4 address it embeds.
        if (preg_match('/^::(?:ffff:)?(\d{1,3}(?:\.\d{1,3}){3})$/i', $address, $match)) {
            $address = $match[1];
        }

        return filter_var($address, FILTER_VALIDATE_IP) === false || IpUtils::checkIp($address, self::BLOCKED_RANGES);
    }

    private function allowPrivate(): bool
    {
        return (bool) config('notifications.webhooks_allow_private_targets', false) && ! app()->isProduction();
    }
}
