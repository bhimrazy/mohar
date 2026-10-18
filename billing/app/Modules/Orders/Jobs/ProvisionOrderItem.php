<?php

namespace App\Modules\Orders\Jobs;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\ItemProvisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs "create" for a service ordered with auto-setup on order. Skips items that are no longer pending
 * or whose order has been flagged in the meantime.
 */
class ProvisionOrderItem implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public int $orderItemId) {}

    public function handle(ItemProvisioner $provisioner): void
    {
        $item = OrderItem::query()->with('order')->find($this->orderItemId);

        if ($item === null || in_array($item->order->status, [OrderStatus::Fraud, OrderStatus::Cancelled], true)) {
            return;
        }

        $provisioner->provision($item->id);
    }
}
