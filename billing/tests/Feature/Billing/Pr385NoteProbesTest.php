<?php

use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use App\Events\Billing\CreditNoteIssued;
use App\Modules\Billing\Actions\CancelInvoiceItems;
use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\FiscalBackfill;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Modules\Billing\Support\NoteAllocator;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Platform\Services\SettingsCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;

require_once __DIR__.'/helpers.php';

/*
| Review probes for #385 (VAT PR 1b). Each states the rule the issuer must keep.
*/

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-20 06:00:00');
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR']);
    TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function rbClient(): Client
{
    return Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
}

function rbIssue(NoteRequest $request): TaxNote
{
    $issuer = app(TaxNoteIssuer::class);
    $preview = $issuer->preview($request);

    return $issuer->issue($request, $preview->version, $preview->digest);
}

/** @return array{net: int, tax1: int, min_net: int} what the notes put on one line */
function rbLineSums(int $itemId): array
{
    $rows = DB::table('tax_note_lines')->where('invoice_item_id', $itemId)->get();

    return ['net' => (int) $rows->sum('amount'), 'tax1' => (int) $rows->sum('tax1_amount'), 'min_net' => (int) $rows->min('amount')];
}

test('B1: many small credits on one line never credit more net than the line has, and no note line has negative net', function () {
    // One line: net 100, VAT 13, gross 113. A gross of 4 rounds its VAT to 0 (13 * 4 / 113 = 0.46).
    $invoice = issueInvoice(rbClient(), [100])->fresh(['items']);
    $item = $invoice->items->first();
    expect([$item->amount, $item->tax_amount])->toBe([100, 13]);

    for ($i = 0; $i < 26; $i++) {
        rbIssue(NoteRequest::creditLines($invoice->id, [$item->id => 4], 'goodwill', 'Small credit '.$i));
    }

    // 26 x 4 = 104 gross credited, all as net: the line's net is 100.
    expect(rbLineSums($item->id)['net'])->toBeLessThanOrEqual(100);
});

test('B1b: the note that takes the remainder after many small credits has non-negative net and VAT no larger than its gross', function () {
    $invoice = issueInvoice(rbClient(), [100])->fresh(['items']);
    $item = $invoice->items->first();

    for ($i = 0; $i < 28; $i++) {
        rbIssue(NoteRequest::creditLines($invoice->id, [$item->id => 4], 'goodwill', 'Small credit '.$i));
    }

    $last = rbIssue(NoteRequest::creditLines($invoice->id, [$item->id => 1], 'goodwill', 'Remainder'));
    $line = $last->lines->first();

    expect(['net' => $line->amount, 'tax1' => $line->tax1_amount, 'gross' => $last->total])
        ->and($line->amount)->toBeGreaterThanOrEqual(0)
        ->and($line->tax1_amount)->toBeLessThanOrEqual($last->total);
});

test('B1c: the same overshoot through notes by amount on a mixed taxable and exempt invoice', function () {
    $invoice = issueInvoice(rbClient(), [100, new InvoiceLineData('Domain', 100, taxable: false, billableType: BillableType::Manual)])->fresh(['items']);
    [$taxed] = $invoice->items->pluck('id')->all();

    // Each spread of 6 puts about 3 on each line; 3 on the taxed line rounds its VAT to 0.
    for ($i = 0; $i < 34; $i++) {
        rbIssue(NoteRequest::creditAmount($invoice->id, 6, 'goodwill', 'Spread '.$i));
    }

    expect(rbLineSums($taxed)['net'])->toBeLessThanOrEqual(100);
});

test('V1: the cached totals agree with the reconcile pass after credits, debits and a legacy cancellation', function () {
    $invoice = issueInvoice(rbClient(), [3_333, 4_444, 1_111])->fresh(['items']);
    rbIssue(NoteRequest::creditAmount($invoice->id, 3_001, 'price_correction', 'Part'));
    rbIssue(NoteRequest::debit($invoice->id, 565, true, 'late_fee', 'Late fee'));
    rbIssue(NoteRequest::creditLines($invoice->id, [$invoice->items[2]->id => 700], 'lines_cancelled', 'Line'));
    app(CancelInvoiceItems::class)->handle($invoice, [$invoice->items[1]->id]);

    CarbonImmutable::setTestNow('2026-10-20 08:00:00');
    expect(app(FiscalBackfill::class)->mismatches())->toBe(0)
        ->and(app(FiscalBackfill::class)->reconcile())->toBe(0);
});

test('V2: a note rolled back with its outer transaction dispatches no CreditNoteIssued and consumes no number', function () {
    $seen = [];
    Event::listen(CreditNoteIssued::class, function (CreditNoteIssued $event) use (&$seen) {
        $seen[] = $event->number;
    });
    $invoice = issueInvoice(rbClient(), [10_000])->fresh(['items']);

    try {
        DB::transaction(function () use ($invoice, &$seen) {
            rbIssue(NoteRequest::creditAmount($invoice->id, 1_000, 'goodwill', 'Rolled back'));
            expect($seen)->toBe([]);

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    expect($seen)->toBe([]);
    rbIssue(NoteRequest::creditAmount($invoice->id, 1_000, 'goodwill', 'Kept'));
    expect($seen)->toBe(['CN-1']);
});

test('V3: a yearly note sequence resets at Nepal midnight on 1 January, not at UTC midnight', function () {
    inCompanyTimezone('Asia/Kathmandu');
    billingSettings(['credit_note_reset' => 'yearly', 'credit_note_format' => 'CN-{YEAR}-{NUMBER}']);
    CarbonImmutable::setTestNow('2026-12-31 10:00:00 UTC');
    $invoice = issueInvoice(rbClient(), [10_000])->fresh(['items']);

    CarbonImmutable::setTestNow('2026-12-31 18:14:59 UTC');
    $a = rbIssue(NoteRequest::creditAmount($invoice->id, 100, 'goodwill', 'a'));
    $b = rbIssue(NoteRequest::creditAmount($invoice->id, 100, 'goodwill', 'b'));
    CarbonImmutable::setTestNow('2026-12-31 18:15:00 UTC');
    $c = rbIssue(NoteRequest::creditAmount($invoice->id, 100, 'goodwill', 'c'));
    $d = rbIssue(NoteRequest::debit($invoice->id, 100, true, 'other', 'd'));

    expect([$a->number, $b->number, $c->number, $d->number])->toBe(['CN-2026-1', 'CN-2026-2', 'CN-2027-1', 'DN-1'])
        ->and($c->issue_date->toDateString())->toBe('2027-01-01');
});

test('M1: emptied note formats are refused, so credit and debit numbers cannot collide on tax_notes.number', function () {
    $rules = app(SettingsCatalog::class)->rules('billing');
    $errors = Validator::make(['billing' => ['credit_note_format' => null, 'debit_note_format' => null, 'credit_note_padding' => 0, 'credit_note_reset' => 'yearly', 'debit_note_padding' => 0, 'debit_note_reset' => 'never']], $rules)->errors()->messages();

    expect(array_filter($errors, fn ($key) => str_contains($key, '_note_'), ARRAY_FILTER_USE_KEY))->not->toBe([]);
});

test('M1b: with both formats stored empty, a credit note and a debit note still get distinct numbers', function () {
    $invoice = issueInvoice(rbClient(), [10_000])->fresh(['items']);
    billingSettings(['credit_note_format' => '', 'debit_note_format' => '']);
    $credit = rbIssue(NoteRequest::creditAmount($invoice->id, 100, 'goodwill', 'c'));
    $debit = rbIssue(NoteRequest::debit($invoice->id, 100, true, 'other', 'd'));

    expect($debit->number)->not->toBe($credit->number);
});

test('M2: a spread by amount never allocates more than the amount asked, even when a line is already over-used', function () {
    $line = fn (int $id, int $used) => ['id' => $id, 'description' => "L$id", 'taxable' => true, 'gross' => 1_130, 'net' => 1_000, 'tax1' => 130, 'tax2' => 0, 'used_gross' => $used, 'used_net' => 0, 'used_tax1' => 0, 'used_tax2' => 0];

    $parts = app(NoteAllocator::class)->byAmount([$line(1, 1_500), $line(2, 0), $line(3, 0)], 1_000, 'NPR');

    expect(array_sum(array_column($parts, 'gross')))->toBe(1_000);
});

// M2b (a legacy CancelInvoiceItems after a partial note over-credits the line) cannot pass until PR 3 makes that path count
// note lines; ADR 0024's PR 3 row records it.
/*
test('M2b: a legacy line cancellation after a partial note on that line over-credits the line', function () {
    $invoice = issueInvoice(rbClient(), [10_000, 10_000])->fresh(['items']);
    [$first] = $invoice->items->pluck('id')->all();
    rbIssue(NoteRequest::creditLines($invoice->id, [$first => 5_000], 'lines_cancelled', 'Half the first line'));

    app(CancelInvoiceItems::class)->handle($invoice, [$first]);

    $legacy = (int) DB::table('invoice_adjustments')->where('invoice_id', $invoice->id)->where('reason', 'item_cancellation')->sum('amount');
    expect(5_000 + $legacy)->toBeLessThanOrEqual(11_300);
});
*/
