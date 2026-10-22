<?php

namespace App\Modules\Domains\Drivers\Registrars\ResellerClub;

use App\Data\Domains\Registrar\RegistrarConfig;
use App\Support\Domains\RegistrarException;
use App\Support\Domains\RegistrarTimeout;
use App\Support\Integrations\Endpoints;
use App\Support\Integrations\OutboundAddressRefused;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Outbound HTTP for the ResellerClub (LogicBoxes) HTTP API (registrars.md §3): `auth-userid` and `api-key` on every
 * call, GET for reads and POST for changes, lists sent as repeated names without brackets (`ns=a&ns=b`), JSON answers.
 * Hosts come from Endpoints::for() (`resellerclub.api`, `resellerclub.check`), the demo host in test mode.
 *
 * Failures map to one outcome (registrars.md §0):
 * - a refused connection or an unresolved host, or a rate limit (HTTP 429): nothing ran, RegistrarException(retryable);
 * - `{"status": "ERROR"}`: rejected, with ResellerClub's message (it has no error codes), except an internal or
 *   "try again" message on a change, which may have run: RegistrarTimeout;
 * - any other failure of a change (a timeout after sending, a 5xx without the error body, an unreadable answer):
 *   RegistrarTimeout. A read that fails that way is retryable.
 *
 * Messages never carry the API key: the key travels only in the request, and the host masks the account's secrets in
 * everything a call returns or raises.
 */
final class ResellerClubClient
{
    public const LIVE_API = 'https://httpapi.com/api';

    public const LIVE_CHECK = 'https://domaincheck.httpapi.com/api';

    /** The demo host; it acts on whichever account's credentials it is sent, so test mode sends only the demo ones. */
    public const TEST_API = 'https://test.httpapi.com/api';

    /** cURL errors that prove the request never reached ResellerClub: unresolved host, refused connection. */
    private const NOT_SENT_ERRORS = ['cURL error 6:', 'cURL error 7:'];

    /**
     * @param  int  $timeoutSeconds  the whole call (connecting takes at most 10 s); a registration can take 30 s or more
     */
    public function __construct(private int $timeoutSeconds = 90) {}

    /**
     * A read (GET). $check sends it to the availability host.
     *
     * @param  array<string, string|int|list<string|int>>  $params
     *
     * @throws RegistrarException
     */
    public function get(RegistrarConfig $config, string $path, array $params = [], bool $check = false): mixed
    {
        return $this->send($config, 'GET', $path, $params, $check);
    }

    /**
     * A change (POST): never retried here, and any doubt about whether it ran is a RegistrarTimeout.
     *
     * @param  array<string, string|int|list<string|int>>  $params
     *
     * @throws RegistrarException
     * @throws RegistrarTimeout
     */
    public function post(RegistrarConfig $config, string $path, array $params = []): mixed
    {
        return $this->send($config, 'POST', $path, $params);
    }

    /**
     * Whether a rejection is ResellerClub refusing the account itself (wrong key, address not on the allow-list).
     */
    public static function accessDenied(RegistrarException $exception): bool
    {
        return str_contains($exception->getMessage(), 'refused the API credentials');
    }

    /**
     * @param  array<string, string|int|list<string|int>>  $params
     */
    private function send(RegistrarConfig $config, string $method, string $path, array $params, bool $check = false): mixed
    {
        $mutating = $method === 'POST';
        [$resellerId, $apiKey] = self::credentials($config);
        try {
            $endpoint = Endpoints::for($check ? 'resellerclub.check' : 'resellerclub.api', $config->testMode ? self::TEST_API : ($check ? self::LIVE_CHECK : self::LIVE_API));
        } catch (OutboundAddressRefused $refused) {
            throw new RegistrarException('The ResellerClub address is refused by this install\'s outbound policy; nothing was sent.', previous: $refused);
        }

        $proxy = $config->setting('http_proxy');
        $options = [...$endpoint->options, ...(is_string($proxy) && $proxy !== '' ? ['proxy' => $proxy] : [])];
        $body = self::encode(['auth-userid' => $resellerId, 'api-key' => $apiKey, ...$params]);
        $request = Http::withOptions($options)->acceptJson()->connectTimeout(10)->timeout(max(1, $this->timeoutSeconds));

        try {
            $response = $mutating
                ? $request->withBody($body, 'application/x-www-form-urlencoded')->post($endpoint->url($path))
                : $request->get($endpoint->url($path).'?'.$body);
        } catch (ConnectionException $exception) {
            $sent = ! str_contains($exception->getMessage(), self::NOT_SENT_ERRORS[0]) && ! str_contains($exception->getMessage(), self::NOT_SENT_ERRORS[1]);

            if ($mutating && $sent) {
                throw new RegistrarTimeout('ResellerClub did not answer in time; the request may have been carried out.');
            }

            throw new RegistrarException('ResellerClub could not be reached; nothing was sent.', retryable: true);
        }

        return $this->answer($response, $mutating);
    }

    private function answer(Response $response, bool $mutating): mixed
    {
        if ($response->status() === 429) {
            throw new RegistrarException('ResellerClub is limiting requests from this server; try again later.', retryable: true);
        }

        $decoded = json_decode($response->body(), true);
        $error = is_array($decoded) && strtoupper((string) ($decoded['status'] ?? '')) === 'ERROR' ? (string) ($decoded['message'] ?? '') : null;

        if ($error !== null) {
            throw self::refusal($error, $mutating);
        }

        if (! $response->successful() || ($decoded === null && trim($response->body()) !== 'null')) {
            if ($mutating) {
                throw new RegistrarTimeout('ResellerClub answered HTTP '.$response->status().' without a readable result; the request may have been carried out.');
            }

            throw new RegistrarException('ResellerClub answered HTTP '.$response->status().' without a readable result.', retryable: true);
        }

        return $decoded;
    }

    private static function refusal(string $message, bool $mutating): RegistrarException
    {
        $text = mb_substr(trim($message), 0, 300);

        return match (true) {
            stripos($text, 'access denied') !== false => new RegistrarException('ResellerClub refused the API credentials, or this server\'s IP address is not on the API allow-list yet (Settings → API in the ResellerClub panel).'),
            stripos($text, 'insufficient funds') !== false => new RegistrarException('The ResellerClub reseller account has insufficient funds for this order; add funds there, then try again.'),
            $mutating && preg_match('/internal|timed? ?out|try again|temporar/i', $text) === 1 => new RegistrarTimeout('ResellerClub reported a problem on its side ('.$text.'); the request may have been carried out.'),
            default => new RegistrarException('ResellerClub refused the request: '.$text),
        };
    }

    /**
     * The reseller id and API key for the account's mode: the demo pair in test mode, the live pair otherwise.
     *
     * @return array{0: string, 1: string}
     */
    private static function credentials(RegistrarConfig $config): array
    {
        $id = $config->setting($config->testMode ? 'demo_reseller_id' : 'reseller_id');
        $key = $config->setting($config->testMode ? 'demo_api_key' : 'api_key');

        if (! is_scalar($id) || (string) $id === '' || ! is_string($key) || $key === '') {
            throw new RegistrarException($config->testMode
                ? 'Enter the demo reseller id and demo API key in the ResellerClub registrar settings (test mode is on).'
                : 'Enter the reseller id and API key in the ResellerClub registrar settings.');
        }

        return [(string) $id, $key];
    }

    /**
     * Form encoding with lists as repeated names, which ResellerClub requires (http_build_query would send `ns[0]=`).
     *
     * @param  array<string, string|int|list<string|int>>  $params
     */
    public static function encode(array $params): string
    {
        $pairs = [];

        foreach ($params as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $pairs[] = rawurlencode($name).'='.rawurlencode((string) $item);
            }
        }

        return implode('&', $pairs);
    }
}
