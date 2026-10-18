<?php

namespace App\Modules\Notifications\Enums;

/**
 * Template groups (spec §2). The type decides which merge-field categories are available.
 */
enum TemplateType: string
{
    case General = 'general';
    case Product = 'product';
    case Invoice = 'invoice';
    case Domain = 'domain';
    case Support = 'support';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General',
            self::Product => 'Product',
            self::Invoice => 'Invoice',
            self::Domain => 'Domain',
            self::Support => 'Support',
            self::Admin => 'Staff',
        };
    }

    /**
     * Types staff may create custom templates for (spec §5).
     *
     * @return list<self>
     */
    public static function customisable(): array
    {
        return [self::General, self::Product, self::Invoice, self::Domain];
    }
}
