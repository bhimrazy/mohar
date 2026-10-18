<?php

namespace App\Modules\Catalog\Enums;

enum ConfigOptionType: string
{
    case Dropdown = 'dropdown';
    case Radio = 'radio';
    case YesNo = 'yes_no';
    case Quantity = 'quantity';

    public function label(): string
    {
        return match ($this) {
            self::Dropdown => 'Dropdown',
            self::Radio => 'Radio buttons',
            self::YesNo => 'Yes / no',
            self::Quantity => 'Quantity',
        };
    }

    /**
     * Yes/no and quantity options carry exactly one value row (the price of "yes" or of one unit).
     */
    public function hasSingleValue(): bool
    {
        return $this === self::YesNo || $this === self::Quantity;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
