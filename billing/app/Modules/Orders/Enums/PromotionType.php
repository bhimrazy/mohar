<?php

namespace App\Modules\Orders\Enums;

enum PromotionType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
    case PriceOverride = 'price_override';
    case FreeSetup = 'free_setup';

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'Percentage',
            self::Fixed => 'Fixed amount',
            self::PriceOverride => 'Price override',
            self::FreeSetup => 'Free setup',
        };
    }

    /**
     * Whether `value` is an amount in the default currency (rather than a percentage or unused).
     */
    public function isAmount(): bool
    {
        return $this === self::Fixed || $this === self::PriceOverride;
    }
}
