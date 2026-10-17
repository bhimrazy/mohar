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
     * Advance a date by whole cycles without month overflow, re-anchored to $anchorDay.
     *
     * Store the service's original day-of-month as its anchor so short months don't cause drift:
     * Jan 31 -> Feb 28 -> Mar 31 (anchor 31), instead of Jan 31 -> Feb 28 -> Mar 28.
     *
     * @param  int|null  $anchorDay  1-31; defaults to the day of $date
     */
    public function advance(CarbonInterface $date, int $cycles = 1, ?int $anchorDay = null): CarbonImmutable
    {
        $date = CarbonImmutable::instance($date);

        if (! $this->isRecurring()) {
            return $date;
        }

        $target = $date->startOfMonth()->addMonths($this->months() * $cycles);

        return $target->setDay(min($anchorDay ?? $date->day, $target->daysInMonth));
    }

    /**
     * @return list<self>
     */
    public static function recurring(): array
    {
        return array_values(array_filter(self::cases(), fn (self $cycle): bool => $cycle->isRecurring()));
    }
}
