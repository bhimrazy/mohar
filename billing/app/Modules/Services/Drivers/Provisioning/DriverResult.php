<?php

namespace App\Modules\Services\Drivers\Provisioning;

/**
 * Outcome of a driver call.
 */
final readonly class DriverResult
{
    /**
     * @param  array<string, mixed>  $data  driver output (e.g. username/password/dedicated_ip/driver_data on create)
     * @param  array<string, mixed>  $request  what was sent to the remote system (redacted before logging)
     * @param  array<string, mixed>  $response  what the remote system answered (redacted before logging)
     */
    public function __construct(
        public bool $success,
        public ?string $message = null,
        public bool $retryable = false,
        public array $data = [],
        public array $request = [],
        public array $response = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $response
     */
    public static function ok(?string $message = null, array $data = [], array $request = [], array $response = []): self
    {
        return new self(true, $message, false, $data, $request, $response);
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $response
     */
    public static function fail(string $message, bool $retryable = false, array $request = [], array $response = []): self
    {
        return new self(false, $message, $retryable, [], $request, $response);
    }
}
