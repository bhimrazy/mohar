<?php

namespace App\Data\Domains\Registrar;

use Carbon\CarbonImmutable;

final readonly class RegistrarResult
{
    /**
     * @param  'completed'|'pending'  $status
     * @param  array<string, mixed>  $meta  driver-specific flags (e.g. contact_change_pending, transfer_lock_until)
     * @param  array<string, string|int>  $remote  registrar ids later calls need (order id, transfer id), kept in
     *                                             registrar_meta.remote
     */
    public function __construct(
        public string $status = 'completed',
        public ?CarbonImmutable $expiryDate = null,
        public ?string $message = null,
        public array $meta = [],
        public array $remote = [],
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
        return ['status' => $this->status, 'expiry_date' => $this->expiryDate?->toDateString(), 'message' => $this->message, 'meta' => $this->meta, 'remote' => $this->remote];
    }

    /**
     * A copy with another message, so the host can replace driver text (for example with a masked version).
     */
    public function withMessage(string $message): self
    {
        return new self($this->status, $this->expiryDate, $message, $this->meta, $this->remote);
    }
}
