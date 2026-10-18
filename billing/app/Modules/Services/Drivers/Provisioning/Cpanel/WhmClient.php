<?php

namespace App\Modules\Services\Drivers\Provisioning\Cpanel;

use App\Data\Integrations\ResolvedAddress;
use App\Data\Services\Provisioning\ServerConnection;
use App\Enums\DriverOutcome;
use App\Support\Integrations\OutboundAddressGuard;
use App\Support\Integrations\OutboundAddressRefused;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;

/**
 * WHM API 1 transport for one driver operation (spec §2): `https://{host}:{port}/json-api/{function}?api.version=1`,
 * an API token (`Authorization: whm user:token`) or HTTP Basic, TLS always verified or pinned to an SPKI hash, every
 * connection checked and pinned by the outbound address guard (ADR 0015), no redirects, capped bodies.
 *
 * Mutating calls are form-encoded POSTs, so passwords stay out of the panel's access log. A failure is a WhmFailure
 * mapped to an outcome (§5): a mutating call that may have run is unknown, a read is retryable.
 *
 * Holds the request and response log of the one operation it serves; secret parameters never enter the log.
 */
final class WhmClient
{
    public const DEFAULT_PORT = 2087;

    /** Plain-HTTP cPanel and WHM ports: credentials would cross the network unencrypted. */
    public const PLAIN_PORTS = [80, 2082, 2086, 2095];

    public const LOGIN_TOKEN = 'token';

    public const LOGIN_PASSWORD = 'password';

    private const CONNECT_TIMEOUT = 10;

    private const READ_TIMEOUT = 20;

    /** Calls that do real work on the server and get the longer read timeout. */
    private const LONG_CALLS = ['createacct', 'removeacct', 'changepackage'];

    private const LONG_TIMEOUT = 50;

    private const MAX_BODY_BYTES = 5 * 1024 * 1024;

    private const LOG_OUTPUT_BYTES = 4096;

    private const SECRET_PARAMS = ['password', 'pass'];

    /** cURL errors raised by the TLS layer: nothing was sent, and retrying cannot fix a certificate. */
    private const TLS_ERRORS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 91];

    /** @var list<array{function: string, method: string, params: array<string, scalar>}> */
    private array $requests = [];

    /** @var list<array<string, mixed>> */
    private array $responses = [];

    private ?ResolvedAddress $address = null;

    /** Set when the pre-request callback refused the presented key. */
    private bool $pinMismatch = false;

    /**
     * @param  float|null  $deadline  microtime(true) by which every call of the operation must have finished; each call's
     *                                timeout is cut to the time left, and a call with no time left is not sent
     */
    public function __construct(
        private readonly ServerConnection $server,
        private readonly OutboundAddressGuard $guard,
        private readonly ?float $deadline = null,
    ) {}

    public static function connectionUser(ServerConnection $server): string
    {
        return $server->username !== null && $server->username !== '' ? $server->username : 'root';
    }

    public static function loginMethod(ServerConnection $server): string
    {
        return ($server->settings['login_method'] ?? self::LOGIN_TOKEN) === self::LOGIN_PASSWORD ? self::LOGIN_PASSWORD : self::LOGIN_TOKEN;
    }

    /**
     * The trusted SPKI SHA-256 (base64), or null when the certificate is verified against the CA chain and hostname.
     */
    public static function pin(ServerConnection $server): ?string
    {
        $pin = trim((string) ($server->settings['spki_pin'] ?? ''));
        $pin = str_starts_with($pin, 'sha256//') ? substr($pin, 8) : $pin;

        return $pin === '' ? null : $pin;
    }

    public static function validPin(string $pin): bool
    {
        return preg_match('#^[A-Za-z0-9+/]{43}=$#', $pin) === 1;
    }

    /**
     * The name the connection goes to: the hostname, or the IP only when no hostname is set (then a pin is required).
     */
    public function host(): string
    {
        $hostname = trim($this->server->hostname);

        return $hostname !== '' ? $hostname : (string) $this->server->ip;
    }

    public function port(): int
    {
        return $this->server->port ?? self::DEFAULT_PORT;
    }

    /**
     * Check the destination once per operation (address policy, port policy) and remember the checked IP.
     *
     * @throws WhmFailure
     */
    public function address(): ResolvedAddress
    {
        if ($this->address !== null) {
            return $this->address;
        }

        $port = $this->port();

        if (in_array($port, self::PLAIN_PORTS, true) || ($port < 1024 && $port !== 443)) {
            throw WhmFailure::rejected("Port {$port} is not allowed for WHM: use 2087 (or 443 behind a proxy), so credentials always travel over TLS.", WhmFailure::CONFIG);
        }

        if ($this->host() === '') {
            throw WhmFailure::rejected('The server has no hostname.', WhmFailure::CONFIG);
        }

        if (trim($this->server->hostname) === '' && self::pin($this->server) === null) {
            throw WhmFailure::rejected('Connecting by IP address needs a trusted certificate: run Test connection and trust the certificate, or set a hostname.', WhmFailure::CONFIG);
        }

        try {
            return $this->address = $this->guard->resolve($this->host(), $port);
        } catch (OutboundAddressRefused $exception) {
            throw WhmFailure::rejected('The server address is not allowed: '.$exception->getMessage(), WhmFailure::ADDRESS);
        }
    }

    /**
     * @param  array<string, scalar>  $params
     *
     * @throws WhmFailure
     */
    public function read(string $function, array $params = []): WhmResponse
    {
        return $this->call($function, $params, mutating: false);
    }

    /**
     * @param  array<string, scalar>  $params
     *
     * @throws WhmFailure
     */
    public function write(string $function, array $params = []): WhmResponse
    {
        return $this->call($function, $params, mutating: true);
    }

    /**
     * What was sent, without secret parameters.
     *
     * @return array<string, mixed>
     */
    public function requestLog(): array
    {
        return ['host' => $this->host(), 'port' => $this->port(), 'calls' => $this->requests];
    }

    /**
     * What came back: HTTP status, `metadata.result`, the raw reason and at most 4 KB of `metadata.output.raw`.
     *
     * @return array<string, mixed>
     */
    public function responseLog(): array
    {
        return ['calls' => $this->responses];
    }

    /**
     * @param  array<string, scalar>  $params
     *
     * @throws WhmFailure
     */
    private function call(string $function, array $params, bool $mutating): WhmResponse
    {
        $address = $this->address();
        $this->requests[] = [
            'function' => $function,
            'method' => $mutating ? 'POST' : 'GET',
            'params' => array_diff_key($params, array_flip(self::SECRET_PARAMS)),
        ];
        $url = sprintf('https://%s:%d/json-api/%s', str_contains($this->host(), ':') ? "[{$this->host()}]" : $this->host(), $this->port(), $function);

        try {
            $request = $this->guard->pin($this->pending($function), $address);
            $response = $mutating
                ? $request->asForm()->post($url.'?api.version=1', $params)
                : $request->get($url, ['api.version' => 1, ...$params]);
        } catch (ConnectionException $exception) {
            $this->responses[] = ['function' => $function, 'error' => $this->errorSummary($exception)];

            throw $this->transportFailure($exception, $mutating);
        }

        return $this->parse($function, $response, $mutating);
    }

    /**
     * @throws WhmFailure
     */
    private function pending(string $function): PendingRequest
    {
        $pin = self::pin($this->server);

        if ($pin !== null && ! self::validPin($pin)) {
            throw WhmFailure::rejected('The trusted certificate pin is not a valid SHA-256 fingerprint; trust the certificate again from Test connection.', WhmFailure::CONFIG);
        }

        $timeout = in_array($function, self::LONG_CALLS, true) ? self::LONG_TIMEOUT : self::READ_TIMEOUT;

        if ($this->deadline !== null) {
            $left = (int) floor($this->deadline - microtime(true));

            if ($left < 2) {
                throw new WhmFailure('The action ran out of time before '.$function.' could be sent; it will be retried.', DriverOutcome::Retryable, WhmFailure::TRANSPORT);
            }

            $timeout = min($timeout, $left);
        }

        $request = Http::acceptJson()
            ->connectTimeout(min(self::CONNECT_TIMEOUT, $timeout))
            ->timeout($timeout)
            ->withOptions([
                // Pinned: the handshake must present exactly this key; CA and hostname checks are replaced by it (§9.3).
                'verify' => $pin === null,
                'curl' => $pin === null ? [] : $this->pinCheck($pin),
                'on_headers' => function (ResponseInterface $response): void {
                    if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BODY_BYTES) {
                        throw new \RuntimeException('Response body over the size limit.');
                    }
                },
                'progress' => function (int $downloadTotal, int $downloaded): void {
                    if ($downloaded > self::MAX_BODY_BYTES) {
                        throw new \RuntimeException('Response body over the size limit.');
                    }
                },
            ]);

        $user = self::connectionUser($this->server);

        if (self::loginMethod($this->server) === self::LOGIN_PASSWORD) {
            if ((string) $this->server->password === '') {
                throw WhmFailure::rejected('The server has no password set.', WhmFailure::CONFIG);
            }

            return $request->withBasicAuth($user, (string) $this->server->password);
        }

        if ((string) $this->server->apiToken === '') {
            throw WhmFailure::rejected('The server has no API token set.', WhmFailure::CONFIG);
        }

        return $request->withHeaders(['Authorization' => "whm {$user}:{$this->server->apiToken}"]);
    }

    /**
     * Guzzle does not pass CURLOPT_PINNEDPUBLICKEY through, so the pin is checked in curl's pre-request callback: it
     * runs after the TLS handshake and before a byte of the request is sent, and aborting there fails closed (the
     * call ends with cURL error 42, reported as "server certificate changed").
     *
     * @return array<int, mixed>
     */
    private function pinCheck(string $pin): array
    {
        return [
            CURLOPT_CERTINFO => true,
            CURLOPT_PREREQFUNCTION => function (\CurlHandle $handle) use ($pin): int {
                $chain = curl_getinfo($handle, CURLINFO_CERTINFO);
                $leaf = isset($chain[0]['Cert']) ? openssl_pkey_get_public($chain[0]['Cert']) : false;
                $presented = $leaf === false ? null : CertificateInspector::spkiPin((string) (openssl_pkey_get_details($leaf)['key'] ?? ''));

                if ($presented !== null && hash_equals($pin, $presented)) {
                    return CURL_PREREQFUNC_OK;
                }

                $this->pinMismatch = true;

                return CURL_PREREQFUNC_ABORT;
            },
        ];
    }

    /**
     * @throws WhmFailure
     */
    private function parse(string $function, Response $response, bool $mutating): WhmResponse
    {
        $status = $response->status();
        $body = $response->body();
        $decoded = strlen($body) <= self::MAX_BODY_BYTES ? json_decode($body, true) : null;
        $metadata = is_array($decoded) && is_array($decoded['metadata'] ?? null) ? $decoded['metadata'] : null;
        $reason = mb_substr(trim(strip_tags((string) ($metadata['reason'] ?? ''))), 0, 1000);

        $this->responses[] = array_filter([
            'function' => $function,
            'http_status' => $status,
            'result' => $metadata['result'] ?? null,
            'reason' => $reason !== '' ? $reason : null,
            'output' => is_string($metadata['output']['raw'] ?? null) ? mb_strcut($metadata['output']['raw'], 0, self::LOG_OUTPUT_BYTES) : null,
            'body' => $metadata === null && $body !== '' ? mb_strcut(strip_tags($body), 0, 512) : null,
        ], fn (mixed $value): bool => $value !== null);

        $where = "{$this->host()}:{$this->port()}";

        if ($status >= 300 && $status < 400) {
            throw WhmFailure::rejected("{$where} answered with a redirect; check the hostname and port.", WhmFailure::HTTP);
        }

        if ($status === 401 || $status === 403) {
            throw WhmFailure::rejected($this->authMessage($body), WhmFailure::AUTH);
        }

        if ($status >= 500) {
            throw $this->uncertain("{$where} answered HTTP {$status}", $mutating, WhmFailure::HTTP);
        }

        if ($status >= 400) {
            throw WhmFailure::rejected("{$where} answered HTTP {$status}; check that the port leads to WHM.", WhmFailure::HTTP);
        }

        if ($metadata === null || ! array_key_exists('result', $metadata)) {
            throw $this->uncertain("{$where} did not answer like the WHM API", $mutating, WhmFailure::BODY);
        }

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        return new WhmResponse($function, (int) $metadata['result'] === 1, $reason, $data, $metadata);
    }

    private function authMessage(string $body): string
    {
        if (self::loginMethod($this->server) === self::LOGIN_PASSWORD && preg_match('/two[- ]factor|2fa|tfa/i', $body) === 1) {
            return 'WHM requires two-factor authentication for this login, so password sign-in cannot work: use an API token.';
        }

        return 'WHM refused the credentials: check the API token (or password), the username it belongs to, and its permissions (ACLs).';
    }

    private function transportFailure(ConnectionException $exception, bool $mutating): WhmFailure
    {
        $errno = $this->curlErrno($exception);
        $message = $exception->getMessage();
        $this->pinMismatch = $this->pinMismatch || $errno === 90;
        $where = "{$this->host()}:{$this->port()}";

        return match (true) {
            $this->pinMismatch => WhmFailure::rejected('Server certificate changed: it no longer matches the trusted certificate. Check the server, then trust the new certificate from Test connection.', WhmFailure::PIN),
            in_array($errno, [6, 7], true) => new WhmFailure("Could not connect to {$where} (connection refused or name not found).", DriverOutcome::Retryable, WhmFailure::TRANSPORT),
            $errno === 28 && preg_match('/Connection timed out|Resolving timed out/i', $message) === 1 => new WhmFailure("Could not connect to {$where} in time.", DriverOutcome::Retryable, WhmFailure::TRANSPORT),
            in_array($errno, self::TLS_ERRORS, true) => WhmFailure::rejected("The TLS connection to {$where} failed: the certificate is not trusted (self-signed, expired or for another name). Run Test connection to inspect and trust it.", WhmFailure::TLS),
            default => $this->uncertain("No complete answer from {$where}", $mutating, WhmFailure::TRANSPORT),
        };
    }

    private function uncertain(string $what, bool $mutating, string $kind): WhmFailure
    {
        return $mutating
            ? new WhmFailure("{$what}; the request may have run on the server.", DriverOutcome::Unknown, $kind)
            : new WhmFailure("{$what}.", DriverOutcome::Retryable, $kind);
    }

    private function curlErrno(ConnectionException $exception): ?int
    {
        $previous = $exception->getPrevious();
        $context = $previous !== null && method_exists($previous, 'getHandlerContext') ? $previous->getHandlerContext() : [];

        if (isset($context['errno']) && is_numeric($context['errno'])) {
            return (int) $context['errno'];
        }

        return preg_match('/cURL error (\d+)/', $exception->getMessage(), $match) === 1 ? (int) $match[1] : null;
    }

    private function errorSummary(ConnectionException $exception): string
    {
        $errno = $this->curlErrno($exception);

        return $errno === null ? 'connection error' : "cURL error {$errno}";
    }
}
