<?php

namespace App\Modules\Domains\Drivers\Registrars\ResellerClub;

use App\Data\Domains\Registrar\RegistrarConfig;
use App\Support\Domains\RegistrarException;
use App\Support\Domains\RegistrarTimeout;
use App\Support\Integrations\Endpoints;
use App\Support\Integrations\OutboundAddressGuard;
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

    /** Changes that can take a registry round trip; every other call is short. */
    private const LONG_CALLS = ['domains/register.json', 'domains/transfer.json', 'domains/renew.json'];

    /** Seconds kept back for the read that follows a change. */
    private const READ_BACK_RESERVE = 15;

    /** @var float|null when the current budget (within()) runs out, as microtime */
    private ?float $deadline = null;

    /**
     * @param  int  $timeoutSeconds  the longest a register, transfer or renew call may take (connecting takes at most 10 s)
     * @param  int  $shortTimeoutSeconds  the longest any other call may take: reads, contacts, customers, settings
     */
    public function __construct(private int $timeoutSeconds = 90, private int $shortTimeoutSeconds = 15) {}

    /**
     * Run a chain of calls inside a time budget, so a job with a hard timeout is never killed between two of them:
     * every call is limited to what is left, a change that cannot be given enough time is refused before it is sent
     * (retryable, nothing ran), and the budget ends with the callback.
     *
     * @template T
     *
     * @param  \Closure(): T  $chain
     * @return T
     */
    public function within(int $seconds, \Closure $chain): mixed
    {
        $outer = $this->deadline;
        $this->deadline = microtime(true) + $seconds;

        try {
            return $chain();
        } finally {
            $this->deadline = $outer;
        }
    }

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

        $timeout = $this->timeoutFor($path, $mutating);
        $proxy = $config->setting('http_proxy');
        $options = [...$endpoint->options, ...(is_string($proxy) && $proxy !== '' ? ['proxy' => self::validProxy($proxy)] : [])];
        $body = self::encode(['auth-userid' => $resellerId, 'api-key' => $apiKey, ...$params]);
        $request = Http::withOptions($options)->acceptJson()->connectTimeout(min(10, $timeout))->timeout($timeout);

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

    /**
     * The call's time limit: its own limit, cut to what is left of the budget. A change that would get less than
     * 10 s after the read-back reserve, or a read with nothing left, is refused before anything is sent.
     *
     * @throws RegistrarException
     */
    private function timeoutFor(string $path, bool $mutating): int
    {
        $own = in_array($path, self::LONG_CALLS, true) ? $this->timeoutSeconds : min($this->shortTimeoutSeconds, $this->timeoutSeconds);

        if ($this->deadline === null) {
            return max(1, $own);
        }

        $left = (int) floor($this->deadline - microtime(true));
        $allowed = $mutating && in_array($path, self::LONG_CALLS, true) ? $left - self::READ_BACK_RESERVE : $left;

        if ($allowed < ($mutating ? 10 : 1)) {
            throw new RegistrarException('The time allowed for this submission ran out before ResellerClub was asked; nothing was sent, try again.', retryable: true);
        }

        return max(1, min($own, $allowed));
    }

    /**
     * An outbound proxy is http, https or socks5h, with a host the install's outbound policy allows.
     *
     * @throws RegistrarException
     */
    private static function validProxy(string $proxy): string
    {
        $parts = parse_url($proxy);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';

        if (! in_array($scheme, ['http', 'https', 'socks5h'], true) || $host === '') {
            throw new RegistrarException('The ResellerClub outbound proxy must be an http://, https:// or socks5h:// address; nothing was sent.');
        }

        try {
            app(OutboundAddressGuard::class)->resolve($host, (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 1080)));
        } catch (OutboundAddressRefused $refused) {
            throw new RegistrarException('The ResellerClub outbound proxy address is refused by this install\'s outbound policy; nothing was sent.', previous: $refused);
        }

        return $proxy;
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
     * The reseller id this account's calls are made as (the demo one in test mode).
     */
    public static function resellerId(RegistrarConfig $config): string
    {
        return self::credentials($config)[0];
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
