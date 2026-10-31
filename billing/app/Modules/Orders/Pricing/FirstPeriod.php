<?php

namespace App\Modules\Orders\Pricing;

use App\Enums\BillingCycle;
use Carbon\CarbonImmutable;

/**
 * The period a new service or addon's order invoice covers: [start, end), the amount charged for it in minor units,
 * and the day of the month later periods renew on. `prorated` is true when the period is not a whole number of cycles.
 */
final readonly class FirstPeriod
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public int $anchorDay,
        public int $amount,
        public bool $prorated,
        public BillingCycle $cycle,
        public int $chargeNextMonthDay = 32,
    ) {}

    /**
     * What another per-cycle price (a promotion's override price) costs for this same period, rounded the same way.
     */
    public function amountFor(int $recurring): int
    {
        return $this->prorated
            ? Prorata::calculate($this->start, $recurring, $this->cycle, $this->anchorDay, $this->chargeNextMonthDay)['first_amount']
            : $recurring;
    }

    /**
     * @return array{start: string, end: string, prorated: bool}
     */
    public function toArray(): array
    {
        return ['start' => $this->start->toDateString(), 'end' => $this->end->toDateString(), 'prorated' => $this->prorated];
    }
}
