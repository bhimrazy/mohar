<?php

namespace Tests\Fakes\Services;

use App\Contracts\Billing\Invoicer;
use App\Data\Billing\InvoiceDraft;
use App\Data\Billing\InvoiceLineData;
use App\Data\Billing\InvoiceResult;
use App\Enums\BillableType;
use App\Events\Billing\InvoiceCancelled;
use App\Events\Billing\InvoicePaid;
use App\Support\Billing\InvoiceNotCancellable;
use Carbon\CarbonImmutable;

/**
 * In-memory Invoicer for Services tests. Mirrors the contract: zero-total invoices are paid on
 * issue, cancel() is a no-op for paid invoices, and paying dispatches InvoicePaid with the lines.
 */
class FakeInvoicer implements Invoicer
{
    /** @var array<int, array{draft: InvoiceDraft, status: string}> */
    public array $invoices = [];

    private int $nextId = 7001;

    public function issue(InvoiceDraft $draft): InvoiceResult
    {
        $id = $this->nextId++;
        $total = array_sum(array_map(fn (InvoiceLineData $line): int => $line->total(), $draft->lines));
        $this->invoices[$id] = ['draft' => $draft, 'status' => $draft->status];

        if ($total === 0 && $draft->status === 'unpaid') {
            $this->pay($id);
        }

        return new InvoiceResult($id, "INV-{$id}", $this->invoices[$id]['status'], $draft->currency, $total, 0, $total);
    }

    /** Billing refuses every cancel (a tax invoice whose credit note it cannot write): the invoice stays unpaid. */
    public bool $refuseCancel = false;

    public function cancel(int $invoiceId, ?string $reason = null, bool $notifyClient = true): void
    {
        if ($this->refuseCancel && isset($this->invoices[$invoiceId]) && $this->invoices[$invoiceId]['status'] === 'unpaid') {
            throw new InvoiceNotCancellable('Scripted refusal');
        }

        if (! isset($this->invoices[$invoiceId]) || in_array($this->invoices[$invoiceId]['status'], ['paid', 'cancelled'], true)) {
            return;
        }

        $this->invoices[$invoiceId]['status'] = 'cancelled';
        InvoiceCancelled::dispatch($invoiceId, $this->invoices[$invoiceId]['draft']->clientId);
    }

    public function hasOpenInvoiceFor(BillableType $type, int $billableId): bool
    {
        foreach ($this->invoices as $invoice) {
            if (in_array($invoice['status'], ['unpaid', 'draft'], true) && $this->billsItem($invoice['draft'], $type, $billableId)) {
                return true;
            }
        }

        return false;
    }

    public function hasInvoiceForPeriod(BillableType $type, int $billableId, CarbonImmutable $periodStart): bool
    {
        foreach ($this->invoices as $invoice) {
            if ($invoice['status'] === 'cancelled') {
                continue;
            }

            foreach ($invoice['draft']->lines as $line) {
                if ($line->billableType === $type && $line->billableId === $billableId && $line->periodStart?->isSameDay($periodStart)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function status(int $invoiceId): ?string
    {
        return $this->invoices[$invoiceId]['status'] ?? null;
    }

    /**
     * Mark paid and dispatch InvoicePaid (what Billing does after a payment).
     */
    public function pay(int $invoiceId): void
    {
        $this->invoices[$invoiceId]['status'] = 'paid';
        $draft = $this->invoices[$invoiceId]['draft'];
        InvoicePaid::dispatch($invoiceId, $draft->clientId, $draft->lines);
    }

    /**
     * @return array<int, array{draft: InvoiceDraft, status: string}>
     */
    public function invoicesFor(BillableType $type, int $billableId): array
    {
        return array_filter($this->invoices, fn (array $invoice): bool => $this->billsItem($invoice['draft'], $type, $billableId));
    }

    public function lastInvoiceId(): ?int
    {
        return array_key_last($this->invoices);
    }

    private function billsItem(InvoiceDraft $draft, BillableType $type, int $billableId): bool
    {
        foreach ($draft->lines as $line) {
            if ($line->billableType === $type && $line->billableId === $billableId) {
                return true;
            }
        }

        return false;
    }
}
