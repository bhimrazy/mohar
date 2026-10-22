<?php

use App\Modules\Billing\Database\Seeders\BillingSeeder;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Database\Seeders\CoreSeeder;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;

// The 730-day history takes tens of seconds to seed: seed it once per worker, each test reads it in a transaction.
beforeEach(function () {
    $this->useSeededDatabase('billing_history', function () {
        $this->seed(CoreSeeder::class);
        $this->seed(BillingSeeder::class);
    });
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

it('bills as the demo Nepal seller: VAT on Nepal invoices, no Indian GST', function () {
    $issued = Invoice::query()->whereNotNull('number')->get();
    $names = $issued->flatMap(fn (Invoice $invoice) => [$invoice->tax1_name, $invoice->tax2_name])->filter()->unique();

    expect(app(Settings::class)->get('billing.tax.home_country'))->toBe('NP')
        ->and($names->intersect(['CGST', 'SGST', 'IGST'])->all())->toBe([])
        ->and($issued->where('currency', 'NPR')->contains('tax1_name', 'VAT'))->toBeTrue();
});

it('never issues an invoice after its due date', function () {
    $invoices = Invoice::query()->whereNotNull('number')->get();

    expect($invoices)->not->toBeEmpty()
        ->and($invoices->filter(fn (Invoice $invoice) => $invoice->issue_date->gt($invoice->due_date))->count())->toBe(0);
});

it('shows the showcase client as a Nepali company: NPR, 13% VAT, buyer PAN, eSewa or Khalti', function () {
    $demo = Client::query()->where('email', 'demo@example.com')->sole();
    $invoices = Invoice::query()->where('client_id', $demo->id)->whereNotNull('number')->orderBy('id')->get();
    $paid = $invoices->where('status', InvoiceStatus::Paid)->first();

    expect($demo->id)->toBe(Client::query()->min('id'))
        ->and([$demo->country, $demo->currency, $demo->state, $demo->city])->toBe(['NP', 'NPR', 'Bagmati', 'Lalitpur'])
        ->and($demo->tax_id)->toMatch('/^\d{9}$/')
        ->and($invoices->pluck('currency')->unique()->all())->toBe(['NPR'])
        ->and($invoices->every(fn (Invoice $invoice) => $invoice->tax1_name === 'VAT' && (float) $invoice->tax1_rate === 13.0))->toBeTrue()
        ->and($invoices->map(fn (Invoice $invoice) => $invoice->client_snapshot['tax_id'] ?? null)->unique()->values()->all())->toBe([$demo->tax_id])
        ->and($paid->payment_gateway)->toBeIn(['esewa', 'khalti'])
        ->and($invoices->load('items')->flatMap(fn (Invoice $invoice) => $invoice->items)->pluck('description')->filter(fn ($text) => str_contains($text, 'Domain'))->all())->each->not->toContain('.np')
        ->and(Transaction::query()->whereIn('invoice_id', $invoices->pluck('id'))->where('type', TransactionType::Refund)->exists())->toBeFalse()
        ->and($paid->issue_date->lt($paid->due_date))->toBeTrue()
        ->and($invoices->where('status', InvoiceStatus::Unpaid)->contains(fn (Invoice $invoice) => $invoice->due_date->isPast()))->toBeTrue();
});

it('keeps exactly one US client, and it is not the first', function () {
    $us = Client::query()->where('currency', 'USD')->get();

    expect($us)->toHaveCount(1)
        ->and($us->first()->country)->toBe('US')
        ->and($us->first()->id)->toBeGreaterThan(Client::query()->min('id'))
        ->and(Client::query()->where('currency', 'NPR')->count())->toBeGreaterThan(10)
        ->and(Invoice::query()->where('client_id', $us->first()->id)->exists())->toBeTrue();
});

it('runs the demo as a Nepal install: NPR default, Nepal time, local date format, BS dates on, one PAN/VAT number', function () {
    $settings = app(Settings::class);

    expect(Currency::default()->code)->toBe('NPR')
        ->and($settings->get('system.timezone'))->toBe('Asia/Kathmandu')
        ->and($settings->get('core.date_format'))->toBe('j M Y')
        ->and($settings->get('billing.show_bs_dates'))->toBeTrue()
        ->and($settings->get('billing.seller.pan'))->toBeEmpty()
        ->and($settings->get('billing.seller.vat_number'))->toBe('600000001');
});

it('keeps the one seeded refund off the showcase client', function () {
    $refunds = Transaction::query()->where('type', TransactionType::Refund)->get();
    $demo = Client::query()->where('email', 'demo@example.com')->value('id');

    expect($refunds)->not->toBeEmpty()
        ->and($refunds->every(fn (Transaction $refund) => $refund->client_id !== $demo))->toBeTrue();
});
