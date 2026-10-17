<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderItemType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Duplicate-domain protection (orders.block_duplicate_domains): a hosting domain may not be ordered
 * again while a service created by an order still uses it.
 */
class DomainGuard
{
    public function isAttachedToService(string $domain): bool
    {
        return OrderItem::query()
            ->where('item_type', OrderItemType::Service)
            ->where('domain', strtolower($domain))
            ->whereIn('status', [OrderItemStatus::Pending, OrderItemStatus::Active])
            ->whereHas('order', fn (Builder $order) => $order->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Fraud]))
            ->exists();
    }

    public static function isValidName(string $domain): bool
    {
        return (bool) preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/i', $domain);
    }
}
