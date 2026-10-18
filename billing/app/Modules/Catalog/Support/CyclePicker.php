<?php

namespace App\Modules\Catalog\Support;

use App\Enums\BillingCycle;

final class CyclePicker
{
    /**
     * Addon cycle on purchase: the parent's cycle when the addon is priced for it, otherwise the addon's
     * shortest enabled cycle.
     *
     * @param  list<BillingCycle>  $enabledCycles
     */
    public static function forAddon(array $enabledCycles, BillingCycle $parentCycle): ?BillingCycle
    {
        if (in_array($parentCycle, $enabledCycles, true)) {
            return $parentCycle;
        }

        return self::sort($enabledCycles)[0] ?? null;
    }

    /**
     * Order cycles from the shortest to the longest (one-time and free last).
     *
     * @param  list<BillingCycle>  $cycles
     * @return list<BillingCycle>
     */
    public static function sort(array $cycles): array
    {
        $position = array_flip(array_map(fn (BillingCycle $cycle): string => $cycle->value, BillingCycle::cases()));
        usort($cycles, fn (BillingCycle $a, BillingCycle $b): int => $position[$a->value] <=> $position[$b->value]);

        return $cycles;
    }

    public static function label(BillingCycle $cycle): string
    {
        return match ($cycle) {
            BillingCycle::Monthly => 'Monthly',
            BillingCycle::Quarterly => 'Quarterly',
            BillingCycle::SemiAnnually => 'Semi-annually',
            BillingCycle::Annually => 'Annually',
            BillingCycle::Biennially => 'Biennially',
            BillingCycle::Triennially => 'Triennially',
            BillingCycle::OneTime => 'One-time',
            BillingCycle::Free => 'Free',
        };
    }

    /**
     * @param  list<BillingCycle>  $cycles
     * @return list<array{value: string, label: string, months: int|null}>
     */
    public static function options(array $cycles): array
    {
        return array_map(fn (BillingCycle $cycle): array => ['value' => $cycle->value, 'label' => self::label($cycle), 'months' => $cycle->months()], $cycles);
    }
}
