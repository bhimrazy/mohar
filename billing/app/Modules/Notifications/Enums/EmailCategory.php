<?php

namespace App\Modules\Notifications\Enums;

/**
 * Client-side email preference categories (spec §2 "Preferences"). Every category defaults to on.
 */
enum EmailCategory: string
{
    case General = 'general';
    case Invoice = 'invoice';
    case Product = 'product';
    case Domain = 'domain';
    case Support = 'support';
    case Affiliate = 'affiliate';

    public function label(): string
    {
        return match ($this) {
            self::General => 'Account & orders',
            self::Invoice => 'Invoices & payments',
            self::Product => 'Products & services',
            self::Domain => 'Domains',
            self::Support => 'Support tickets',
            self::Affiliate => 'Affiliate programme',
        };
    }

    /**
     * Categories that must always reach at least one recipient (spec §7 last-recipient rule).
     */
    public function mustReachSomeone(): bool
    {
        return $this === self::Invoice || $this === self::Domain;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $category): string => $category->value, self::cases());
    }
}
