<?php

namespace App\Modules\Support\Enums;

enum IncidentStatus: string
{
    case Investigating = 'investigating';
    case Identified = 'identified';
    case Monitoring = 'monitoring';
    case Scheduled = 'scheduled';
    case Resolved = 'resolved';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => ucfirst($case->value)], self::cases());
    }
}
