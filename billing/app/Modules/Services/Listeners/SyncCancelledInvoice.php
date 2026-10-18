<?php

namespace App\Modules\Services\Listeners;

use App\Events\Billing\InvoiceCancelled;
use App\Modules\Services\Enums\ChangeStatus;
use App\Modules\Services\Models\ServiceChange;
use App\Modules\Services\Services\InvoiceLinks;

/**
 * A cancelled invoice no longer makes its periods overdue; an unpaid upgrade on it is cancelled.
 */
class SyncCancelledInvoice
{
    public function __construct(private InvoiceLinks $links) {}

    public function handle(InvoiceCancelled $event): void
    {
        $this->links->cancelInvoice($event->invoiceId);

        ServiceChange::query()
            ->where('invoice_id', $event->invoiceId)
            ->where('status', ChangeStatus::PendingPayment->value)
            ->update(['status' => ChangeStatus::Cancelled->value]);
    }
}
