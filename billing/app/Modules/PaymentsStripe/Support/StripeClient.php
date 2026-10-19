<?php

namespace App\Modules\PaymentsStripe\Support;

use App\Data\Billing\GatewayConfig;
use App\Data\Integrations\Endpoint;
use App\Modules\Core\Support\Settings;
use App\Support\Billing\SandboxPolicy;
use App\Support\Integrations\Endpoints;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Outbound calls to the Stripe REST API (docs/product/payments-stripe.md §8): pinned host and Stripe-Version, bearer
 * secret key checked against the gateway's mode, TLS verification on (the Http client default), a short connect
 * timeout, the billing.gateways.http_timeout_seconds total timeout, an Idempotency-Key on every POST, and retries
 * on background lookups only.
 *
 * The base URL and its request options come from Endpoints (`stripe.api`), in one call per request: the configured
 * host unchanged, or an override from the environment in development, verified and pinned through the outbound guard.
 */
final class StripeClient
{
    public const TEST = 'test';

    public const LIVE = 'live';

    public function __construct(private Settings $settings) {}

    /**
     * Test or live mode. Test mode where test money is not allowed (production) is a configuration error: it never
     * silently falls back to live.
     *
     * @return self::TEST|self::LIVE
     */
    public static function mode(GatewayConfig $gateway): string
    {
        if (! $gateway->sandbox) {
            return self::LIVE;
        }

        if (! SandboxPolicy::allowed()) {
            throw new StripeException(__('Stripe test mode is not allowed in production. Turn it off and enter live keys.'));
        }

        return self::TEST;
    }

    /**
     * The secret (or restricted) key for the gateway's mode. A key of the other mode is refused, so test keys can
     * never take part in live settlement and the reverse.
     */
    public static function secretKey(GatewayConfig $gateway): string
    {
        $mode = self::mode($gateway);
        $key = $gateway->setting('secret_key', '');
        $key = is_string($key) ? trim($key) : '';

        if ($key === '') {
            throw new StripeException(__('Enter and save the Stripe secret key first.'));
        }

        if (preg_match('/^(sk|rk)_'.$mode.'_[A-Za-z0-9]{8,247}$/', $key) !== 1) {
            throw new StripeException($mode === self::TEST
                ? __('Test mode needs a test secret key (sk_test_… or rk_test_…).')
                : __('Live mode needs a live secret key (sk_live_… or rk_live_…).'));
        }

        return $key;
    }

    /**
     * GET an object, e.g. get($gateway, 'checkout/sessions/cs_…', ['payment_intent']).
     *
     * @param  list<string>  $expand
     * @param  bool  $background  safe to repeat: retried on connection errors and 5xx (poller, webhooks)
     * @param  bool  $interactive  a customer's browser is waiting: one quick try
     */
    public function get(GatewayConfig $gateway, string $path, array $expand = [], bool $background = false, bool $interactive = false): Response
    {
        $query = implode('&', array_map(fn (string $field): string => 'expand[]='.rawurlencode($field), $expand));
        $request = match (true) {
            $interactive => $this->interactive($gateway),
            $background => $this->retrying($gateway),
            default => $this->request($gateway),
        };

        $endpoint = $this->endpoint();

        return $request->withOptions($endpoint->options)->get($endpoint->url($path).($query !== '' ? '?'.$query : ''));
    }

    /**
     * Single-shot POST (never retried, so nothing is sent twice) with form-encoded nested parameters.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function post(GatewayConfig $gateway, string $path, array $parameters, string $idempotencyKey): Response
    {
        $endpoint = $this->endpoint();

        return $this->request($gateway)
            ->withOptions($endpoint->options)
            ->asForm()
            ->withHeaders(['Idempotency-Key' => mb_substr($idempotencyKey, 0, 255)])
            ->post($endpoint->url($path), $parameters);
    }

    /**
     * Short, secret-free description of a Stripe error body for logs and staff messages.
     */
    public static function errorSummary(Response $response): string
    {
        $error = $response->json('error');

        if (! is_array($error)) {
            return 'HTTP '.$response->status();
        }

        $parts = [];

        foreach (['type', 'code', 'decline_code', 'message'] as $field) {
            if (isset($error[$field]) && is_string($error[$field]) && $error[$field] !== '') {
                $parts[] = $field.': '.mb_substr($error[$field], 0, 160);
            }
        }

        // Stripe masks keys in its messages; strip anything key-shaped anyway.
        return (string) preg_replace('/\b(sk|rk|pk|whsec)_[A-Za-z0-9_*]+/', '[key]', implode('; ', $parts) ?: 'HTTP '.$response->status());
    }

    /** Stripe's machine-readable error code (e.g. resource_missing, card_declined), when the body has one. */
    public static function errorCode(Response $response): ?string
    {
        $code = $response->json('error.code');

        return is_string($code) ? $code : null;
    }

    private function request(GatewayConfig $gateway): PendingRequest
    {
        $timeout = $this->settings->get('billing.gateways.http_timeout_seconds', 15);

        return $this->base($gateway, self::intConfig('http.connect_timeout_seconds', 5), is_numeric($timeout) ? max(3, (int) $timeout) : 15);
    }

    private function interactive(GatewayConfig $gateway): PendingRequest
    {
        return $this->base($gateway, self::intConfig('http.interactive_connect_timeout_seconds', 3), self::intConfig('http.interactive_timeout_seconds', 6));
    }

    private function retrying(GatewayConfig $gateway): PendingRequest
    {
        $delays = config('payments_stripe.http.lookup_retry_delays_ms', [300, 900]);
        $delays = is_array($delays) ? array_values(array_map(fn (mixed $delay): int => is_numeric($delay) ? (int) $delay : 0, $delays)) : [];

        return $this->request($gateway)->retry(
            $delays === [] ? 1 : $delays,
            0,
            fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError()),
            throw: false,
        );
    }

    private function base(GatewayConfig $gateway, int $connectTimeout, int $timeout): PendingRequest
    {
        $version = config('payments_stripe.api_version');

        return Http::withToken(self::secretKey($gateway))
            ->withHeaders(['Stripe-Version' => is_string($version) ? $version : ''])
            ->acceptJson()
            ->connectTimeout(max(1, $connectTimeout))
            ->timeout(max(2, $timeout));
    }

    private function endpoint(): Endpoint
    {
        $base = config('payments_stripe.api');

        if (! is_string($base) || ! str_starts_with($base, 'https://')) {
            throw new StripeException('payments_stripe.api is not configured.');
        }

        return Endpoints::for('stripe.api', $base);
    }

    private static function intConfig(string $key, int $default): int
    {
        $value = config('payments_stripe.'.$key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
