<?php

namespace App\Modules\Billing\Actions;

use App\Events\Billing\InvoiceModified;
use App\Modules\Billing\Enums\AdjustmentKind;
use App\Modules\Billing\Enums\AdjustmentReason;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\InvoiceSettlement;
use App\Modules\Billing\Services\Tax\TaxMath;
use App\Modules\Billing\Support\AfterCommit;
use Illuminate\Support\Facades\DB;

/**
 * Cancel selected lines of an unpaid invoice with an item-cancellation credit note (gross line amount).
 * Cancelling every line cancels the invoice.
 */
class CancelInvoiceItems
{
    public function __construct(
        private InvoiceSettlement $settlement,
        private CancelInvoice $cancel,
    ) {}

    /**
     * @param  list<int>  $itemIds
     */
    public function handle(Invoice $invoice, array $itemIds, ?int $userId = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $itemIds, $userId): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! $invoice->status->isPayable()) {
                throw new BillingException(__('Lines can only be cancelled on unpaid invoices.'));
            }

            $items = $invoice->items()->whereIn('id', $itemIds)->whereNull('cancelled_at')->get();

            if ($items->isEmpty()) {
                throw new BillingException(__('Select at least one open line.'));
            }

            foreach ($items as $item) {
                $gross = $invoice->tax_inclusive ? $item->amount : $item->amount + $item->tax_amount;
                $item->update(['cancelled_at' => now(), 'period_guard' => null]);
                $label = __('Cancelled line: :line', ['line' => mb_substr($item->description, 0, 150)]);

                if ($gross < 0) {
                    // Cancelling a discount line raises the balance again.
                    $this->settlement->adjust($invoice, AdjustmentKind::Debit, AdjustmentReason::ItemCancellation, -$gross, $label, userId: $userId, taxableAmount: -($gross - $item->tax_amount), taxAmount: -$item->tax_amount);

                    continue;
                }

                // On a partly paid invoice the credit note can't exceed the balance; its tax split is prorated.
                $credited = min($gross, max(0, $invoice->balance));
                $tax = $gross > 0 ? TaxMath::roundDiv($item->tax_amount * $credited, $gross) : 0;

                if ($credited > 0) {
                    $this->settlement->adjust($invoice, AdjustmentKind::Credit, AdjustmentReason::ItemCancellation, $credited, $label, userId: $userId, taxableAmount: $credited - $tax, taxAmount: $tax);
                }
            }

            if (! $invoice->items()->whereNull('cancelled_at')->exists()) {
                return $this->cancel->handle($invoice, $userId);
            }

            // Lines cancelled down to a zero balance settle the invoice (the remaining lines are paid for).
            if (! $this->settlement->settle($invoice)) {
                AfterCommit::dispatch(new InvoiceModified($invoice->id, $invoice->client_id));
            }

            return $invoice;
        });
    }
}
