<?php

namespace App\Modules\Orders\Actions;

use App\Contracts\Domains\DomainRegistry;
use App\Contracts\Services\ServiceRegistry;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderItemType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;

/**
 * Cancels an order's items when the order is cancelled or deleted. Items that never went live (pending, or
 * flagged as fraud) are cancelled through their registries (no driver call); addons follow their parent
 * service in Services. Activated items are terminated by Services reacting to OrderCancelled; stock is
 * returned by Catalog reacting to the same event.
 */
class ReleasePendingItems
{
    public function __construct(
        private ServiceRegistry $services,
        private DomainRegistry $domains,
    ) {}

    public function handle(Order $order): void
    {
        $order->items()
            ->whereIn('status', [OrderItemStatus::Pending, OrderItemStatus::Fraud, OrderItemStatus::Active])
            ->eachById(function (OrderItem $item): void {
                if ($item->status !== OrderItemStatus::Active && $item->item_id !== null) {
                    match ($item->item_type) {
                        OrderItemType::Service, OrderItemType::Upgrade => $this->services->cancelPending($item->item_id),
                        OrderItemType::Domain => $this->domains->cancelPending($item->item_id),
                        OrderItemType::Addon => null,
                    };
                }

                $item->update(['status' => OrderItemStatus::Cancelled]);
            });
    }
}
