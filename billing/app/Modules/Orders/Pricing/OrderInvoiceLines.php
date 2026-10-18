<?php

namespace App\Modules\Orders\Pricing;

use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use App\Modules\Orders\Enums\OrderItemType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use Carbon\CarbonImmutable;

/**
 * Rebuilds the first invoice's lines from an order snapshot (used when an order whose invoice was
 * cancelled is set back to pending). Mirrors InvoiceLineBuilder: charge, setup fee and a negative
 * promotion line per discounted item; periods start on $today.
 */
class OrderInvoiceLines
{
    /**
     * @return list<InvoiceLineData>
     */
    public function build(Order $order, CarbonImmutable $today): array
    {
        $lines = [];

        foreach ($order->items()->orderBy('id')->get() as $item) {
            array_push($lines, ...$this->linesFor($order, $item, $today));
        }

        return $lines;
    }

    /**
     * @return list<InvoiceLineData>
     */
    private function linesFor(Order $order, OrderItem $item, CarbonImmutable $today): array
    {
        [$periodStart, $periodEnd] = match (true) {
            $item->item_type === OrderItemType::Domain => [$today, $today->addYearsNoOverflow($item->years)],
            $item->cycle !== null && $item->cycle->isRecurring() => [$today, $item->cycle->advance($today, anchorDay: $today->day)],
            default => [null, null],
        };

        $type = match ($item->item_type) {
            OrderItemType::Addon => BillableType::ServiceAddon,
            OrderItemType::Domain => BillableType::Domain,
            OrderItemType::Service, OrderItemType::Upgrade => BillableType::Service,
        };

        $lines = [new InvoiceLineData($item->description, $item->first_amount, 1, $item->taxable, $type, $item->item_id, $periodStart, $periodEnd)];

        if ($item->setup_fee > 0) {
            $setupType = $item->item_type === OrderItemType::Addon ? BillableType::ServiceAddon : BillableType::ServiceSetup;
            $lines[] = new InvoiceLineData(__('Setup fee: :name', ['name' => $item->description]), $item->setup_fee, 1, $item->taxable, $setupType, $item->item_id);
        }

        if ($item->discount_amount > 0 && $order->promotion_id !== null) {
            $lines[] = new InvoiceLineData(
                __('Promotion :code: :name', ['code' => $order->promotion_code, 'name' => $item->description]),
                -$item->discount_amount, 1, $item->taxable, BillableType::Promotion, $order->promotion_id, $periodStart, $periodEnd,
            );
        }

        return $lines;
    }
}
