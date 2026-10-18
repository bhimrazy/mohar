<?php

namespace App\Data\Services\Provisioning;

use App\Enums\DriverOutcome;
use InvalidArgumentException;

/**
 * Answer of a provisioning driver call. Use the named constructors: ok() (completed), fail() (rejected, or retryable
 * with `retryable: true`) and unknown(). Provisioning has no `pending` outcome yet; a later trailing flag would add it.
 *
 * $data is the only way a driver reports changes back, with the documented keys `username`, `password`,
 * `dedicated_ip` (strings) and `driver_data` (an array merged into the service's driver data). $request and $response
 * are what was sent and answered; the host redacts them before they reach the module log.
 */
final readonly class DriverResult
{
    /** Keys the host reads from $data. */
    public const DATA_KEYS = ['username', 'password', 'dedicated_ip', 'driver_data'];

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $response
     */
    public function __construct(
        public bool $success,
        public ?string $message = null,
        public bool $retryable = false,
        #[\SensitiveParameter]
        public array $data = [],
        public array $request = [],
        public array $response = [],
        public bool $outcomeUnknown = false,
    ) {
        if ($success && ($retryable || $outcomeUnknown)) {
            throw new InvalidArgumentException('A successful driver result cannot be retryable or of unknown outcome.');
        }

        if ($retryable && $outcomeUnknown) {
            throw new InvalidArgumentException('A driver result of unknown outcome must not be retried.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $response
     */
    public static function ok(?string $message = null, array $data = [], array $request = [], array $response = []): self
    {
        return new self(true, $message, data: $data, request: $request, response: $response);
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $response
     */
    public static function fail(string $message, bool $retryable = false, array $request = [], array $response = []): self
    {
        return new self(false, $message, $retryable, request: $request, response: $response);
    }

    /**
     * The request may have been executed: the host opens a "verify" item instead of retrying.
     *
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $response
     */
    public static function unknown(string $message, array $request = [], array $response = []): self
    {
        return new self(false, $message, request: $request, response: $response, outcomeUnknown: true);
    }

    public function outcome(): DriverOutcome
    {
        return match (true) {
            $this->success => DriverOutcome::Completed,
            $this->outcomeUnknown => DriverOutcome::Unknown,
            $this->retryable => DriverOutcome::Retryable,
            default => DriverOutcome::Rejected,
        };
    }
}
