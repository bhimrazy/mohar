<?php

namespace App\Modules\Domains\Drivers\Registrars\Data;

use Carbon\CarbonImmutable;

final readonly class TransferSyncResult
{
    public function __construct(
        public bool $completed,
        public bool $failed = false,
        public ?string $reason = null,
        public ?CarbonImmutable $expiryDate = null,
    ) {}
}
