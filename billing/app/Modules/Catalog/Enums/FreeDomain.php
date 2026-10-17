<?php

namespace App\Modules\Catalog\Enums;

enum FreeDomain: string
{
    case None = 'none';
    case Once = 'once';
    case Recurring = 'recurring';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No free domain',
            self::Once => 'Free registration only',
            self::Recurring => 'Free while the service is active',
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
