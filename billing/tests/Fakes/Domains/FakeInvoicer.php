<?php

namespace Tests\Fakes\Domains;

use App\Contracts\Billing\Invoicer;
use App\Data\Billing\InvoiceDraft;
use App\Data\Billing\InvoiceLineData;
use App\Data\Billing\InvoiceResult;
use App\Enums\BillableType;
use App\Events\Billing\InvoicePaid;
use App\Support\Billing\InvoiceNotCancellable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * In-memory Invoicer for Domains tests (Billing is a sibling module).
 */
class FakeInvoicer implements Invoicer
{
    /** @var array<int, array{draft: InvoiceDraft, status: string}> */
    public array $invoices = [];

    /** @var list<int> */
    public array $cancelled = [];

    private int $nextId = 1000;

    public static function bind(): self
    {
        $fake = new self;
        app()->instance(Invoicer::class, $fake);

        return $fake;
    }

    public function issue(InvoiceDraft $draft): InvoiceResult
    {
        $id = ++$this->nextId;
        $total = array_sum(array_map(fn (InvoiceLineData $line): int => $line->total(), $draft->lines));
        $status = $total === 0 && $draft->status === 'unpaid' ? 'paid' : $draft->status;
        $this->invoices[$id] = ['draft' => $draft, 'status' => $status];

        if ($status === 'paid') {
            // Contract: a zero-total unpaid invoice is paid immediately and InvoicePaid is dispatched (after commit).
            DB::afterCommit(fn () => event(new InvoicePaid($id, $draft->clientId, $draft->lines)));
        }

        return new InvoiceResult($id, 'INV-'.$id, $status, $draft->currency, $total, 0, $total);
    }

    /** Billing refuses every cancel (a tax invoice whose credit note it cannot write): the invoice stays unpaid. */
    public bool $refuseCancel = false;

    public function cancel(int $invoiceId, ?string $reason = null): void
    {
        if ($this->refuseCancel && isset($this->invoices[$invoiceId]) && $this->invoices[$invoiceId]['status'] === 'unpaid') {
            throw new InvoiceNotCancellable('Scripted refusal');
        }

        if (isset($this->invoices[$invoiceId]) && in_array($this->invoices[$invoiceId]['status'], ['unpaid', 'draft'], true)) {
            $this->invoices[$invoiceId]['status'] = 'cancelled';
            $this->cancelled[] = $invoiceId;
        }
    }

    public function hasOpenInvoiceFor(BillableType $type, int $billableId): bool
    {
        foreach ($this->invoices as $invoice) {
            if (in_array($invoice['status'], ['unpaid', 'draft'], true) && $this->bills($invoice['draft'], $type, $billableId)) {
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
     * Mark paid and dispatch InvoicePaid like Billing would.
     */
    public function pay(int $invoiceId): void
    {
        $draft = $this->invoices[$invoiceId]['draft'];
        $this->invoices[$invoiceId] = ['draft' => $draft, 'status' => 'paid'];
        event(new InvoicePaid($invoiceId, $draft->clientId, $draft->lines));
    }

    /**
     * @return list<array{draft: InvoiceDraft, status: string}>
     */
    public function issued(): array
    {
        return array_values($this->invoices);
    }

    public function lastInvoiceId(): ?int
    {
        return array_key_last($this->invoices);
    }

    private function bills(InvoiceDraft $draft, BillableType $type, int $billableId): bool
    {
        foreach ($draft->lines as $line) {
            if ($line->billableType === $type && $line->billableId === $billableId) {
                return true;
            }
        }

        return false;
    }
}
