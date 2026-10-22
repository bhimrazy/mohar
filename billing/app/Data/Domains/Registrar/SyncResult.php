<?php

namespace App\Data\Domains\Registrar;

use Carbon\CarbonImmutable;

final readonly class SyncResult
{
    /**
     * @param  array<string, string|int>  $remote  registrar ids, merged into registrar_meta.remote
     */
    public function __construct(
        public bool $active,
        public bool $cancelled = false,
        public bool $transferredAway = false,
        public ?CarbonImmutable $expiryDate = null,
        public array $remote = [],
    ) {}
}
