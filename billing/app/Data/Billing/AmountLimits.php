<?php

namespace App\Data\Billing;

/**
 * Minimum/maximum amount per payment in minor units (e.g. Khalti min 1000 paisa).
 */
final readonly class AmountLimits
{
    public function __construct(
        public int $min = 1,
        public ?int $max = null,
    ) {}

    public function allows(int $amount): bool
    {
        return $amount >= $this->min && ($this->max === null || $amount <= $this->max);
    }
}
