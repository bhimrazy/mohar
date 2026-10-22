<?php

namespace App\Modules\Domains\Services;

use App\Data\Domains\Registrar\RegistrarConfig;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarCommandLog;
use App\Support\Domains\CapabilityNotSupported;
use App\Support\Domains\RegistrarDriverRegistry;
use App\Support\Domains\RegistrarException;
use App\Support\Domains\RegistrarTimeout;
use App\Support\Integrations\Redactor;
use Closure;
use Illuminate\Support\Facades\Auth;
use JsonSerializable;
use LogicException;
use Throwable;

/**
 * The only way to call a registrar driver: refuses disabled registrars and unsupported capabilities,
 * times the call and writes registrar_command_log with secrets redacted and responses truncated.
 *
 * Error text a driver raises is masked (secret pairs by name, and the account's own configured credentials by value)
 * before it is logged or re-thrown, so callers can store it in last_error, the activity log and events.
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
     * @return T
     *
     * @throws RegistrarException
     */
    public function call(string $registrarKey, string $capability, string $interface, Closure $call, ?Domain $domain = null, array $request = [], ?int $staffId = null, bool $logSuccess = true): mixed
    {
        $driver = $this->drivers->get($registrarKey);

        if (! $this->accounts->isActive($registrarKey)) {
            throw new RegistrarException("The {$driver->name()} registrar is disabled; enable it under Registrars first.");
        }

        if (($this->drivers->capabilities()[$capability] ?? null) !== $interface) {
            throw new LogicException("The registrar capability [{$capability}] is not implemented through {$interface}.");
        }

        if (! $driver instanceof $interface) {
            throw new CapabilityNotSupported($capability, $driver->name());
        }

        $started = hrtime(true);
        $staffId ??= $this->currentStaffId();
        $config = $this->accounts->config($registrarKey);
        $secrets = $config->secrets();

        try {
            $result = $call($driver, $config);

            if ($logSuccess) {
                $this->record($registrarKey, $capability, $domain, $request, $this->normalise($result), true, null, $started, $staffId, $secrets);
            }

            return $result;
        } catch (RegistrarException $exception) {
            $error = $this->redactor->maskValues($exception->getMessage(), $secrets);
            $this->record($registrarKey, $capability, $domain, $request, null, false, $error, $started, $staffId, $secrets);

            throw match (true) {
                $error === $exception->getMessage() => $exception,
                $exception instanceof RegistrarTimeout => new RegistrarTimeout($error),
                default => new RegistrarException($error, $exception->retryable),
            };
        } catch (Throwable $exception) {
            $error = $this->redactor->maskValues($exception->getMessage(), $secrets);
            $this->record($registrarKey, $capability, $domain, $request, null, false, $error, $started, $staffId, $secrets);

            if (in_array($capability, self::MUTATING, true)) {
                throw new RegistrarTimeout("Unexpected registrar error; the {$capability} outcome is unknown: {$error}");
            }

            throw new RegistrarException('The registrar call failed: '.$error, retryable: true);
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
     * @return T
     */
    public function forDomain(Domain $domain, string $capability, string $interface, Closure $call, array $request = [], ?int $staffId = null): mixed
    {
        if ($domain->registrar_key === null) {
            throw new CapabilityNotSupported($capability, 'manual');
        }

        return $this->call($domain->registrar_key, $capability, $interface, $call, $domain, ['domain' => $domain->name, ...$request], $staffId);
    }

    /**
     * Whether the domain's registrar supports a capability (false for manual domains).
     */
    public function supports(Domain $domain, string $capability): bool
    {
        return $domain->registrar_key !== null && $this->drivers->supports($domain->registrar_key, $capability);
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
