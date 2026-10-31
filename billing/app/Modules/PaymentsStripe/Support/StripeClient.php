<?php

namespace App\Modules\PaymentsStripe\Support;

use App\Data\Billing\GatewayConfig;
use App\Data\Integrations\Endpoint;
use App\Support\Billing\GatewayHttp;
use App\Support\Billing\SandboxPolicy;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Outbound calls to the Stripe REST API (docs/product/payments-stripe.md §8): pinned host and Stripe-Version, bearer
 * secret key checked against the gateway's mode, an Idempotency-Key on every POST, and GatewayHttp's timeouts and
 * lookup retries.
 *
 * The base URL and its request options come from Endpoints (`stripe.api`), in one call per request: the configured
 * host unchanged, or an override from the environment in development, verified and pinned through the outbound guard.
 */
final class StripeClient
{
    public const TEST = 'test';

    public const LIVE = 'live';

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
     * @param  array<string, string>  $query  extra query parameters (list filters)
     * @param  bool  $background  safe to repeat: retried on connection errors and 5xx (poller, webhooks)
     * @param  bool  $interactive  a customer's browser is waiting: one quick try
     */
    public function get(GatewayConfig $gateway, string $path, array $expand = [], bool $background = false, bool $interactive = false, array $query = []): Response
    {
        $query = implode('&', [...array_map(fn (string $field): string => 'expand[]='.rawurlencode($field), $expand), ...array_map(fn (string $key, string $value): string => rawurlencode($key).'='.rawurlencode($value), array_keys($query), $query)]);
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
     * @param  bool  $interactive  a customer's browser is waiting: the short interactive timeouts
     */
    public function post(GatewayConfig $gateway, string $path, array $parameters, string $idempotencyKey, bool $interactive = false): Response
    {
        $endpoint = $this->endpoint();

        return ($interactive ? $this->interactive($gateway) : $this->request($gateway))
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
        return $this->authenticated($gateway, self::http()->request($gateway));
    }

    private function interactive(GatewayConfig $gateway): PendingRequest
    {
        return $this->authenticated($gateway, self::http()->interactive());
    }

    private function retrying(GatewayConfig $gateway): PendingRequest
    {
        return $this->authenticated($gateway, self::http()->lookup($gateway));
    }

    private function authenticated(GatewayConfig $gateway, PendingRequest $request): PendingRequest
    {
        $version = config('payments_stripe.api_version');

        return $request->withToken(self::secretKey($gateway))->withHeaders(['Stripe-Version' => is_string($version) ? $version : '']);
    }

    private static function http(): GatewayHttp
    {
        return GatewayHttp::for('payments_stripe.http');
    }

    private function endpoint(): Endpoint
    {
        return GatewayHttp::endpoint('stripe.api', config('payments_stripe.api'))
            ?? throw new StripeException('payments_stripe.api is not configured.');
    }
}
