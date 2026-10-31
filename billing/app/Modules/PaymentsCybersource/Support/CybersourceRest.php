<?php

namespace App\Modules\PaymentsCybersource\Support;

use App\Data\Billing\GatewayConfig;
use App\Data\Integrations\Endpoint;
use App\Support\Billing\GatewayHttp;
use App\Support\Billing\SandboxPolicy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Cybersource REST calls authenticated with HTTP Signature: HmacSHA256, keyed with the decoded shared secret, over
 * the host, date, request target, body digest (for a body) and merchant id. Hosts are pinned per mode in config.
 */
final class CybersourceRest
{
    public const SANDBOX = 'sandbox';

    public const LIVE = 'live';

    /**
     * Sandbox mode where test money is not allowed (production) is a configuration error: never a fallback to live.
     *
     * @return self::SANDBOX|self::LIVE
     */
    public static function mode(GatewayConfig $gateway): string
    {
        if (! $gateway->sandbox) {
            return self::LIVE;
        }

        if (! SandboxPolicy::allowed()) {
            throw new CybersourceException(__('Sandbox mode is not allowed in production. Turn it off and enter live credentials.'));
        }

        return self::SANDBOX;
    }

    /** Pinned endpoint for the gateway's mode: `pay` (the hosted form) or `rest`. */
    public static function endpoint(GatewayConfig $gateway, string $key): Endpoint
    {
        return GatewayHttp::endpoint('cybersource.'.$key, config('payments_cybersource.'.$key.'.'.self::mode($gateway)))
            ?? throw new CybersourceException("Endpoint payments_cybersource.{$key} is not configured.");
    }

    /**
     * GET or POST a REST path. Single-shot unless $retry (background lookups only: safe to repeat). Connection
     * failures throw ConnectionException; HTTP error statuses are returned.
     *
     * @param  array<string, mixed>|null  $body
     *
     * @throws ConnectionException
     */
    public function send(GatewayConfig $gateway, string $method, string $path, ?array $body = null, bool $interactive = false, bool $retry = false): Response
    {
        [$merchantId, $keyId, $secret] = self::credentials($gateway);
        $endpoint = self::endpoint($gateway, 'rest');
        $url = $endpoint->url($path);
        $json = $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $headers = self::signedHeaders($method, $url, $json, $merchantId, $keyId, $secret);

        $request = $this->client($gateway, $interactive, $retry && ! $interactive)->withOptions($endpoint->options)->withHeaders($headers);

        return $method === 'GET'
            ? $request->get($url)
            : $request->withBody($json, 'application/json')->post($url);
    }

    /**
     * The headers that authenticate one request. The Host line uses the URL's host, with its port when it has one,
     * which is the Host header the HTTP client sends.
     *
     * @return array<string, string>
     */
    public static function signedHeaders(string $method, string $url, string $body, string $merchantId, string $keyId, #[\SensitiveParameter] string $secret, ?int $time = null): array
    {
        $parts = parse_url($url);
        $host = ($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $target = strtolower($method).' '.($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        $headers = ['host' => $host, 'date' => gmdate('D, d M Y H:i:s \G\M\T', $time ?? time()), '(request-target)' => $target];

        if ($method !== 'GET') {
            $headers['digest'] = 'SHA-256='.base64_encode(hash('sha256', $body, true));
        }

        $headers['v-c-merchant-id'] = $merchantId;
        $lines = implode("\n", array_map(fn (string $name, string $value): string => $name.': '.$value, array_keys($headers), $headers));
        $signature = base64_encode(hash_hmac('sha256', $lines, (string) base64_decode($secret, true), true));

        return array_filter([
            'v-c-merchant-id' => $merchantId,
            'Date' => $headers['date'],
            'Digest' => $headers['digest'] ?? null,
            'Signature' => sprintf('keyid="%s", algorithm="HmacSHA256", headers="%s", signature="%s"', $keyId, implode(' ', array_keys($headers)), $signature),
        ], fn (?string $value): bool => $value !== null);
    }

    /**
     * @return array{0: string, 1: string, 2: string} merchant id, REST key id and REST shared secret
     */
    public static function credentials(GatewayConfig $gateway): array
    {
        $values = array_map(fn (string $key): string => is_string($gateway->setting($key)) ? trim($gateway->setting($key)) : '', ['merchant_id', 'rest_key_id', 'rest_secret']);

        if (in_array('', $values, true)) {
            throw new CybersourceException(__('Enter and save the Cybersource merchant ID, REST key ID and REST shared secret first.'));
        }

        if (base64_decode($values[2], true) === false) {
            throw new CybersourceException(__('The REST shared secret must be the base64 value the Business Center shows.'));
        }

        return $values;
    }

    /** Short, secret-free summary of an error body for logs and staff messages. */
    public static function summary(Response $response): string
    {
        $body = $response->json();
        $parts = ['HTTP '.$response->status()];

        foreach (['reason', 'message', 'response.rmsg', 'errorInformation.reason', 'errorInformation.message'] as $key) {
            $value = is_array($body) ? data_get($body, $key) : null;

            if (is_scalar($value)) {
                $parts[] = mb_substr((string) $value, 0, 120);
            }
        }

        return implode(': ', $parts);
    }

    /** Its own `http.timeout_seconds` replaces the staff timeout (GatewayHttp). */
    private function client(GatewayConfig $gateway, bool $interactive, bool $retry): PendingRequest
    {
        $http = GatewayHttp::for('payments_cybersource.http');

        return match (true) {
            $interactive => $http->interactive(),
            $retry => $http->lookup($gateway),
            default => $http->request($gateway),
        };
    }
}
