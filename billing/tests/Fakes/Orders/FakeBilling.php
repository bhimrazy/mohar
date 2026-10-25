<?php

namespace Tests\Fakes\Orders;

use App\Contracts\Billing\Invoicer;
use App\Contracts\Billing\TaxCalculator;
use App\Data\Billing\InvoiceDraft;
use App\Data\Billing\InvoiceLineData;
use App\Data\Billing\InvoiceResult;
use App\Data\Billing\TaxTotals;
use App\Enums\BillableType;
use App\Events\Billing\InvoicePaid;
use Carbon\CarbonImmutable;

/**
 * Invoicer + TaxCalculator double: a flat tax rate on taxable lines; zero-total invoices are marked paid
 * and dispatch InvoicePaid, as the contract requires.
 */
class FakeBilling implements Invoicer, TaxCalculator
{
    /** Tax rate in percent applied to taxable lines. */
    public float $taxRate = 0;

    /** @var array<int, InvoiceDraft> */
    public array $issued = [];

    /** @var array<int, string> */
    public array $statuses = [];

    /** @var list<int> */
    public array $cancelled = [];

    /** @var array<int, bool> invoice id => whether its cancel may email the client */
    public array $cancelNotifies = [];

    private int $nextId = 900;

    public function issue(InvoiceDraft $draft): InvoiceResult
    {
        $id = $this->nextId++;
        $totals = $this->calculate($draft->lines, $draft->currency, null, null);
        $this->issued[$id] = $draft;
        $this->statuses[$id] = $totals->total === 0 ? 'paid' : $draft->status;

        if ($totals->total === 0) {
            InvoicePaid::dispatch($id, $draft->clientId, $draft->lines);
        }

        return new InvoiceResult($id, "INV-{$id}", $this->statuses[$id], $draft->currency, $totals->subtotal, $totals->taxAmount(), $totals->total);
    }

    public function cancel(int $invoiceId, ?string $reason = null, bool $notifyClient = true): void
    {
        $this->cancelled[] = $invoiceId;
        $this->cancelNotifies[$invoiceId] = $notifyClient;

        if (in_array($this->statuses[$invoiceId] ?? null, ['unpaid', 'draft'], true)) {
            $this->statuses[$invoiceId] = 'cancelled';
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
        return $this->statuses[$invoiceId] ?? null;
    }

    public function calculate(array $lines, string $currency, ?string $country, ?string $state, bool $taxExempt = false): TaxTotals
    {
        $subtotal = array_sum(array_map(fn (InvoiceLineData $line): int => $line->total(), $lines));
        $taxable = array_sum(array_map(fn (InvoiceLineData $line): int => $line->taxable ? $line->total() : 0, $lines));
        $tax = $this->taxRate > 0 && ! $taxExempt ? (int) round($taxable * $this->taxRate / 100) : 0;
        $taxes = $tax > 0 ? [['name' => 'VAT', 'rate' => $this->taxRate, 'amount' => $tax]] : [];

        return new TaxTotals($currency, $subtotal, $taxes, $subtotal + $tax);
    }

    public function calculateForClient(array $lines, int $clientId, string $currency): TaxTotals
    {
        return $this->calculate($lines, $currency, null, null);
    }

    public function lastDraft(): InvoiceDraft
    {
        $last = end($this->issued);

        if ($last === false) {
            throw new \RuntimeException('No invoice has been issued.');
        }

        return $last;
    }
}
