<?php

namespace App\Modules\Billing\Services;

use App\Contracts\Billing\ClientDocuments;
use App\Contracts\Billing\GatewayDirectory;
use App\Contracts\Billing\InvoiceQueries;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Quote;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Support\ClientPaymentOptions;

/**
 * Read-only billing facts for other modules (checkout gateway list, invoice payment history).
 */
class BillingDirectory implements ClientDocuments, GatewayDirectory, InvoiceQueries
{
    public function __construct(
        private GatewayManager $gateways,
        private ClientPaymentOptions $options,
    ) {}

    public function available(string $currency): array
    {
        return array_values($this->gateways->clientGateways()
            ->filter(fn (Gateway $gateway): bool => $this->options->acceptsCurrency($gateway, $currency))
            ->map(fn (Gateway $gateway): array => ['key' => $gateway->key, 'label' => $gateway->display_name])
            ->all());
    }

    public function hasPayments(int $invoiceId): bool
    {
        return Transaction::query()->where('invoice_id', $invoiceId)->exists()
            || Invoice::query()->whereKey($invoiceId)->where('credit_applied', '>', 0)->exists();
    }

    public function hasDocumentsInCurrency(string $currency): bool
    {
        $currency = strtoupper($currency);

        return Invoice::query()->where('currency', $currency)->exists()
            || Quote::query()->where('currency', $currency)->exists()
            || Transaction::query()->where('currency', $currency)->exists()
            || CreditLedgerEntry::query()->where('currency', $currency)->exists();
    }

    /**
     * Four indexed lookups, each only for the clients not found yet.
     */
    public function clientsWithDocuments(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $found = [];

        foreach ([Invoice::class, Quote::class, Transaction::class, CreditLedgerEntry::class] as $model) {
            $pending = array_values(array_diff($clientIds, $found));

            if ($pending !== []) {
                array_push($found, ...$model::query()->whereIn('client_id', $pending)->distinct()->pluck('client_id')->map(fn (mixed $id): int => (int) $id)->all());
            }
        }

        return array_values(array_unique($found));
    }
}
