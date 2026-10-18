<?php

namespace App\Modules\Orders\Enums;

enum OrderItemType: string
{
    case Service = 'service';
    case Addon = 'addon';
    case Domain = 'domain';
    case Upgrade = 'upgrade';

    /**
     * Item type name used in the OrderPlaced payload.
     */
    public function eventType(): string
    {
        return match ($this) {
            self::Service, self::Upgrade => 'service',
            self::Addon => 'service_addon',
            self::Domain => 'domain',
        };
    }
}
