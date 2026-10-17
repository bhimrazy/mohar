<?php

namespace App\Modules\Catalog\Enums;

enum AddonKind: string
{
    /** Separately provisioned item (e.g. an SSL certificate). */
    case Independent = 'independent';

    /** Feature toggled on the parent service (e.g. backups). */
    case Feature = 'feature';

    public function label(): string
    {
        return match ($this) {
            self::Independent => 'Independent',
            self::Feature => 'Feature of the parent service',
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
