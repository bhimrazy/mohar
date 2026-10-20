<?php

namespace App\Modules\Notifications\Webhooks;

use App\Data\Integrations\ResolvedAddress;
use App\Support\Integrations\OutboundAddressGuard;
use App\Support\Integrations\OutboundAddressRefused;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * SSRF guard for outbound webhooks (spec §8): a stricter policy on top of the kernel OutboundAddressGuard
 * (ADR 0015). URLs must be https (http only in local) on port 443 (80 outside production). At send time the host is
 * resolved once and judged by the kernel guard (metadata, link-local, reserved, tunnelled and non-canonical forms),
 * and then by this guard: private, loopback and CGNAT addresses are refused too, whatever the install's outbound
 * policy allows for control panels (security review 2026-10-19, L2). The sender pins the connection to the vetted
 * address through OutboundAddressGuard::pin(), which also bypasses any proxy, so a DNS answer cannot change between
 * the check and the request (rebinding).
 */
class WebhookUrlGuard
{
    /** Ranges that must never receive webhooks. */
    public const BLOCKED_RANGES = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001::/32', '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8',
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

        // Only web ports: 443, plus 80 outside production (security review L6).
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (! in_array($port, app()->isProduction() ? [443] : [443, 80], true)) {
            return 'Use the standard HTTPS port (443).';
        }

        $host = trim($parts['host'], '[]');

        if (! $this->allowPrivate() && (strtolower($host) === 'localhost' || str_ends_with(strtolower($host), '.localhost')
            || (filter_var($host, FILTER_VALIDATE_IP) !== false && $this->isBlocked($host)))) {
            return 'Private, loopback and link-local addresses are not allowed.';
        }

        return null;
    }

    /**
     * Resolves and vets the host right before sending; the result is the one address to pin the connection to.
     *
     * @throws UnsafeWebhookTarget
     */
    public function vet(string $url): ResolvedAddress
    {
        if (($problem = $this->problemWith($url)) !== null) {
            throw new UnsafeWebhookTarget($problem);
        }

        $parts = parse_url($url);
        $host = trim(is_array($parts) ? ($parts['host'] ?? '') : '', '[]');
        $port = is_array($parts) ? ($parts['port'] ?? (strtolower($parts['scheme'] ?? '') === 'https' ? 443 : 80)) : 443;
        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $addresses = $literal ? [$host] : $this->resolver->resolve($host);

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

        // The kernel guard judges the same answer (no second lookup), so what is pinned is what was checked.
        try {
            return (new OutboundAddressGuard(fn (): array => $addresses))->resolve($host, (int) $port);
        } catch (OutboundAddressRefused $refused) {
            throw new UnsafeWebhookTarget('Refused: '.$refused->getMessage());
        }
    }

    /**
     * The URL without credentials, path or query, for audit logs: hook URLs often embed bearer tokens.
     */
    public static function forLog(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return '(invalid URL)';
        }

        return ($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').'/…';
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
