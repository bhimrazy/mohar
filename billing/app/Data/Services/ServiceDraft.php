<?php

namespace App\Data\Services;

use App\Enums\BillingCycle;
use Carbon\CarbonImmutable;

final readonly class ServiceDraft
{
    /**
     * @param  array<int, int>  $configOptions
     * @param  array<int, mixed>  $customFields  custom field id => value
     * @param  int|null  $promotionCyclesRemaining  renewals that still get $renewalDiscount (null = forever when > 0)
     * @param  int  $renewalDiscount  recurring discount per renewal in minor units (from a recurring promotion)
     * @param  CarbonImmutable  $nextDueDate  end (exclusive) of the first period the order invoice covers, pro-rated
     *                                        or one cycle: the service's next invoice date, and its due date once paid
     * @param  int|null  $billingAnchorDay  day of the month later periods renew on (1-31, clamped to short months);
     *                                      null = the registration day
     */
    public function __construct(
        public int $clientId,
        public int $productId,
        public BillingCycle $cycle,
        public string $currency,
        public int $recurringAmount,
        public int $firstPaymentAmount,
        public CarbonImmutable $nextDueDate,
        public ?int $orderId = null,
        public ?string $domain = null,
        public array $configOptions = [],
        public array $customFields = [],
        public ?int $promotionId = null,
        public ?int $promotionCyclesRemaining = null,
        public int $renewalDiscount = 0,
        public ?int $billingAnchorDay = null,
    ) {}
}
