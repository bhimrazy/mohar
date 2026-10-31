<?php

namespace App\Data\Services;

use App\Enums\BillingCycle;
use Carbon\CarbonImmutable;

final readonly class AddonDraft
{
    /**
     * @param  CarbonImmutable  $nextDueDate  end (exclusive) of the first period the order invoice covers, pro-rated
     *                                        or one cycle: the addon's next invoice date, and its due date once paid
     * @param  int|null  $billingAnchorDay  day of the month later periods renew on (1-31); null = the registration day
     */
    public function __construct(
        public int $serviceId,
        public int $addonId,
        public BillingCycle $cycle,
        public int $recurringAmount,
        public int $setupFee,
        public CarbonImmutable $nextDueDate,
        public ?int $orderId = null,
        public ?int $billingAnchorDay = null,
    ) {}
}
