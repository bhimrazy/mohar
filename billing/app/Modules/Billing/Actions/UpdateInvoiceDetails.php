<?php

namespace App\Modules\Billing\Actions;

use App\Events\Billing\InvoiceModified;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\AfterCommit;
use Carbon\CarbonImmutable;

/**
 * Fields that stay editable after issue: due date, gateway, client notes and admin notes.
 */
class UpdateInvoiceDetails
{
    /**
     * @param  array{due_date?: string|null, payment_gateway?: string|null, notes?: string|null, admin_notes?: string|null}  $data
     */
    public function handle(Invoice $invoice, array $data): Invoice
    {
        if (array_key_exists('due_date', $data)) {
            $invoice->due_date = $data['due_date'] !== null ? CarbonImmutable::parse($data['due_date']) : $invoice->due_date;
        }

        $invoice->fill(array_intersect_key($data, array_flip(['payment_gateway', 'notes', 'admin_notes'])));

        if ($invoice->isDirty()) {
            $invoice->save();
            AfterCommit::dispatch(new InvoiceModified($invoice->id, $invoice->client_id));
        }

        return $invoice;
    }
}
