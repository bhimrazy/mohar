<?php

namespace App\Enums;

/**
 * How converted prices are rounded in a currency (minor units are always 1/100, ADR 0002). A rule applies only
 * from its unit up (whole and .99: 1.00, tens: 10.00); smaller amounts and zero are left as they are.
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

    /**
     * Smallest amount (minor units) the rule applies to; smaller amounts keep their exact minor units, so a 0.50
     * fee never becomes 1.00 or 10.00.
     */
    public function minimum(): int
    {
        return match ($this) {
            self::None => 0,
            self::Whole, self::NinetyNine => 100,
            self::Tens => 1000,
        };
    }

    public function apply(int $minor): int
    {
        if ($minor === 0 || $this === self::None || abs($minor) < $this->minimum()) {
            return $minor;
        }

        $sign = $minor < 0 ? -1 : 1;
        $absolute = abs($minor);

        return $sign * match ($this) {
            self::Whole => self::nearest($absolute, 100),
            self::Tens => self::nearest($absolute, 1000),
            self::NinetyNine => max(99, self::nearest($absolute + 1, 100) - 1),
        };
    }

    /**
     * Half-up rounding to a multiple of $step.
     */
    private static function nearest(int $value, int $step): int
    {
        return intdiv($value + intdiv($step, 2), $step) * $step;
    }
}
