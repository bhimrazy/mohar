<?php

namespace App\Modules\Billing\Actions;

use App\Contracts\Core\CompanyClock;
use App\Events\Billing\InvoiceModified;
use App\Modules\Billing\Enums\AdjustmentKind;
use App\Modules\Billing\Enums\AdjustmentReason;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceItem;
use App\Modules\Billing\Services\InvoiceSettlement;
use App\Modules\Billing\Services\NepalVatMode;
use App\Modules\Billing\Support\AfterCommit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * cancelled → unpaid (or draft): reverse the cancellation credit note and restore the period guards.
 */
class ReopenInvoice
{
    public function __construct(private InvoiceSettlement $settlement, private NepalVatMode $mode, private CompanyClock $clock) {}

    public function handle(Invoice $invoice, ?int $userId = null): Invoice
    {
        try {
            return DB::transaction(function () use ($invoice, $userId): Invoice {
                $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

                if ($invoice->status !== InvoiceStatus::Cancelled) {
                    throw new BillingException(__('Only cancelled invoices can be reopened.'));
                }

                $at = $this->clock->now()->utc();

                if ($invoice->numbered_at !== null && $invoice->kind !== InvoiceKind::AddFunds && $this->mode->inEffectLocked($at)) {
                    throw new BillingException(__('A numbered tax invoice cannot be reopened while Nepal VAT mode is on. Raise a new invoice instead.'));
                }

                $cancelled = (int) $invoice->adjustments()->where('reason', AdjustmentReason::Cancellation)->where('kind', AdjustmentKind::Credit)->sum('amount');
                $reversed = (int) $invoice->adjustments()->where('reason', AdjustmentReason::Cancellation)->where('kind', AdjustmentKind::Debit)->sum('amount');

                if ($cancelled - $reversed > 0) {
                    $this->settlement->adjust($invoice, AdjustmentKind::Debit, AdjustmentReason::Cancellation, $cancelled - $reversed, __('Invoice reopened'), userId: $userId, at: $at);
                }

                $invoice->status = $invoice->number === null && $invoice->proforma_number === null ? InvoiceStatus::Draft : InvoiceStatus::Unpaid;
                $invoice->cancelled_at = null;
                $invoice->save();

                foreach ($invoice->items()->whereNull('cancelled_at')->get() as $item) {
                    $item->update(['period_guard' => InvoiceItem::guardKey($item->type, $item->billable_id, $item->period_start)]);
                }

                AfterCommit::dispatch(new InvoiceModified($invoice->id, $invoice->client_id));

                return $invoice;
            });
        } catch (UniqueConstraintViolationException) {
            throw new BillingException(__('An item on this invoice has been invoiced again since it was cancelled. Reissue instead.'));
        }
    }
}
