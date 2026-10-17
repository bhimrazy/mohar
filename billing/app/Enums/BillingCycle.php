<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SemiAnnually = 'semiannually';
    case Annually = 'annually';
    case Biennially = 'biennially';
    case Triennially = 'triennially';
    case OneTime = 'onetime';
    case Free = 'free';

    /**
     * Length of the cycle in months, or null for non-recurring cycles.
     */
    public function months(): ?int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::SemiAnnually => 6,
            self::Annually => 12,
            self::Biennially => 24,
            self::Triennially => 36,
            self::OneTime, self::Free => null,
        };
    }

    public function isRecurring(): bool
    {
        return $this->months() !== null;
    }

    /**
     * Advance a date by one cycle, keeping month-end anchoring sane (Jan 31 -> Feb 28).
     */
    public function advance(CarbonInterface $date, int $cycles = 1): CarbonImmutable
    {
        $date = CarbonImmutable::instance($date);

        return $this->isRecurring() ? $date->addMonthsNoOverflow($this->months() * $cycles) : $date;
    }

    /**
     * @return list<self>
     */
    public static function recurring(): array
    {
        return array_values(array_filter(self::cases(), fn (self $cycle): bool => $cycle->isRecurring()));
    }
}
