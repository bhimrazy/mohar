<?php

namespace App\Modules\Domains\Drivers\Registrars\Namecheap;

use App\Data\Domains\Registrar\RegistrarConfig;
use App\Support\Domains\RegistrarException;
use App\Support\Domains\RegistrarTimeout;
use App\Support\Integrations\Endpoints;
use App\Support\Integrations\OutboundAddressGuard;
use App\Support\Integrations\OutboundAddressRefused;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use SimpleXMLElement;

/**
 * Outbound HTTP for the Namecheap XML API (registrars.md §4): one URL, `Command=` and the four global parameters
 * (`ApiUser`, `ApiKey`, `UserName`, `ClientIp`) on every call, POST, an XML answer that is HTTP 200 even for errors.
 * The host comes from Endpoints::for() (`namecheap.api`, the full `xml.response` URL), the sandbox in test mode.
 *
 * Failures map to one outcome (registrars.md §0):
 * - a refused connection, an unresolved host, an exhausted local request budget or a rate limit (HTTP 429): nothing
 *   ran, RegistrarException(retryable);
 * - an `<Error>` the API gives before it did any work (credentials, the address allow-list, a validation, a name that
 *   is not available): rejected;
 * - an error number that says the provider behind Namecheap failed or half-ran (registrars.md §4), or any failure of a
 *   change that leaves it open whether the change happened (a timeout after sending, a 5xx, an unreadable answer):
 *   RegistrarTimeout. A read that fails that way is retryable.
 *
 * Namecheap allows 50 calls a minute, 700 an hour and 8000 a day per key, so the client keeps a smaller budget of its
 * own (40, 600 and 7000) and holds a reserve back for changes: reads stop earlier than changes do.
 *
 * Messages never carry the API key: it travels only in the request, and the host masks the account's secrets in
 * everything a call returns or raises.
 */
final class NamecheapClient
{
    public const LIVE_API = 'https://api.namecheap.com/xml.response';

    public const SANDBOX_API = 'https://api.sandbox.namecheap.com/xml.response';

    /** Calls allowed in a window, by seconds: below Namecheap's own 50, 700 and 8000. */
    private const BUDGET = [60 => 40, 3600 => 600, 86400 => 7000];

    /** Calls in each window that only changes may use. */
    private const WRITE_RESERVE = [60 => 10, 3600 => 100, 86400 => 1000];

    /** cURL errors that prove the request never reached Namecheap: unresolved host, refused connection. */
    private const NOT_SENT_ERRORS = ['cURL error 6:', 'cURL error 7:'];

    /** Changes that can take a registry round trip (about 30 s for a registration); every other call is short. */
    private const LONG_CALLS = ['namecheap.domains.create', 'namecheap.domains.transfer.create', 'namecheap.domains.renew', 'namecheap.domains.reactivate'];

    /** The provider behind Namecheap failed or half-ran: on a change, the outcome is unknown (registrars.md §4). */
    private const UNKNOWN_ERRORS = [3011511, 3031900, 3050900, 5050900, 3028166, 3031510, 4023166, 4024167, 4022337, 4026312];

    private const ALLOW_LIST_ERRORS = [1011150, 1017150, 1017105];

    private const READ_BACK_RESERVE = 15;

    /** @var float|null when the current budget (within()) runs out, as microtime */
    private ?float $deadline = null;

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
     * A read: may be repeated, spends no write reserve.
     *
     * @param  array<string, string|int>  $params
     *
     * @throws RegistrarException
     */
    public function read(RegistrarConfig $config, string $command, array $params = []): SimpleXMLElement
    {
        return $this->send($config, $command, $params, false);
    }

    /**
     * A change: never retried here, and any doubt about whether it ran is a RegistrarTimeout.
     *
     * @param  array<string, string|int>  $params
     *
     * @throws RegistrarException
     * @throws RegistrarTimeout
     */
    public function change(RegistrarConfig $config, string $command, array $params = []): SimpleXMLElement
    {
        return $this->send($config, $command, $params, true);
    }

    /**
     * @param  array<string, string|int>  $params
     * @return SimpleXMLElement the CommandResponse element
     */
    private function send(RegistrarConfig $config, string $command, array $params, bool $mutating): SimpleXMLElement
    {
        $credentials = self::credentials($config);

        try {
            $endpoint = Endpoints::for('namecheap.api', $config->testMode ? self::SANDBOX_API : self::LIVE_API);
        } catch (OutboundAddressRefused $refused) {
            throw new RegistrarException('The Namecheap address is refused by this install\'s outbound policy; nothing was sent.', previous: $refused);
        }

        $timeout = $this->timeoutFor($command, $mutating);
        $proxy = $config->setting('http_proxy');
        $options = [...$endpoint->options, ...(is_string($proxy) && $proxy !== '' ? ['proxy' => self::validProxy($proxy)] : [])];
        $this->spend($credentials['ApiUser'], $config->testMode, $mutating);

        try {
            $response = Http::withOptions($options)->connectTimeout(min(10, $timeout))->timeout($timeout)->asForm()
                ->post($endpoint->url(), [...$credentials, 'Command' => $command, ...$params]);
        } catch (ConnectionException $exception) {
            $sent = ! str_contains($exception->getMessage(), self::NOT_SENT_ERRORS[0]) && ! str_contains($exception->getMessage(), self::NOT_SENT_ERRORS[1]);

            if ($mutating && $sent) {
                throw new RegistrarTimeout('Namecheap did not answer in time; the request may have been carried out.');
            }

            throw new RegistrarException('Namecheap could not be reached; nothing was sent.', retryable: true);
        }

        return $this->answer($response, $mutating);
    }

    private function answer(Response $response, bool $mutating): SimpleXMLElement
    {
        if ($response->status() === 429) {
            throw new RegistrarException('Namecheap is limiting requests from this server; try again later.', retryable: true);
        }

        $xml = self::parse($response->body());

        if ($xml === null || ! in_array((string) $xml['Status'], ['OK', 'ERROR'], true)) {
            if ($mutating) {
                throw new RegistrarTimeout('Namecheap answered HTTP '.$response->status().' without a readable result; the request may have been carried out.');
            }

            throw new RegistrarException('Namecheap answered HTTP '.$response->status().' without a readable result.', retryable: true);
        }

        if ((string) $xml['Status'] === 'ERROR') {
            $errors = [];

            foreach ($xml->Errors->Error ?? [] as $error) {
                $errors[] = [(int) $error['Number'], mb_substr(trim((string) $error), 0, 300)];
            }

            throw self::refusal($errors, $mutating);
        }

        if (! isset($xml->CommandResponse)) {
            throw $mutating
                ? new RegistrarTimeout('Namecheap answered without a command result; the request may have been carried out.')
                : new RegistrarException('Namecheap answered without a command result.', retryable: true);
        }

        return $xml->CommandResponse;
    }

    /**
     * The answer as XML, or null. Documents with a DOCTYPE are refused (no entity is ever expanded), and the default
     * namespace is dropped so paths read plainly.
     */
    private static function parse(string $body): ?SimpleXMLElement
    {
        if ($body === '' || stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string) preg_replace('/\sxmlns="[^"]*"/', '', $body, 1), SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $xml === false ? null : $xml;
    }

    /**
     * @param  list<array{0: int, 1: string}>  $errors  number and message
     */
    private static function refusal(array $errors, bool $mutating): RegistrarException
    {
        $numbers = array_column($errors, 0);
        $text = implode(' ', array_column($errors, 1));

        if (array_intersect($numbers, self::ALLOW_LIST_ERRORS) !== []) {
            return new RegistrarException('Namecheap does not accept requests from this server\'s IP address: add it to the API allow-list (Profile → Tools → API Access in Namecheap) and enter the same address as the client IP in the registrar settings.');
        }

        // 101xxxx: the global parameters (credentials, user name, command), checked before any work.
        foreach ($numbers as $number) {
            if ($number >= 1_010_000 && $number < 1_020_000) {
                return new RegistrarException('Namecheap refused the API credentials or the request parameters ('.$number.'). Check the API user, key and user name in the registrar settings.');
            }
        }

        // Errors from the provider behind Namecheap (3xxxxxx to 5xxxxxx) may come after the work began.
        $mayHaveRun = array_intersect($numbers, self::UNKNOWN_ERRORS) !== [] || array_filter($numbers, fn (int $number): bool => $number >= 3_000_000 && $number < 6_000_000 && ! in_array($number, [3_019_166, 4_019_166], true)) !== [];

        if ($mutating && $mayHaveRun) {
            return new RegistrarTimeout('Namecheap reported a problem on its side ('.implode(', ', $numbers).'); the request may have been carried out.');
        }

        if (in_array(2_033_409, $numbers, true)) {
            return new NamecheapRefusal('The Namecheap account has no funds for this order; add funds there, then try again.', $numbers);
        }

        return new NamecheapRefusal('Namecheap refused the request ('.implode(', ', $numbers).'): '.$text, $numbers);
    }

    /**
     * The credentials for the account's mode: the sandbox set in test mode (live credentials are never sent to the
     * sandbox), the live set otherwise.
     *
     * @return array{ApiUser: string, ApiKey: string, UserName: string, ClientIp: string}
     */
    private static function credentials(RegistrarConfig $config): array
    {
        $prefix = $config->testMode ? 'sandbox_' : '';
        $user = $config->setting($prefix.'api_user');
        $key = $config->setting($prefix.'api_key');
        $name = $config->setting($prefix.'username');
        $ip = $config->setting('client_ip');

        if (! is_string($user) || trim($user) === '' || ! is_string($key) || $key === '') {
            throw new RegistrarException($config->testMode
                ? 'Enter the sandbox API user and sandbox API key in the Namecheap registrar settings (test mode is on).'
                : 'Enter the API user and API key in the Namecheap registrar settings.');
        }

        if (! is_string($ip) || filter_var(trim($ip), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RegistrarException('Enter the IPv4 address this server sends requests from (the address on the Namecheap API allow-list) as the client IP in the registrar settings.');
        }

        return ['ApiUser' => trim($user), 'ApiKey' => $key, 'UserName' => is_string($name) && trim($name) !== '' ? trim($name) : trim($user), 'ClientIp' => trim($ip)];
    }

    /**
     * Count one call against the local budget. A read stops WRITE_RESERVE calls before the limit, so changes still get
     * through when lookups have used the rest; nothing is sent when the budget is spent.
     *
     * @throws RegistrarException
     */
    private function spend(string $apiUser, bool $testMode, bool $mutating): void
    {
        $key = 'namecheap:'.($testMode ? 'sandbox:' : 'live:').sha1($apiUser);

        foreach (self::BUDGET as $seconds => $limit) {
            $allowed = $mutating ? $limit : $limit - self::WRITE_RESERVE[$seconds];

            if (RateLimiter::attempts($key.':'.$seconds) >= $allowed) {
                throw new RegistrarException('The Namecheap request budget for this '.match ($seconds) {
                    60 => 'minute', 3600 => 'hour', default => 'day',
                }.' is used up'.($mutating ? '' : ' (the rest is kept for registrations, renewals and other changes)').'; nothing was sent, try again later.', retryable: true);
            }
        }

        foreach (array_keys(self::BUDGET) as $seconds) {
            RateLimiter::hit($key.':'.$seconds, $seconds);
        }
    }

    /**
     * The call's time limit: its own limit, cut to what is left of the budget. A change that would get less than
     * 10 s after the read-back reserve, or a read with nothing left, is refused before anything is sent.
     *
     * @throws RegistrarException
     */
    private function timeoutFor(string $command, bool $mutating): int
    {
        $long = in_array($command, self::LONG_CALLS, true);
        $own = $long ? $this->timeoutSeconds : min($this->shortTimeoutSeconds, $this->timeoutSeconds);

        if ($this->deadline === null) {
            return max(1, $own);
        }

        $left = (int) floor($this->deadline - microtime(true));
        $allowed = $mutating && $long ? $left - self::READ_BACK_RESERVE : $left;

        if ($allowed < ($mutating ? 10 : 1)) {
            throw new RegistrarException('The time allowed for this submission ran out before Namecheap was asked; nothing was sent, try again.', retryable: true);
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
            throw new RegistrarException('The Namecheap outbound proxy must be an http://, https:// or socks5h:// address; nothing was sent.');
        }

        try {
            app(OutboundAddressGuard::class)->resolve($host, (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 1080)));
        } catch (OutboundAddressRefused $refused) {
            throw new RegistrarException('The Namecheap outbound proxy address is refused by this install\'s outbound policy; nothing was sent.', previous: $refused);
        }

        return $proxy;
    }
}
