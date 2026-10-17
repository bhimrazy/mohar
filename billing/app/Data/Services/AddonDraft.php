<?php

namespace App\Data\Services;

use App\Enums\BillingCycle;
use Carbon\CarbonImmutable;

final readonly class AddonDraft
{
    public function __construct(
        public int $serviceId,
        public int $addonId,
        public BillingCycle $cycle,
        public int $recurringAmount,
        public int $setupFee,
        public CarbonImmutable $nextDueDate,
        public ?int $orderId = null,
    ) {}
}
