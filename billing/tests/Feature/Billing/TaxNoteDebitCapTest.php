<?php

use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\BillingAutomation;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Carbon\CarbonImmutable;

require_once __DIR__.'/helpers.php';

/* The debit cap counts late fees and applies on paid invoices; a refunded invoice refuses debit notes (adopted from the PR #429 re-review probe). */

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-20 06:00:00');
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR', 'nepal_vat.enabled' => true,
        'late_fee.amount' => 10, 'late_fee.type' => 'percent', 'late_fee.days_overdue' => 7]);
    TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

$debitNoteData = fn (string $amount) => ['kind' => 'debit', 'amount' => $amount, 'taxable' => true, 'reason_code' => 'price_correction', 'reason_text' => 'x'];

test('a late fee uses up debit-note room, and the message says so with a formatted amount', function () use ($debitNoteData) {
    $invoice = issueInvoice(Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']), [1_000_000], dueDate: CarbonImmutable::parse('2026-08-01'));
    expect(app(BillingAutomation::class)->lateFees(CarbonImmutable::parse('2026-10-20')))->toBe(1);
    $invoice = $invoice->fresh();
    $fee = $invoice->debited_total;
    expect($fee)->toBeGreaterThan(0);
    billingStaff();
    $left = $invoice->fiscal_total - $fee;
    $over = number_format(($left + 1) / 100, 2, '.', '');
    $exact = number_format($left / 100, 2, '.', '');

    $this->getJson(route('admin.billing.invoices.notes.preview', [$invoice, ...$debitNoteData($over)]))->assertUnprocessable()
        ->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Debit notes and late fees together add at most the value of the invoice; Rs ') && ! str_contains($m, 'NPR'));
    $this->getJson(route('admin.billing.invoices.notes.preview', [$invoice, ...$debitNoteData($exact)]))->assertOk();
});

test('a debit note on a refunded invoice is refused with a reason', function () use ($debitNoteData) {
    $invoice = issueInvoice(Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']), [1_000_000]);
    payInvoice($invoice, 1_130_000);
    $invoice->fresh()->forceFill(['status' => InvoiceStatus::Refunded])->save();
    billingStaff();

    $this->getJson(route('admin.billing.invoices.notes.preview', [$invoice, ...$debitNoteData('100.00')]))->assertUnprocessable()
        ->assertJsonPath('message', 'The invoice is refunded, so it takes no debit note or reversal; raise a new invoice instead.');
});

test('a debit note on a paid invoice is capped too', function () use ($debitNoteData) {
    $invoice = issueInvoice(Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']), [1_000_000]);
    payInvoice($invoice, 1_130_000);
    billingStaff();

    $this->getJson(route('admin.billing.invoices.notes.preview', [$invoice, ...$debitNoteData('11300.01')]))->assertUnprocessable();
    $this->getJson(route('admin.billing.invoices.notes.preview', [$invoice, ...$debitNoteData('11300.00')]))->assertOk()->assertJsonPath('due_date', '2026-11-03');
});
