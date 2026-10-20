<?php

namespace App\Modules\Billing\Services;

use App\Contracts\Billing\CreditLedger;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Core\Models\Client;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Append-only client credit ledger. Balance = latest balance_after per currency; appends lock the client row.
 */
class CreditLedgerService implements CreditLedger
{
    public function balance(int $clientId, string $currency): Money
    {
        $latest = CreditLedgerEntry::query()
            ->where('client_id', $clientId)
            ->where('currency', strtoupper($currency))
            ->latest('id')
            ->value('balance_after');

        return Money::of((int) ($latest ?? 0), $currency);
    }

    public function credit(int $clientId, Money $amount, string $description): void
    {
        if (! $amount->isPositive()) {
            throw new BillingException(__('Credit amounts must be positive.'));
        }

        $this->post($clientId, $amount, CreditReason::Manual, description: $description);
    }

    public function debit(int $clientId, Money $amount, string $description): void
    {
        if (! $amount->isPositive()) {
            throw new BillingException(__('Debit amounts must be positive.'));
        }

        $this->post($clientId, $amount->negate(), CreditReason::Manual, description: $description);
    }

    /**
     * Append a signed movement. Throws when the balance would go negative unless $allowNegative (reversals).
     * $gateway and $externalRef name the provider movement behind it; the pair is unique when set.
     */
    public function post(
        int $clientId,
        Money $amount,
        CreditReason $reason,
        ?int $invoiceId = null,
        ?int $transactionId = null,
        ?string $description = null,
        ?int $staffId = null,
        bool $allowNegative = false,
        ?string $gateway = null,
        ?string $externalRef = null,
    ): CreditLedgerEntry {
        return DB::transaction(function () use ($clientId, $amount, $reason, $invoiceId, $transactionId, $description, $staffId, $allowNegative, $gateway, $externalRef): CreditLedgerEntry {
            Client::query()->whereKey($clientId)->lockForUpdate()->firstOrFail();

            $balance = $this->balance($clientId, $amount->currency)->add($amount);

            if ($balance->isNegative() && ! $allowNegative && $amount->isNegative()) {
                throw new BillingException(__('Insufficient credit balance.'));
            }

            return CreditLedgerEntry::query()->create([
                'client_id' => $clientId,
                'currency' => $amount->currency,
                'amount' => $amount->amount,
                'balance_after' => $balance->amount,
                'reason' => $reason,
                'invoice_id' => $invoiceId,
                'transaction_id' => $transactionId,
                'description' => $description,
                'staff_id' => $staffId,
                'gateway' => $gateway,
                'external_ref' => $externalRef,
            ]);
        });
    }
}
