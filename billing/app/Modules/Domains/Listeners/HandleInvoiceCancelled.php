<?php

namespace App\Modules\Domains\Listeners;

use App\Events\Billing\InvoiceCancelled;
use App\Modules\Domains\Models\DomainInvoice;

/**
 * Keep domain_invoices in step when Billing (or staff) cancels an invoice we track.
 */
class HandleInvoiceCancelled
{
    public function handle(InvoiceCancelled $event): void
    {
        DomainInvoice::query()
            ->where('invoice_id', $event->invoiceId)
            ->where('status', 'open')
            ->update(['status' => 'cancelled']);
    }
}
