<?php

namespace App\Modules\Domains\Services;

use App\Contracts\Domains\Registrar\TestsRegistrarConnection;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\RegistrarResult;
use App\Data\Domains\Registrar\SubmissionLookup;
use App\Data\Domains\Registrar\TransferSyncResult;
use App\Data\Integrations\ConnectionCheck;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarCommandLog;
use App\Support\Domains\CapabilityNotSupported;
use App\Support\Domains\RegistrarDriverRegistry;
use App\Support\Domains\RegistrarException;
use App\Support\Domains\RegistrarTimeout;
use App\Support\Integrations\Redactor;
use Closure;
use Error;
use Exception;
use Illuminate\Support\Facades\Auth;
use JsonSerializable;
use LogicException;
use ReflectionProperty;
use Throwable;

/**
 * The only way to call a registrar driver: refuses disabled registrars and unsupported capabilities,
 * times the call and writes registrar_command_log with secrets redacted and responses truncated.
 *
 * Error text a driver raises is masked (secret pairs by name, and the account's own configured credentials by value)
 * before it is logged or re-thrown, so callers can store it in last_error, the activity log and events. The re-thrown
 * exception keeps its class, retry flag and previous chain; only the messages along the chain are masked. What a
 * driver answers (result and lookup messages, transfer reasons, connection-test text) is masked the same way.
 *
 * Failure semantics for the non-idempotent commands (register, transfer, renew, restore): only a
 * RegistrarException(retryable: true) from the driver means "not executed, safe to retry". Any other
 * unexpected error (read timeout after sending, response parse error) becomes a RegistrarTimeout: the
 * outcome is unknown and the caller must verify with the registrar before anything is re-sent.
 */
class RegistrarGateway
{
    public const MAX_RESPONSE_BYTES = 16384;

    /** Commands that change state at the registry and must never be blindly repeated. */
    public const MUTATING = ['register', 'transfer', 'renew', 'restore'];

    public function __construct(private RegistrarDriverRegistry $drivers, private RegistrarManager $accounts, private Redactor $redactor) {}

    /**
     * Run $call against the driver of $registrarKey and its account after checking $capability, whose interface
     * (its entry in RegistrarDriverRegistry::capabilities()) the closure receives the driver as.
     *
     * @template TDriver of object
     * @template T
     *
     * @param  class-string<TDriver>  $interface
     * @param  Closure(TDriver, RegistrarConfig): T  $call
     * @param  array<string, mixed>  $request
     * @param  bool  $logSuccess  false for high-volume read calls (public lookups): only failures are logged
     * @param  list<string>  $secrets  secret values the call's request carries (RegistrationRequest::secrets()), masked
     *                                 with the account's credentials in everything the call returns, raises or logs
     * @return T
     *
     * @throws RegistrarException
     */
    public function call(string $registrarKey, string $capability, string $interface, Closure $call, ?Domain $domain = null, array $request = [], ?int $staffId = null, bool $logSuccess = true, array $secrets = []): mixed
    {
        if (! $this->accounts->isActive($registrarKey)) {
            throw new RegistrarException("The {$this->drivers->get($registrarKey)->name()} registrar is disabled; enable it under Registrars first.");
        }

        return $this->run($registrarKey, $capability, $interface, $call, $domain, $request, $staffId, $logSuccess, $secrets);
    }

    /**
     * Test the account's connection, logged and masked like every other call. Unlike call() it also runs while the
     * registrar is disabled, so staff can check new credentials before enabling them.
     *
     * @throws RegistrarException
     */
    public function testConnection(string $registrarKey): ConnectionCheck
    {
        return $this->run($registrarKey, RegistrarDriverRegistry::TEST_CONNECTION, TestsRegistrarConnection::class, fn (TestsRegistrarConnection $driver, RegistrarConfig $config): ConnectionCheck => $driver->testConnection($config));
    }

    /**
     * @template TDriver of object
     * @template T
     *
     * @param  class-string<TDriver>  $interface
     * @param  Closure(TDriver, RegistrarConfig): T  $call
     * @param  array<string, mixed>  $request
     * @param  list<string>  $secrets
     * @return T
     *
     * @throws RegistrarException
     */
    private function run(string $registrarKey, string $capability, string $interface, Closure $call, ?Domain $domain = null, array $request = [], ?int $staffId = null, bool $logSuccess = true, array $secrets = []): mixed
    {
        $driver = $this->drivers->get($registrarKey);

        if (($this->drivers->capabilities()[$capability] ?? null) !== $interface) {
            throw new LogicException("The registrar capability [{$capability}] is not implemented through {$interface}.");
        }

        if (! $driver instanceof $interface) {
            throw new CapabilityNotSupported($capability, $driver->name());
        }

        $started = hrtime(true);
        $staffId ??= $this->currentStaffId();
        $config = $this->accounts->config($registrarKey);
        $secrets = [...$config->secrets(), ...array_values(array_filter($secrets, fn (string $secret): bool => $secret !== ''))];

        try {
            $result = $this->maskResult($call($driver, $config), $secrets);

            if ($logSuccess) {
                $this->record($registrarKey, $capability, $domain, $request, $this->normalise($result), true, null, $started, $staffId, $secrets);
            }

            return $result;
        } catch (RegistrarException $exception) {
            $error = $this->redactor->maskValues($exception->getMessage(), $secrets);
            $this->record($registrarKey, $capability, $domain, $request, null, false, $error, $started, $staffId, $secrets);

            throw $this->maskChain($exception, $secrets);
        } catch (Throwable $exception) {
            $error = $this->redactor->maskValues($exception->getMessage(), $secrets);
            $this->record($registrarKey, $capability, $domain, $request, null, false, $error, $started, $staffId, $secrets);
            $previous = $this->maskChain($exception, $secrets);

            if (in_array($capability, self::MUTATING, true)) {
                throw new RegistrarTimeout("Unexpected registrar error; the {$capability} outcome is unknown: {$error}", $previous);
            }

            throw new RegistrarException('The registrar call failed: '.$error, retryable: true, previous: $previous);
        }
    }

    /**
     * Run $call against the domain's own registrar.
     *
     * @template TDriver of object
     * @template T
     *
     * @param  class-string<TDriver>  $interface
     * @param  Closure(TDriver, RegistrarConfig): T  $call
     * @param  array<string, mixed>  $request
     * @param  list<string>  $secrets  secret values the request carries (see call())
     * @return T
     */
    public function forDomain(Domain $domain, string $capability, string $interface, Closure $call, array $request = [], ?int $staffId = null, array $secrets = []): mixed
    {
        if ($domain->registrar_key === null) {
            throw new CapabilityNotSupported($capability, 'manual');
        }

        return $this->call($domain->registrar_key, $capability, $interface, $call, $domain, ['domain' => $domain->name, ...$request], $staffId, secrets: $secrets);
    }

    /**
     * Whether the domain's registrar supports a capability (false for manual domains).
     */
    public function supports(Domain $domain, string $capability): bool
    {
        return $domain->registrar_key !== null && $this->drivers->supports($domain->registrar_key, $capability);
    }

    /**
     * Mask the message of the exception and of every exception behind it, in place, so the re-thrown error keeps its
     * class, retry flag, trace and `previous` while nothing it carries to the log or to staff holds a credential.
     *
     * @template E of Throwable
     *
     * @param  E  $exception
     * @param  list<string>  $secrets
     * @return E
     */
    private function maskChain(Throwable $exception, array $secrets): Throwable
    {
        for ($link = $exception, $hops = 0; $link !== null && $hops < 16; $link = $link->getPrevious(), $hops++) {
            $masked = $this->redactor->maskValues($link->getMessage(), $secrets);

            if ($masked !== $link->getMessage()) {
                (new ReflectionProperty($link instanceof Exception ? Exception::class : Error::class, 'message'))->setValue($link, $masked);
            }
        }

        return $exception;
    }

    /**
     * The driver's answer with every staff-facing text masked (ADR 0009 Amendment 2): callers store and show result and
     * lookup messages, transfer failure reasons and connection-test text as they get them.
     *
     * @template T
     *
     * @param  T  $result
     * @param  list<string>  $secrets
     * @return T
     */
    private function maskResult(mixed $result, array $secrets): mixed
    {
        $mask = fn (string $text): string => $this->redactor->maskValues($text, $secrets);

        return match (true) {
            ($result instanceof RegistrarResult || $result instanceof SubmissionLookup) && $result->message !== null => $result->withMessage($mask($result->message)),
            $result instanceof TransferSyncResult && $result->reason !== null => $result->withReason($mask($result->reason)),
            $result instanceof ConnectionCheck => $result->mapText($mask),
            default => $result,
        };
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>|null  $response
     * @param  list<string>  $secrets
     */
    private function record(string $registrarKey, string $command, ?Domain $domain, array $request, ?array $response, bool $success, ?string $error, int|float $started, ?int $staffId, array $secrets = []): void
    {
        RegistrarCommandLog::query()->create([
            'domain_id' => $domain?->id,
            'registrar_key' => $registrarKey,
            'command' => $command,
            'request' => $this->redactor->redact($request, $secrets, self::codeKeys($command)),
            'response' => $response === null ? null : $this->truncate($this->redactor->redact($response, $secrets, self::codeKeys($command))),
            'success' => $success,
            'error' => $error === null ? null : $this->redactor->maskValues($error, $secrets),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'staff_id' => $staffId,
        ]);
    }

    /**
     * In EPP code and transfer calls a bare `code` key is the domain's transfer secret; elsewhere it is an error code.
     *
     * @return list<string>
     */
    private static function codeKeys(string $command): array
    {
        return in_array($command, ['epp', 'transfer'], true) ? ['code'] : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalise(mixed $result): ?array
    {
        if ($result === null) {
            return null;
        }

        if (is_object($result) && method_exists($result, 'toArray')) {
            $array = $result->toArray();
        } elseif ($result instanceof JsonSerializable) {
            $array = (array) $result->jsonSerialize();
        } elseif (is_array($result)) {
            $array = $result;
        } else {
            $array = is_object($result) ? get_object_vars($result) : ['value' => $result];
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) json_encode($array), true) ?: [];

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function truncate(array $response): array
    {
        $json = (string) json_encode($response);

        return strlen($json) <= self::MAX_RESPONSE_BYTES
            ? $response
            : ['truncated' => true, 'excerpt' => substr($json, 0, self::MAX_RESPONSE_BYTES - 100)];
    }

    private function currentStaffId(): ?int
    {
        $user = Auth::user();

        return $user !== null && (bool) $user->getAttribute('is_staff') ? (int) $user->getAuthIdentifier() : null;
    }
}
