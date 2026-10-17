<?php

namespace App\Modules\Orders\Enums;

/**
 * The order's view of an item it created. Services/Domains own the real lifecycle; Orders only tracks
 * whether the item was ever activated (stock/promo release, cancel behaviour).
 */
enum OrderItemStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Fraud = 'fraud';
}
