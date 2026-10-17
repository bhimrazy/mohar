<?php

namespace App\Modules\Services\Listeners;

use App\Events\Orders\OrderPlaced;
use App\Modules\Services\Enums\InvoiceLinkKind;
use App\Modules\Services\Enums\InvoiceLinkStatus;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use App\Modules\Services\Services\InvoiceLinks;

/**
 * Remembers which invoice bills the first period of newly ordered services and addons.
 */
class RecordOrderInvoice
{
    public function __construct(private InvoiceLinks $links) {}

    public function handle(OrderPlaced $event): void
    {
        if ($event->invoiceId === null) {
            return;
        }

        $serviceIds = array_column(array_filter($event->items, fn (array $item): bool => $item['type'] === 'service'), 'id');
        $addonIds = array_column(array_filter($event->items, fn (array $item): bool => $item['type'] === 'service_addon'), 'id');

        foreach (Service::query()->whereKey($serviceIds)->get() as $service) {
            $this->links->record($service, $event->invoiceId, InvoiceLinkKind::Order, $service->next_due_date, $service->next_invoice_date, $service->first_payment_amount, InvoiceLinkStatus::Open);
        }

        foreach (ServiceAddon::query()->whereKey($addonIds)->get() as $addon) {
            $this->links->record($addon, $event->invoiceId, InvoiceLinkKind::Order, $addon->next_due_date, $addon->next_invoice_date, $addon->recurring_amount + $addon->setup_fee, InvoiceLinkStatus::Open);
        }
    }
}
