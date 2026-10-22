<?php

namespace App\Modules\PaymentsNepal\Support;

use App\Data\Billing\GatewayConfig;
use App\Data\Integrations\Endpoint;
use App\Modules\Core\Support\Settings;
use App\Support\Billing\SandboxPolicy;
use App\Support\Integrations\Endpoints;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Outbound HTTP for the Nepal drivers: pinned hosts per mode, TLS verification on (the Http client default),
 * a short connect timeout, the billing.gateways.http_timeout_seconds total timeout, and retries on lookups only.
 */
final class ProviderHttp
{
    public const SANDBOX = 'sandbox';

    public const LIVE = 'live';

    public function __construct(private Settings $settings) {}

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
        $url = config('payments_nepal.'.$key.'.'.self::mode($gateway));

        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new ProviderException("Endpoint payments_nepal.{$key} is not configured.");
        }

        return Endpoints::for($key, $url);
    }

    /** Single-shot request (initiate, refund, connection test): never retried, so nothing is sent twice. */
    public function request(): PendingRequest
    {
        $connect = config('payments_nepal.http.connect_timeout_seconds', 5);
        $timeout = $this->settings->get('billing.gateways.http_timeout_seconds', 15);

        return Http::acceptJson()
            ->connectTimeout(is_numeric($connect) ? max(1, (int) $connect) : 5)
            ->timeout(is_numeric($timeout) ? max(3, (int) $timeout) : 15);
    }

    /**
     * A lookup while the customer's browser waits (the return route): one try with a short timeout, so a slow
     * provider costs seconds, not a minute. An inconclusive answer is left to the poller.
     */
    public function interactiveLookup(): PendingRequest
    {
        $connect = config('payments_nepal.http.interactive_connect_timeout_seconds', 3);
        $timeout = config('payments_nepal.http.interactive_timeout_seconds', 6);

        return Http::acceptJson()
            ->connectTimeout(is_numeric($connect) ? max(1, (int) $connect) : 3)
            ->timeout(is_numeric($timeout) ? max(2, (int) $timeout) : 6);
    }

    /**
     * Background status lookup (poller, staff "check now"): safe to repeat, so retried on connection errors and 5xx. Never throws for HTTP error statuses
     * (Khalti answers Expired/User canceled with HTTP 400 and a normal body); connection failures still throw.
     */
    public function lookup(bool $interactive = false): PendingRequest
    {
        if ($interactive) {
            return $this->interactiveLookup();
        }

        $delays = config('payments_nepal.http.lookup_retry_delays_ms', [300, 900]);
        $delays = is_array($delays) ? array_values(array_map(fn (mixed $delay): int => is_numeric($delay) ? (int) $delay : 0, $delays)) : [];

        return $this->request()->retry(
            $delays === [] ? 1 : $delays,
            0,
            fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError()),
            throw: false,
        );
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
