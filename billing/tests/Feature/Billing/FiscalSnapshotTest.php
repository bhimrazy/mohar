<?php

use App\Modules\Billing\Actions\ApplyLateFee;
use App\Modules\Billing\Actions\CancelInvoiceItems;
use App\Modules\Billing\Actions\CreateAddFundsInvoice;
use App\Modules\Billing\Enums\AdjustmentKind;
use App\Modules\Billing\Enums\AdjustmentReason;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceFiscalReview;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\InvoiceSettlement;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

// Snapshot at numbering (ADR 0024 §2, AC 7 and 8): Nepal VAT 13%, NPR.

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-20 06:00:00');
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR']);
    TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function nepalClient(): Client
{
    return Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
}

test('an invoice numbered at publish stores its own totals, rate, class and BS fields', function () {
    $invoice = issueInvoice(nepalClient(), [10_000, 4_000])->fresh();

    expect($invoice)
        ->number->toBe('1')
        ->numbered_at->toEqual(CarbonImmutable::now())
        ->fiscal_taxable->toBe(14_000)->fiscal_exempt->toBe(0)->fiscal_tax1->toBe(1_820)->fiscal_tax2->toBe(0)->fiscal_total->toBe(15_820)
        ->report_currency->toBe('NPR')->report_rate->toBe('1.000000000000')
        ->supply_class->toBe('taxable')
        // 20 Oct 2026 is 3 Kartik 2083 BS, in the fiscal year 2083/84.
        ->bs_issue_date->toBe('2083-07-03')->bs_period->toBe('2083-07')->bs_fiscal_year->toBe('2083.084')
        ->credited_total->toBe(0)->debited_total->toBe(0);
});

test('the reporting rate is the stored cross rate as a decimal string, from the stored rates only', function () {
    Http::preventStrayRequests();
    $client = Client::factory()->create(['country' => 'US', 'currency' => 'USD']);
    $invoice = issueInvoice($client, [10_000])->fresh();

    expect($invoice->report_currency)->toBe('NPR')->and($invoice->report_rate)->toBe('134.400000000000');
});

test('a proforma has no number and no snapshot until it is paid, then both are written in that transaction (AC 7)', function () {
    billingSettings(['sequential_paid_numbering' => true]);
    $invoice = issueInvoice(nepalClient(), [10_000]);

    expect($invoice->fresh())->number->toBeNull()->numbered_at->toBeNull()->fiscal_total->toBeNull()->bs_period->toBeNull();

    CarbonImmutable::setTestNow('2026-10-21 08:30:00');
    payInvoice($invoice, 11_300);
    $invoice = $invoice->fresh();

    expect($invoice)->number->toBe('1')->status->toBe(InvoiceStatus::Paid)
        ->numbered_at->toEqual(CarbonImmutable::parse('2026-10-21 08:30:00'))
        ->fiscal_total->toBe(11_300)->fiscal_taxable->toBe(10_000)->fiscal_tax1->toBe(1_300)
        ->issue_date->toDateString()->toBe('2026-10-21');
});

test('a collections invoice paid again gets a new paid_at but keeps its number, numbered_at and snapshot (AC 7)', function () {
    billingSettings(['sequential_paid_numbering' => true]);
    $invoice = issueInvoice(nepalClient(), [10_000]);
    payInvoice($invoice, 11_300);
    $first = $invoice->fresh();

    CarbonImmutable::setTestNow('2026-11-05 09:00:00');
    DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'collections', 'amount_paid' => 0, 'balance' => 11_300]);
    payInvoice($invoice->fresh(), 11_300, 'again');
    $again = $invoice->fresh();

    expect($again->status)->toBe(InvoiceStatus::Paid)
        ->and($again->paid_at->toDateString())->toBe('2026-11-05')
        ->and($again->number)->toBe($first->number)
        ->and($again->numbered_at->equalTo($first->numbered_at))->toBeTrue()
        ->and([$again->fiscal_total, $again->bs_period, $again->issue_date->toDateString()])->toBe([$first->fiscal_total, $first->bs_period, $first->issue_date->toDateString()]);
});

test('a proforma with a late fee and a line cancelled by the settling call stores fee and cancellation (AC 8)', function () {
    billingSettings(['sequential_paid_numbering' => true, 'late_fee.type' => 'fixed', 'late_fee.fixed_amount' => 500]);
    $invoice = issueInvoice(nepalClient(), [10_000, 4_000]);
    $second = $invoice->items()->orderBy('id')->get()->last();

    expect(app(ApplyLateFee::class)->handle($invoice))->toBeTrue();
    // Fee 500 net + 65 VAT. Pay line one in full with its tax and the fee; the cancelled line is what is left.
    payInvoice($invoice->fresh(), 11_300 + 565);
    app(CancelInvoiceItems::class)->handle($invoice->fresh(), [$second->id]);
    $invoice = $invoice->fresh();

    expect($invoice->number)->toBe('1')
        ->and([$invoice->fiscal_taxable, $invoice->fiscal_exempt, $invoice->fiscal_tax1, $invoice->fiscal_tax2, $invoice->fiscal_total])
        // (10,000 + 4,000 + 500 - 4,000) taxable; (1,300 + 520 + 65 - 520) VAT; 15,820 + 565 - 4,520 = 11,865.
        ->toBe([10_500, 0, 1_365, 0, 11_865])
        // Both rows were written before the number, so they are inside the snapshot and not in the caps.
        ->and([$invoice->credited_total, $invoice->debited_total])->toBe([0, 0]);
});

test('an adjustment after numbering adds to the credited or debited total and never to the snapshot', function () {
    $invoice = issueInvoice(nepalClient(), [10_000])->fresh();
    DB::transaction(function () use ($invoice) {
        $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
        app(InvoiceSettlement::class)->adjust($invoice, AdjustmentKind::Debit, AdjustmentReason::LateFee, 565, taxableAmount: 500, taxAmount: 65);
        app(InvoiceSettlement::class)->adjust($invoice, AdjustmentKind::Credit, AdjustmentReason::Cancellation, 100);
        app(InvoiceSettlement::class)->adjust($invoice, AdjustmentKind::Debit, AdjustmentReason::Overpayment, 9);
    });

    expect($invoice->fresh())->fiscal_total->toBe(11_300)->debited_total->toBe(565)->credited_total->toBe(100);
});

test('an add-funds invoice is a deposit and gets no snapshot', function () {
    billingSettings(['credit.require_accepted_order' => false]);
    $client = nepalClient();
    $invoice = app(CreateAddFundsInvoice::class)->handle($client, 50_000);
    $row = Invoice::query()->findOrFail($invoice->id);

    expect($row->number)->not->toBeNull()->and($row->numbered_at)->toBeNull()->and($row->fiscal_total)->toBeNull();
});

test('a missing exchange rate leaves the rate empty and puts the invoice on the review list, without failing the publish', function () {
    billingSettings(['tax.report_currency' => 'JPY']);
    $invoice = issueInvoice(nepalClient(), [10_000])->fresh();

    expect($invoice)->number->not->toBeNull()->report_rate->toBeNull()->fiscal_total->toBe(11_300)
        ->and(InvoiceFiscalReview::query()->where('invoice_id', $invoice->id)->value('reason'))->toBe(InvoiceFiscalReview::REPORT_RATE);
});
