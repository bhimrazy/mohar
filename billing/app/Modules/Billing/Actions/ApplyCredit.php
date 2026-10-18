<?php

namespace App\Modules\Billing\Actions;

use App\Contracts\Orders\PaymentGuard;
use App\Events\Billing\InvoiceModified;
use App\Modules\Billing\Enums\AdjustmentKind;
use App\Modules\Billing\Enums\AdjustmentReason;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\InvoiceSettlement;
use App\Modules\Billing\Support\AfterCommit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Pay (part of) an invoice from the client's credit balance: amount ≤ min(balance, credit) (§4.4).
 */
class ApplyCredit
{
    public function __construct(
        private InvoiceSettlement $settlement,
        private CreditLedgerService $credit,
        private PaymentGuard $guard,
    ) {}

    public function handle(Invoice $invoice, int $amount, ?int $userId = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $amount, $userId): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->kind === InvoiceKind::AddFunds) {
                throw new BillingException(__('Credit can\'t be applied to an add-funds invoice.'));
            }

            if (! $invoice->status->isPayable()) {
                throw new BillingException(__('Credit can only be applied to unpaid invoices.'));
            }

            if (($reason = $this->guard->refusalReason($invoice->id)) !== null) {
                throw new BillingException($reason);
            }

            if ($amount <= 0 || $amount > $invoice->balance) {
                throw new BillingException(__('Enter an amount between 0.01 and the invoice balance.'));
            }

            $this->credit->post($invoice->client_id, Money::of(-$amount, $invoice->currency), CreditReason::AppliedToInvoice, $invoice->id, description: __('Applied to invoice :number', ['number' => $invoice->displayNumber()]), staffId: $userId);

            $invoice->credit_applied += $amount;
            $this->settlement->adjust($invoice, AdjustmentKind::Credit, AdjustmentReason::CreditApplied, $amount, __('Account credit applied'), userId: $userId);

            if (! $this->settlement->settle($invoice)) {
                AfterCommit::dispatch(new InvoiceModified($invoice->id, $invoice->client_id));
            }

            return $invoice;
        });
    }
}
