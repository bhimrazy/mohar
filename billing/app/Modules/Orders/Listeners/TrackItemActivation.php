<?php

namespace App\Modules\Orders\Listeners;

use App\Events\Domains\DomainRegistered;
use App\Events\Domains\DomainTransferred;
use App\Events\Services\ServiceActivated;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderItemType;
use App\Modules\Orders\Models\OrderItem;

/**
 * Remembers which order items were activated (Services/Domains own the lifecycle), so cancel/fraud know
 * which items never went live.
 */
class TrackItemActivation
{
    public function handle(ServiceActivated|DomainRegistered|DomainTransferred $event): void
    {
        [$type, $id] = $event instanceof ServiceActivated
            ? [OrderItemType::Service, $event->serviceId]
            : [OrderItemType::Domain, $event->domainId];

        OrderItem::query()
            ->where('item_type', $type)
            ->where('item_id', $id)
            ->where('status', OrderItemStatus::Pending)
            ->update(['status' => OrderItemStatus::Active]);
    }
}
