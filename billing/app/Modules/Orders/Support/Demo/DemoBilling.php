<?php

namespace App\Modules\Orders\Support\Demo;

use App\Contracts\Billing\GatewayDirectory;
use App\Contracts\Billing\Invoicer;
use App\Contracts\Billing\TaxCalculator;
use App\Data\Billing\InvoiceDraft;
use App\Data\Billing\InvoiceLineData;
use App\Data\Billing\InvoiceResult;
use App\Data\Billing\TaxTotals;
use App\Enums\BillableType;
use App\Events\Billing\InvoicePaid;
use App\Modules\Core\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * LOCAL DEMO ONLY: a cache-backed invoicer, a flat demo tax rate and two gateways until the Billing module
 * is merged.
 */
class DemoBilling implements GatewayDirectory, Invoicer, TaxCalculator
{
    /** @var array<string, array{name: string, rate: float}> */
    private const RATES = [
        'US' => ['name' => 'Sales tax', 'rate' => 7.5],
        'GB' => ['name' => 'VAT', 'rate' => 20.0],
        'DE' => ['name' => 'VAT', 'rate' => 19.0],
    ];

    public function available(string $currency): array
    {
        return [
            ['key' => 'bank_transfer', 'label' => 'Bank transfer'],
            ['key' => 'sandbox_card', 'label' => 'Card'],
        ];
    }

    public function issue(InvoiceDraft $draft): InvoiceResult
    {
        $tax = $this->calculateForClient($draft->lines, $draft->clientId, $draft->currency);
        $id = self::nextId('orders.demo.invoice_id');
        $status = $tax->total === 0 ? 'paid' : $draft->status;

        Cache::forever("orders.demo.invoice.{$id}", $status);

        if ($status === 'paid') {
            InvoicePaid::dispatch($id, $draft->clientId, $draft->lines);
        }

        return new InvoiceResult($id, 'DEMO-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT), $status, $draft->currency, $tax->subtotal, $tax->taxAmount(), $tax->total);
    }

    public function cancel(int $invoiceId, ?string $reason = null, bool $notifyClient = true): void
    {
        if ($this->status($invoiceId) === 'unpaid') {
            Cache::forever("orders.demo.invoice.{$invoiceId}", 'cancelled');
        }
    }

    public function hasOpenInvoiceFor(BillableType $type, int $billableId): bool
    {
        return false;
    }

    public function hasInvoiceForPeriod(BillableType $type, int $billableId, CarbonImmutable $periodStart): bool
    {
        return false;
    }

    public function status(int $invoiceId): ?string
    {
        $status = Cache::get("orders.demo.invoice.{$invoiceId}");

        return is_string($status) ? $status : null;
    }

    public function calculate(array $lines, string $currency, ?string $country, ?string $state, bool $taxExempt = false): TaxTotals
    {
        $subtotal = array_sum(array_map(fn (InvoiceLineData $line): int => $line->total(), $lines));
        $taxable = array_sum(array_map(fn (InvoiceLineData $line): int => $line->taxable ? $line->total() : 0, $lines));
        $rate = $taxExempt ? null : (self::RATES[strtoupper((string) $country)] ?? null);
        $taxes = $rate === null || $taxable <= 0 ? [] : [['name' => $rate['name'], 'rate' => $rate['rate'], 'amount' => (int) round($taxable * $rate['rate'] / 100)]];

        return new TaxTotals($currency, $subtotal, $taxes, $subtotal + array_sum(array_column($taxes, 'amount')));
    }

    public function calculateForClient(array $lines, int $clientId, string $currency): TaxTotals
    {
        $client = Client::query()->find($clientId);

        return $this->calculate($lines, $currency, $client?->country, $client?->state, (bool) $client?->tax_exempt);
    }

    /**
     * Sequential demo id (Cache::increment returns false for a missing key on some stores).
     */
    private static function nextId(string $key): int
    {
        Cache::add($key, 0);

        return (int) Cache::increment($key);
    }
}
