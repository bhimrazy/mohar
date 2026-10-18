<?php

use App\Modules\Billing\Database\Seeders\BillingSeeder;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Database\Seeders\CoreSeeder;

beforeEach(function () {
    $this->seed(CoreSeeder::class);
    $this->seed(BillingSeeder::class);
});

it('seeds about 90 days of billing history in every demo currency', function () {
    $issued = Invoice::query()->whereNotNull('number')->orderBy('issue_date')->orderBy('id')->get();

    expect($issued->first()->issue_date->lte(today()->subDays(85)))->toBeTrue()
        ->and($issued->pluck('currency')->unique()->sort()->values()->all())->toBe(['INR', 'NPR', 'USD'])
        ->and($issued->where('status', InvoiceStatus::Paid)->count())->toBeGreaterThan(100)
        ->and($issued->where('status', InvoiceStatus::Cancelled)->count())->toBeGreaterThan(0)
        ->and($issued->where('status', InvoiceStatus::Unpaid)->filter(fn (Invoice $invoice) => $invoice->due_date->isPast())->count())->toBeGreaterThan(5)
        ->and($issued->filter(fn (Invoice $invoice) => $invoice->amount_paid > 0 && $invoice->balance > 0)->count())->toBeGreaterThan(0)
        ->and(Transaction::query()->where('type', TransactionType::Refund)->exists())->toBeTrue();
});

it('keeps seeded ledgers and numbering consistent', function () {
    $issued = Invoice::query()->whereNotNull('number')->orderBy('id')->get();

    expect($issued->pluck('issue_date')->map->toDateString()->all())->toBe($issued->sortBy('issue_date')->pluck('issue_date')->map->toDateString()->values()->all())
        ->and($issued->every(fn (Invoice $invoice) => $invoice->balance === $invoice->total + $invoice->adjustments_total - $invoice->amount_paid
            || $invoice->status === InvoiceStatus::Cancelled))->toBeTrue()
        ->and($issued->where('status', InvoiceStatus::Paid)->every(fn (Invoice $invoice) => $invoice->balance === 0))->toBeTrue();

    $payments = Transaction::query()->with('invoice')->where('type', TransactionType::Payment)->get();
    expect($payments->pluck('occurred_at')->min()->lte(today()->subDays(80)))->toBeTrue()
        ->and($payments->every(fn (Transaction $payment) => $payment->occurred_at->gte($payment->invoice->issue_date->startOfDay())))->toBeTrue();
});
