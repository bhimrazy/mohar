<?php

namespace App\Modules\Orders\Actions;

use App\Contracts\Domains\DomainRegistry;
use App\Contracts\Services\ServiceRegistry;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderItemType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;

/**
 * Moves an order's items to cancelled/fraud. Pending services and domains are cancelled through their
 * registries (no driver call); addons follow their parent service in the Services module. Items that
 * were already activated are terminated by Services/Domains reacting to OrderCancelled/OrderMarkedFraud.
 */
class ReleasePendingItems
{
    public function __construct(
        private ServiceRegistry $services,
        private DomainRegistry $domains,
    ) {}

    /**
     * @param  list<OrderItemStatus>  $from  item statuses to move
     */
    public function handle(Order $order, OrderItemStatus $to, array $from = [OrderItemStatus::Pending, OrderItemStatus::Active]): void
    {
        $order->items()->whereIn('status', $from)->orderBy('id')->each(function (OrderItem $item) use ($to): void {
            if ($item->status === OrderItemStatus::Pending && $item->item_id !== null) {
                match ($item->item_type) {
                    OrderItemType::Service, OrderItemType::Upgrade => $this->services->cancelPending($item->item_id, $to->value),
                    OrderItemType::Domain => $this->domains->cancelPending($item->item_id, $to->value),
                    OrderItemType::Addon => null,
                };
            }

            $item->update(['status' => $to]);
        });
    }
}
