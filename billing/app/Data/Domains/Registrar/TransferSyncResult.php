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
}
