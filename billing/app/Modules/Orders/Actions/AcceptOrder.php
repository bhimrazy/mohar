<?php

namespace App\Modules\Orders\Actions;

use App\Contracts\Domains\DomainRegistry;
use App\Contracts\Services\ServiceRegistry;
use App\Events\Orders\OrderAccepted;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderItemType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use DomainException;
use Throwable;

/**
 * Staff accept (orders.md §8): order → active, then run "create" for each selected pending item
 * (default: services whose product auto-sets up on accept, domains once their invoice is paid).
 * Provisioning failures never roll back the acceptance; they are returned for the staff notice.
 */
class AcceptOrder
{
    public function __construct(
        private ServiceRegistry $services,
        private DomainRegistry $domains,
        private ActivityLogger $activity,
    ) {}

    /**
     * Default "run create" selection for the accept dialog.
     *
     * @return list<int> order item ids
     */
    public function defaultSelection(Order $order, ?string $invoiceStatus): array
    {
        return array_values($order->items
            ->filter(fn (OrderItem $item): bool => $item->status === OrderItemStatus::Pending && match ($item->item_type) {
                OrderItemType::Service => $item->auto_setup === 'accept',
                OrderItemType::Domain => $invoiceStatus === 'paid',
                default => false,
            })
            ->pluck('id')
            ->all());
    }

    /**
     * @param  list<int>|null  $runCreate  order item ids to provision; null = default selection
     * @return list<string> provisioning failures
     */
    public function handle(Order $order, ?array $runCreate = null, ?string $invoiceStatus = null): array
    {
        if ($order->status !== OrderStatus::Pending) {
            throw new DomainException(__('Only pending orders can be accepted.'));
        }

        $order->update(['status' => OrderStatus::Active, 'accepted_at' => now()]);
        $runCreate ??= $this->defaultSelection($order->load('items'), $invoiceStatus);

        $failures = [];

        foreach ($order->items()->whereIn('id', $runCreate)->where('status', OrderItemStatus::Pending)->orderBy('id')->get() as $item) {
            if ($item->item_id === null || $item->item_type === OrderItemType::Addon) {
                continue;
            }

            try {
                $result = $item->item_type === OrderItemType::Domain
                    ? $this->domains->process($item->item_id)
                    : $this->services->provision($item->item_id);
            } catch (Throwable $exception) {
                report($exception);
                $failures[] = "{$item->description}: ".__('provisioning error.');

                continue;
            }

            if ($result->success) {
                $item->update(['status' => OrderItemStatus::Active]);
            } else {
                $failures[] = "{$item->description}: ".($result->message ?? __('provisioning failed.'));
            }
        }

        $this->activity->log("Order {$order->number} accepted", $order, $order->client_id, $failures === [] ? [] : ['failures' => $failures]);
        OrderAccepted::dispatch($order->id, $order->client_id);

        return $failures;
    }
}
