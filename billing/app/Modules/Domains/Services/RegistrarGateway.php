<?php

namespace App\Modules\Domains\Services;

use App\Modules\Domains\Drivers\Registrars\Registrar;
use App\Modules\Domains\Drivers\Registrars\RegistrarManager;
use App\Modules\Domains\Exceptions\CapabilityNotSupported;
use App\Modules\Domains\Exceptions\RegistrarException;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarCommandLog;
use Closure;
use Illuminate\Support\Facades\Auth;
use JsonSerializable;
use Throwable;

/**
 * The only way to call a registrar driver: checks the capability, times the call and writes
 * registrar_command_log with secrets redacted and responses truncated.
 */
class RegistrarGateway
{
    public const MAX_RESPONSE_BYTES = 16384;

    private const SECRET_KEYS = ['epp_code', 'eppcode', 'auth_code', 'authcode', 'code', 'password', 'api_key', 'apikey', 'secret', 'token'];

    public function __construct(private RegistrarManager $registrars) {}

    /**
     * Run $call against the driver of $registrarKey after checking $capability.
     *
     * @template T
     *
     * @param  Closure(Registrar): T  $call
     * @param  array<string, mixed>  $request
     * @return T
     *
     * @throws RegistrarException
     */
    public function call(string $registrarKey, string $capability, Closure $call, ?Domain $domain = null, array $request = [], ?int $staffId = null): mixed
    {
        $driver = $this->registrars->driver($registrarKey);

        if (! in_array($capability, $driver->capabilities(), true)) {
            throw new CapabilityNotSupported($capability, $driver->name());
        }

        $started = hrtime(true);
        $staffId ??= $this->currentStaffId();

        try {
            $result = $call($driver);
            $this->record($registrarKey, $capability, $domain, $request, $this->normalise($result), true, null, $started, $staffId);

            return $result;
        } catch (RegistrarException $exception) {
            $this->record($registrarKey, $capability, $domain, $request, null, false, $exception->getMessage(), $started, $staffId);

            throw $exception;
        } catch (Throwable $exception) {
            $this->record($registrarKey, $capability, $domain, $request, null, false, $exception->getMessage(), $started, $staffId);

            throw new RegistrarException('The registrar call failed: '.$exception->getMessage(), retryable: true, previous: $exception);
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
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::redact($value);
            } elseif (in_array(strtolower((string) $key), self::SECRET_KEYS, true) && $value !== null && $value !== '') {
                $data[$key] = '[redacted]';
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>|null  $response
     */
    private function record(string $registrarKey, string $command, ?Domain $domain, array $request, ?array $response, bool $success, ?string $error, int|float $started, ?int $staffId): void
    {
        RegistrarCommandLog::query()->create([
            'domain_id' => $domain?->id,
            'registrar_key' => $registrarKey,
            'command' => $command,
            'request' => self::redact($request),
            'response' => $response === null ? null : $this->truncate(self::redact($response)),
            'success' => $success,
            'error' => $error,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'staff_id' => $staffId,
        ]);
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
