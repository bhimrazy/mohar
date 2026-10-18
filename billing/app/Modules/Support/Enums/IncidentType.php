<?php

namespace App\Modules\Support\Enums;

enum IncidentType: string
{
    case Server = 'server';
    case System = 'system';
    case Other = 'other';

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => ucfirst($case->value)], self::cases());
    }
}
