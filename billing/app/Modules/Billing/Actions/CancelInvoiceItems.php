<?php

namespace App\Modules\Billing\Actions;

use App\Contracts\Core\CompanyClock;
use App\Events\Billing\InvoiceModified;
use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Enums\AdjustmentKind;
use App\Modules\Billing\Enums\AdjustmentReason;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceItem;
use App\Modules\Billing\Services\InvoiceSettlement;
use App\Modules\Billing\Services\NepalVatMode;
use App\Modules\Billing\Services\Tax\TaxMath;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Modules\Billing\Support\AfterCommit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cancel selected lines of an unpaid invoice with an item-cancellation credit note (gross line amount).
 * Cancelling every line cancels the invoice. On a numbered tax invoice while Nepal VAT mode is in effect, the lines are
 * credited with a numbered credit note instead (vat-register §5.4).
 */
class CancelInvoiceItems
{
    public function __construct(
        private InvoiceSettlement $settlement,
        private CancelInvoice $cancel,
        private TaxNoteIssuer $notes,
        private NepalVatMode $mode,
        private CompanyClock $clock,
    ) {}

    /**
     * @param  list<int>  $itemIds
     * @param  ?string  $note  the reason printed on the credit note; staff must give it while the mode is in effect
     */
    public function handle(Invoice $invoice, array $itemIds, ?int $userId = null, ?string $note = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $itemIds, $userId, $note): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! $invoice->status->isPayable()) {
                throw new BillingException(__('Lines can only be cancelled on unpaid invoices.'));
            }

            $items = $invoice->items()->whereIn('id', $itemIds)->whereNull('cancelled_at')->get();

            if ($items->isEmpty()) {
                throw new BillingException(__('Select at least one open line.'));
            }

            // One instant for everything this transaction writes, taken after the lock; the mode is read under a shared lock.
            $at = $this->clock->now()->utc();

            if ($invoice->numbered_at !== null && $invoice->kind !== InvoiceKind::AddFunds && $this->mode->inEffectLocked($at)) {
                return $this->withCreditNote($invoice, $items, $userId, $note, $at);
            }

            // Not reachable with a note on a line: notes exist only once the mode is in effect, and then the branch above runs.
            foreach ($items as $item) {
                $gross = $invoice->tax_inclusive ? $item->amount : $item->amount + $item->tax_amount;
                $item->update(['cancelled_at' => $at, 'period_guard' => null]);
                $label = __('Cancelled line: :line', ['line' => mb_substr($item->description, 0, 150)]);

                if ($gross < 0) {
                    // Cancelling a discount line raises the balance again.
                    $this->settlement->adjust($invoice, AdjustmentKind::Debit, AdjustmentReason::ItemCancellation, -$gross, $label, userId: $userId, taxableAmount: -($gross - $item->tax_amount), taxAmount: -$item->tax_amount, at: $at);

                    continue;
                }

                // On a partly paid invoice the credit note can't exceed the balance; its tax split is prorated.
                $credited = min($gross, max(0, $invoice->balance));
                $tax = $gross > 0 ? TaxMath::roundDiv($item->tax_amount * $credited, $gross) : 0;

                if ($credited > 0) {
                    $this->settlement->adjust($invoice, AdjustmentKind::Credit, AdjustmentReason::ItemCancellation, $credited, $label, userId: $userId, taxableAmount: $credited - $tax, taxAmount: $tax, at: $at);
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

    /**
     * One credit note with a line per cancelled line (`lines_cancelled`); when the selection is every open line, the invoice
     * is cancelled instead and gets the single `invoice_cancelled` note for its open balance.
     *
     * @param  Collection<int, InvoiceItem>  $items
     */
    private function withCreditNote(Invoice $invoice, Collection $items, ?int $userId, ?string $note, CarbonImmutable $at): Invoice
    {
        if ($userId !== null && trim((string) $note) === '') {
            throw new BillingException(__('Say why the lines are cancelled: the reason is printed on the credit note.'));
        }

        $lastOnes = ! $invoice->items()->whereNull('cancelled_at')->whereNotIn('id', $items->modelKeys())->exists();
        $mark = fn () => $items->each(fn (InvoiceItem $item) => $item->update(['cancelled_at' => $at, 'period_guard' => null]));

        if ($lastOnes) {
            $mark();

            return $this->cancel->handle($invoice, $userId, $note);
        }

        if ($items->contains(fn (InvoiceItem $item): bool => $item->amount < 0)) {
            throw new BillingException(__('A discount line is cancelled with the line it discounts, not on its own.'));
        }

        // The note can't exceed the balance; each line takes what is left of it, in order.
        $left = max(0, $invoice->balance);
        $lines = [];

        foreach ($items as $item) {
            $credited = min($invoice->tax_inclusive ? $item->amount : $item->amount + $item->tax_amount, $left);

            if ($credited > 0) {
                $lines[$item->id] = $credited;
                $left -= $credited;
            }
        }

        if ($lines !== []) {
            $this->notes->issueNow(NoteRequest::creditLines($invoice->id, $lines, 'lines_cancelled', (string) $note !== '' ? (string) $note : __('Lines cancelled'), $userId === null, $userId), $at);
        }

        $mark();

        return $invoice->refresh();
    }
}
