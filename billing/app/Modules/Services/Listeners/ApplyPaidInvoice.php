<?php

namespace App\Modules\Services\Listeners;

use App\Events\Billing\InvoicePaid;
use App\Modules\Services\Actions\ApplyInvoicePayment;

class ApplyPaidInvoice
{
    public function __construct(private ApplyInvoicePayment $apply) {}

    public function handle(InvoicePaid $event): void
    {
        $this->apply->handle($event->invoiceId, $event->lines);
    }
}
