<?php

namespace App\Modules\Domains\Drivers\Registrars\Data;

use Carbon\CarbonImmutable;

final readonly class SyncResult
{
    public function __construct(
        public bool $active,
        public bool $cancelled = false,
        public bool $transferredAway = false,
        public ?CarbonImmutable $expiryDate = null,
    ) {}
}
