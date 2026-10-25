<?php

namespace App\Modules\PaymentsRazorpay\Support;

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
 * Outbound calls to the Razorpay REST API (razorpay.com/docs/api): the pinned host for the gateway's mode through
 * Endpoints (`razorpay.api`), HTTP Basic with the key id and secret checked against that mode, JSON bodies, TLS
 * verification on (the Http client default), short timeouts, and retries on background lookups only.
 */
final class RazorpayClient
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
            throw new RazorpayException(__('Razorpay test mode is not allowed in production. Turn it off and enter live keys.'));
        }

        return self::TEST;
    }

    /**
     * The key id and secret for the gateway's mode. A key id of the other mode is refused, so test keys never take
     * part in live settlement and the reverse.
     *
     * @return array{0: string, 1: string}
     */
    public static function credentials(GatewayConfig $gateway): array
    {
        $mode = self::mode($gateway);
        [$id, $secret] = [$gateway->setting('key_id'), $gateway->setting('key_secret')];
        [$id, $secret] = [is_string($id) ? trim($id) : '', is_string($secret) ? trim($secret) : ''];

        if ($id === '' || $secret === '') {
            throw new RazorpayException(__('Enter and save the Razorpay key id and key secret first.'));
        }

        if (preg_match('/^rzp_'.$mode.'_[A-Za-z0-9]{8,40}$/', $id) !== 1) {
            throw new RazorpayException($mode === self::TEST
                ? __('Test mode needs a test key id (rzp_test_…).')
                : __('Live mode needs a live key id (rzp_live_…).'));
        }

        return [$id, $secret];
    }

    /**
     * @param  array<string, string>  $query
     * @param  bool  $background  safe to repeat: retried on connection errors and 5xx (poller, webhooks)
     * @param  bool  $interactive  a customer's browser is waiting: one quick try
     */
    public function get(GatewayConfig $gateway, string $path, array $query = [], bool $background = false, bool $interactive = false): Response
    {
        $request = match (true) {
            $interactive => $this->interactive($gateway),
            $background => $this->retrying($gateway),
            default => $this->request($gateway),
        };
        $endpoint = self::endpoint($gateway);

        return $request->withOptions($endpoint->options)->get($endpoint->url($path), $query);
    }

    /**
     * Single-shot JSON POST: never retried, so nothing is sent twice.
     *
     * @param  array<string, mixed>  $body
     */
    public function post(GatewayConfig $gateway, string $path, array $body, bool $interactive = false): Response
    {
        $endpoint = self::endpoint($gateway);

        return ($interactive ? $this->interactive($gateway) : $this->request($gateway))
            ->withOptions($endpoint->options)
            ->asJson()
            ->post($endpoint->url($path), $body === [] ? (object) [] : $body);
    }

    /** Razorpay answers an unknown id with HTTP 400, not 404. */
    public static function isUnknownId(Response $response): bool
    {
        $description = $response->json('error.description');

        return $response->status() === 404 || ($response->status() === 400 && is_string($description) && str_contains(strtolower($description), 'does not exist'));
    }

    /**
     * Short, secret-free description of a Razorpay error body for logs and staff messages.
     */
    public static function errorSummary(Response $response): string
    {
        $error = $response->json('error');

        if (! is_array($error)) {
            return 'HTTP '.$response->status();
        }

        $parts = ['HTTP '.$response->status()];

        foreach (['code', 'description', 'reason', 'field'] as $field) {
            if (isset($error[$field]) && is_string($error[$field]) && $error[$field] !== '') {
                $parts[] = $field.': '.mb_substr($error[$field], 0, 160);
            }
        }

        return (string) preg_replace('/\brzp_(test|live)_[A-Za-z0-9]+/', '[key]', implode('; ', $parts));
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
        $delays = config('payments_razorpay.http.lookup_retry_delays_ms', [300, 900]);
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
        [$id, $secret] = self::credentials($gateway);

        return Http::withBasicAuth($id, $secret)
            ->acceptJson()
            ->connectTimeout(max(1, $connectTimeout))
            ->timeout(max(2, $timeout));
    }

    private static function endpoint(GatewayConfig $gateway): Endpoint
    {
        $base = config('payments_razorpay.api.'.(self::mode($gateway) === self::LIVE ? 'live' : 'sandbox'));

        if (! is_string($base) || ! str_starts_with($base, 'https://')) {
            throw new RazorpayException('payments_razorpay.api is not configured.');
        }

        return Endpoints::for('razorpay.api', $base);
    }

    public static function intConfig(string $key, int $default): int
    {
        $value = config('payments_razorpay.'.$key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
