<?php

namespace App\Modules\PaymentsRazorpay\Support;

use App\Data\Billing\GatewayConfig;
use App\Data\Integrations\Endpoint;
use App\Support\Billing\GatewayHttp;
use App\Support\Billing\SandboxPolicy;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Outbound calls to the Razorpay REST API (razorpay.com/docs/api): the pinned host for the gateway's mode through
 * Endpoints (`razorpay.api`), HTTP Basic with the key id and secret checked against that mode, JSON bodies, and
 * GatewayHttp's timeouts and lookup retries.
 */
final class RazorpayClient
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
        [$id, $secret] = self::credentials($gateway);

        return $request->withBasicAuth($id, $secret);
    }

    private static function http(): GatewayHttp
    {
        return GatewayHttp::for('payments_razorpay.http');
    }

    private static function endpoint(GatewayConfig $gateway): Endpoint
    {
        return GatewayHttp::endpoint('razorpay.api', config('payments_razorpay.api.'.(self::mode($gateway) === self::LIVE ? 'live' : 'sandbox')))
            ?? throw new RazorpayException('payments_razorpay.api is not configured.');
    }

    public static function intConfig(string $key, int $default): int
    {
        $value = config('payments_razorpay.'.$key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
