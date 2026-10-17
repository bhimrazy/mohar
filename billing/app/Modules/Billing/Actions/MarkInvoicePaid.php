<?php

namespace App\Modules\Billing\Actions;

use App\Contracts\Orders\PaymentGuard;
use App\Modules\Billing\Enums\AdjustmentKind;
use App\Modules\Billing\Enums\AdjustmentReason;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\InvoiceSettlement;
use Illuminate\Support\Facades\DB;

/**
 * Staff "mark paid" without money (§4.3): a zero-amount manual transaction plus a write-off credit note for the
 * remaining balance, so reports can tell write-offs from income.
 */
class MarkInvoicePaid
{
    public function __construct(
        private InvoiceSettlement $settlement,
        private PaymentGuard $guard,
    ) {}

    public function handle(Invoice $invoice, ?int $userId = null, ?string $note = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $userId, $note): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! $invoice->status->isPayable() || $invoice->balance <= 0) {
                throw new BillingException(__('Only unpaid invoices with a balance can be marked as paid.'));
            }

            if (($reason = $this->guard->refusalReason($invoice->id)) !== null) {
                throw new BillingException($reason);
            }

            $transaction = Transaction::query()->create([
                'client_id' => $invoice->client_id,
                'invoice_id' => $invoice->id,
                'gateway' => 'manual',
                'type' => TransactionType::Payment,
                'status' => TransactionStatus::Succeeded,
                'currency' => $invoice->currency,
                'description' => $note ?? __('Marked as paid (write-off)'),
                'created_by' => $userId,
                'occurred_at' => now(),
            ]);

            $this->settlement->adjust($invoice, AdjustmentKind::Credit, AdjustmentReason::WriteOff, $invoice->balance, $note ?? __('Written off by staff'), $transaction->id, $userId);
            $this->settlement->settle($invoice);

            return $invoice;
        });
    }
}
