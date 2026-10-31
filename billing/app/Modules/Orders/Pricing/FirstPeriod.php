<?php

namespace App\Modules\Orders\Pricing;

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
    ) {}

    /**
     * @return array{start: string, end: string, prorated: bool}
     */
    public function toArray(): array
    {
        return ['start' => $this->start->toDateString(), 'end' => $this->end->toDateString(), 'prorated' => $this->prorated];
    }
}
