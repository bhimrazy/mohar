<?php

namespace App\Modules\Billing\Actions;

use App\Modules\Billing\Data\PaymentData;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Core\Models\Client;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Staff-entered income or expense. Income is applied to the given invoices in order; any remainder becomes credit.
 */
class RecordManualTransaction
{
    public function __construct(
        private ApplyPayment $applyPayment,
        private CreditLedgerService $credit,
    ) {}

    /**
     * @param  list<int>  $invoiceIds
     * @return list<Transaction>
     */
    public function handle(
        Client $client,
        TransactionType $type,
        int $amount,
        string $gateway,
        ?string $gatewayTxnId,
        ?string $description,
        CarbonImmutable $occurredAt,
        array $invoiceIds = [],
        int $fee = 0,
        ?int $userId = null,
    ): array {
        if ($amount <= 0) {
            throw new BillingException(__('Enter an amount greater than zero.'));
        }

        return DB::transaction(function () use ($client, $type, $amount, $gateway, $gatewayTxnId, $description, $occurredAt, $invoiceIds, $fee, $userId): array {
            if ($type === TransactionType::ManualExpense) {
                return [$this->standalone($client, $type, 0, $amount, $gateway, $gatewayTxnId, $description, $occurredAt, $fee, $userId)];
            }

            $transactions = [];
            $remaining = $amount;
            $invoices = Invoice::query()->where('client_id', $client->id)->whereIn('id', $invoiceIds)->get()->sortBy(fn (Invoice $invoice): int => (int) array_search($invoice->id, $invoiceIds, true));

            foreach ($invoices as $index => $invoice) {
                if ($remaining <= 0 || ! $invoice->status->isPayable() || $invoice->balance <= 0) {
                    continue;
                }

                $portion = min($remaining, $invoice->balance);
                $transactions[] = $this->applyPayment->handle($invoice, new PaymentData(
                    amount: $portion,
                    gateway: $gateway,
                    gatewayTxnId: $gatewayTxnId !== null ? $gatewayTxnId.($index > 0 ? '-'.$invoice->id : '') : null,
                    fee: $transactions === [] ? $fee : 0,
                    createdBy: $userId,
                    occurredAt: $occurredAt,
                    description: $description,
                    type: TransactionType::ManualIncome,
                ));
                $remaining -= $portion;
            }

            if ($remaining > 0) {
                $transaction = $this->standalone($client, $type, $remaining, 0, $gateway, $transactions === [] ? $gatewayTxnId : null, $description ?? __('Unallocated payment'), $occurredAt, $transactions === [] ? $fee : 0, $userId);
                $this->credit->post($client->id, Money::of($remaining, $client->currency), CreditReason::Manual, transactionId: $transaction->id, description: $description ?? __('Unallocated payment'), staffId: $userId);
                $transaction->update(['refundable_remaining' => 0]);
                $transactions[] = $transaction;
            }

            return $transactions;
        });
    }

    private function standalone(Client $client, TransactionType $type, int $in, int $out, string $gateway, ?string $gatewayTxnId, ?string $description, CarbonImmutable $occurredAt, int $fee, ?int $userId): Transaction
    {
        return Transaction::query()->create([
            'client_id' => $client->id,
            'gateway' => $gateway,
            'gateway_txn_id' => $gatewayTxnId,
            'type' => $type,
            'status' => TransactionStatus::Succeeded,
            'amount_in' => $in,
            'amount_out' => $out,
            'fee' => $fee,
            'currency' => $client->currency,
            'refundable_remaining' => $in,
            'description' => $description,
            'created_by' => $userId,
            'occurred_at' => $occurredAt,
        ]);
    }
}
