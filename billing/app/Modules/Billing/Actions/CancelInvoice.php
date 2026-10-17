<?php

namespace App\Modules\Billing\Actions;

use App\Events\Billing\InvoiceCancelled;
use App\Modules\Billing\Enums\AdjustmentKind;
use App\Modules\Billing\Enums\AdjustmentReason;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\InvoiceSettlement;
use App\Modules\Billing\Support\AfterCommit;
use Illuminate\Support\Facades\DB;

/**
 * Cancel a draft or unpaid invoice: a cancellation credit note zeroes the balance and the double-invoicing
 * guards are released (the invoice keeps its rows for the audit trail).
 */
class CancelInvoice
{
    public function __construct(private InvoiceSettlement $settlement) {}

    /**
     * @param  bool  $strict  throw instead of silently ignoring invoices that can't be cancelled
     */
    public function handle(Invoice $invoice, ?int $userId = null, ?string $note = null, bool $strict = true): Invoice
    {
        return DB::transaction(function () use ($invoice, $userId, $note, $strict): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Unpaid, InvoiceStatus::PaymentPending], true)) {
                if ($strict && $invoice->status !== InvoiceStatus::Cancelled) {
                    throw new BillingException(__('Only draft or unpaid invoices can be cancelled. Refund paid invoices instead.'));
                }

                return $invoice;
            }

            if ($invoice->status !== InvoiceStatus::Draft && $invoice->balance > 0) {
                $this->settlement->adjust($invoice, AdjustmentKind::Credit, AdjustmentReason::Cancellation, $invoice->balance, $note ?? __('Invoice cancelled'), userId: $userId);
            }

            $invoice->status = InvoiceStatus::Cancelled;
            $invoice->cancelled_at = now();

            if ($note !== null) {
                $invoice->admin_notes = trim(($invoice->admin_notes ?? '')."\n".$note);
            }

            $invoice->save();
            $this->settlement->releaseGuards($invoice);

            AfterCommit::dispatch(new InvoiceCancelled($invoice->id, $invoice->client_id));

            return $invoice;
        });
    }
}
