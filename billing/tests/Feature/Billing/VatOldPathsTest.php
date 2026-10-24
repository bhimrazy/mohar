<?php

use App\Contracts\Billing\Invoicer;
use App\Events\Billing\DebitNoteIssued;
use App\Events\Billing\LateFeeApplied;
use App\Models\User;
use App\Modules\Billing\Actions\ApplyLateFee;
use App\Modules\Billing\Actions\CancelInvoice;
use App\Modules\Billing\Actions\CancelInvoiceItems;
use App\Modules\Billing\Actions\ReopenInvoice;
use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\BillingAutomation;
use App\Modules\Billing\Services\NepalVatMode;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

/*
| The old paths in Nepal VAT mode (VAT PR 3b; vat-register §5.4, AC 21-25, ADR 0024 §8): a numbered tax invoice cancelled,
| cancelled by line or charged a late fee gets a note, not a bare adjustment. NPR, VAT 13%, Nepal time. These invoices are
| numbered at publish and unpaid (legacy), the case that exists when the mode starts.
*/

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-20 06:00:00');
    inCompanyTimezone();
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR']);
    useNepalVatRule(TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']));
});

afterEach(fn () => CarbonImmutable::setTestNow());

function oldPathStaff(): int
{
    return User::factory()->staff(['billing.*'])->create()->id;
}

function oldPathInvoice(array $lines = [10_000]): Invoice
{
    return issueInvoice(Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']), $lines)->fresh(['items']);
}

/** @return list<string> the reasons of an invoice's adjustments */
function oldPathReasons(Invoice $invoice): array
{
    return DB::table('invoice_adjustments')->where('invoice_id', $invoice->id)->orderBy('id')->pluck('reason')->all();
}

test('staff cancelling a numbered invoice issue one credit note with their reason and write no cancellation adjustment (AC 21)', function () {
    $invoice = oldPathInvoice();

    app(CancelInvoice::class)->handle($invoice, oldPathStaff(), 'Client asked to cancel');

    $note = TaxNote::query()->sole();

    expect($note)->kind->toBe('credit')->reason_code->toBe('invoice_cancelled')->reason_text->toBe('Client asked to cancel')->total->toBe(11_300)->automatic->toBeFalse()
        ->and($invoice->fresh())->status->toBe(InvoiceStatus::Cancelled)->balance->toBe(0)
        ->and(oldPathReasons($invoice))->toBe(['credit_note']);
});

test('staff must give a reason when cancelling a numbered invoice in the mode, and nothing is written without it', function () {
    $invoice = oldPathInvoice();

    expect(fn () => app(CancelInvoice::class)->handle($invoice, oldPathStaff()))->toThrow(BillingException::class, 'Say why')
        ->and(TaxNote::query()->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
});

test('a module cancelling through the Invoicer contract issues an automatic note naming the module', function () {
    $invoice = oldPathInvoice();

    app(Invoicer::class)->cancel($invoice->id, 'Cancelled by Orders');

    expect(TaxNote::query()->sole())->automatic->toBeTrue()->reason_code->toBe('invoice_cancelled')->reason_text->toBe('Cancelled by Orders')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Cancelled);
});

test('auto-cancel issues the credit note with the overdue text', function () {
    billingSettings(['auto_cancel.days_overdue' => 5]);
    $invoice = issueInvoice(Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']), [10_000], CarbonImmutable::parse('2026-10-10'));

    app(BillingAutomation::class)->autoCancel(CarbonImmutable::parse('2026-10-20'));

    expect(TaxNote::query()->sole())->reason_text->toBe('Automatically cancelled after 5 days overdue.')->automatic->toBeTrue()
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Cancelled);
});

test('a proforma invoice cancels as before: no note, nothing in the register (AC 23)', function () {
    billingSettings(['sequential_paid_numbering' => true]);
    $invoice = oldPathInvoice();

    app(CancelInvoice::class)->handle($invoice, oldPathStaff());

    expect(TaxNote::query()->count())->toBe(0)->and(oldPathReasons($invoice))->toBe(['cancellation']);
});

test('cancelling some lines issues one credit note with a line each, and the invoice stays open', function () {
    $invoice = oldPathInvoice([10_000, 4_000, 2_000]);

    app(CancelInvoiceItems::class)->handle($invoice, [$invoice->items[0]->id, $invoice->items[1]->id], oldPathStaff(), 'Wrong plan');

    $note = TaxNote::query()->with('lines')->sole();

    expect($note)->reason_code->toBe('lines_cancelled')->total->toBe(15_820)->and($note->lines)->toHaveCount(2)
        ->and($invoice->fresh())->status->toBe(InvoiceStatus::Unpaid)->balance->toBe(2_260)
        ->and(oldPathReasons($invoice))->toBe(['credit_note'])
        ->and($invoice->items()->whereNotNull('cancelled_at')->count())->toBe(2);
});

test('cancelling the last open line issues a single invoice_cancelled note, not two (AC 22)', function () {
    $invoice = oldPathInvoice([10_000, 4_000]);

    app(CancelInvoiceItems::class)->handle($invoice, [$invoice->items[0]->id], oldPathStaff(), 'First');
    app(CancelInvoiceItems::class)->handle($invoice->fresh(), [$invoice->items[1]->id], oldPathStaff(), 'Second');

    expect(TaxNote::query()->orderBy('id')->pluck('reason_code')->all())->toBe(['lines_cancelled', 'invoice_cancelled'])
        ->and($invoice->fresh())->status->toBe(InvoiceStatus::Cancelled)->balance->toBe(0);
});

test('cancelling lines needs a reason in the mode', function () {
    $invoice = oldPathInvoice([10_000, 4_000]);

    expect(fn () => app(CancelInvoiceItems::class)->handle($invoice, [$invoice->items[0]->id], oldPathStaff()))->toThrow(BillingException::class, 'Say why')
        ->and(TaxNote::query()->count())->toBe(0)
        ->and($invoice->items()->whereNotNull('cancelled_at')->count())->toBe(0);
});

test('a late fee on a numbered invoice is a debit note, and LateFeeApplied still fires (AC 24)', function () {
    billingSettings(['late_fee.type' => 'fixed', 'late_fee.amount' => 1_130, 'late_fee.minimum' => 0]);
    Event::fake([LateFeeApplied::class, DebitNoteIssued::class]);
    $invoice = oldPathInvoice();

    expect(app(ApplyLateFee::class)->handle($invoice))->toBeTrue();

    $note = TaxNote::query()->sole();

    expect($note)->kind->toBe('debit')->reason_code->toBe('late_fee')->total->toBe(1_277)->automatic->toBeTrue()
        ->and($invoice->fresh())->balance->toBe(12_577)->late_fee_applied_at->not->toBeNull()
        ->and(oldPathReasons($invoice))->toBe(['debit_note']);
    Event::assertDispatched(LateFeeApplied::class);
    Event::assertDispatched(DebitNoteIssued::class);
});

test('Reopen is refused for a numbered tax invoice in the mode, and works for a proforma or with the mode off (AC 25)', function () {
    $invoice = oldPathInvoice();
    DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'cancelled']);

    expect(fn () => app(ReopenInvoice::class)->handle($invoice->fresh(), oldPathStaff()))->toThrow(BillingException::class, 'cannot be reopened');

    billingSettings(['nepal_vat.enabled' => false]);
    expect(app(ReopenInvoice::class)->handle($invoice->fresh(), oldPathStaff())->status)->toBe(InvoiceStatus::Unpaid);
});

test('before the start day the old path runs and its row is dated by the transaction instant; from 00:00 Nepal time a note replaces it (AC 26)', function () {
    billingSettings(['nepal_vat.enabled_from' => '2026-11-17']);
    CarbonImmutable::setTestNow('2026-11-16 17:00:00 UTC');
    $before = oldPathInvoice();
    $after = oldPathInvoice();

    CarbonImmutable::setTestNow('2026-11-16 18:14:59 UTC');
    app(CancelInvoice::class)->handle($before, oldPathStaff(), 'Late on the last day');
    CarbonImmutable::setTestNow('2026-11-16 18:15:00 UTC');
    app(CancelInvoice::class)->handle($after, oldPathStaff(), 'On the first day');

    expect(oldPathReasons($before))->toBe(['cancellation'])
        ->and(DB::table('invoice_adjustments')->where('invoice_id', $before->id)->value('created_at'))->toBe('2026-11-16 18:14:59')
        ->and(oldPathReasons($after))->toBe(['credit_note'])
        ->and(TaxNote::query()->sole())->issue_date->toDateString()->toBe('2026-11-17');
});

test('a note cannot be issued before the start day', function () {
    billingSettings(['nepal_vat.enabled_from' => '2026-11-17']);
    $invoice = oldPathInvoice();

    expect(fn () => app(TaxNoteIssuer::class)->issueNow(NoteRequest::creditAmount($invoice->id, 1_000, 'goodwill', 'Early'), CarbonImmutable::now()))
        ->toThrow(BillingException::class, 'starts on 2026-11-17')
        ->and(TaxNote::query()->count())->toBe(0);
});

test('the mode is read from the database under the lock, not from a cached value', function () {
    $mode = app(NepalVatMode::class);
    expect($mode->enabled())->toBeTrue();

    // Another process switches the mode off; this request's settings memo still says on.
    Setting::query()->whereKey('billing.nepal_vat.enabled')->update(['value' => false]);

    expect($mode->enabled())->toBeTrue()->and(DB::transaction(fn () => $mode->inEffectLocked(CarbonImmutable::now())))->toBeFalse();
});

test('enabling is refused while a refund recorded the old way is pending on a numbered invoice, and not when a note reserved it', function () {
    $invoice = oldPathInvoice();
    billingSettings(['nepal_vat.enabled' => false]);
    $refund = Transaction::factory()->create(['client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'type' => 'refund', 'status' => 'pending', 'amount_in' => 0, 'amount_out' => 500, 'currency' => 'NPR']);
    $vat = TaxRule::query()->where('name', 'VAT')->value('id');
    $enable = fn () => app(NepalVatMode::class)->apply(User::factory()->create(['is_staff' => true]), ['enabled' => true, 'enabled_from' => '2026-11-17', 'vat_rules' => [$vat], 'export_rule' => null]);

    expect(fn () => $enable())->toThrow(ValidationException::class, 'refund');

    DB::table('tax_note_reservations')->insert(['invoice_id' => $invoice->id, 'transaction_id' => $refund->id, 'reason_code' => 'refunded', 'digest' => str_repeat('a', 64), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

    expect($enable())->toBeTrue();
});
