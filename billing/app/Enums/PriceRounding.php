<?php

namespace App\Enums;

/**
 * How converted prices are rounded in a currency (minor units are always 1/100, ADR 0002).
 * Zero stays zero (free items stay free).
 */
enum PriceRounding: string
{
    case None = 'none';
    case Whole = 'whole';
    case Tens = 'tens';
    case NinetyNine = 'ninety_nine';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No rounding (to the cent)',
            self::Whole => 'Nearest whole unit',
            self::Tens => 'Nearest 10',
            self::NinetyNine => 'Nearest .99 ending',
        };
    }

    public function apply(int $minor): int
    {
        if ($minor === 0 || $this === self::None) {
            return $minor;
        }

        $sign = $minor < 0 ? -1 : 1;
        $absolute = abs($minor);

        $rounded = match ($this) {
            self::Whole => self::nearest($absolute, 100),
            self::Tens => max(1000, self::nearest($absolute, 1000)),
            self::NinetyNine => max(99, self::nearest($absolute + 1, 100) - 1),
        };

        return $sign * ($this === self::Whole ? max(100, $rounded) : $rounded);
    }

    /**
     * Half-up rounding to a multiple of $step.
     */
    private static function nearest(int $value, int $step): int
    {
        return intdiv($value + intdiv($step, 2), $step) * $step;
    }
}
