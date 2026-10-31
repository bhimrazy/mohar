<?php

namespace App\Modules\PaymentsNepal\Support;

use App\Data\Billing\GatewayConfig;
use App\Data\Integrations\Endpoint;
use App\Support\Billing\GatewayHttp;
use App\Support\Billing\SandboxPolicy;
use Illuminate\Http\Client\PendingRequest;

/**
 * Outbound HTTP for the Nepal drivers: pinned hosts per mode, and GatewayHttp's timeouts and lookup retries
 * (`payments_nepal.http`).
 */
final class ProviderHttp
{
    public const SANDBOX = 'sandbox';

    public const LIVE = 'live';

    /**
     * Endpoint mode for a gateway. Sandbox mode where test money is not allowed (production) is a configuration
     * error: it never silently falls back to live, and never talks to a sandbox host.
     *
     * @return self::SANDBOX|self::LIVE
     */
    public static function mode(GatewayConfig $gateway): string
    {
        if (! $gateway->sandbox) {
            return self::LIVE;
        }

        if (! SandboxPolicy::allowed()) {
            throw new ProviderException(__('Sandbox mode is not allowed in production. Turn it off and enter live credentials.'));
        }

        return self::SANDBOX;
    }

    /**
     * Pinned endpoint from config for the gateway's mode, e.g. endpoint($gateway, 'khalti.api'), with the request
     * options its destination needs (Endpoints::for(), ADR 0016 §3): [] for the provider's own host.
     */
    public static function endpoint(GatewayConfig $gateway, string $key): Endpoint
    {
        return GatewayHttp::endpoint($key, config('payments_nepal.'.$key.'.'.self::mode($gateway)))
            ?? throw new ProviderException("Endpoint payments_nepal.{$key} is not configured.");
    }

    /** An integer from payments_nepal config, e.g. intConfig('khalti.min_amount', 1000); the default when not numeric. */
    public static function intConfig(string $key, int $default): int
    {
        $value = config('payments_nepal.'.$key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /** Single-shot request (initiate, refund, connection test): never retried, so nothing is sent twice. */
    public function request(GatewayConfig $gateway): PendingRequest
    {
        return self::http()->request($gateway);
    }

    /**
     * A status lookup. While the customer's browser waits (the return route, $interactive) it is one quick try, and an
     * inconclusive answer is left to the poller. In the background (poller, staff "check now") it is retried on
     * connection errors and 5xx. Never throws for HTTP error statuses (Khalti answers Expired/User canceled with HTTP
     * 400 and a normal body); connection failures still throw.
     */
    public function lookup(GatewayConfig $gateway, bool $interactive = false): PendingRequest
    {
        return $interactive ? self::http()->interactive() : self::http()->lookup($gateway);
    }

    private static function http(): GatewayHttp
    {
        return GatewayHttp::for('payments_nepal.http');
    }

    /**
     * Keep the last 3 digits of a payer's mobile number (logs and stored payloads never hold the full number).
     */
    public static function maskMobile(mixed $mobile): ?string
    {
        if (! is_scalar($mobile) || (string) $mobile === '') {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', (string) $mobile);

        return $digits === '' ? null : str_repeat('*', max(0, strlen($digits) - 3)).substr($digits, -3);
    }

    /**
     * Short, secret-free summary of a provider error body for logs and staff messages.
     */
    public static function summary(mixed $body): string
    {
        if (! is_array($body)) {
            return '';
        }

        $parts = [];

        foreach (['detail', 'error_key', 'error_message', 'message', 'code'] as $key) {
            if (isset($body[$key]) && is_scalar($body[$key])) {
                $parts[] = $key.': '.mb_substr((string) $body[$key], 0, 120);
            }
        }

        // Khalti validation errors are keyed by field: name the fields, not their values.
        $fields = array_filter(array_keys($body), fn (int|string $key): bool => is_string($key) && is_array($body[$key]));

        if ($fields !== []) {
            $parts[] = 'fields: '.implode(', ', array_slice($fields, 0, 8));
        }

        return implode('; ', $parts);
    }
}
