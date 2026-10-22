<?php

namespace App\Data\Domains\Registrar;

use Carbon\CarbonImmutable;

final readonly class TransferSyncResult
{
    /**
     * @param  array<string, string|int>  $remote  registrar ids, merged into registrar_meta.remote
     */
    public function __construct(
        public bool $completed,
        public bool $failed = false,
        public ?string $reason = null,
        public ?CarbonImmutable $expiryDate = null,
        public array $remote = [],
    ) {}

    /**
     * A copy with another failure reason, so the host can replace driver text (for example with a masked version).
     */
    public function withReason(?string $reason): self
    {
        return new self($this->completed, $this->failed, $reason, $this->expiryDate, $this->remote);
    }
}
