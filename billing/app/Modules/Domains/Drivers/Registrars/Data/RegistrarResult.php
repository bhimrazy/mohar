<?php

namespace App\Modules\Domains\Drivers\Registrars\Data;

use Carbon\CarbonImmutable;

final readonly class RegistrarResult
{
    /**
     * @param  'completed'|'pending'  $status
     * @param  array<string, mixed>  $meta  driver-specific flags (e.g. contact_change_pending, transfer_lock_until)
     */
    public function __construct(
        public string $status = 'completed',
        public ?CarbonImmutable $expiryDate = null,
        public ?string $message = null,
        public array $meta = [],
    ) {}

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'expiry_date' => $this->expiryDate?->toDateString(), 'message' => $this->message, 'meta' => $this->meta];
    }
}
