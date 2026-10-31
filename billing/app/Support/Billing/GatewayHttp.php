<?php

namespace App\Support\Billing;

use App\Data\Billing\GatewayConfig;
use App\Data\Integrations\Endpoint;
use App\Support\Integrations\Endpoints;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Outbound HTTP for gateway drivers (ADR 0030 A4): TLS verification on (the Http client default), JSON accepted, the
 * extension's connect timeouts, the staff's total timeout from GatewayConfig, and retries on background lookups only.
 * Each extension keeps an `http` block in its own config, named when it calls for():
 *
 *   connect_timeout_seconds (5), interactive_connect_timeout_seconds (3), interactive_timeout_seconds (6),
 *   lookup_retry_delays_ms ([300, 900]), and optionally timeout_seconds, which replaces the staff timeout.
 *
 * The driver adds its own authentication to the request it gets back.
 *
 * @api
 */
final class GatewayHttp
{
    private function __construct(private string $config) {}

    /**
     * @param  string  $config  the extension's `http` config block, e.g. "payments_stripe.http"
     */
    public static function for(string $config): self
    {
        return new self($config);
    }

    /** Single-shot request (initiate, refund, connection test): never retried, so nothing is sent twice. */
    public function request(GatewayConfig $gateway): PendingRequest
    {
        $own = config($this->config.'.timeout_seconds');

        return Http::acceptJson()
            ->connectTimeout(max(1, $this->int('connect_timeout_seconds', 5)))
            ->timeout(is_numeric($own) ? max(2, (int) $own) : max(2, $gateway->httpTimeoutSeconds));
    }

    /**
     * A call while the customer's browser waits (the return route): one try with short timeouts, so a slow provider
     * costs seconds. An inconclusive answer is left to the poller.
     */
    public function interactive(): PendingRequest
    {
        return Http::acceptJson()
            ->connectTimeout(max(1, $this->int('interactive_connect_timeout_seconds', 3)))
            ->timeout(max(2, $this->int('interactive_timeout_seconds', 6)));
    }

    /**
     * A background lookup (poller, webhook, staff "check now"): safe to repeat, so retried on connection errors and
     * 5xx. Never throws for an HTTP error status; a connection failure still throws once the retries are spent.
     */
    public function lookup(GatewayConfig $gateway): PendingRequest
    {
        $delays = config($this->config.'.lookup_retry_delays_ms', [300, 900]);
        $delays = is_array($delays) ? array_values(array_map(fn (mixed $delay): int => is_numeric($delay) ? (int) $delay : 0, $delays)) : [];

        return $this->request($gateway)->retry($delays === [] ? 1 : $delays, 0, self::retryable(...), throw: false);
    }

    /** Whether a lookup that failed this way is worth another try. */
    public static function retryable(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            || ($exception instanceof RequestException && $exception->response->serverError());
    }

    /**
     * The pinned endpoint for a configured base URL (Endpoints::for(), ADR 0016 §3), or null when the URL is not an
     * https:// string; the driver then throws its own "not configured" error.
     */
    public static function endpoint(string $key, mixed $url): ?Endpoint
    {
        return is_string($url) && str_starts_with($url, 'https://') ? Endpoints::for($key, $url) : null;
    }

    private function int(string $key, int $default): int
    {
        $value = config($this->config.'.'.$key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
