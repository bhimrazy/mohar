<?php

namespace App\Modules\Catalog\Enums;

enum QuantityMode: string
{
    /** Quantity is fixed to 1. */
    case Single = 'single';

    /** Quantity N creates N services, each priced at the unit price. */
    case MultipleServices = 'multiple_services';

    /** One service with quantity N; recurring = unit price × N. */
    case Scaling = 'scaling';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Single',
            self::MultipleServices => 'Multiple services',
            self::Scaling => 'Scaling (one service, quantity N)',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
