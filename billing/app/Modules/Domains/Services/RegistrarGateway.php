<?php

namespace App\Modules\Domains\Services;

use App\Contracts\Domains\Registrar\TestsRegistrarConnection;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\RegistrarResult;
use App\Data\Domains\Registrar\SubmissionLookup;
use App\Data\Domains\Registrar\TransferSyncResult;
use App\Data\Integrations\ConnectionCheck;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarAccount;
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
        $account = $this->accounts->account($registrarKey);

        if (! $this->accounts->isActiveFor($registrarKey, $account)) {
            $name = $this->drivers->get($registrarKey)->name();

            throw new RegistrarException($account !== null
                ? "The {$name} registrar is disabled; enable it under Registrars first."
                : "The {$name} registrar has no saved account; save its account under Registrars first.");
        }

        return $this->run($account, $registrarKey, $capability, $interface, $call, $domain, $request, $staffId, $logSuccess, $secrets);
    }

    /**
     * Test the account's connection, logged and masked like every other call. Unlike call() it also runs while the
     * registrar is disabled, so staff can check new credentials before enabling them.
     *
     * @throws RegistrarException
     */
    public function testConnection(string $registrarKey): ConnectionCheck
    {
        return $this->run($this->accounts->account($registrarKey), $registrarKey, RegistrarDriverRegistry::TEST_CONNECTION, TestsRegistrarConnection::class, fn (TestsRegistrarConnection $driver, RegistrarConfig $config): ConnectionCheck => $driver->testConnection($config));
    }

    /**
     * @template TDriver of object
     * @template T
     *
     * @param  class-string<TDriver>  $interface
     * @param  Closure(TDriver, RegistrarConfig): T  $call
     * @param  array<string, mixed>  $request
     * @param  list<string>  $secrets
     * @param  RegistrarAccount|null  $account  the saved account, already loaded by the caller (null: none saved)
     * @return T
     *
     * @throws RegistrarException
     */
    private function run(?RegistrarAccount $account, string $registrarKey, string $capability, string $interface, Closure $call, ?Domain $domain = null, array $request = [], ?int $staffId = null, bool $logSuccess = true, array $secrets = []): mixed
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
        $config = $this->accounts->configFor($registrarKey, $account);
        $secrets = [...$config->secrets(), ...array_values(array_filter($secrets, fn (string $secret): bool => $secret !== ''))];

        try {
            // Keys the driver declares secret in this call's payloads: masked by key in the log, and their values in its
            // text. Read before the call: a driver that cannot name them fails here, before anything is sent.
            $declared = Redactor::declaredKeys($driver, $capability);
        } catch (Throwable $exception) {
            $error = $this->redactor->maskValues("The {$driver->name()} driver could not name its sensitive keys; nothing was sent: {$exception->getMessage()}", $secrets);
            $this->record($registrarKey, $capability, $domain, $request, null, false, $error, $started, $staffId, $secrets, self::codeKeys($capability), null);

            throw new RegistrarException($error, previous: $this->maskChain($exception, fn (string $text): string => $this->redactor->maskValues($text, $secrets)));
        }

        $keys = [...self::codeKeys($capability), ...$declared];
        $values = $this->redactor->valuesUnder($declared, $request);
        // By reference: the answer's values join the request's once the call returns.
        $mask = function (string $text) use (&$values, $secrets): string {
            return $this->redactor->maskDeclared($text, $values, $secrets);
        };

        try {
            $answer = $call($driver, $config);

            if ($declared !== [] && $values !== null) {
                $answerValues = $this->redactor->valuesUnder($declared, $this->normalise($answer) ?? []);
                $values = $answerValues === null ? null : [...$values, ...$answerValues];
            }

            $result = $this->maskResult($answer, $mask);

            if ($logSuccess) {
                $this->record($registrarKey, $capability, $domain, $request, $this->normalise($result), true, null, $started, $staffId, $secrets, $keys, $values);
            }

            return $result;
        } catch (RegistrarException $exception) {
            $values = $this->withValuesIn($values, $declared, $exception);
            $error = $mask($exception->getMessage());
            $this->record($registrarKey, $capability, $domain, $request, null, false, $error, $started, $staffId, $secrets, $keys, $values);

            throw $this->maskChain($exception, $mask);
        } catch (Throwable $exception) {
            $values = $this->withValuesIn($values, $declared, $exception);
            $error = $mask($exception->getMessage());
            $this->record($registrarKey, $capability, $domain, $request, null, false, $error, $started, $staffId, $secrets, $keys, $values);
            $previous = $this->maskChain($exception, $mask);

            if (in_array($capability, self::MUTATING, true)) {
                throw new RegistrarTimeout("Unexpected registrar error; the {$capability} outcome is unknown: {$error}", $previous);
            }

            throw new RegistrarException('The registrar call failed: '.$error, retryable: true, previous: $previous);
        }
    }

    /**
     * $values plus the declared values in the markup of the exception's message (a driver may throw the answer it got);
     * null stays null (withheld).
     *
     * @param  list<string>|null  $values
     * @param  list<string>  $declared
     * @return list<string>|null
     */
    private function withValuesIn(?array $values, array $declared, Throwable $exception): ?array
    {
        if ($values === null || $declared === []) {
            return $values;
        }

        $found = $this->redactor->valuesUnder($declared, ['message' => $exception->getMessage()]);

        return $found === null ? null : [...$values, ...$found];
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
     * @param  Closure(string): string  $mask
     * @return E
     */
    private function maskChain(Throwable $exception, Closure $mask): Throwable
    {
        for ($link = $exception, $hops = 0; $link !== null && $hops < 16; $link = $link->getPrevious(), $hops++) {
            $masked = $mask($link->getMessage());

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
     * @param  Closure(string): string  $mask
     * @return T
     */
    private function maskResult(mixed $result, Closure $mask): mixed
    {
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
     * @param  list<string>  $sensitiveKeys  extra keys secret in this command's payloads
     * @param  list<string>|null  $declaredValues  the values under the driver's declared keys (Redactor::valuesUnder())
     */
    private function record(string $registrarKey, string $command, ?Domain $domain, array $request, ?array $response, bool $success, ?string $error, int|float $started, ?int $staffId, array $secrets, array $sensitiveKeys, ?array $declaredValues): void
    {
        RegistrarCommandLog::query()->create([
            'domain_id' => $domain?->id,
            'registrar_key' => $registrarKey,
            'command' => $command,
            'request' => $this->redactor->redactDeclared($request, $declaredValues, $secrets, $sensitiveKeys),
            'response' => $response === null ? null : $this->truncate($this->redactor->redactDeclared($response, $declaredValues, $secrets, $sensitiveKeys)),
            'success' => $success,
            'error' => $error === null ? null : $this->redactor->maskValues($error, $secrets),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'staff_id' => $staffId,
        ]);
    }

    /**
     * In EPP code and transfer calls a bare `code` key is the domain's transfer secret; elsewhere it is an error code.
     * Built in for every driver; a driver adds its own keys through DeclaresSensitiveKeys.
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
