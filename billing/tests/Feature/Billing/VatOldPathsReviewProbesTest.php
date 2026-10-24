<?php

use App\Contracts\Billing\Invoicer;
use App\Models\User;
use App\Modules\Billing\Actions\ApplyCredit;
use App\Modules\Billing\Actions\ApplyLateFee;
use App\Modules\Billing\Actions\CancelInvoice;
use App\Modules\Billing\Actions\CancelInvoiceItems;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceFiscalReview;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\BillingAutomation;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\NoteRefusals;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Support\Billing\InvoiceNotCancellable;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

// Review probes for PR #451 (VAT PR 3b). NPR, VAT 13%, mode in effect since 2026-01-01.
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-20 06:00:00');
    inCompanyTimezone();
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR']);
    useNepalVatRule(TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']));
    billingSettings(['late_fee.type' => 'fixed', 'late_fee.amount' => 1_130, 'late_fee.minimum' => 0]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function oldPathProbeInvoice(array $lines = [10_000], ?CarbonImmutable $due = null): Invoice
{
    return issueInvoice(Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']), $lines, $due)->fresh(['items']);
}

function oldPathProbeStaff(): int
{
    return User::factory()->staff(['billing.*'])->create()->id;
}

test('P1 staff can cancel a numbered invoice that took a late fee in the mode', function () {
    $invoice = oldPathProbeInvoice();
    app(ApplyLateFee::class)->handle($invoice);
    expect($invoice->fresh()->balance)->toBe(12_577);

    app(CancelInvoice::class)->handle($invoice->fresh(), oldPathProbeStaff(), 'Client left');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Cancelled);
});

test('P2 auto-cancel keeps going past an invoice whose note cannot be written', function () {
    billingSettings(['auto_cancel.days_overdue' => 5]);
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    $first = issueInvoice($client, [10_000], CarbonImmutable::parse('2026-10-01'));
    $second = issueInvoice($client, [10_000], CarbonImmutable::parse('2026-10-01'));
    app(ApplyLateFee::class)->handle($first);

    $error = null;
    try {
        app(BillingAutomation::class)->autoCancel(CarbonImmutable::parse('2026-10-20'));
    } catch (Throwable $e) {
        $error = $e::class.': '.$e->getMessage();
    }

    expect($error)->toBeNull()->and($second->fresh()->status)->toBe(InvoiceStatus::Cancelled);
});

test('P3 a module cancel of an invoice with a late fee cancels it, and the note credits the late fee as a line of its own', function () {
    $invoice = oldPathProbeInvoice();
    app(ApplyLateFee::class)->handle($invoice);

    app(Invoicer::class)->cancel($invoice->id, 'Cancelled by Services');

    $note = TaxNote::query()->where('reason_code', 'invoice_cancelled')->with('lines')->sole();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Cancelled)->and($note)->total->toBe(12_577)
        ->and($note->lines->pluck('description')->all())->toContain('Late payment fee')
        ->and($note->lines->sum(fn ($line) => $line->amount + $line->tax1_amount + $line->tax2_amount))->toBe(12_577);
});

test('P4 partial payment: the cancel note is the open balance', function () {
    $invoice = oldPathProbeInvoice();
    payInvoice($invoice, 5_000);

    app(CancelInvoice::class)->handle($invoice->fresh(), oldPathProbeStaff(), 'Partial');

    $note = TaxNote::query()->sole();
    expect($note->total)->toBe(6_300)->and($note->taxable_amount + $note->tax1_amount)->toBe(6_300)->and($invoice->fresh())->status->toBe(InvoiceStatus::Cancelled)->balance->toBe(0);
});

test('P5 applied credit is returned, the note covers the full value', function () {
    $invoice = oldPathProbeInvoice();
    app(CreditLedgerService::class)->post($invoice->client_id, Money::of(3_000, 'NPR'), CreditReason::Manual, description: 'seed');
    app(ApplyCredit::class)->handle($invoice->fresh(), 3_000);

    app(CancelInvoice::class)->handle($invoice->fresh(), oldPathProbeStaff(), 'With credit');

    expect(TaxNote::query()->sole()->total)->toBe(11_300)->and(app(CreditLedgerService::class)->balance($invoice->client_id, 'NPR')->amount)->toBe(3_000);
});

test('P6 the late-fee run keeps going past an invoice whose debit note is refused', function () {
    billingSettings(['late_fee.amount' => 50_000, 'late_fee.days_overdue' => 0]);
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    issueInvoice($client, [1_000], CarbonImmutable::parse('2026-10-01'));
    $second = issueInvoice($client, [100_000], CarbonImmutable::parse('2026-10-01'));

    $error = null;
    try {
        app(BillingAutomation::class)->lateFees(CarbonImmutable::parse('2026-10-20'));
    } catch (Throwable $e) {
        $error = $e::class.': '.$e->getMessage();
    }

    expect($error)->toBeNull()->and($second->fresh()->late_fee_applied_at)->not->toBeNull();
});

test('P7 bulk cancel in the mode says which numbered invoices it skipped and why', function () {
    $invoice = oldPathProbeInvoice();
    billingStaff();
    confirmPassword();

    $this->post(route('admin.billing.invoices.bulk'), ['action' => 'cancel', 'ids' => [$invoice->id]])->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast.type', 'info');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)->and(session('inertia.flash_data.toast.message'))->toContain('Say why');
});

test('P8 cancel lines on a part-paid invoice', function () {
    $invoice = oldPathProbeInvoice([10_000, 4_000]);
    payInvoice($invoice, 12_000);

    app(CancelInvoiceItems::class)->handle($invoice->fresh(), [$invoice->items[1]->id], oldPathProbeStaff(), 'Drop addon');

    $note = TaxNote::query()->with('lines')->sole();
    // The note is capped at the open balance (45.20 line, 7.00 of it paid): the old path did the same.
    expect($note->total)->toBe(3_820)->and($note->lines)->toHaveCount(1)->and($invoice->fresh())->status->toBe(InvoiceStatus::Paid)->balance->toBe(0);
});

test('P9 a late fee written before the start day, then cancel after it', function () {
    billingSettings(['nepal_vat.enabled_from' => '2026-11-17']);
    $invoice = oldPathProbeInvoice();
    app(ApplyLateFee::class)->handle($invoice); // old-style late_fee row
    CarbonImmutable::setTestNow('2026-11-20 06:00:00');

    app(CancelInvoice::class)->handle($invoice->fresh(), oldPathProbeStaff(), 'After start');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Cancelled);
});

test('P10 a numbered invoice with a discount line can be cancelled whole in the mode', function () {
    $invoice = oldPathProbeInvoice([10_000, -2_000]);
    expect($invoice->items()->where('amount', '<', 0)->exists())->toBeTrue();

    app(CancelInvoice::class)->handle($invoice->fresh(), oldPathProbeStaff(), 'Whole invoice');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Cancelled);
});

test('P11 cancelling every line of a discounted invoice (the parent with its discount)', function () {
    $invoice = oldPathProbeInvoice([10_000, -2_000]);

    app(CancelInvoiceItems::class)->handle($invoice->fresh(), $invoice->items->modelKeys(), oldPathProbeStaff(), 'All lines');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Cancelled);
});

test('P1b the cancel note credits a discount line with the line it discounts: nothing is left to credit afterwards', function () {
    $invoice = oldPathProbeInvoice([10_000, -2_000]);
    app(CancelInvoice::class)->handle($invoice->fresh(), oldPathProbeStaff(), 'Whole invoice');

    $note = TaxNote::query()->with('lines')->sole();

    expect($note->total)->toBe($invoice->fresh()->fiscal_total)->and($note->lines)->toHaveCount(2)->and($note->lines->sum('amount'))->toBe(8_000)
        ->and($invoice->fresh())->credited_total->toBe($invoice->fresh()->fiscal_total)->balance->toBe(0);
});

test('a refused cancel is typed for the module and recorded for staff: the invoice stays payable', function () {
    $invoice = oldPathProbeInvoice();
    DB::table('invoice_items')->where('invoice_id', $invoice->id)->update(['tax_amount' => DB::raw('tax_amount + 1')]);

    expect(fn () => app(Invoicer::class)->cancel($invoice->id, 'Cancelled by Orders'))->toThrow(InvoiceNotCancellable::class, 'do not add up')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(app(NoteRefusals::class)->open())->toBe(1)
        ->and(ActivityLog::query()->where('event', 'billing.note_refused')->count())->toBe(1);

    // Cancelling it by hand later (the lines fixed) clears the entry.
    DB::table('invoice_items')->where('invoice_id', $invoice->id)->update(['tax_amount' => DB::raw('tax_amount - 1')]);
    app(CancelInvoice::class)->handle($invoice->fresh(), oldPathProbeStaff(), 'Fixed');

    expect(app(NoteRefusals::class)->open())->toBe(0);
});

test('the daily runs keep going past an invoice whose note is refused, and list it for staff', function () {
    billingSettings(['auto_cancel.days_overdue' => 5]);
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    $bad = issueInvoice($client, [10_000], CarbonImmutable::parse('2026-10-01'));
    $good = issueInvoice($client, [10_000], CarbonImmutable::parse('2026-10-01'));
    DB::table('invoice_items')->where('invoice_id', $bad->id)->update(['tax_amount' => DB::raw('tax_amount + 1')]);

    app(BillingAutomation::class)->autoCancel(CarbonImmutable::parse('2026-10-20'));

    expect($good->fresh()->status)->toBe(InvoiceStatus::Cancelled)->and($bad->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(InvoiceFiscalReview::query()->where('invoice_id', $bad->id)->where('reason', InvoiceFiscalReview::CANCEL_REFUSED)->exists())->toBeTrue();
});

test('an invoice whose tax was never classified is refused its cancel and late fee, listed for staff, and the run goes on', function () {
    billingSettings(['auto_cancel.days_overdue' => 5, 'late_fee.days_overdue' => 0]);
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    $bad = issueInvoice($client, [10_000], CarbonImmutable::parse('2026-10-01'));
    $good = issueInvoice($client, [10_000], CarbonImmutable::parse('2026-10-01'));
    DB::table('invoices')->where('id', $bad->id)->update(['fiscal_tax1_is_vat' => null, 'fiscal_tax2_is_vat' => null]);

    app(BillingAutomation::class)->lateFees(CarbonImmutable::parse('2026-10-20'));
    app(BillingAutomation::class)->autoCancel(CarbonImmutable::parse('2026-10-20'));

    expect($good->fresh()->status)->toBe(InvoiceStatus::Cancelled)->and($bad->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(InvoiceFiscalReview::query()->where('invoice_id', $bad->id)->pluck('reason')->sort()->values()->all())->toBe([InvoiceFiscalReview::CANCEL_REFUSED, InvoiceFiscalReview::LATE_FEE_REFUSED])
        ->and(app(NoteRefusals::class)->open())->toBe(2);
});
