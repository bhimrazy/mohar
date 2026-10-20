<?php

namespace App\Modules\Domains\Services;

use App\Modules\Domains\Drivers\Registrars\Registrar;
use App\Modules\Domains\Drivers\Registrars\RegistrarManager;
use App\Modules\Domains\Exceptions\CapabilityNotSupported;
use App\Modules\Domains\Exceptions\RegistrarException;
use App\Modules\Domains\Exceptions\RegistrarTimeout;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Modules\Domains\Models\RegistrarCommandLog;
use App\Support\Integrations\Redactor;
use Closure;
use Illuminate\Support\Facades\Auth;
use JsonSerializable;
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

    public function __construct(private RegistrarManager $registrars, private Redactor $redactor) {}

    /**
     * Run $call against the driver of $registrarKey after checking $capability.
     *
     * @template T
     *
     * @param  Closure(Registrar): T  $call
     * @param  array<string, mixed>  $request
     * @param  bool  $logSuccess  false for high-volume read calls (public lookups): only failures are logged
     * @return T
     *
     * @throws RegistrarException
     */
    public function call(string $registrarKey, string $capability, Closure $call, ?Domain $domain = null, array $request = [], ?int $staffId = null, bool $logSuccess = true): mixed
    {
        $driver = $this->registrars->driver($registrarKey);

        if (! $this->registrars->isActive($registrarKey)) {
            throw new RegistrarException("The {$driver->name()} registrar is disabled; enable it under Registrars first.");
        }

        if (! in_array($capability, $driver->capabilities(), true)) {
            throw new CapabilityNotSupported($capability, $driver->name());
        }

        $started = hrtime(true);
        $staffId ??= $this->currentStaffId();
        $secrets = $this->secretValues($registrarKey);

        try {
            $result = $call($driver);

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
     * @template T
     *
     * @param  Closure(Registrar): T  $call
     * @param  array<string, mixed>  $request
     * @return T
     */
    public function forDomain(Domain $domain, string $capability, Closure $call, array $request = [], ?int $staffId = null): mixed
    {
        if ($domain->registrar_key === null) {
            throw new CapabilityNotSupported($capability, 'manual');
        }

        return $this->call($domain->registrar_key, $capability, $call, $domain, ['domain' => $domain->name, ...$request], $staffId);
    }

    /**
     * Whether the domain's registrar supports a capability (false for manual domains).
     */
    public function supports(Domain $domain, string $capability): bool
    {
        return in_array($capability, $this->registrars->capabilitiesOf($domain->registrar_key), true);
    }

    /**
     * The registrar account's configured credentials (its secret settings), masked by value wherever they appear.
     *
     * @return list<string>
     */
    private function secretValues(string $registrarKey): array
    {
        $settings = RegistrarAccount::query()->where('key', $registrarKey)->first()->settings ?? [];
        $secrets = [];

        foreach ($this->registrars->fields($registrarKey) as $field) {
            if ($field->isSecret() && is_string($settings[$field->key] ?? null) && $settings[$field->key] !== '') {
                $secrets[] = $settings[$field->key];
            }
        }

        return $secrets;
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
