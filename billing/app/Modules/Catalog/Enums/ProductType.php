<?php

namespace App\Modules\Catalog\Enums;

enum ProductType: string
{
    case SharedHosting = 'shared_hosting';
    case ResellerHosting = 'reseller_hosting';
    case Server = 'server';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::SharedHosting => 'Shared hosting',
            self::ResellerHosting => 'Reseller hosting',
            self::Server => 'Server / VPS',
            self::Other => 'Other',
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
